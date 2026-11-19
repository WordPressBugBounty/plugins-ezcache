<?php

namespace Upress\EzCache;

use Upress\EzCache\Utilities\Cron;
use Upress\EzCache\Utilities\Logger;

/**
 * Cache Preloader.
 *
 * Inspired by WP Rocket's Preload module, ezCache's Preload feature warms up
 * the cache for all the public URLs of the website by crawling them in
 * background batches. Sources of URLs include:
 *  - The homepage and links found on it
 *  - The XML sitemap (auto-detected or user provided)
 *  - URLs added programmatically via the `ezcache_preload_queue_url` filter
 *
 * The queue is stored as a WordPress option to avoid the need for a database
 * table. URLs are processed in small batches by a recurring cron event so we
 * don't overload the host. Each URL is fetched twice (desktop + mobile UA) so
 * both cache variants are generated.
 */
class Preload {

	const QUEUE_OPTION    = 'ezcache_preload_queue';
	const STATE_OPTION    = 'ezcache_preload_state';
	const CRON_HOOK       = 'ezcache_preload_process_queue';
	const CRON_INTERVAL   = 'every_minute_ezcache';
	const PROCESSED_OPTION = 'ezcache_preload_processed';

	/** @var Preload */
	protected static $instance;

	/** @var Cache */
	protected $cache;

	/** @var object */
	protected $settings;
    private $deadline;
    private $sitemap_deadline;
    private $sitemaps_seen = [];

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->cache    = Cache::instance();
		$this->settings = Settings::get_settings();

