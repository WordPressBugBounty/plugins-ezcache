<?php
namespace Upress\EzCache;

class Settings {
    /** @var object */
    protected static $settings;

	/**
     * Get the path to the config file
	 * @return string
	 */
	public static function settings_file_path() {
        return WP_CONTENT_DIR . '/ezcache-config.php';
    }

    public static function is_disabled() {
        $file=WP_CONTENT_DIR.'/ezcache-disabled.php';
        clearstatcache(true,$file);
        return is_file($file);
    }

    public static function get_default_settings() {
	    return (object) [
		    'no_cache_known_users'     => true,
		    'no_cache_comment_authors' => true,
		    'separate_mobile_cache'    => true,
		    'cache_lifetime'           => 604800,
		    'cache_expiry_interval'    => 10800,

		    'disable_wp_emoji'        => false,
		    'optimize_google_fonts'   => false,
		    'minify_html'             => false,
		    'minify_html_comments'    => true,
		    'minify_inline_js'        => false,
		    'minify_inline_css'       => false,
		    'minify_js'               => false,
		    'combine_head_js'         => false,
		    'combine_body_js'         => false,
		    'combine_head_inline_js'  => true,
		    'combine_body_inline_js'  => true,
		    'minify_css'              => false,
		    'combine_css'             => false,
		    'combine_css_footer'      => false,
		    'critical_css'            => '',
		    'enable_webp_support'     => false,
            'webp_external_api'       => false,

		    'no_cache_query_params'         => false,
		    'ignore_query_params'           => true,
		    'ignored_query_params_list'     => "gclid\ngbraid\nwbraid\ngad_source\ngad_campaignid\nsrsltid\nfbclid\nmsclkid\ndclid\nyclid\nttclid\ntwclid\nigshid\nutm_*\nmc_cid\nmc_eid\n_ga\n_gl\n_hsenc\n_hsmi\nmkt_tok",

		    // Cache bloat guards (2.6.6+): filtered archives and very deep/long
		    // URLs are generated in endless combinations and are not cached.
		    'no_cache_filtered_pages'       => true,
		    'max_cache_url_depth'           => 7,
		    'max_cache_url_length'          => 250,
		    // Off (default): login, cart, password and comment-author cookies and an
		    // explicit no-cache header make a page private. On: so do an open PHP
		    // session and any other cookie the response sets. See Cache::strict_privacy().
		    'strict_response_privacy'       => false,
		    'cache_clear_on_post_edit'      => true,
		    'cache_clear_home_on_post_edit' => true,
		    'enable_varnish_purge'          => true,
		    'bypass_cache'                  => [
			    'single'    => false,
			    'pages'     => false,
			    'frontpage' => false,
			    'home'      => false,
			    'archives'  => false,
			    'tag'       => false,
			    'category'  => false,
			    'feed'      => false,
			    'search'    => false,
			    'author'    => false,
		    ],
		    'rejected_uri'          => "/cart\n/checkout",
		    'rejected_user_agent'   => '',
		    'rejected_cookies'      => '',
		    'excluded_minify_files' => '',

		    // Preload (1.7.0+)
		    'enable_preload'               => false,
		    'preload_on_cache_clear'       => true,
		    'preload_sitemap_url'          => '',
		    'preload_batch_size'           => 5,
		    'preload_crawl_homepage_links' => true,

		    // Front-end optimizations (1.7.0+)
		    'lazy_load_images'     => false,
		    'lazy_load_iframes'    => false,
		    'remove_query_strings' => false,
		    'defer_js'             => false,
		    'defer_js_exclusions'  => '',
		    'dns_prefetch'         => '',
		    'preconnect'           => '',

		    // Heartbeat (1.7.0+)
		    'heartbeat_control' => false,
		    'heartbeat_mode'    => 'reduce',

		    // CDN (1.7.0+)
		    'cdn_enabled' => false,
		    'cdn_url'     => '',

		    // Database cleanup (1.7.0+)
		    'db_cleanup_revisions'          => false,
		    'db_cleanup_auto_drafts'        => false,
		    'db_cleanup_trashed_posts'      => false,
		    'db_cleanup_spam_comments'      => false,
		    'db_cleanup_trashed_comments'   => false,
		    'db_cleanup_expired_transients' => false,
		    'db_cleanup_orphan_postmeta'    => false,
		    'db_optimize_tables'            => false,
		    'db_cleanup_schedule'           => 'never',

		    // Redis Object Cache (v2.2.0+)
		    'enable_redis_object_cache' => false,
		    'enable_redis_fullpage'     => false,

		    // Critical CSS (v2.2.0+)
		    'enable_critical_css' => false,

		    // Speculative Loading (v2.2.0+)
		    'enable_speculative_loading' => false,
		    'speculative_mode'           => 'moderate',

		    // 103 Early Hints (v2.2.0+)
		    'enable_early_hints' => false,
	    ];
    }

