<?php

namespace Upress\EzCache;

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
		if ( ! empty( $this->settings->enable_preload ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 30, self::CRON_INTERVAL, self::CRON_HOOK );
			}
		} else {
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
	public function start() {
		$urls = $this->collect_initial_urls();
		$urls = apply_filters( 'ezcache_preload_initial_urls', $urls );

		$urls = $this->normalize_urls( $urls );

		update_option( self::QUEUE_OPTION, array_values( $urls ), false );
		update_option( self::PROCESSED_OPTION, [], false );
		update_option( self::STATE_OPTION, [
			'status'    => 'running',
			'total'     => count( $urls ),
			'processed' => 0,
			'started'   => time(),
			'finished'  => 0,
		], false );

		$this->maybe_schedule_cron();

		// Run an immediate first batch so the user sees movement quickly.
		if ( ! defined( 'EZCACHE_PRELOAD_NO_IMMEDIATE' ) || ! EZCACHE_PRELOAD_NO_IMMEDIATE ) {
			$this->process_queue();
		}

		return $this->get_status();
	}

	/**
	 * Cancel any in-progress preload.
	 */
	public function stop() {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::PROCESSED_OPTION );
		update_option( self::STATE_OPTION, [
			'status'    => 'cancelled',
			'total'     => 0,
			'processed' => 0,
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
			'started'   => 0,
			'finished'  => 0,
		];
		$state = array_merge( $defaults, is_array( $state ) ? $state : [] );

		$state['remaining'] = is_array( $queue ) ? count( $queue ) : 0;
		$state['enabled']   = ! empty( $this->settings->enable_preload );

		return $state;
	}

	/**
	 * Add a URL to the queue if it's not yet been processed.
	 *
	 * @param string $url
	 */
	public function enqueue_url( $url ) {
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
		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			$state = get_option( self::STATE_OPTION, [] );
			if ( is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'] ) {
				$state['status']   = 'completed';
				$state['finished'] = time();
				update_option( self::STATE_OPTION, $state, false );
			}
			return;
		}

		$batch_size = isset( $this->settings->preload_batch_size ) ? max( 1, (int) $this->settings->preload_batch_size ) : 5;
		$batch      = array_splice( $queue, 0, $batch_size );

		// Save updated queue first so a long fetch doesn't reprocess the same URL.
		update_option( self::QUEUE_OPTION, array_values( $queue ), false );

		$processed = get_option( self::PROCESSED_OPTION, [] );
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}

		foreach ( $batch as $url ) {
			$this->fetch_url( $url );
			$processed[] = $url;
		}

		// Cap processed list to prevent it from growing without bound on huge sites.
		$cap = (int) apply_filters( 'ezcache_preload_processed_cap', 50000 );
		if ( count( $processed ) > $cap ) {
			$processed = array_slice( $processed, -1 * $cap );
		}

		update_option( self::PROCESSED_OPTION, $processed, false );

		$state = get_option( self::STATE_OPTION, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$state['processed'] = ( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) + count( $batch );
		$state['status']    = empty( $queue ) ? 'completed' : 'running';
		if ( 'completed' === $state['status'] ) {
			$state['finished'] = time();
		}
		update_option( self::STATE_OPTION, $state, false );
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
			'timeout'             => 30,
			'redirection'         => 3,
			'blocking'            => true,
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'          => $desktop_ua,
			'headers'             => [
				'X-Ezcache-Preload' => '1',
				'Cache-Control'     => 'no-cache',
			],
		];

		wp_safe_remote_get( $url, $args );

		if ( ! empty( $this->settings->separate_mobile_cache ) ) {
			$args['user-agent'] = $mobile_ua;
			wp_safe_remote_get( $url, $args );
		}

		// Optionally extract internal links from the homepage to seed the queue.
		if ( $this->is_homepage( $url ) && ! empty( $this->settings->preload_crawl_homepage_links ) ) {
			$this->seed_from_homepage( $url );
		}

		Logger::log( 'ezCache Preload fetched ' . $url );
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
	 * Crawl the homepage HTML and seed the queue with internal links found there.
	 *
	 * @param string $url
	 */
	protected function seed_from_homepage( $url ) {
		$response = wp_safe_remote_get( $url, [
			'timeout'    => 30,
			'sslverify'  => false,
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
		if ( $depth > 3 ) {
			return [];
		}

		$response = wp_safe_remote_get( $sitemap_url, [
			'timeout'    => 30,
			'sslverify'  => false,
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
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) {
			return [];
		}

		$urls = [];

		// Sitemap index: contains <sitemap><loc>...</loc></sitemap> entries.
		if ( isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $entry ) {
				$loc = (string) $entry->loc;
				if ( $loc ) {
					$urls = array_merge( $urls, $this->parse_sitemap( $loc, $depth + 1 ) );
				}
			}
		}

		// Regular sitemap: contains <url><loc>...</loc></url> entries.
		if ( isset( $xml->url ) ) {
			foreach ( $xml->url as $entry ) {
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
