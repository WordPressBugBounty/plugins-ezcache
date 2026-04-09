<?php
namespace Upress\EzCache\Rest;

use Upress\EzCache\Settings;
use Upress\EzCache\Preload;
use Upress\EzCache\DatabaseCleanup;
use WP_REST_Request;

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
	];

	/**
	 * Get performance settings and preload status
	 */
	function show() {
		$all_settings = Settings::get_settings();
		$settings = [];
		
		foreach ( self::$performance_keys as $key ) {
			$settings[ $key ] = isset( $all_settings->{$key} ) ? $all_settings->{$key} : null;
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

		return wp_send_json_success( [
			'settings'       => $settings,
			'preload_status' => $preload_status,
		] );
	}

	/**
	 * Update performance settings
	 * @param WP_REST_Request $request
	 */
	function update( $request ) {
		$input = (array) $request->get_json_params();
		$current_settings = (array) Settings::get_settings();
		
		foreach ( self::$performance_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current_settings[ $key ] = $this->sanitize_value( $key, $input[ $key ] );
			}
		}

		Settings::set_settings( $current_settings );

		return wp_send_json_success();
	}

	/**
	 * Run preload
	 */
	function runPreload() {
		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
			Preload::instance()->start();
		}

		return wp_send_json_success( [ 'message' => 'Preload started' ] );
	}

	/**
	 * Stop preload
	 */
	function stopPreload() {
		if ( class_exists( '\Upress\EzCache\Preload' ) ) {
			Preload::instance()->stop();
		}

		return wp_send_json_success( [ 'message' => 'Preload stopped' ] );
	}

	/**
	 * Run database cleanup
	 */
	function runDbCleanup() {
		if ( class_exists( '\Upress\EzCache\DatabaseCleanup' ) ) {
			DatabaseCleanup::instance()->run();
		}

		return wp_send_json_success( [ 'message' => 'Database cleanup completed' ] );
	}

	/**
	 * Sanitize a value based on its key
	 */
	private function sanitize_value( $key, $value ) {
		// Boolean fields
		$booleans = [
			'enable_preload', 'preload_on_cache_clear', 'preload_crawl_homepage_links',
			'lazy_load_images', 'lazy_load_iframes', 'defer_js', 'remove_query_strings',
			'heartbeat_control', 'cdn_enabled',
			'db_cleanup_revisions', 'db_cleanup_auto_drafts', 'db_cleanup_trashed_posts',
			'db_cleanup_spam_comments', 'db_cleanup_trashed_comments', 
			'db_cleanup_expired_transients', 'db_cleanup_orphan_postmeta', 'db_optimize_tables',
		];

		if ( in_array( $key, $booleans, true ) ) {
			return (bool) $value;
		}

		// Integer fields
		if ( $key === 'preload_batch_size' ) {
			return max( 1, min( 50, (int) $value ) );
		}

		// Select fields
		if ( $key === 'heartbeat_mode' ) {
			return in_array( $value, [ 'reduce', 'disable' ], true ) ? $value : 'reduce';
		}

		if ( $key === 'db_cleanup_schedule' ) {
			return in_array( $value, [ 'never', 'daily', 'weekly' ], true ) ? $value : 'never';
		}

		// Text fields
		return sanitize_textarea_field( $value );
	}
}
