<?php

namespace Upress\EzCache;

use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use UnexpectedValueException;
use Upress\EzCache\BackgroundProcesses\ConvertWebpProcess;
use Upress\EzCache\FileOptimizer\CombineGoogleFonts;
use Upress\EzCache\FileOptimizer\CssMinifier;
use Upress\EzCache\FileOptimizer\CssCombiner;
use Upress\EzCache\FileOptimizer\JsMinifier;
use Upress\EzCache\FileOptimizer\JsCombiner;
use Upress\EzCache\FileOptimizer\WebpConverter;
use Upress\EzCache\ThirdParty\Minify_HTML;
use Upress\EzCache\Utilities\Cron;
use Upress\EzCache\Utilities\Logger;
use Upress\EzCache\PremiumFeatures;

class Cache {
	/** Directory under the cache root holding caches moved aside for background deletion. */
	const TRASH_DIR = '.trash';
	/** WP-Cron hook that deletes the contents of TRASH_DIR in batches. */
	const PURGE_HOOK = 'ezcache_purge_trash';

	protected static $instance;
	protected $settings;
	protected $cache_start_time;
	protected $webp_processor;
	protected $root_cache_dir = WP_CONTENT_DIR . '/cache/ezcache/v2/';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings         = Settings::get_settings();
		$this->webp_processor   = new ConvertWebpProcess();
		$this->cache_start_time = microtime( true );
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'nocache_headers', [ __CLASS__, 'note_nocache_headers' ] );
		}
	}

	/**
	 * @return string
	 */
	public function get_default_cache_path() {
		$hostname = preg_replace('/[^a-z0-9.-]/i', '_', $this->get_http_host());

		return $this->root_cache_dir . $hostname . '/';
	}

	/**
	 * Get the HTTP host
	 *
	 * @return string
	 */
	public function get_http_host() {
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $_SERVER['HTTP_HOST'] ) : strtolower( $_SERVER['HTTP_HOST'] );

			return htmlentities( $host );
		} elseif ( function_exists( 'get_option' ) ) {
			$parts = parse_url(get_option('home'));
            return ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
		}

		return '';
	}

	/**
	 * Check if the current user has a log in cookie set (ie. the user is logged in)
	 * @return bool
	 */
	public function has_login_cookie() {
		$cookiehash = '';
		if ( defined( 'COOKIEHASH' ) ) {
			$cookiehash = preg_quote( constant( 'COOKIEHASH' ), '|' );
		}

		$regex = "|^wordpress_logged_in_{$cookiehash}|";
		if ( defined( 'LOGGED_IN_COOKIE' ) ) {
			$regex = "|^" . preg_quote( constant( 'LOGGED_IN_COOKIE' ), '|' ) . '|';
		}

		foreach ( $_COOKIE as $key => $value ) {
			if ( preg_match( $regex, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a cookie is set to show that the current user has left comments and saved their data
	 * @return bool
	 */
	public function has_comment_author_cookie() {
		$cookiehash = '';
		if ( defined( 'COOKIEHASH' ) ) {
			$cookiehash = preg_quote( constant( 'COOKIEHASH' ) );
		}

		$regex = "/^wp-postpass_{$cookiehash}|^comment_author_{$cookiehash}/";

		foreach ( $_COOKIE as $key => $value ) {
			if ( preg_match( $regex, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the request supports gzip compression
	 *
	 * @return bool
	 */
	public function gzip_accepted() {
		if ( defined( 'EZCACHE_DISABLE_GZIP' ) && EZCACHE_DISABLE_GZIP ) {
			return false;
		}

		return isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' );
	}

	/**
	 * Check if the request supports webp images
	 *
	 * @return bool
	 */
	public function webp_accepted() {
		return isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' );
	}

	/**
	 * Check if the request comes from the backend
	 *
	 * @return bool
	 */
	public function is_backend() {
		if ( is_admin() ) {
			return true;
		}

		$script = isset( $_SERVER['PHP_SELF'] ) ? basename( $_SERVER['PHP_SELF'] ) : '';
		if ( $script !== 'index.php' ) {
			if ( in_array( $script, [ 'wp-login.php', 'xmlrpc.php', 'wp-cron.php' ] ) ) {
				return true;
			} elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return true;
			} elseif ( PHP_SAPI == 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the relative URL based on the url provided or false if the url is not on this website
	 * @param string $url
	 *
	 * @return string|bool
	 */
    public function get_relative_url( $url ) {
        if (!is_string($url) || $url === '') { return false; }
        $parts = parse_url($url);
        if (false === $parts || (isset($parts['host']) && strtolower($parts['host']) !== strtolower((string)parse_url(home_url(),PHP_URL_HOST)))) { return false; }
        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

	/**
	 * Should we serve the cached file
	 *
	 * @return bool
	 */
    public function refresh_settings() { $this->settings = Settings::get_settings(); }

    /**
     * Which signals make a page private.
     *
     * In every mode: a logged-in user, a WooCommerce session or cart, a
     * password-protected post, a comment author (as request cookies or as
     * cookies the response sets), the site's own "rejected cookies", HTTP
     * authentication, DONOTCACHEPAGE, a redirect, and an explicit
     * private/no-store/no-cache header from WordPress or a plugin.
     *
     * Only in strict mode (setting strict_response_privacy, off by default): an
     * open PHP session, the session cookie, and any other cookie the response
     * sets. 2.7.0 treated all of those as private unconditionally. On a real
     * shop that turned most of the page cache off: a plugin opens a session for
     * every visitor (category page 0.05 s -> 1.2 s on every view) and the theme
     * sets a "recently viewed" cookie on every product page. Such cookies say
     * something about the visitor, not about the page; every version before
     * 2.7.0, and other page caches, cache these pages. Strict mode is there for
     * sites that print per-visitor content from $_SESSION or from their own
     * cookies.
     */
    private function strict_privacy() {
        return ! empty( $this->settings->strict_response_privacy );
    }

    /** Cookie names that mark a visitor or a response as private in every mode. */
    private function is_private_cookie( $name ) {
        $name = (string) $name;
        if ( preg_match('/^(wp_woocommerce_session_|woocommerce_items_in_cart|woocommerce_cart_hash|wp-postpass_|wordpress_sec_|wordpress_logged_in_|comment_author_)/i', $name) ) { return true; }
        // The site's own list ("Rejected cookies" setting), read as on the request side.
        foreach ( preg_split('/[\r\n]+/', trim( (string) ( $this->settings->rejected_cookies ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY) as $pattern ) {
            if ( self::cookie_pattern_matches( $pattern, $name ) ) { return true; }
        }
        return false;
    }

    /**
     * Whether one line of the "Rejected cookies" setting matches a text.
     *
     * A line is literal text with * as a wildcard. Up to 2.6.9 the lines were
     * joined into one regular expression, so sites have entries such as
     * "member_session_.*" or "(vip|club)_id"; a line that is a valid expression
     * therefore still matches as one. Each line is tried on its own: joined, a
     * single invalid line ("*_in_cart") switched the whole list off.
     */
    private static function cookie_pattern_matches( $pattern, $text ) {
        $pattern = trim( (string) $pattern );
        if ( '' === $pattern ) { return false; }
        if ( preg_match( '#' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '#', (string) $text ) ) { return true; }
        return 1 === @preg_match( '#' . str_replace( '#', '\\#', $pattern ) . '#', (string) $text );
    }

    /** Name of PHP's session cookie (PHPSESSID unless the site renamed it). */
    private function session_cookie_name() {
        $name = function_exists('session_name') ? (string) session_name() : '';
        return '' !== $name ? $name : 'PHPSESSID';
    }

    private function session_blocks_cache() {
        return $this->strict_privacy() && function_exists('session_status') && session_status()===PHP_SESSION_ACTIVE;
    }

    /** Checks available before WordPress has resolved a query or a user. */
    private function request_is_public() {
        if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) { return false; }
        if ( $this->session_blocks_cache() ) { return false; }
        if ( ! empty($_SERVER['HTTP_AUTHORIZATION']) || ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER']) ) { return false; }
        if ( $this->has_login_cookie() || $this->has_comment_author_cookie() ) { return false; }
        $session_cookie = $this->session_cookie_name();
        foreach ( array_keys( $_COOKIE ) as $name ) {
            if ( preg_match('/^(wp_woocommerce_session_|woocommerce_items_in_cart|woocommerce_cart_hash|wp-postpass_|wordpress_sec_|wordpress_logged_in_|comment_author_)/i', $name) ) { return false; }
            if ( $this->strict_privacy() && ( 0 === stripos((string) $name, 'PHPSESSID') || 0 === strcasecmp((string) $name, $session_cookie) ) ) { return false; }
        }
        foreach ( ['_wpnonce','wc-ajax','add-to-cart','remove_item','removed_item','action','doing_wp_cron','add_to_wishlist','preview','rest_route','s'] as $param ) {
            if ( isset($_GET[$param]) ) { return false; }
        }
        if ( ! empty($this->settings->no_cache_filtered_pages) && $this->is_filtered_request() ) { return false; }
        if ( $this->url_exceeds_cache_limits() ) { return false; }
        $uri = rawurldecode( (string) ($_SERVER['REQUEST_URI'] ?? '/') );
        $path = explode('?', $uri, 2)[0];
        if ( preg_match('#/(?:wp-admin|wp-json|cart|checkout|my-account)(?:/|$)#i', $path) ) { return false; }
        foreach ( preg_split('/[\r\n]+/', trim((string)$this->settings->rejected_uri), -1, PREG_SPLIT_NO_EMPTY) as $pattern ) {
            if ( preg_match('#^https?://#i', $pattern) ) { $pattern = (string) parse_url($pattern, PHP_URL_PATH); }
            $pattern = '/' . trim($pattern, '/');
            $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '/?$#u';
            if ( preg_match($regex, $path) || preg_match($regex, $uri) ) { return false; }
        }
        foreach ( preg_split('/[\r\n]+/', trim((string)$this->settings->rejected_user_agent), -1, PREG_SPLIT_NO_EMPTY) as $agent ) {
            if ( false !== strpos($_SERVER['HTTP_USER_AGENT'] ?? '', trim($agent)) ) { return false; }
        }
        return true;
    }

    /** Set once WordPress or a plugin asked for "no cache" headers during this request. */
    public static $nocache_requested = false;

    /** Filter callback for nocache_headers; only takes note. */
    public static function note_nocache_headers( $headers ) {
        self::$nocache_requested = true;
        return $headers;
    }

    /**
     * Vary fields that mark a response as built for one visitor (lower case).
     * Filter: ezcache_private_vary_fields.
     *
     * @return string[]
     */
    private static function private_vary_fields() {
        $fields = [ '*', 'cookie', 'authorization' ];
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'ezcache_private_vary_fields', $fields );
            if ( is_array( $filtered ) ) { $fields = array_map( 'strtolower', array_map( 'strval', $filtered ) ); }
        }
        return $fields;
    }

    public function response_is_public() {
        if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) { return false; }
        // nocache_headers() was called for this response. Remembered here because a
        // session_start() that follows replaces those headers with PHP's own pair,
        // and that pair is tolerated below.
        if ( self::$nocache_requested ) { return false; }
        if ( $this->session_blocks_cache() ) { return false; }
        $status = http_response_code();
        if ( $status && 200 !== $status ) { return false; }
        $headers = headers_list();
        $strict  = $this->strict_privacy();
        // PHP's session "nocache" limiter always sends this exact pair. It describes
        // the session, not the page; WordPress and WooCommerce send different values
        // (and a different Expires date) when they mean "do not cache this page".
        $session_limiter = false;
        foreach ( $headers as $header ) {
            if ( 0 === stripos($header, 'Expires:') && false !== stripos($header, '19 Nov 1981') ) { $session_limiter = true; }
        }
        foreach ( $headers as $header ) {
            if ( 0 === stripos($header, 'Location:') ) { return false; }
            if ( 0 === stripos($header, 'Set-Cookie:') ) {
                if ( $strict ) { return false; }
                // Private cookies block; a cookie about the visitor (session id, "recently viewed") does not.
                $cookie = trim( explode('=', substr($header, 11), 2)[0] );
                if ( '' === $cookie || $this->is_private_cookie($cookie) ) { return false; }
                // The visitor already carried this cookie and the page sets it again: the page
                // was most likely built from it (a "recently viewed" list). It is sent to this
                // visitor but not stored for everyone else. PHP stores "a.b" as "a_b".
                if ( isset( $_COOKIE[ $cookie ] ) || isset( $_COOKIE[ str_replace( ['.', ' '], '_', $cookie ) ] ) ) { return false; }
                continue;
            }
            if ( ! $strict && $session_limiter && 0 === stripos($header, 'Cache-Control:')
                && 'no-store,no-cache,must-revalidate' === strtolower(preg_replace('/\s+/', '', substr($header,14))) ) { continue; }
            if (0 === stripos($header, 'Cache-Control:') && preg_match('/(?:^|,)\s*(?:(?:private|no-store|no-cache)(?=[=,\s]|$)|(?:s-maxage|max-age)\s*=\s*"?0"?(?=[,\s]|$))/i', trim(substr($header,14)))) { return false; }
            if (0 === stripos($header, 'Vary:')) {
                // Vary lists the request headers a proxy keeps separate copies for. Only a
                // few of them say that the page was built for one visitor: Cookie,
                // Authorization and "*". The others (Accept, Content-Type, Origin,
                // User-Agent, Accept-Language) describe the format of the request, and up to
                // 2.6.9 such pages were cached. 2.7.0 refused every field other than
                // Accept-Encoding. Jetpack sends "Vary: accept, content-type" on every
                // front-end page, so on those sites no page was stored at all and every
                // view was rendered by PHP. Strict mode keeps the 2.7.0 behaviour.
                foreach (explode(',',strtolower(substr($header,5))) as $field) {
                    $field = trim($field);
                    if ( '' === $field || 'accept-encoding' === $field ) { continue; }
                    if ( $strict || in_array( $field, self::private_vary_fields(), true ) ) { return false; }
                }
            }
        }
        return true;
    }

	public function should_serve_cached_data() {
		// Asked on every request, before WordPress has loaded. Right after an update PHP
		// can hold a mix of old and new plugin files for up to a minute; an error in
		// here would then be an error page for every visitor. "Do not use the cache"
		// is always a safe answer.
		try {
			return $this->request_may_use_cache();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function request_may_use_cache() {
        if (Settings::is_disabled()) { return false; }
        if ( ! $this->request_is_public() ) { return false; }
		// Dev Mode — bypass cache entirely
		if ( self::is_dev_mode_active() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'JSON_REQUEST' ) && JSON_REQUEST ) {
			return false;
		}
		if ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
			return false;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}
		if ( defined( 'WP_USE_THEMES' ) && false === WP_USE_THEMES ) {
			return false;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ( isset( $_SERVER['REQUEST_METHOD'] ) && in_array( $_SERVER['REQUEST_METHOD'], [
					'HEAD',
					'POST',
					'PUT',
					'PATCH',
					'DELETE',
				] ) ) || isset( $_GET['customize_changeset_uuid'] ) || isset( $_POST['wp_customize'] ) ) {
			return false;
		}

		$settings = $this->settings;

		if ( $settings->no_cache_known_users && $this->has_login_cookie() ) {
			return false;
		}

		if ( $settings->no_cache_comment_authors && $this->has_comment_author_cookie() ) {
			return false;
		}

		if ( $this->is_backend() ) {
			return false;
		}

		// Don't cache with variables but the cache is enabled if the visitor comes from an RSS feed, a Facebook action or Google Adsense tracking
		if (
			(
				$settings->no_cache_query_params
				&& $this->has_query_params()
				&& ! (
					isset( $_GET['utm_source'], $_GET['utm_medium'], $_GET['utm_campaign'] )
					|| isset( $_GET['utm_expid'] )
					|| isset( $_GET['fb_action_ids'], $_GET['fb_action_types'], $_GET['fb_source'] )
					|| isset( $_GET['gclid'] )
				)
			) || (
				isset( $_GET['permalink_name'] )
				|| isset( $_GET['lp-variation-id'] )
				|| isset( $_GET['lang'] )
				|| isset( $_GET['s'] )
				|| isset( $_GET['age-verified'] )
				|| isset( $_GET['ao_noptimize'] )
				|| isset( $_GET['usqp'] )
				|| isset( $_GET['woo_ajax'] )
			)
		) {

			return false;
		}

        $cookies=(string)json_encode($_COOKIE);
        foreach (preg_split('/[\r\n]+/',trim((string)$settings->rejected_cookies),-1,PREG_SPLIT_NO_EMPTY) as $pattern) {
            if (self::cookie_pattern_matches($pattern,$cookies)) { return false; }
        }

		return true;
	}

	/**
	 * Should we save the cache files.
	 * Most of the checks here have to be run after the page was rendered as they require WordPress.
	 *
	 * @return bool
	 */
	public function should_save_cache() {
		global $wp_query;

		if ( ! $this->should_serve_cached_data() || ! $this->response_is_public() ) {
			return false;
		}

		$settings = $this->settings;

		// check if we have any errors or otherwise settings preventing caching
		$error = error_get_last();
		if ( null !== $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
			return false;
		}

		if ( function_exists( 'http_response_code' ) && http_response_code() > 300 ) {
			return false;
		}

		if ( is_404() ) {
			return false;
		}

		if ( $settings->bypass_cache->single && is_single() ) {
			return false;
		}
		if ( $settings->bypass_cache->pages && is_page() ) {
			return false;
		}
		if ( $settings->bypass_cache->frontpage && is_front_page() ) {
			return false;
		}
		if ( $settings->bypass_cache->home && is_home() ) {
			return false;
		}
		if ( $settings->bypass_cache->archives && is_archive() ) {
			return false;
		}
		if ( $settings->bypass_cache->tag && is_tag() ) {
			return false;
		}
		if ( $settings->bypass_cache->category && is_category() ) {
			return false;
		}
		if ( $settings->bypass_cache->feed && is_feed() ) {
			return false;
		}
		if ( $settings->bypass_cache->search && is_search() ) {
			return false;
		}
		if ( $settings->bypass_cache->author && is_author() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		// WooCommerce account pages and endpoints (my-account, order-pay,
		// order-received, lost-password...) are per-user and carry nonces: a
		// cached login form served for days breaks logging in.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return false;
		}

		// Filtered archives (WooCommerce layered nav, Filter Everything, WOOF,
		// YITH and similar). Every filter combination is a distinct URL and
		// crawlers walk through them without end: on one site this produced
		// 1.27 million cache directories in three days and filled the server's
		// disk. Such pages are rendered live unless the site opts in.
		if ( ! empty( $settings->no_cache_filtered_pages ) && $this->is_filtered_request() ) {
			return false;
		}

		// Unusually deep or long paths are almost always generated (filter
		// combinations, crawler artefacts) rather than real pages.
		if ( $this->url_exceeds_cache_limits() ) {
			return false;
		}

		if ( is_null( $wp_query ) || is_robots() || get_query_var( 'sitemap' ) || get_query_var( 'xsl' ) || get_query_var( 'xml_sitemap' ) ) {
			return false;
		}

		if ( isset( $_GET['preview'] ) || isset( $_POST['wp_customize'] ) ) {
			return false;
		}

		// Never cache requests carrying a nonce or an action parameter. These are
		// either one-time/per-request tokens (e.g. _wpnonce) or non-idempotent
		// actions (add to cart, AJAX). Caching them is both wasteful (a new cache
		// variation per value) and incorrect — a cached page could serve one user's
		// nonce to another. The list is filterable for site-specific additions.
		$bypass_query_params = apply_filters( 'ezcache_bypass_query_params', [
			'_wpnonce', 'wc-ajax', 'add-to-cart', 'remove_item', 'removed_item',
			'action', 'doing_wp_cron', 'add_to_wishlist',
		] );
		foreach ( $bypass_query_params as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return false;
			}
		}

		if ( get_post_meta( get_the_ID(), '_ezcache_do_not_cache_post', true ) ) {
			return false;
		}

		// check useragent
		$rejected_useragents = preg_split( "/\\r\\n|\\r|\\n/u", trim( $settings->rejected_user_agent ), - 1, PREG_SPLIT_NO_EMPTY );
		$rejected_useragents = array_filter( $rejected_useragents );
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			foreach ( $rejected_useragents as $ua ) {
				if ( empty( $ua ) ) {
					continue;
				}

				if ( false !== strpos( $_SERVER['HTTP_USER_AGENT'], trim( $ua ) ) ) {
					return false;
				}
			}
		}

		// check URL
		$rejected_uris = preg_split( "/\\r\\n|\\r|\\n/u", trim( $settings->rejected_uri ), - 1, PREG_SPLIT_NO_EMPTY );
		$rejected_uris = array_filter( $rejected_uris );
		$domain        = untrailingslashit( home_url() );
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			foreach ( $rejected_uris as $url ) {
				$url = str_replace( $domain, '', $url );
				$url = '/' . trim( $url, '/' );
				// Build the wildcard pattern by escaping each literal segment and
				// joining the segments with `.*?`. This has to be done around
				// preg_quote(), not after it: running preg_quote() first turns every
				// `*` into `\*`, so a later str_replace('*', '.*?') corrupts it into
				// `\.*?` (zero-or-more literal dots) and the wildcard silently never
				// matches — which broke every pattern with a trailing or mid `*`.
				$regex = implode( '.*?', array_map(
					function ( $part ) { return preg_quote( $part, '/' ); },
					explode( '*', $url )
				) );
				if ( @preg_match( "/^{$regex}\/?$/u", urldecode( $_SERVER['REQUEST_URI'] ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get the mobile browser name
	 *
	 * @return string
	 */
	public function detect_mobile() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$mobile_browsers = apply_filters( 'ezcache_mobile_browsers', [
			'2.0 MMP',
			'240x320',
			'400X240',
			'AvantGo',
			'BlackBerry',
			'Blazer',
			'Cellphone',
			'Danger',
			'DoCoMo',
			'Elaine/3.0',
			'EudoraWeb',
			'Googlebot-Mobile',
			'hiptop',
			'IEMobile',
			'KYOCERA/WX310K',
			'LG/U990',
			'MIDP-2.',
			'MMEF20',
			'MOT-V',
			'NetFront',
			'Newt',
			'Nintendo Wii',
			'Nitro',
			'Nokia',
			'Opera Mini',
			'Palm',
			'PlayStation Portable',
			'portalmmm',
			'Proxinet',
			'ProxiNet',
			'SHARP-TQ-GX10',
			'SHG-i900',
			'Small',
			'SonyEricsson',
			'Symbian OS',
			'SymbianOS',
			'TS21i-10',
			'UP.Browser',
			'UP.Link',
			'webOS',
			'Windows CE',
			'WinWAP',
			'YahooSeeker/M1A1-R2D2',
			'iPhone',
			'iPod',
			'iPad',
			'Android',
			'BlackBerry9530',
			'LG-TU915 Obigo',
			'LGE VX',
			'webOS',
			'Nokia5800',
		] );
		$user_agent      = strtolower( $_SERVER['HTTP_USER_AGENT'] );
		foreach ( $mobile_browsers as $browser ) {
			if ( strstr( $user_agent, trim( strtolower( $browser ) ) ) ) {
				return $user_agent;
			}
		}

		if ( isset( $_SERVER['HTTP_X_WAP_PROFILE'] ) ) {
			return $_SERVER['HTTP_X_WAP_PROFILE'];
		}

		if ( isset( $_SERVER['HTTP_PROFILE'] ) ) {
			return $_SERVER['HTTP_PROFILE'];
		}

		$browser_prefixes = apply_filters( 'ezcache_mobile_browser_prefixes', [
			'w3c',
			'w3c-',
			'acs-',
			'alav',
			'alca',
			'amoi',
			'audi',
			'avan',
			'benq',
			'bird',
			'blac',
			'blaz',
			'brew',
			'cell',
			'cldc',
			'cmd-',
			'dang',
			'doco',
			'eric',
			'hipt',
			'htc_',
			'inno',
			'ipaq',
			'ipod',
			'jigs',
			'kddi',
			'keji',
			'leno',
			'lg-c',
			'lg-d',
			'lg-g',
			'lge-',
			'lg/u',
			'maui',
			'maxo',
			'midp',
			'mits',
			'mmef',
			'mobi',
			'mot-',
			'moto',
			'mwbp',
			'nec-',
			'newt',
			'noki',
			'palm',
			'pana',
			'pant',
			'phil',
			'play',
			'port',
			'prox',
			'qwap',
			'sage',
			'sams',
			'sany',
			'sch-',
			'sec-',
			'send',
			'seri',
			'sgh-',
			'shar',
			'sie-',
			'siem',
			'smal',
			'smar',
			'sony',
			'sph-',
			'symb',
			't-mo',
			'teli',
			'tim-',
			'tosh',
			'tsm-',
			'upg1',
			'upsi',
			'vk-v',
			'voda',
			'wap-',
			'wapa',
			'wapi',
			'wapp',
			'wapr',
			'webc',
			'winw',
			'winw',
			'xda',
			'xda-',
		] );
		foreach ( $browser_prefixes as $prefix ) {
			if ( substr( $user_agent, 0, 4 ) == $prefix ) {
				return $prefix;
			}
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( $_SERVER['HTTP_ACCEPT'] ) : '';
		if ( strpos( $accept, 'wap' ) !== false ) {
			return 'wap';
		}

		if ( isset( $_SERVER['ALL_HTTP'] ) && false !== strpos( strtolower( $_SERVER['ALL_HTTP'] ), 'operamini' ) ) {
			return 'operamini';
		}

		return '';
	}

	/**
	 * Search & replace in a string
	 *
	 * @param string|string[] $search
	 * @param string $subject
	 *
	 * @return string
	 */
	public function deep_replace( $search, $subject ) {
		$subject = (string) $subject;

		$count = 1;
		while ( $count ) {
			$subject = str_replace( $search, '', $subject, $count );
		}

		return $subject;
	}

	/**
	 * Get the cache directory URL for the current post
	 *
	 * @param int $post_id
	 *
	 * @param null|string $url
	 *
	 * @return mixed|string
	 */
    public function get_current_url_cache_dir( $post_id = 0, $url = null ) {
        $uri = $post_id ? get_permalink($post_id) : ($url !== null ? $url : ($_SERVER['REQUEST_URI'] ?? '/'));
        return md5( self::cache_key_path( $uri ) ) . '/';
    }

    /**
     * The path that names a page in the cache: no query string, no fragment,
     * lower-cased.
     *
     * A request path must not go through parse_url(). It reads "//shop/" as a
     * host and "/tag/x/page:2" or "/shop/tax/product_cat:16/" as a port and
     * returns no path at all, and such a page was then stored, and served, as
     * the home page. Only an absolute URL (a permalink) loses its scheme and
     * host here, with a pattern that cannot fail.
     *
     * Lower-casing keeps /%D7%A9/ and /%d7%a9/ on one entry, as every version
     * before 2.7.0 did. Browsers send either form; a purge by permalink has to
     * remove the copy visitors were actually served.
     *
     * @param string $uri Request URI, path or absolute URL.
     * @return string
     */
    public static function cache_key_path( $uri ) {
        $uri = (string) $uri;
        if ( preg_match( '#^[a-z][a-z0-9+.\-]*://[^/?\#]*#i', $uri, $match ) ) {
            $uri = (string) substr( $uri, strlen( $match[0] ) );
        }
        $uri = (string) substr( $uri, 0, strcspn( $uri, '?#' ) );
        if ( '' === $uri || '/' !== $uri[0] ) {
            $uri = '/' . $uri;
        }
        return strtolower( $uri );
    }

	/**
	 * Get the cache directory path
	 *
	 * @param int $postid
	 *
	 * @param null|string $url
	 *
	 * @return string
	 */
	public function get_real_cache_dir( $postid = 0, $url = null ) {
		return $this->get_default_cache_path() . $this->get_current_url_cache_dir( $postid, $url );
	}

	/**
	 * Get the full cache file path
	 *
	 * @param int $postid
	 *
	 * @param null|string $url
	 *
	 * @return string
	 */
	public function get_cache_file_path( $postid = 0, $url = null ) {
		return $this->get_real_cache_dir( $postid, $url ) . $this->get_cache_filename();
	}

	/**
	 * Get the filename for the cached file
	 *
	 * @return string
	 */
	/**
	 * Lowercased list of query-string parameters to ignore when building the
	 * cache key. Only meaningful when the ignore_query_params setting is on.
	 *
	 * @return array
	 */
	private function get_ignored_query_params() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$raw  = isset( $this->settings->ignored_query_params_list ) ? (string) $this->settings->ignored_query_params_list : '';
		$list = preg_split( '/[\s,]+/', strtolower( $raw ), -1, PREG_SPLIT_NO_EMPTY );

		/**
		 * Filters the query-string parameters ignored when building the cache key.
		 *
		 * @param array $list Lowercased parameter names.
		 */
		$list   = apply_filters( 'ezcache_ignored_query_params', $list );
		$cached = array_values( array_unique( array_map( 'strtolower', (array) $list ) ) );

		return $cached;
	}

	/**
	 * Normalize a raw query string for cache-key purposes. When the
	 * ignore_query_params feature is on, drop the ignored (tracking) parameters
	 * and sort the rest so different orderings and tracking values map to the
	 * same cache entry. Returns '' when nothing meaningful remains.
	 *
	 * @param string $query_string
	 * @return string
	 */
	private function normalize_query_string( $query_string ) {
		if ( '' === (string) $query_string ) {
			return '';
		}
		if ( empty( $this->settings->ignore_query_params ) ) {
			return $query_string; // feature off — behaviour unchanged
		}
		parse_str( (string) $query_string, $params );
		if ( empty( $params ) ) {
			return '';
		}
		$ignored = $this->get_ignored_query_params();
		foreach ( array_keys( $params ) as $key ) {
			if ( $this->query_param_is_ignored( strtolower( $key ), $ignored ) ) {
				unset( $params[ $key ] );
			}
		}
		if ( empty( $params ) ) {
			return '';
		}
		ksort( $params );

		return http_build_query( $params );
	}

	/**
	 * Whether a (lowercased) query parameter name matches the ignore list.
	 * Supports exact names and trailing-"*" prefix patterns (e.g. "utm_*").
	 * A bare "*" is skipped to avoid accidentally dropping every parameter.
	 *
	 * @param string $key     Lowercased parameter name.
	 * @param array  $ignored Lowercased ignore patterns.
	 * @return bool
	 */
	private function query_param_is_ignored( $key, $ignored ) {
		foreach ( $ignored as $pattern ) {
			if ( '' === $pattern || '*' === $pattern ) {
				continue;
			}
			if ( '*' === substr( $pattern, -1 ) ) {
				$prefix = substr( $pattern, 0, -1 );
				if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			} elseif ( $key === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the request carries query-string parameters that matter for
	 * caching. With ignore_query_params on, a request whose parameters are all
	 * on the ignore list (?gclid=..., ?srsltid=...) counts as having none, so
	 * it is served from, and saved to, the clean page's cache entry.
	 *
	 * @return bool
	 */
	private function has_query_params() {
		if ( empty( $_GET ) ) {
			return false;
		}
		if ( empty( $this->settings->ignore_query_params ) ) {
			return true;
		}
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		if ( '' === $query_string ) {
			// $_GET filled without a query string (rewrite rules): keep the old behaviour.
			return true;
		}

		return '' !== $this->normalize_query_string( $query_string );
	}

	/**
	 * Whether the current request is a filtered archive: a shop or archive page
	 * with one or more product/post filters applied. Detected through the
	 * filter plugins' own APIs when they are present, plus the query
	 * parameters the common filter plugins use. The result is filterable via
	 * ezcache_is_filtered_request; the parameter list via
	 * ezcache_filter_query_params (exact names, or a trailing * for a prefix).
	 *
	 * @return bool
	 */
	public function is_filtered_request() {
		$filtered = false;

		try {
			// WooCommerce layered nav, price and rating filters. Filter plugins
			// built on WooCommerce (YITH, WOOF and others) report through the
			// same woocommerce_is_filtered hook.
			if ( function_exists( 'is_filtered' ) && is_filtered() ) {
				$filtered = true;
			}

			// Filter Everything (free and Pro): its filters travel as path
			// segments, so only the plugin itself knows the request is filtered.
			if ( ! $filtered && function_exists( 'flrt_is_filter_request' ) && flrt_is_filter_request() ) {
				$filtered = true;
			}
		} catch ( \Throwable $e ) {
			Logger::log( 'ezCache filter detection failed: ' . $e->getMessage() );
		}

		if ( ! $filtered && ! empty( $_GET ) ) {
			$params = apply_filters( 'ezcache_filter_query_params', [
				'swoof', 'yith_wcan', 'filters', 'min_price', 'max_price', 'rating_filter',
				'filter_*', 'query_type_*', 'wpf_*', 'awf_*',
			] );
			$params = array_map( 'strtolower', array_filter( (array) $params, 'is_string' ) );
			foreach ( array_keys( $_GET ) as $key ) {
				if ( $this->query_param_is_ignored( strtolower( (string) $key ), $params ) ) {
					$filtered = true;
					break;
				}
			}
		}

		return (bool) apply_filters( 'ezcache_is_filtered_request', $filtered );
	}

	/**
	 * Whether the request path is deeper than max_cache_url_depth segments or
	 * longer than max_cache_url_length characters (measured on the decoded
	 * path, so non-Latin URLs are not penalised for their percent-encoding).
	 * A limit of 0 disables that check.
	 *
	 * @return bool
	 */
	public function url_exceeds_cache_limits() {
		$settings   = $this->settings;
		$max_depth  = isset( $settings->max_cache_url_depth ) ? (int) $settings->max_cache_url_depth : 0;
		$max_length = isset( $settings->max_cache_url_length ) ? (int) $settings->max_cache_url_length : 0;
		if ( $max_depth <= 0 && $max_length <= 0 ) {
			return false;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$pos  = strpos( $path, '?' );
		if ( false !== $pos ) {
			$path = substr( $path, 0, $pos );
		}
		$path = rawurldecode( $path );

		if ( $max_length > 0 ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $path, 'UTF-8' ) : strlen( $path );
			if ( $length > $max_length ) {
				return true;
			}
		}

		if ( $max_depth > 0 && count( array_filter( explode( '/', $path ), 'strlen' ) ) > $max_depth ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the full-page (Redis) cache URL for the current request, applying
	 * the same query-string normalization used for the disk cache key.
	 *
	 * @return string
	 */
	private function build_fullpage_url() {
		$scheme = ( is_ssl() ? 'https://' : 'http://' );
		$host   = $_SERVER['HTTP_HOST'] ?? '';
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';
		$path   = $uri;
		$qs     = '';
		$pos    = strpos( $uri, '?' );
		if ( false !== $pos ) {
			$path = substr( $uri, 0, $pos );
			$qs   = substr( $uri, $pos + 1 );
		}
		$norm = $this->normalize_query_string( $qs );

		return $scheme . $host . $path . ( '' !== $norm ? '?' . $norm : '' ) . '#ezcache=' . $this->get_cache_filename();
	}

	public function get_cache_filename() {
		$settings = $this->settings;

		// Add support for https and http caching
		// also supports https requests coming from an nginx reverse proxy
		$is_https  = ( ( isset( $_SERVER['HTTPS'] ) && 'on' == strtolower( $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' == strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
		$extra_str = $is_https ? '-https' : '';

		if ( $settings->separate_mobile_cache ) {
			$mobile_ua = $this->detect_mobile();
			if ( ! empty( $mobile_ua ) ) {
				$extra_str .= '-mobile';
			}
		}

		if ( $settings->enable_webp_support && $this->webp_accepted() ) {
			$extra_str .= '-webp';
		}

		$filename = 'index';
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$normalized = $this->normalize_query_string( $_SERVER['QUERY_STRING'] );
			// When every parameter was ignored, fall back to 'index' so the
			// request maps to the same cache entry as the clean URL.
			if ( '' !== $normalized ) {
				$filename = md5( $normalized );
			}
		}

		return $filename . $extra_str . '.html';
	}

	/**
	 * Check if we have a cached file and serve it
	 */
	public function maybe_serve_cached_data() {
		if ( ! $this->should_serve_cached_data() ) {
			return;
		}

		// ── Redis Full-Page Cache fast path ────────────────────
		// When enabled, try Redis first. A hit is sub-millisecond and skips
		// the disk read entirely. On miss we fall through to the disk path
		// below (and the response handler in maybe_write_cache_file will
		// populate Redis for next time).
		if (
			! empty( $this->settings->enable_redis_fullpage )
			&& class_exists( '\\Upress\\EzCache\\RedisObjectCache' )
		) {
			$current_url = $this->build_fullpage_url();
			$cached_html = \Upress\EzCache\RedisObjectCache::get_page( $current_url );
			if ( false !== $cached_html && '' !== $cached_html ) {
				header( 'X-Cached-With: ezCache (Redis)' );
				// As before 2.7.0. Naming User-Agent and Accept here makes a proxy in front of
				// the site (Varnish, a CDN) keep one copy per browser version.
				header( 'Vary: Accept-Encoding, Cookie' );
				echo $cached_html;
				exit;
			}
		}

		$cache_file    = $this->get_cache_file_path();
		$gzip_accepted = $this->gzip_accepted();

		$cache_file = $cache_file . '.gz';
		$filesize   = file_exists( $cache_file ) ? @filesize( $cache_file ) : false;

        $mtime = $filesize ? @filemtime($cache_file) : false;
        $ttl = (int) $this->settings->cache_lifetime;
        if ( $filesize && $ttl > 0 && ( ! $mtime || $mtime + $ttl <= time() ) ) { @unlink($cache_file); return; }

		if ( ! $filesize ) {
			// the file is empty, we have nothing to serve
			return;
		}

		header( "X-Cached-With: ezCache" );
		// As before 2.7.0: with User-Agent and Accept in this header a proxy in front of
		// the site (Varnish, a CDN) keeps one copy of every page per browser version.
		header( "Vary: Accept-Encoding, Cookie" );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $cache_file ) ) . ' GMT' );

		// Getting If-Modified-Since headers sent by the client.
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers                = apache_request_headers();
			$http_if_modified_since = ( isset( $headers['If-Modified-Since'] ) ) ? $headers['If-Modified-Since'] : '';
		} else {
			$http_if_modified_since = ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
		}

		// Checking if the client is validating his cache and if it is current.
		if ( $http_if_modified_since && ( strtotime( $http_if_modified_since ) === @filemtime( $cache_file ) ) ) {
			// Client's cache is current, so we just respond '304 Not Modified'.
			header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
			exit;
		}

		// Serve the cache if file isn't store in the client browser cache.
		// if the browser does not support gzip read the file and output it without gzip encoding
		if ( ! $gzip_accepted ) {
			readgzfile( $cache_file );
			exit;
		}

		// otherwise output the gzipped file as-is
		header( "Content-Length: {$filesize}" );
		header( "Content-Encoding: gzip" );
		readfile( $cache_file );
		exit;
	}

	public function do_frontend_optimizations() {
		if ( ! $this->should_serve_cached_data() ) {
			return;
		}

		$settings = $this->settings;

		if ( isset( $settings->disable_wp_emoji ) && $settings->disable_wp_emoji ) {
			add_action( 'init', function () {
				remove_action( 'admin_print_styles', 'print_emoji_styles' );
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
				remove_action( 'wp_print_styles', 'print_emoji_styles' );
				remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
				remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
				remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
				add_filter( 'emoji_svg_url', '__return_false' );
			}, 999 );
		}


		if ( ! empty( $settings->critical_css ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_critical_css' ], PHP_INT_MAX );
		}
	}

	public function enqueue_critical_css() {
		wp_register_style( 'ezcache-critical-css', false );
		wp_enqueue_style( 'ezcache-critical-css' );
		wp_add_inline_style( 'ezcache-critical-css', $this->settings->critical_css );
	}

	/**
	 * Write cache file if we need to
	 * @noinspection PhpUnused
	 */
	public function maybe_write_cache_file() {
		if ( ! $this->should_serve_cached_data() ) {
			return;
		}

		ob_start( [ $this, 'buffer_callback' ] );
	}

	/**
	 * Optimize output and write the buffer to the cache file
	 *
	 * @param string $buffer
	 *
	 * @return string
	 */
	/**
	 * Output-buffer callback. PHP throws the page away when its buffer callback
	 * fails, so anything thrown while optimizing or saving ends here and the page
	 * goes out the way WordPress produced it. (Right after an update the classes
	 * used in there can briefly be a mix of old and new files.)
	 *
	 * @param string $buffer
	 * @return string
	 */
	public function buffer_callback( $buffer ) {
		try {
			return $this->optimize_and_write_cache_file( $buffer );
		} catch ( \Throwable $e ) {
			Logger::log( 'ezCache: page sent without optimization: ' . $e->getMessage() );

			return $buffer;
		}
	}

	public function optimize_and_write_cache_file( $buffer ) {
		global $wpdb;

		// we need these check to run after WordPress is finished preparing the page
		if ( ! $this->should_save_cache() ) {
			return $buffer;
		}

		// Only process and cache real HTML responses. Non-HTML output — text/plain
		// (IndexNow key files, llms.txt), RSS/Atom feeds, JSON, etc. — must pass
		// through untouched: appending the footer comment or running the HTML
		// transforms (minify, WebP, combine) on it corrupts the content, and
		// IndexNow in particular requires a byte-exact body. We bail only on an
		// explicit non-HTML Content-Type; a missing header is treated as HTML so
		// normal page caching is never disabled.
		$content_type = '';
		foreach ( headers_list() as $header ) {
			if ( stripos( $header, 'content-type:' ) === 0 ) {
				$content_type = strtolower( $header );
			}
		}
		if ( '' !== $content_type
			&& false === stripos( $content_type, 'text/html' )
			&& false === stripos( $content_type, 'application/xhtml' ) ) {
			return $buffer;
		}

		$real_cache_dir  = $this->get_real_cache_dir();
		$cache_file      = $this->get_cache_file_path() . '.gz';
		$asset_cache_dir = $this->get_default_cache_path() . 'min/';
		$asset_cache_url = trailingslashit( trailingslashit( get_site_url() ) . trim( str_replace( dirname( WP_CONTENT_DIR ), '', $asset_cache_dir ), '/' ) );
		$settings        = $this->settings;

		if ( $settings->optimize_google_fonts ) {
			$optimizer = new CombineGoogleFonts();
			$buffer    = $optimizer->optimize( $buffer );
		}

		if ( $settings->minify_css ) {
			if ( $settings->combine_css ) {
				$optimizer = new CssCombiner( $asset_cache_dir, $asset_cache_url, $settings->combine_css_footer );
			} else {
				$optimizer = new CssMinifier( $asset_cache_dir, $asset_cache_url );
			}

			$buffer = $optimizer->optimize( $buffer );
		}

		if ( $settings->minify_js ) {
			if ( $settings->combine_head_js ) {
				$optimizer = new JsCombiner( $asset_cache_dir, $asset_cache_url, 'head', $settings->combine_head_inline_js );
				$buffer    = $optimizer->optimize( $buffer );
			}

			if ( $settings->combine_body_js ) {
				$optimizer = new JsCombiner( $asset_cache_dir, $asset_cache_url, 'body', $settings->combine_body_inline_js );
				$buffer    = $optimizer->optimize( $buffer );
			}

			if ( ! $settings->combine_head_js && ! $settings->combine_body_js ) {
				$optimizer = new JsMinifier( $asset_cache_dir, $asset_cache_url );
				$buffer    = $optimizer->optimize( $buffer );
			}
		}

		if ( $settings->minify_html ) {
			wp_raise_memory_limit( 'image' );

			$buffer = Minify_HTML::minify( $buffer, [
				'htmlCleanComments' => $settings->minify_html_comments,

				'cssMinifier' => function ( $css ) use ( $settings ) {
					if ( ! $settings->minify_inline_css ) {
						return $css;
					}

					$minifier = new CSS( $css );
					$minifier->setMaxImportSize( 0 );
					$minifier->setImportExtensions( [] );

					return $minifier->minify();
				},

				'jsMinifier' => function ( $js ) use ( $settings ) {
					if ( ! $settings->minify_inline_js ) {
						return $js;
					}

					$minifier = new JS( $js );

					return $minifier->minify();
				},
			] );
		}

		if ( $settings->enable_webp_support && $this->webp_accepted() ) {
			$optimizer = new WebpConverter( $real_cache_dir, $cache_file, $this->webp_processor, $wpdb );
			$buffer    = $optimizer->optimize( $buffer );
		}

		$buffer = trim( $buffer );
		if ( empty( $buffer ) ) {
			Logger::log( 'ezCache will not save cache file for a blank page' );

			return $buffer;
		}

		if ( ! apply_filters( 'wp_bost_hide_cache_time_comment', false ) ) {
			$total_time = number_format( microtime( true ) - $this->cache_start_time, 2 );
			$cache_type = ( \Upress\EzCache\Settings::get_settings()->enable_redis_fullpage ?? false ) ? 'Redis' : 'Disk';
			$buffer     .= "\n<!-- Cached by ezCache | Full-Page Cache: {$cache_type} | Generated: " . date('Y-m-d H:i:s') . " | Time: {$total_time}s -->";
		}

		$buffer = apply_filters( 'ezcache_before_save_cache', $buffer );
        if ( ! $this->response_is_public() ) { return $buffer; }

		// ── Redis Full-Page Cache write ───────────────────────
		// Mirror the cached HTML to Redis when the flag is on. TTL matches
		// the disk-cache lifetime so both backends expire in sync.
		if (
			! empty( $settings->enable_redis_fullpage )
			&& class_exists( '\\Upress\\EzCache\\RedisObjectCache' )
		) {
			$current_url = $this->build_fullpage_url();
			$ttl = (int) $settings->cache_lifetime;
			\Upress\EzCache\RedisObjectCache::set_page( $current_url, $buffer, $ttl );
		}

		if ( ! file_exists( $real_cache_dir ) ) {
			if ( ! @wp_mkdir_p( $real_cache_dir ) ) {
				Logger::log( 'ezCache could not create directory ' . $real_cache_dir );

				return $buffer;
			}
		}

        // Same-directory rename prevents readers from seeing a partially written gzip.
        // Not flushed to disk: a cached page lost in a power cut is simply rendered again,
        // and an fsync for every page that is cached is paid by the visitor waiting for it.
        if (!Utilities\AtomicFile::write($cache_file, gzencode($buffer,6,FORCE_GZIP), 0644, false)) {
            Logger::log('ezCache could not write cache file.');
        }

		return $buffer;
	}

	/**
	 * Delete a path recursively
	 *
	 * @param string $path
	 */
	public function rmdir_recursive( $path, $deadline = 0 ) {
		// Streams the tree instead of loading it into memory: glob() on a
		// directory with over a million entries exhausted PHP's memory and hung
		// admin requests. With a deadline (a microtime(true) value) the work
		// stops once it passes and false is returned; call again to continue.
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			$count = 0;
			foreach ( $iterator as $entry ) {
				if ( $entry->isDir() && ! $entry->isLink() ) {
					@rmdir( $entry->getPathname() );
				} else {
					@unlink( $entry->getPathname() );
				}
				if ( $deadline && ( ++$count % 200 ) === 0 && microtime( true ) > $deadline ) {
					return false;
				}
			}
		} catch ( \Throwable $e ) {
			Logger::log( 'ezCache could not delete ' . $path . ': ' . $e->getMessage() );

			return false;
		}

		@rmdir( $path );

		return ! file_exists( $path );
	}

	/**
	 * Empty the cache without waiting for the files to go. Every host
	 * directory under the cache root is renamed into .trash/ (instant, same
	 * filesystem) and the renamed copies are deleted afterwards in bounded
	 * batches: a little now, the rest from the ezcache_purge_trash cron
	 * event. The previous synchronous delete took minutes on large caches
	 * and, run from the upgrade routine, took whole sites down.
	 *
	 * Loose files directly under the root (the dev-mode flag) are kept.
	 *
	 * @param float $purge_budget Seconds to spend deleting right away.
	 */
    public function migrate_legacy_cache() {
        $legacy = WP_CONTENT_DIR . '/cache/ezcache/';
        $trash = $this->root_cache_dir . self::TRASH_DIR;
        foreach (array_merge(glob($legacy . '*', GLOB_ONLYDIR) ?: [], is_dir($legacy.'.trash') ? [$legacy.'.trash'] : []) as $path) {
            if (basename($path) === 'v2' || is_link($path)) { continue; }
            self::make_dir($trash);
            if (@rename($path, $trash . '/legacy-' . bin2hex(random_bytes(16)))) { $this->schedule_purge(); }
        }
        $this->purge_trash(2);
    }

	public function discard_cache_contents( $purge_budget = 5 ) {
		$root = $this->root_cache_dir;
		if ( ! is_dir( $root ) ) {
			return;
		}

		$trash = $root . self::TRASH_DIR;
		foreach ( (array) glob( $root . '*', GLOB_ONLYDIR ) as $dir ) { // dot-directories such as .trash are not matched
			if ( ! is_dir( $trash ) ) {
				self::make_dir( $trash );
			}
			$target = $trash . '/' . basename( $dir ) . '.' . time() . '.' . mt_rand( 1000, 9999 );
			if ( ! is_dir( $trash ) || ! @rename( $dir, $target ) ) {
				// Cannot move it aside: delete in place, still within a budget.
				Logger::log( 'ezCache could not move ' . $dir . ' aside, deleting in place' );
				$this->rmdir_recursive( $dir, microtime( true ) + max( 1, (float) $purge_budget ) );
			}
		}

		if ( $purge_budget > 0 ) {
			$this->purge_trash( $purge_budget );
		} elseif ( is_dir( $trash ) ) {
			$this->schedule_purge();
		}
	}

	/**
	 * Delete what discard_cache_contents() moved into .trash/, for at most
	 * $budget seconds. Hooked to the ezcache_purge_trash cron event and also
	 * run by the expiry cron, so leftovers go even if a single event is lost.
	 * Reschedules itself when the budget runs out with work left. Entries
	 * that cannot be deleted are skipped (and retried by later runs) so they
	 * never hold up the rest of the trash.
	 *
	 * @param float $budget Seconds.
	 *
	 * @return bool true when the trash is empty.
	 */
	public function purge_trash( $budget = 20 ) {
		$trash = $this->root_cache_dir . self::TRASH_DIR;
		if ( ! is_dir( $trash ) ) {
			return true;
		}

		$deadline = microtime( true ) + max( 0.1, (float) $budget );
		$stuck    = [];
		foreach ( (array) glob( $trash . '/*' ) as $entry ) {
			if ( $this->rmdir_recursive( $entry, $deadline ) ) {
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				// Out of time: the next run continues from here.
				$this->schedule_purge();

				return false;
			}
			// The whole entry was walked but something in it could not be
			// deleted (for example files owned by another user, written by
			// WP-CLI running as root). Move on so the rest of the trash still
			// goes: stopping here blocked every later entry, and rescheduling
			// for it repeated every 30 seconds without progress.
			$stuck[] = $entry;
		}

		if ( $stuck ) {
			Logger::log( 'ezCache could not fully delete ' . count( $stuck ) . ' item(s) in ' . $trash . ', first: ' . $stuck[0] );

			return false;
		}

		@rmdir( $trash );

		return ! is_dir( $trash );
	}

	/**
	 * Make sure a purge run is scheduled (WP-Cron single event).
	 */
	public function schedule_purge() {
        if (Settings::is_disabled()) { return; }
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		// Cron::ensure also gets an event that is stuck in the past run and, when WordPress
		// does not spawn WP-Cron itself (DISABLE_WP_CRON), triggers one run.
		Cron::ensure( self::PURGE_HOOK, 30 );
	}

	/**
	 * Preload the homepage and immediately create cache for it
	 */
	public function preload_homepage() {
        if (Settings::is_disabled()) { return; }
        if ( (defined('EZCACHE_ACTIVATING') || defined('EZCACHE_DEACTIVATING') || get_transient('ezcache_deactivating')) ) { return; }
		$desktop_ua = apply_filters(
			'ezcache_desktop_useragent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36 (ezCache Preload)'
		);
		$mobile_ua  = apply_filters(
			'ezcache_mobile_useragent',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 12_0 like Mac OS X) AppleWebKit/ 604.1.21 (KHTML, like Gecko) Version/ 12.0 Mobile/17A6278a Safari/602.1.26 (ezCache Preload)'
		);

		wp_safe_remote_get( site_url(), [
			'user-agent' => $desktop_ua,
			'timeout'    => 0.1,
		] );

		wp_safe_remote_get( site_url(), [
			'user-agent' => $mobile_ua,
			'timeout'    => 0.1,
		] );
	}

    function delete_missing_webp_images( $delete_all = false ) {
        if ($delete_all) { return (new Rest\WebpController())->destroy(); }
        // Ordinary page-cache invalidation must not scan the media library.
        return true;
    }

    function delete_all_webp_images() { return $this->delete_missing_webp_images(true); }

	/**
	 * Clear all caches
	 *
	 * @param bool $clear_webp Should deleting cache clear the WebP images
	 */
	/**
	 * Create a cache folder so that it belongs to the site's owner also when a
	 * command run as root (WP-CLI) creates it: in a root-owned cache folder the
	 * web server could not store a single page.
	 */
	private static function make_dir( $dir ) {
		if ( method_exists( '\Upress\EzCache\Utilities\AtomicFile', 'make_dir' ) ) {
			return Utilities\AtomicFile::make_dir( $dir );
		}

		return @wp_mkdir_p( $dir );
	}

	public function clear_cache( $clear_webp = false ) {
		$this->discard_cache_contents();
		self::make_dir( $this->root_cache_dir );

		if ( $clear_webp ) {
			$this->delete_all_webp_images();
		} else {
			$this->delete_missing_webp_images();
		}

		$this->purge_varnish_cache();

		// The pages kept in Redis go with the page cache. The object cache (options,
		// transients, locks) does not: emptying it on every purge made each purge a
		// full cold start for the database as well.
		if ( ! empty($this->settings->enable_redis_fullpage) && method_exists( '\Upress\EzCache\RedisObjectCache', 'flush_pages' ) ) {
			\Upress\EzCache\RedisObjectCache::flush_pages();
		}

		$this->preload_homepage();

		/**
		 * Fires after the entire cache has been cleared.
		 * Used by the Preload module to start a fresh preload run.
		 */
		do_action( 'ezcache_after_clear_cache' );
	}

	/**
	 * Clear cache for a single post
	 *
	 * @param int $post_id
	 */
	public function clear_cache_single( $post_id ) {
		$real_cache_dir = $this->get_real_cache_dir( $post_id );

		$this->rmdir_recursive( $real_cache_dir );

		$this->purge_varnish_cache();

		// Remove the matching Redis full-page key so the next request rebuilds.
		if ( ! empty( $this->settings->enable_redis_fullpage ) && class_exists( '\\Upress\\EzCache\\RedisObjectCache' ) ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				\Upress\EzCache\RedisObjectCache::delete_page( $url );
			}
		}

		/**
		 * Fires after a single post's cache has been cleared.
		 *
		 * @param int $post_id
		 */
		do_action( 'ezcache_after_clear_cache_single', $post_id );
	}

	public function clear_cache_url( $url ) {
		$real_cache_dir = $this->get_real_cache_dir( 0, $url );

		$this->rmdir_recursive( $real_cache_dir );

		$this->purge_varnish_cache();

		if ( ! empty( $this->settings->enable_redis_fullpage ) && class_exists( '\\Upress\\EzCache\\RedisObjectCache' ) ) {
			\Upress\EzCache\RedisObjectCache::delete_page( $url );
		}

		/**
		 * Fires after a URL's cache has been cleared.
		 *
		 * @param string $url
		 */
		do_action( 'ezcache_after_clear_cache_url', $url );
	}

	public function purge_varnish_cache() {
		// Whether Varnish PURGE is enabled (on by default). Can be turned off from
		// the settings screen on servers where Varnish is not in the request path,
		// to avoid generating needless 403 noise in the logs.
		$enabled = ! isset( $this->settings->enable_varnish_purge ) || ! empty( $this->settings->enable_varnish_purge );

		/**
		 * Filters whether ezCache should send a PURGE request to Varnish.
		 *
		 * Return false to skip the PURGE entirely.
		 *
		 * @param bool $enabled Whether the PURGE request should be sent.
		 */
		if ( ! apply_filters( 'ezcache_should_purge_varnish', $enabled ) ) {
			return;
		}

		$desktop_ua = apply_filters(
			'ezcache_desktop_useragent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36 (ezCache Preload)'
		);

		$parseUrl = parse_url( home_url() );

		$schema = 'http://';
		if ( isset( $parseUrl['scheme'] ) ) {
			$schema = $parseUrl['scheme'] . '://';
		}

		$host = $parseUrl['host'];

		// Send the PURGE to the local Varnish instance over loopback rather than to
		// the public host. The public hostname is preserved in the Host header so
		// Varnish still matches the right cache objects, while the request originates
		// from 127.0.0.1 — which is what Varnish/nginx PURGE ACLs typically allow,
		// avoiding the public 403 errors seen when the request leaves and re-enters
		// the server via its public IP.
		$purge_host = apply_filters( 'ezcache_varnish_purge_host', '127.0.0.1' );

		$request_args = [
			'method'    => 'PURGE',
			'headers'   => [
				'Host'       => $host,
				'User-Agent' => $desktop_ua,
			],
			'sslverify' => !in_array($purge_host, ['127.0.0.1','[::1]','localhost'], true),
		];
		$response = wp_remote_request( $schema . $purge_host . '/.*', $request_args );
		if ( is_wp_error( $response ) || $response['response']['code'] != '200' ) {
			if ( $schema === 'https://' ) {
				$schema = 'http://';
			} else {
				$schema = 'https://';
			}
			wp_remote_request( $schema . $purge_host . '/.*', $request_args );
		}
	}

	/**
	 * Delete expired cache
	 */
	public function clear_expired_cache() {
		$settings = $this->settings;

		// First finish any background deletion left by clear_cache(), then
		// walk the live cache only (.trash is skipped).
		$this->purge_trash( 2 );
        if ((int)$settings->cache_lifetime <= 0) { return; }
        $deadline = microtime(true) + 5;
		$trash = $this->root_cache_dir . self::TRASH_DIR;

		try {
			$dir      = new RecursiveDirectoryIterator( $this->root_cache_dir, \FilesystemIterator::SKIP_DOTS );
			$live     = new \RecursiveCallbackFilterIterator( $dir, function ( $current ) use ( $trash ) {
				return $current->getPathname() !== $trash;
			} );
			$iterator = new RecursiveIteratorIterator( $live );
			$files    = new RegexIterator( $iterator, '/^.+\.(?:gz|html|js|css)$/i', RegexIterator::GET_MATCH );
		} catch ( UnexpectedValueException $ex ) {
			if ( strpos( $ex->getMessage(), 'No such file or directory' ) ) {
				$files = [];
			} else {
				throw $ex;
			}
		}

		foreach ( $files as $file ) {
            if (microtime(true) > $deadline) { break; }
			if ( is_array( $file ) ) {
				$file = array_shift( $file );
			}

			if (is_link($file)) { continue; }
            $stats = @stat( $file );
            if (!$stats) { continue; }
			if ( $stats['mtime'] > ( time() - $settings->cache_lifetime ) ) {
				// skip not expired files
				continue;
			}

			@unlink( $file );
		}
	}

	/**
	 * Get caching statistics and file sizes
	 * @return array
	 */
	public function get_cache_stats() {
		$settings  = $this->settings;
        $deadline = microtime(true)+2; $partial=false;
		$cache_dir = $this->get_default_cache_path();

		try {
			$dir      = new RecursiveDirectoryIterator( $cache_dir );
			$iterator = new RecursiveIteratorIterator( $dir );
			$files    = new RegexIterator( $iterator, '/^.+\.(?:gz|html|css|js)$/i', RegexIterator::GET_MATCH );
		} catch ( UnexpectedValueException $ex ) {
			if ( strpos( $ex->getMessage(), 'No such file or directory' ) ) {
				$files = [];
			} else {
				throw $ex;
			}
		}


		$mobile_count          = 0;
		$mobile_size           = 0;
		$mobile_expired_count  = 0;
		$mobile_expired_size   = 0;
		$desktop_count         = 0;
		$desktop_size          = 0;
		$desktop_expired_count = 0;
		$desktop_expired_size  = 0;
		$js_count              = 0;
		$js_size               = 0;
		$js_expired_count      = 0;
		$js_expired_size       = 0;
		$css_count             = 0;
		$css_size              = 0;
		$css_expired_count     = 0;
		$css_expired_size      = 0;

		foreach ( $files as $file ) {
            if (microtime(true)>$deadline) { $partial=true; break; }
			if ( is_array( $file ) ) {
				$file = array_shift( $file );
			}

			if (is_link($file)) { continue; }
            $stats = @stat($file);
            if (!$stats) { continue; }
			$expired = $settings->cache_lifetime > 0 && $stats['mtime'] <= ( time() - $settings->cache_lifetime );



			if ( preg_match( '/^.+?-mobile(?:-webp)?\.html(\.gz)?$/i', $file ) ) {
				if ( ! $expired ) {
					$mobile_count ++;
					$mobile_size += $stats['size'];
				} else {
					$mobile_expired_count ++;
					$mobile_expired_size += $stats['size'];
				}
			} elseif ( preg_match( '/\.css$/i', $file ) ) {
				if ( $expired ) {
					$css_expired_count ++;
					$css_expired_size += $stats['size'];
				} else {
					$css_count ++;
					$css_size += $stats['size'];
				}
			} elseif ( preg_match( '/\.js$/i', $file ) ) {
				if ( $expired ) {
					$js_expired_count ++;
					$js_expired_size += $stats['size'];
				} else {
					$js_count ++;
					$js_size += $stats['size'];
				}
			} else {
				if ( ! $expired ) {
					$desktop_count ++;
					$desktop_size += $stats['size'];
				} else {
					$desktop_expired_count ++;
					$desktop_expired_size += $stats['size'];
				}
			}
		}

		// Count stored variants; only gzip HTML is written by this version.



		global $wpdb;
		$webp_images               = 0;
		$webp_images_size          = 0;
		$webp_images_original_size = 0;

		$results = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(`original_size`) AS total_original_size, SUM(`webp_size`) AS total_webp_size FROM `{$wpdb->prefix}ezcache_webp_images` WHERE `status` = 'completed'" );
		if ( $results ) {
			$webp_images               = intval( $results->total );
			$webp_images_size          = intval( $results->total_webp_size );
			$webp_images_original_size = intval( $results->total_original_size );
		}

		return compact( 'partial', 'webp_images', 'webp_images_original_size', 'webp_images_size', 'mobile_count', 'desktop_count', 'mobile_expired_count', 'desktop_expired_count', 'mobile_size', 'desktop_size', 'mobile_expired_size', 'desktop_expired_size', 'css_size', 'css_count', 'js_count', 'js_size', 'css_expired_count', 'css_expired_size', 'js_expired_count', 'js_expired_size' );
	}

	/**
	 * Get the status of the cache
	 *
	 * @return array
	 */
	public function get_status() {
		global $wpdb;

		$wp_cache_enabled       = defined( 'WP_CACHE' ) && WP_CACHE;
		$adv_cache_exists       = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		$correct_advanced_cache = $adv_cache_exists && strpos( file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' ), 'ezCache Advanced Cache' ) !== false;
		$webp_table_exists      = ! is_null( $wpdb->get_row( "SHOW TABLES LIKE '{$wpdb->prefix}ezcache_webp_images'" ) );

		return [
			'cache_enabled'        => $wp_cache_enabled,
			'adv_cache_exists'     => $adv_cache_exists,
			'correct_cache_exists' => $correct_advanced_cache,
			'webp_table_exists'    => $webp_table_exists,
		];
	}

	/**
	 * Get a path by the URL
	 *
	 * @param string $url
	 *
	 * @return bool|string
	 */
	public static function url_to_path( $url ) {
        if ( ! is_string($url) || strpos($url, "\0") !== false ) { return false; }
        $parts = parse_url($url);
        if ( false === $parts || isset($parts['user']) || isset($parts['pass']) ) { return false; }
        if ( isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http','https'], true) ) { return false; }
        $base = parse_url(content_url());
        if ( isset($parts['host']) && ( strtolower($parts['host']) !== strtolower($base['host'] ?? '') || ($parts['port'] ?? null) !== ($base['port'] ?? null) ) ) { return false; }
        $path = rawurldecode($parts['path'] ?? '');
        if ( preg_match('#(?:^|/)\.\.(?:/|$)#', $path) || strpos($path, '\\') !== false ) { return false; }
        $root = realpath(dirname(WP_CONTENT_DIR));
        if ( ! $root ) { return false; }
        $base_path = rtrim(dirname($base['path'] ?? '/wp-content'), '/.');
        if ( $base_path && strpos($path, $base_path . '/') !== 0 ) { return false; }
        if ( $base_path ) { $path = substr($path, strlen($base_path)); }
        $file = realpath($root . '/' . ltrim($path, '/'));
        if ( ! $file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || ! is_file($file) || ! is_readable($file) ) { return false; }
        if ( ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css','js','jpg','jpeg','png','gif','webp','avif','svg','woff','woff2','ttf','eot','otf'], true) ) { return false; }
        return $file;
    }

	/**
	 * Returns canonicalized absolute pathname.
	 * The resulting path will have no symbolic link, '/./' or '/../' components.
	 * Same as the defautl PHP realpath() function but works even when the files does not exist.
	 *
	 * @param string $file The path being checked.
	 *
	 * @return string
	 * @see \realpath()
	 *
	 */
	public static function realpath( $file ) {
		$path = [];

		foreach ( explode( '/', $file ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' !== $part ) {
				array_push( $path, $part );
			} elseif ( count( $path ) > 0 ) {
				array_pop( $path );
			}
		}

		$prefix = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? '' : '/';

		return $prefix . join( '/', $path );
	}

	/**
	 * Check if Development Mode is active (file-based, works before WP loads)
	 */
	public static function is_dev_mode_active() {
		$flag_file = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(__DIR__)) . '/cache/ezcache/.dev-mode';
		if ( ! file_exists( $flag_file ) ) {
			return false;
		}
		$expires = (int) trim( @file_get_contents( $flag_file ) );
		if ( $expires === 0 ) {
			return true;
		}
		if ( time() >= $expires ) {
			@unlink( $flag_file );
			return false;
		}
		return true;
	}

	public static function enable_dev_mode( $seconds = 3600 ) {
		$dir = WP_CONTENT_DIR . '/cache/ezcache';
		if ( ! is_dir( $dir ) ) {
			self::make_dir( $dir );
		}
		$expires = ( $seconds === 0 ) ? 0 : time() + $seconds;
		return Utilities\AtomicFile::write($dir . '/.dev-mode', (string)$expires);
	}

	public static function disable_dev_mode() {
		$file=WP_CONTENT_DIR . '/cache/ezcache/.dev-mode';
        return !is_file($file) || @unlink($file);
	}

	public static function get_dev_mode_status() {
		$flag = WP_CONTENT_DIR . '/cache/ezcache/.dev-mode';
		if ( ! file_exists( $flag ) ) {
			return [ 'active' => false ];
		}
		$expires = (int) trim( @file_get_contents( $flag ) );
		if ( $expires > 0 && time() >= $expires ) {
			@unlink( $flag );
			return [ 'active' => false ];
		}
		return [
			'active'    => true,
			'expires'   => $expires === 0 ? 'permanent' : $expires,
			'remaining' => $expires === 0 ? null : $expires - time(),
		];
	}
}
