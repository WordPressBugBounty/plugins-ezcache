<?php

namespace Upress\EzCache\Rest;

use Upress\EzCache\Settings;
use Upress\EzCache\PremiumFeatures;

class SettingsController {

	/**
	 * Allowed settings keys (whitelist)
	 */
	private static $allowed_keys = [
		// Core cache settings
		'no_cache_known_users', 'no_cache_comment_authors', 'separate_mobile_cache',
		'cache_lifetime', 'cache_expiry_interval',
		// Optimization
		'disable_wp_emoji', 'optimize_google_fonts',
		'minify_html', 'minify_html_comments', 'minify_inline_js', 'minify_inline_css',
		'minify_js', 'combine_head_js', 'combine_body_js', 'combine_head_inline_js',
		'combine_body_inline_js', 'minify_css', 'combine_css', 'combine_css_footer',
		'critical_css', 'enable_webp_support',
		// Cache behavior
		'no_cache_query_params', 'cache_clear_on_post_edit', 'cache_clear_home_on_post_edit',
		'bypass_cache', 'rejected_uri', 'rejected_user_agent', 'rejected_cookies',
		'excluded_minify_files',
	];

	/**
	 * Boolean settings keys
	 */
	private static $boolean_keys = [
		'no_cache_known_users', 'no_cache_comment_authors', 'separate_mobile_cache',
		'disable_wp_emoji', 'optimize_google_fonts',
		'minify_html', 'minify_html_comments', 'minify_inline_js', 'minify_inline_css',
		'minify_js', 'combine_head_js', 'combine_body_js', 'combine_head_inline_js',
		'combine_body_inline_js', 'minify_css', 'combine_css', 'combine_css_footer',
		'enable_webp_support',
		'no_cache_query_params', 'cache_clear_on_post_edit', 'cache_clear_home_on_post_edit',
	];

	/**
	 * Integer settings keys with [min, max] ranges
	 */
	private static $integer_keys = [
		'cache_lifetime'       => [ 0, 31536000 ],
		'cache_expiry_interval' => [ 60, 86400 ],
	];

	function show() {
		$settings = Settings::get_settings();

		return wp_send_json_success( $settings );
	}

	function update( $request ) {
		$json             = $request->get_json_params();
		$updated_settings = [];

		foreach ( $json as $key => $value ) {
			// Only allow known settings keys
			if ( ! in_array( $key, self::$allowed_keys, true ) ) {
				continue;
			}
			$updated_settings[ $key ] = self::sanitize_setting( $key, $value );
		}

		// Strip premium features for free users
		if ( ! PremiumFeatures::is_premium() ) {
			$premium = PremiumFeatures::get_premium_features();
			foreach ( $premium as $key ) {
				unset( $updated_settings[ $key ] );
			}
		}

		Settings::set_settings( $updated_settings );

		return wp_send_json_success( Settings::get_settings() );
	}

	function destroy() {
		$default_settings = (array) Settings::get_default_settings();
		Settings::set_settings( $default_settings );

		return wp_send_json_success( Settings::get_settings() );
	}

	// ── Dev Mode ──────────────────────────────────────────

	function devModeStatus() {
		$status = \Upress\EzCache\Cache::get_dev_mode_status();
		return wp_send_json_success( $status );
	}

	function enableDevMode( $request ) {
		$duration = $request->get_param( 'duration' );
		if ( $duration === 'permanent' || $duration === '0' ) {
			$seconds = 0;
		} else {
			$seconds = max( (int) $duration, 3600 );
		}
		\Upress\EzCache\Cache::enable_dev_mode( $seconds );
		return wp_send_json_success( [ 'active' => true, 'message' => 'Development mode enabled' ] );
	}

	function disableDevMode() {
		\Upress\EzCache\Cache::disable_dev_mode();
		return wp_send_json_success( [ 'active' => false, 'message' => 'Development mode disabled' ] );
	}

	// ── Diagnostics ──────────────────────────────────────