		add_filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'process_queue' ] );

		// Trigger preload after cache is cleared (full or partial).
		add_action( 'ezcache_after_clear_cache', [ $this, 'maybe_start_preload' ], 10, 0 );
		add_action( 'ezcache_after_clear_cache_single', [ $this, 'enqueue_post_url' ], 10, 1 );

		// A run whose processor event went missing (older versions dropped it
		// when the preload toggle was off) picks up again on the next admin visit.
		add_action( 'admin_init', [ $this, 'maybe_resume_stalled' ] );
	}

	/**
	 * Make sure the queue processor is scheduled, whatever the preload toggle
	 * says. Used while a run is in progress: a run started by hand must finish
	 * even when automatic preloading is off. The processor unschedules itself
	 * once the queue is empty (see process_queue()).
	 */
	public function ensure_cron() {
        if (Settings::is_disabled()) { return; }
		// Cron::ensure also gets an event that is stuck in the past run and, when WordPress
		// does not spawn WP-Cron itself (DISABLE_WP_CRON), triggers one run.
		Cron::ensure( self::CRON_HOOK, 30, self::CRON_INTERVAL );
	}

	/**
	 * Whether URLs are still waiting in the queue.
	 *
	 * @return bool
	 */
	public function has_pending_queue() {
		$queue = get_option( self::QUEUE_OPTION, [] );

		return is_array( $queue ) && ! empty( $queue );
	}

	/**
	 * Re-arm the processor for a run that is marked running but has no cron
	 * event. Cheap on purpose: the cron check reads an autoloaded option and
	 * the state is read only when the event is missing.
	 */
	public function maybe_resume_stalled() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, [] );
		if ( is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'] && $this->has_pending_queue() ) {
			$this->ensure_cron();
			// Count the re-arm as activity, so the status reads "running" rather
			// than "stalled" during the minute before the first batch completes.
			$state['last_batch'] = time();
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Continue a stalled or interrupted run from where it stopped: re-arm the
	 * processor and handle one batch right away so the user sees movement.
	 *
	 * @return array Status data.
	 */
	public function resume() {
        $lock = Utilities\Lock::acquire('preload-' . get_current_blog_id());
        if (!$lock) { return new \WP_Error('preload_busy','A preload batch is running. Try again shortly.',['status'=>409]); }
        try { return $this->resume_locked(); } finally { Utilities\Lock::release($lock); }
    }

    private function resume_locked() {
		if ( $this->has_pending_queue() ) {
			$state = get_option( self::STATE_OPTION, [] );
			if ( ! is_array( $state ) ) {
				$state = [];
			}
			$state['status'] = 'running';
			update_option( self::STATE_OPTION, $state, false );

			$this->ensure_cron();
			$this->process_queue();
		}

		return $this->get_status();
	}

	/**
	 * Register a 1-minute cron schedule that we use to process the queue
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = [
				'interval' => 60,
				'display'  => __( 'Every Minute (ezCache Preload)', 'ezcache' ),
			];
		}

		return $schedules;
	}

	/**
	 * Schedule (or unschedule) the recurring queue processor based on settings.
	 */
	public function maybe_schedule_cron() {
        if (Settings::is_disabled()) { wp_clear_scheduled_hook(self::CRON_HOOK); return; }
        $this->settings = Settings::get_settings();
		if ( ! empty( $this->settings->enable_preload ) ) {
			$this->ensure_cron();
		} else {
			// Automatic preloading is off. Keep the processor while a run is
			// still in progress (saving settings must not kill a running
			// preload); it unschedules itself once the queue is empty.
			if ( $this->has_pending_queue() ) {
				return;
			}
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}
	}

	/**
	 * Start a preload run if the feature is enabled.
	 */
	public function maybe_start_preload() {
        if ((defined('EZCACHE_DEACTIVATING') || get_transient('ezcache_deactivating'))) { return; }
        $this->settings = Settings::get_settings();
		if ( empty( $this->settings->enable_preload ) ) {
			return;
		}

		if ( empty( $this->settings->preload_on_cache_clear ) ) {
			return;
		}

		$this->start();
	}

	/**
	 * Build the initial queue and kick off the cron processor.
	 *
	 * @return array Status data.
	 */
    public function start(  ) {
        $lock = Utilities\Lock::acquire('preload-' . get_current_blog_id());
        if (!$lock) { return new \WP_Error('preload_busy', 'A preload batch is running. Try again shortly.', ['status'=>409]); }
        try { return $this->start_locked(); } finally { Utilities\Lock::release($lock); }
    }
    private function start_locked(  ) {
        $this->settings = Settings::get_settings();
        $this->sitemap_deadline = microtime(true) + 10;
        $this->sitemaps_seen = [];
		$urls = $this->collect_initial_urls();
		$urls = apply_filters( 'ezcache_preload_initial_urls', $urls );

		$urls = $this->normalize_urls( $urls );

		update_option( self::QUEUE_OPTION, array_values( $urls ), false );
		update_option( self::PROCESSED_OPTION, [], false );
		update_option( self::STATE_OPTION, [
			'status'     => 'running',
			'total'      => count( $urls ),
			'processed'  => 0,
            'failed' => 0,
            'attempts' => [],
            'failed_urls' => [],
			'started'    => time(),
			'finished'   => 0,
			'last_batch' => 0,
		], false );

		// A run started by hand must complete even when the preload toggle is
		// off. Before 2.6.8 the processor was only scheduled with the toggle
		// on, so "Run preload" did one batch and then silently stalled.
		$this->ensure_cron();

		// Run an immediate first batch so the user sees movement quickly.
		if ( ! defined( 'EZCACHE_PRELOAD_NO_IMMEDIATE' ) || ! EZCACHE_PRELOAD_NO_IMMEDIATE ) {
			$this->process_queue();
		}

		return $this->get_status();
	}

	/**
	 * Cancel any in-progress preload.
	 */
    public function stop(  ) {
        $lock = Utilities\Lock::acquire('preload-' . get_current_blog_id());
        if (!$lock) { return new \WP_Error('preload_busy', 'A preload batch is running. Try again shortly.', ['status'=>409]); }
        try { return $this->stop_locked(); } finally { Utilities\Lock::release($lock); }
    }
    private function stop_locked(  ) {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::PROCESSED_OPTION );
		update_option( self::STATE_OPTION, [
			'status'    => 'cancelled',
			'total'     => 0,
			'processed' => 0,
            'failed' => 0,
			'started'   => 0,
			'finished'  => time(),
		], false );

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		return $this->get_status();
	}

	/**
	 * Return the status of the running/last preload run.
	 *
	 * @return array
	 */
	public function get_status() {
		$state = get_option( self::STATE_OPTION, [] );
		$queue = get_option( self::QUEUE_OPTION, [] );

		$defaults = [
			'status'    => 'idle',
			'total'     => 0,
			'processed' => 0,
            'failed' => 0,
			'started'   => 0,
			'finished'  => 0,
		];
		$state = array_merge( $defaults, is_array( $state ) ? $state : [] );

		$state['remaining']  = is_array( $queue ) ? count( $queue ) : 0;
		$state['enabled']    = ! empty( $this->settings->enable_preload );
		$state['scheduled']  = (bool) wp_next_scheduled( self::CRON_HOOK );
		$state['last_batch'] = isset( $state['last_batch'] ) ? (int) $state['last_batch'] : 0;

		// A run is stalled when URLs are waiting but nothing will process them:
		// no cron event, or no batch for a long while (a batch is at most
		// 2 x 30 s per URL; the processor runs every minute).
		$last_activity     = max( (int) $state['last_batch'], (int) $state['started'] );
		$stalled_after     = (int) apply_filters( 'ezcache_preload_stalled_after', 10 * MINUTE_IN_SECONDS );
		$state['stalled']  = 'running' === $state['status']
			&& $state['remaining'] > 0
			&& ( ! $state['scheduled'] || ( $last_activity > 0 && time() - $last_activity > $stalled_after ) );

		return $state;
	}

	/**
	 * Add a URL to the queue if it's not yet been processed.
	 *
	 * @param string $url
	 */
    public function enqueue_url( $url ) {
        $lock = Utilities\Lock::acquire('preload-' . get_current_blog_id());
        if (!$lock) { return new \WP_Error('preload_busy', 'A preload batch is running. Try again shortly.', ['status'=>409]); }
        try { return $this->enqueue_url_locked($url); } finally { Utilities\Lock::release($lock); }
    }
    private function enqueue_url_locked( $url ) {
		$url = $this->normalize_url( $url );
		if ( ! $url ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}
		$processed = get_option( self::PROCESSED_OPTION, [] );
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}

		if ( in_array( $url, $queue, true ) || in_array( $url, $processed, true ) ) {
			return;
		}

        if (count($queue)>=50000) { return; }
		$queue[] = $url;
		update_option( self::QUEUE_OPTION, array_values( $queue ), false );

		$state = get_option( self::STATE_OPTION, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$state['total']  = ( isset( $state['total'] ) ? (int) $state['total'] : 0 ) + 1;
		$state['status'] = 'running';
		update_option( self::STATE_OPTION, $state, false );

		$this->maybe_schedule_cron();
	}

	/**
	 * Convenience method to enqueue a single post permalink.
	 *
	 * @param int $post_id
	 */
	public function enqueue_post_url( $post_id ) {
		if ( empty( $this->settings->enable_preload ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( $url ) {
			$this->enqueue_url( $url );
		}
	}

	/**
	 * Process the next batch of URLs from the queue.
	 */
    public function process_queue() {
        $lock = Utilities\Lock::acquire('preload-' . get_current_blog_id());
        if (!$lock) { return; }
        try {
            $this->settings = Settings::get_settings();
            $this->deadline = microtime(true) + 15;
            $batch_size = max(1, min(50, (int)$this->settings->preload_batch_size));
            $seen = [];
            for ($i=0; $i<$batch_size && microtime(true)<$this->deadline; $i++) {
                if (Settings::is_disabled()) { $this->stop_locked(); return; }
                $queue = (array)get_option(self::QUEUE_OPTION, []);
                if (!$queue) { break; }
                $url = reset($queue);
                if (isset($seen[$url])) { break; } // At most one attempt per URL per batch.
                $seen[$url] = true;
                $ok = $this->fetch_url($url);
                if (Settings::is_disabled()) { $this->stop_locked(); return; }
                // Keep work in the queue until fetch completes. A killed worker retries it.
                $queue = (array)get_option(self::QUEUE_OPTION, []);
                $state = (array)get_option(self::STATE_OPTION, []);
                $key = hash('sha256', $url);
                $queue = array_values(array_diff($queue, [$url]));
                if ($ok === true) {
                    $state['processed'] = ($state['processed'] ?? 0) + 1;
                    unset($state['attempts'][$key]);
                    $processed = (array)get_option(self::PROCESSED_OPTION, []);
                    $processed[] = $url;
                    update_option(self::PROCESSED_OPTION, array_slice(array_unique($processed), -50000), false);
                } else {
                    $attempt = ($state['attempts'][$key] ?? 0) + 1;
                    $state['last_error'] = is_wp_error($ok) ? $ok->get_error_message() : 'Fetch failed';
                    if ($attempt < 3) { $state['attempts'][$key] = $attempt; $queue[] = $url; }
                    else {
                        unset($state['attempts'][$key]);
                        $state['failed'] = ($state['failed'] ?? 0) + 1;
                        $state['failed_urls'] = array_slice(array_merge($state['failed_urls'] ?? [], [$url]), -50);
                    }
                }
                update_option(self::QUEUE_OPTION, $queue, false);
                $state['last_batch'] = time();
                update_option(self::STATE_OPTION, $state, false);
            }
            $state = (array)get_option(self::STATE_OPTION, []);
            if (!$this->has_pending_queue() && ($state['status'] ?? '') === 'running') {
                $state['status'] = !empty($state['failed']) ? 'failed' : 'completed';
                $state['finished'] = time();
                update_option(self::STATE_OPTION, $state, false);
            }
            $this->maybe_schedule_cron();
        } finally { Utilities\Lock::release($lock); }
    }

	/**
	 * Fetch a single URL with both desktop and mobile user agents to populate
	 * both cache variants.
	 *
	 * @param string $url
	 */
	protected function fetch_url( $url ) {
		$desktop_ua = apply_filters(
			'ezcache_desktop_useragent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36 (ezCache Preload)'
		);
		$mobile_ua  = apply_filters(
			'ezcache_mobile_useragent',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1 (ezCache Preload)'
		);

		$args = [
			'timeout'             => max(0.1, min(5, ($this->deadline ?: microtime(true)+5) - microtime(true))),
            'limit_response_size' => 2*1024*1024,
			'redirection'         => 3,
			'blocking'            => true,
			'sslverify'           => $this->verify_ssl_for( $url ),
			'user-agent'          => $desktop_ua,
			'headers'             => [
				'X-Ezcache-Preload' => '1',
				'Cache-Control'     => 'no-cache',
			],
		];

        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response)) { return $response; }
        if ((int)wp_remote_retrieve_response_code($response) !== 200) { return new \WP_Error('preload_http', 'Preload did not return HTTP 200.'); }

		if ( ! empty( $this->settings->separate_mobile_cache ) ) {
			$args['user-agent'] = $mobile_ua;
            $args['timeout'] = max(0.1, min(5, $this->deadline - microtime(true)));
            $mobile = wp_safe_remote_get($url, $args);
            if (is_wp_error($mobile)) { return $mobile; }
            if ((int)wp_remote_retrieve_response_code($mobile) !== 200) { return new \WP_Error('preload_http', 'Mobile preload did not return HTTP 200.'); }
		}

		// Optionally extract internal links from the homepage to seed the queue.
		if ( $this->is_homepage( $url ) && ! empty( $this->settings->preload_crawl_homepage_links ) ) {
			$this->seed_from_homepage( $url, $response );
		}

		Logger::log( 'ezCache Preload fetched ' . $url );
        return true;
	}

	/**
	 * Returns true when the URL points to the site homepage.
	 *
	 * @param string $url
	 * @return bool
	 */
	protected function is_homepage( $url ) {
		return untrailingslashit( $url ) === untrailingslashit( home_url() );
	}


	/**
	 * Certificate checks for a request the preload makes. To this site itself they
	 * follow WordPress's rule for loopback requests (off unless the site says
	 * otherwise), as in every version before 2.7.0: with them always on, the
	 * preload stopped working on sites whose own certificate the server cannot
	 * verify. A sitemap on another host is always verified.
	 *
	 * @param string $url
	 * @return bool
	 */
	protected function verify_ssl_for( $url ) {
		$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
		$own  = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );

		return ( '' !== $host && $host === $own ) ? (bool) apply_filters( 'https_local_ssl_verify', false ) : true;
	}
	/**
	 * Crawl the homepage HTML and seed the queue with internal links found there.
	 *
	 * @param string $url
	 */
	protected function seed_from_homepage( $url, $response = null ) {
		$response = $response ?: wp_safe_remote_get( $url, [
			'timeout'    => 5,
            'limit_response_size' => 2*1024*1024,
			'sslverify'  => $this->verify_ssl_for( $url ),
			'user-agent' => 'ezCache Preload Crawler',
		] );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return;
		}

		if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $body, $matches ) ) {
			return;
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $matches[1] as $href ) {
			$href  = trim( $href );
			$href  = strtok( $href, '#' );
			$first = substr( $href, 0, 1 );

			if ( '' === $href || '#' === $first || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) || 0 === strpos( $href, 'javascript:' ) ) {
				continue;
			}

			// Resolve protocol relative & relative URLs.
			if ( 0 === strpos( $href, '//' ) ) {
				$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
			} elseif ( '/' === $first ) {
				$href = home_url( $href );
			} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}

			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $host !== $home_host ) {
				continue;
			}

			$this->enqueue_url( $href );
		}
	}

	/**
	 * Build the initial list of URLs from sitemaps and the homepage.
	 *
	 * @return array
	 */
	protected function collect_initial_urls() {
		$urls = [ home_url( '/' ) ];

		// Try the user provided sitemap first.
		$sitemap_urls = [];
		if ( ! empty( $this->settings->preload_sitemap_url ) ) {
			$sitemap_urls[] = trim( $this->settings->preload_sitemap_url );
		}

		// Auto-detect common sitemap locations.
		$sitemap_urls = array_merge( $sitemap_urls, [
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
		] );

		$found = false;
		foreach ( $sitemap_urls as $sitemap_url ) {
			$found_urls = $this->parse_sitemap( $sitemap_url );
			if ( ! empty( $found_urls ) ) {
				$urls  = array_merge( $urls, $found_urls );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			// Fallback: use recently published posts and pages.
			$query = new \WP_Query( [
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			] );

			foreach ( $query->posts as $post_id ) {
				$urls[] = get_permalink( $post_id );
			}
		}

		return $urls;
	}

	/**
	 * Parse an XML sitemap (recursively) and return all `<loc>` URLs.
	 *
	 * @param string $sitemap_url
	 * @param int    $depth
	 * @return array
	 */
	protected function parse_sitemap( $sitemap_url, $depth = 0 ) {
		if ( $depth > 3 || count($this->sitemaps_seen) >= 20 || isset($this->sitemaps_seen[$sitemap_url]) || ($this->sitemap_deadline && microtime(true) >= $this->sitemap_deadline) || wp_parse_url($sitemap_url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST) ) {
			return [];
		}

        $this->sitemaps_seen[$sitemap_url] = true;
		$response = wp_safe_remote_get( $sitemap_url, [
			'timeout'    => 5,
            'limit_response_size' => 2*1024*1024,
			'sslverify'  => $this->verify_ssl_for( $sitemap_url ),
			'user-agent' => 'ezCache Preload',
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [];
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) {
			return [];
		}

		$urls = [];

		// Sitemap index: contains <sitemap><loc>...</loc></sitemap> entries.
		if ( isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $entry ) {
                if (count($urls)>=5000) { break; }
				$loc = (string) $entry->loc;
				if ( $loc ) {
					$urls = array_merge( $urls, $this->parse_sitemap( $loc, $depth + 1 ) );
				}
			}
		}

		// Regular sitemap: contains <url><loc>...</loc></url> entries.
		if ( isset( $xml->url ) ) {
			foreach ( $xml->url as $entry ) {
                if (count($urls)>=5000) { break; }
				$loc = (string) $entry->loc;
				if ( $loc ) {
					$urls[] = $loc;
				}
			}
		}

		return $urls;
	}

	/**
	 * Normalize a URL: trim, ensure same host, drop fragments, dedupe.
	 *
	 * @param string $url
	 * @return string|false
	 */
	protected function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( empty( $url ) ) {
			return false;
		}

		$url = strtok( $url, '#' );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $url_host || $url_host !== $home_host ) {
			return false;
		}

		// Excluded URIs from settings: don't waste cycles preloading them.
		$settings = $this->settings;
		if ( ! empty( $settings->rejected_uri ) ) {
			$rejected = preg_split( "/\\r\\n|\\r|\\n/u", trim( $settings->rejected_uri ), -1, PREG_SPLIT_NO_EMPTY );
			$path     = wp_parse_url( $url, PHP_URL_PATH );
			foreach ( $rejected as $pattern ) {
				$pattern = trim( $pattern );
				if ( '' === $pattern ) {
					continue;
				}
				$regex = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );
				if ( @preg_match( '#^' . $regex . '/?$#u', $path ) ) {
					return false;
				}
			}
		}

		return $url;
	}

	/**
	 * Normalize and dedupe an array of URLs.
	 *
	 * @param array $urls
	 * @return array
	 */
	protected function normalize_urls( $urls ) {
		$out = [];
		foreach ( (array) $urls as $url ) {
			$url = $this->normalize_url( $url );
			if ( $url && ! isset( $out[ $url ] ) ) {
				$out[ $url ] = true;
			}
		}

		return array_keys( $out );
	}
}