	/**
     * Get the settings stored in the config file
	 * @return object
	 */
	const FILE_GUARD = "<?php exit; ?>\n";

    public static function get_settings() {
        if ( self::$settings ) { return self::$settings; }
        $file = self::settings_file_path();
        $legacy = WP_CONTENT_DIR . '/ezcache-config.json';
        $source = is_file( $file ) ? $file : $legacy;
        $raw = is_file( $source ) ? @file_get_contents( $source ) : '';
        if ( 0 === strpos( (string) $raw, self::FILE_GUARD ) ) { $raw = substr( $raw, strlen( self::FILE_GUARD ) ); }
        $values = json_decode( (string) $raw, true );
        $values = is_array( $values ) ? self::normalize( $values ) : [];
        self::$settings = (object) array_merge( (array) self::get_default_settings(), $values );
        self::$settings->bypass_cache = (object) self::$settings->bypass_cache;
        // Migration for early-bootstrap readers as well as normal requests. The old-format
        // file is copied here, not removed: during the minute after an update PHP can still
        // run the previous version (and a command-line process runs the new one at once).
        // The previous version reads that file, falls back to default settings when it is
        // missing, and writes those defaults out again. Updater::upgrade() removes the
        // file once no old code is left.
        if ( ! is_file( $file ) ) { self::persist( self::$settings ); }
        return self::$settings;
    }

    /** One schema for normal saves, imports, reset and internal updates. */
    public static function normalize( array $values ) {
        $defaults = (array) self::get_default_settings();
        $ranges = [ 'cache_lifetime' => [0,31536000], 'cache_expiry_interval' => [60,86400],
            'max_cache_url_depth' => [0,50], 'max_cache_url_length' => [0,4000], 'preload_batch_size' => [1,50] ];
        $result = [];
        foreach ( $values as $key => $value ) {
            if ( ! array_key_exists( $key, $defaults ) ) { continue; }
            if ( is_bool( $defaults[$key] ) ) {
                $result[$key] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            } elseif ( isset( $ranges[$key] ) ) {
                $result[$key] = max( $ranges[$key][0], min( $ranges[$key][1], is_scalar( $value ) ? (int) $value : $defaults[$key] ) );
            } elseif ( 'bypass_cache' === $key ) {
                $result[$key] = [];
                foreach ( $defaults[$key] as $part => $default ) {
                    $result[$key][$part] = isset( ((array) $value)[$part] ) && filter_var( ((array) $value)[$part], FILTER_VALIDATE_BOOLEAN );
                }
            } elseif ( is_string( $defaults[$key] ) && is_scalar( $value ) ) {
                $result[$key] = substr( str_replace( "\0", '', strip_tags( (string) $value ) ), 0, 100000 );
            }
        }
        foreach ( [ 'db_cleanup_schedule' => ['never','daily','weekly','monthly'], 'heartbeat_mode' => ['reduce','disable'], 'speculative_mode' => ['conservative','moderate','eager'] ] as $key => $allowed ) {
            if ( isset( $result[$key] ) && ! in_array( $result[$key], $allowed, true ) ) { $result[$key] = $defaults[$key]; }
        }
        // Private visitors always bypass full-page caching. Keep legacy keys truthful.
        foreach (['no_cache_known_users','no_cache_comment_authors'] as $key) { if (array_key_exists($key,$values)) { $result[$key]=true; } }
        return $result;
    }

    /**
     * Settings that change neither what a cached page contains nor which requests
     * are cached. Saving only these leaves the page cache alone; a shorter
     * lifetime needs no purge either, because the age is checked on every hit.
     */
    const NO_PURGE_KEYS = [
        'cache_lifetime', 'cache_expiry_interval', 'cache_clear_on_post_edit', 'cache_clear_home_on_post_edit', 'enable_varnish_purge',
        'enable_preload', 'preload_on_cache_clear', 'preload_sitemap_url', 'preload_batch_size', 'preload_crawl_homepage_links',
        'webp_external_api', 'enable_redis_object_cache',
        'db_cleanup_revisions', 'db_cleanup_auto_drafts', 'db_cleanup_trashed_posts', 'db_cleanup_spam_comments', 'db_cleanup_trashed_comments',
        'db_cleanup_expired_transients', 'db_cleanup_orphan_postmeta', 'db_optimize_tables', 'db_cleanup_schedule',
    ];

