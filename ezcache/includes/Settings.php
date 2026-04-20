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
        return WP_CONTENT_DIR . '/ezcache-config.json';
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

		    'no_cache_query_params'         => false,
		    'cache_clear_on_post_edit'      => true,
		    'cache_clear_home_on_post_edit' => true,
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
	public static function get_settings() {

	    if ( self::$settings ) {
	        return self::$settings;
        }

        $file = self::settings_file_path();
		$default_settings = self::get_default_settings();

        if ( ! file_exists( $file ) || ! $json = json_decode( file_get_contents( $file ) ) ) {
	        self::$settings = $default_settings;
            file_put_contents( $file, json_encode( self::$settings ) );

	        return self::$settings;
        }

		self::$settings = (object) array_merge( (array) $default_settings, (array) $json );
        return self::$settings;
    }

	/**
     * Merge new settings into the settings file
	 * @param array $new_settings
	 * @return object
	 */
	public static function set_settings( $new_settings=[] ) {
        $settings = self::get_settings();

        foreach( $new_settings as $key => $value ) {
	        $settings->{$key} = $value;
        }

        self::maybe_update_cronjobs( $settings );

        file_put_contents( self::settings_file_path(), json_encode( $settings ) );
		self::$settings = $settings;

		// Refresh schedules for the new modules.
		if ( class_exists( '\\Upress\\EzCache\\Preload' ) ) {
			Preload::instance()->maybe_schedule_cron();
		}
		if ( class_exists( '\\Upress\\EzCache\\DatabaseOptimizer' ) ) {
			( new DatabaseOptimizer() )->schedule();
		}

        return $settings;
    }

	/**
	 * @param $settings
	 */
	public static function maybe_update_cronjobs( $settings ) {
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