	function diagnose( $request ) {
		$site_url = home_url();

		// Call Go diagnostic API
		$response = wp_remote_post( 'https://api.ezcache-wp.com/analyze', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => json_encode( [ 'url' => $site_url ] ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'diag_error', $response->get_error_message(), [ 'status' => 500 ] );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return wp_send_json_success( $data );
	}

	// ── Settings Backup/Restore ──────────────────────────

	private function get_backup_dir() {
		$dir = WP_CONTENT_DIR . '/ezcache-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', 'Deny from all' );
			file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
		}
		return $dir;
	}

	function listBackups() {
		$dir = $this->get_backup_dir();
		$files = glob( $dir . '/*.json' );
		$backups = [];

		foreach ( $files as $file ) {
			$content = json_decode( file_get_contents( $file ), true );
			$backups[] = [
				'filename'   => basename( $file ),
				'name'       => isset( $content['name'] ) ? $content['name'] : basename( $file, '.json' ),
				'created_at' => isset( $content['created_at'] ) ? $content['created_at'] : date( 'Y-m-d H:i:s', filemtime( $file ) ),
				'size'       => filesize( $file ),
			];
		}

		usort( $backups, function( $a, $b ) { return strcmp( $b['created_at'], $a['created_at'] ); } );

		return wp_send_json_success( $backups );
	}

	function createBackup( $request ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) );
		if ( empty( $name ) ) {
			$name = 'Backup ' . date( 'Y-m-d H:i' );
		}

		$settings = \Upress\EzCache\Settings::get_settings();
		$backup = [
			'name'       => $name,
			'created_at' => date( 'Y-m-d H:i:s' ),
			'version'    => defined( 'EZCACHE_VERSION' ) ? EZCACHE_VERSION : '2.5.1',
			'site_url'   => home_url(),
			'settings'   => $settings,
		];

		$dir = $this->get_backup_dir();
		$filename = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) . '-' . date( 'Ymd-His' ) . '.json';
		file_put_contents( $dir . '/' . $filename, json_encode( $backup, JSON_PRETTY_PRINT ) );

		return wp_send_json_success( [
			'filename' => $filename,
			'message'  => 'Backup created: ' . $name,
		] );
	}

	function restoreBackup( $request ) {
		$filename = sanitize_file_name( $request->get_param( 'filename' ) );

		// Handle file upload
		$upload = $request->get_param( 'settings_json' );
		if ( ! empty( $upload ) ) {
			$backup = json_decode( $upload, true );
		} else {
			$dir = $this->get_backup_dir();
			$filepath = $dir . '/' . $filename;

			if ( ! file_exists( $filepath ) ) {
				return new \WP_Error( 'not_found', 'Backup file not found', [ 'status' => 404 ] );
			}

			$backup = json_decode( file_get_contents( $filepath ), true );
		}

		if ( empty( $backup['settings'] ) ) {
			return new \WP_Error( 'invalid_backup', 'Invalid backup file', [ 'status' => 400 ] );
		}

		\Upress\EzCache\Settings::set_settings( (array) $backup['settings'] );

		return wp_send_json_success( [
			'message'  => 'Settings restored from: ' . ( $backup['name'] ?? $filename ),
			'settings' => \Upress\EzCache\Settings::get_settings(),
		] );
	}

	/**
	 * Sanitize a single setting value based on its key
	 */
	private static function sanitize_setting( $key, $value ) {
		// Boolean fields
		if ( in_array( $key, self::$boolean_keys, true ) ) {
			return (bool) $value;
		}

		// Integer fields with range
		if ( isset( self::$integer_keys[ $key ] ) ) {
			[ $min, $max ] = self::$integer_keys[ $key ];
			return max( $min, min( $max, (int) $value ) );
		}

		// bypass_cache is an associative array of booleans
		if ( $key === 'bypass_cache' && is_array( $value ) ) {
			$allowed_bypass = [ 'single', 'pages', 'frontpage', 'home', 'archives', 'tag', 'category', 'feed', 'search', 'author' ];
			$sanitized = [];
			foreach ( $allowed_bypass as $bypass_key ) {
				$sanitized[ $bypass_key ] = isset( $value[ $bypass_key ] ) ? (bool) $value[ $bypass_key ] : false;
			}
			return $sanitized;
		}

		// Text/textarea fields
		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}

		return $value;
	}
}
