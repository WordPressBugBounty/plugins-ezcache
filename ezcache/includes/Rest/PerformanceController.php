<?php
namespace Upress\EzCache\Rest;

use Upress\EzCache\Settings;
use Upress\EzCache\Preload;
use Upress\EzCache\DatabaseOptimizer;
use WP_REST_Request;
use Upress\EzCache\PremiumFeatures;
use Upress\EzCache\CriticalCSS;

class PerformanceController {

	/**
	 * Performance settings keys
	 */
	private static $performance_keys = [
		'enable_preload',
		'preload_on_cache_clear',
		'preload_crawl_homepage_links',
		'preload_sitemap_url',
		'preload_batch_size',
		'lazy_load_images',
		'lazy_load_iframes',
		'defer_js',
		'defer_js_exclusions',
		'remove_query_strings',
		'dns_prefetch',
		'preconnect',
		'heartbeat_control',
		'heartbeat_mode',
		'cdn_enabled',
		'cdn_url',
		'db_cleanup_revisions',
		'db_cleanup_auto_drafts',
		'db_cleanup_trashed_posts',
		'db_cleanup_spam_comments',
		'db_cleanup_trashed_comments',
		'db_cleanup_expired_transients',
		'db_cleanup_orphan_postmeta',
		'db_optimize_tables',
		'db_cleanup_schedule',
		// v2.2.0
		'enable_redis_object_cache',
		'enable_redis_fullpage',
		'enable_critical_css',
		'enable_speculative_loading',
		'speculative_mode',
		'enable_early_hints',
	];

	/**
	 * Get performance settings and preload status
	 */
	function show() {
		$all_settings = Settings::get_settings();
		$settings = [];
		
		$bool_keys = [
			'enable_preload', 'preload_on_cache_clear', 'preload_crawl_homepage_links',
			'lazy_load_images', 'lazy_load_iframes', 'defer_js', 'remove_query_strings',
			// NOTE: dns_prefetch and preconnect are multi-line TEXT fields (domain
			// lists), not booleans — casting them here turned the textarea into
			// "true"/"false". They are intentionally excluded.
			'heartbeat_control', 'cdn_enabled',
			'db_cleanup_revisions', 'db_cleanup_auto_drafts', 'db_cleanup_trashed_posts',
			'db_cleanup_spam_comments', 'db_cleanup_trashed_comments', 'db_cleanup_expired_transients',
			'db_cleanup_orphan_postmeta', 'db_optimize_tables',
			'enable_redis_object_cache', 'enable_redis_fullpage', 'enable_critical_css',
			'enable_speculative_loading', 'enable_early_hints',
		];

		foreach ( self::$performance_keys as $key ) {
			$val = isset( $all_settings->{$key} ) ? $all_settings->{$key} : null;
			// Cast to boolean for checkbox fields
			if ( in_array( $key, $bool_keys, true ) && $val !== null ) {
				$val = (bool) $val;
			}
			$settings[ $key ] = $val;
		}

		$preload_status = [
			'status'    => 'idle',
			'processed' => 0,
			'total'     => 0,
			'remaining' => 0,
		];

		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
			$preload_status = Preload::instance()->get_status();
		}

		return Response::success( array_merge( $settings, [
			'preload_status' => $preload_status,
		] ) );
	}

	/**
	 * Update performance settings
	 * @param WP_REST_Request $request
	 */
	function update( $request ) {
		$input = (array) $request->get_json_params();
		$current_settings = [];
		
		foreach ( self::$performance_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current_settings[ $key ] = $input[ $key ];
			}
		}


		$saved = Settings::set_settings( $current_settings );
        if ( is_wp_error($saved) ) { return $saved; }

		return Response::success();
	}

	/**
	 * Run preload
	 */
    function generateCriticalCss( $request ) {
        $url = $request->get_param('url') ?: home_url('/');
        if ( ! is_string($url) || ! wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST) ) {
            return new \WP_Error('invalid_url', 'Choose a public page on this site.', ['status'=>400]);
        }
        $result = \Upress\EzCache\CriticalCSS::instance()->generate($url);
        if ( empty($result['success']) ) { return new \WP_Error('critical_css_failed', $result['message'], ['status'=>502]); }
        return Response::success($result);
    }

	function runPreload() {
		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
	        $result = Preload::instance()->start();
        if (is_wp_error($result)) { return $result; }
		}

		return Response::success( [ 'message' => 'Preload started' ] );
	}

	/**
	 * Resume a stalled or interrupted preload from where it stopped
	 */
	function resumePreload() {
		$status = [];
		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
			$status = Preload::instance()->resume();
            if (is_wp_error($status)) { return $status; }
		}

		return Response::success( [ 'message' => 'Preload resumed', 'preload_status' => $status ] );
	}

	/**
	 * Stop preload
	 */
	function stopPreload() {
		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
			$status = Preload::instance()->stop();
            if (is_wp_error($status)) { return $status; }
		}

		return Response::success( [ 'message' => 'Preload stopped' ] );
	}

	/**
	 * Run database cleanup
	 */
	function runDbCleanup( $request ) {
		// The Vue UI posts an associative map ( { db_cleanup_revisions: true, … } )
		// and does NOT persist the toggles before running, so the selected tasks
		// must be taken from the request body. DatabaseOptimizer::clean() expects a
		// flat list of enabled task keys, so filter out the false values and keep the
		// keys. An empty body falls back to the saved settings.
		$params = $request ? (array) $request->get_json_params() : [];
		$tasks  = ! empty( $params ) ? array_keys( array_filter( $params ) ) : null;

		$optimizer = new DatabaseOptimizer();
		$results   = $optimizer->clean( $tasks );

		return Response::success( [
			'message' => 'Database cleanup completed',
			'results' => $results,
		] );
	}

}