    /** Names of the settings whose value differs between two settings objects. */
    public static function changed_keys( $old, $new ) {
        $old = (array) $old; $new = (array) $new; $changed = [];
        foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
            if ( json_encode( $old[ $key ] ?? null ) !== json_encode( $new[ $key ] ?? null ) ) { $changed[] = $key; }
        }
        return $changed;
    }

    private static function persist( $settings ) {
        $json = json_encode( $settings );
        return false !== $json && Utilities\AtomicFile::write( self::settings_file_path(), self::FILE_GUARD . $json );
    }

    public static function set_settings( $new_settings = [] ) {
        if ( ! is_array( $new_settings ) ) { return new \WP_Error( 'invalid_settings', 'Settings must be an object.', ['status'=>400] ); }
        $lock = Utilities\Lock::acquire( 'settings' );
        if ( ! $lock ) { return new \WP_Error( 'settings_busy', 'Another settings update is in progress.', ['status'=>409] ); }
        try {
            // Re-read after acquiring the lock; never mutate the shared old object on failure.
            self::$settings = null;
            $old = self::get_settings();
            $settings = (object) array_merge( (array) $old, self::normalize( $new_settings ) );
            $settings->bypass_cache = (object) $settings->bypass_cache;
            if ( ! empty( $settings->enable_redis_object_cache ) && file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && ! RedisObjectCache::is_our_dropin() ) {
                return new \WP_Error( 'foreign_object_cache', 'Another object-cache.php is installed.', ['status'=>409] );
            }
            // Switching the Redis object cache on: it must be able to store something.
            // Without phpredis, or with Redis down, WordPress would be told that a
            // persistent cache exists and would stop keeping transients anywhere.
            if ( ! empty( $settings->enable_redis_object_cache ) && empty( $old->enable_redis_object_cache )
                && method_exists( '\Upress\EzCache\RedisObjectCache', 'can_persist' ) && ! RedisObjectCache::can_persist() ) {
                return new \WP_Error( 'redis_unavailable', 'The Redis object cache needs the phpredis PHP extension and a Redis server that answers. Nothing was changed.', ['status'=>409] );
            }
            if ( ! self::persist( $settings ) ) {
                return new \WP_Error( 'ezcache_settings_write_failed', 'Failed to save settings. Check file permissions.', ['status'=>500] );
            }
            self::$settings = $settings;
            $dropin_ok = ! empty( $settings->enable_redis_object_cache ) ? RedisObjectCache::maybe_deploy_dropin() : RedisObjectCache::remove_dropin();
            if ( ! $dropin_ok ) {
                self::persist( $old ); self::$settings = $old;
                return new \WP_Error( 'dropin_write_failed', 'Could not update object-cache.php. Settings were restored.', ['status'=>500] );
            }
        } finally { Utilities\Lock::release( $lock ); }
        self::maybe_update_cronjobs( $settings );
        Preload::instance()->maybe_schedule_cron();
        ( new DatabaseOptimizer() )->schedule();
        Cache::instance()->refresh_settings();
        $changed = self::changed_keys( $old, $settings );
        // A WebP encoder was just made available: images that failed without one get another chance.
        if ( ! empty( $settings->enable_webp_support ) && array_intersect( $changed, [ 'enable_webp_support', 'webp_external_api' ] )
            && method_exists( '\Upress\EzCache\Rest\WebpController', 'requeue_failed' ) ) {
            Rest\WebpController::requeue_failed();
        }
        // Old entries are re-evaluated under the new policy only when the policy changed.
        // Every save used to empty the whole cache (with Varnish and a preload run),
        // also when nothing, or only a schedule, had been changed.
        if ( array_diff( $changed, self::NO_PURGE_KEYS ) ) {
            Cache::instance()->clear_cache();
        } elseif ( in_array( 'enable_preload', $changed, true ) && ! empty( $settings->enable_preload ) ) {
            // Switching preload on used to start a run as a side effect of the purge.
            Preload::instance()->maybe_start_preload();
        }
        return $settings;
    }

	/**
	 * @param $settings
	 */
	public static function maybe_update_cronjobs( $settings ) {
        if (self::is_disabled()) { wp_clear_scheduled_hook('ezcache_clear_expired_cache'); return; }
		if ( wp_next_scheduled( 'ezcache_clear_expired_cache' ) ) {
			wp_clear_scheduled_hook( 'ezcache_clear_expired_cache' );
		}

		if ( $settings->cache_lifetime > 0 ) {
			$schedules = wp_get_schedules();
			foreach( $schedules as $key=>$s ) {
				if( $s['interval'] == $settings->cache_expiry_interval ) {
					if ( ! wp_next_scheduled( 'ezcache_clear_expired_cache' ) ) {
						wp_schedule_event( time(), $key, 'ezcache_clear_expired_cache' );
					}
					break;
				}
			}
		}
	}
}
