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
		'critical_css', 'enable_webp_support', 'webp_external_api',
		// Cache behavior
		'no_cache_query_params', 'cache_clear_on_post_edit', 'cache_clear_home_on_post_edit',
		'enable_varnish_purge',
		'ignore_query_params', 'ignored_query_params_list',
		'no_cache_filtered_pages', 'max_cache_url_depth', 'max_cache_url_length',
		'strict_response_privacy',
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
		'enable_webp_support', 'webp_external_api',
		'no_cache_query_params', 'cache_clear_on_post_edit', 'cache_clear_home_on_post_edit',
		'enable_varnish_purge',
		'ignore_query_params', 'no_cache_filtered_pages', 'strict_response_privacy',
	];

	/**
	 * Integer settings keys with [min, max] ranges
	 */
	private static $integer_keys = [
		'cache_lifetime'        => [ 0, 31536000 ],
		'cache_expiry_interval' => [ 60, 86400 ],
		'max_cache_url_depth'   => [ 0, 50 ],
		'max_cache_url_length'  => [ 0, 4000 ],
	];

	function show() {
		$settings = Settings::get_settings();

		return Response::success( $settings );
	}

	function update( $request ) {
		$json             = (array) $request->get_json_params();
		$updated_settings = [];

		foreach ( $json as $key => $value ) {
			// Only allow known settings keys
			if ( ! in_array( $key, self::$allowed_keys, true ) ) {
				continue;
			}
			$updated_settings[ $key ] = $value;
		}

		// Strip premium features for free users
		if ( ! PremiumFeatures::is_premium() ) {
			$premium = PremiumFeatures::get_premium_features();
			foreach ( $premium as $key ) {
				unset( $updated_settings[ $key ] );
			}
		}

		$saved = Settings::set_settings( $updated_settings );
		if ( is_wp_error( $saved ) ) { return $saved; }

		return Response::success( Settings::get_settings() );
	}

	function destroy() {
		$default_settings = (array) Settings::get_default_settings();
		$saved = Settings::set_settings( $default_settings );
		if ( is_wp_error( $saved ) ) { return $saved; }

		return Response::success( Settings::get_settings() );
	}

	// ── Dev Mode ──────────────────────────────────────────

	function devModeStatus() {
		$status = \Upress\EzCache\Cache::get_dev_mode_status();
		return Response::success( $status );
	}

	function enableDevMode( $request ) {
		$duration = $request->get_param( 'duration' );
		if ( $duration === 'permanent' || $duration === '0' ) {
			$seconds = 0;
		} else {
			$seconds = max( (int) $duration, 3600 );
		}
		if (!\Upress\EzCache\Cache::enable_dev_mode($seconds)) { return new \WP_Error('dev_mode_write_failed','Could not enable development mode.',['status'=>500]); }
		return Response::success( [ 'active' => true, 'message' => 'Development mode enabled' ] );
	}

	function disableDevMode() {
		if (!\Upress\EzCache\Cache::disable_dev_mode()) { return new \WP_Error('dev_mode_write_failed','Could not disable development mode.',['status'=>500]); }
		return Response::success( [ 'active' => false, 'message' => 'Development mode disabled' ] );
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
		return Response::success( $data );
	}

	// ── Settings Backup/Restore ──────────────────────────

	public function get_backup_dir() {
		$dir = WP_CONTENT_DIR . '/ezcache-backups';
		if ( ! is_dir( $dir ) ) {
			// Owned by the site's owner also when a root command line creates it (it is created during the update routine).
			if ( method_exists( '\Upress\EzCache\Utilities\AtomicFile', 'make_dir' ) ) { \Upress\EzCache\Utilities\AtomicFile::make_dir( $dir ); } else { wp_mkdir_p( $dir ); }
			file_put_contents( $dir . '/.htaccess', 'Deny from all' );
			file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
		}
        // Migrate legacy public JSON backups to PHP-guarded storage.
        foreach ( glob( $dir . '/*.json' ) ?: [] as $legacy ) {
            $raw = @file_get_contents( $legacy );
            if ( false !== $raw && \Upress\EzCache\Utilities\AtomicFile::write( substr( $legacy, 0, -5 ) . '.php', Settings::FILE_GUARD . $raw ) ) { @unlink( $legacy ); }
        }
        return $dir;
	}

	function listBackups() {
		$dir = $this->get_backup_dir();
		$files = glob( $dir . '/*.php' ) ?: [];
        $files = array_filter( $files, function($file) { return basename($file) !== 'index.php'; } );
		$backups = [];

		foreach ( $files as $file ) {
			$content = json_decode( substr( file_get_contents( $file ), strlen( Settings::FILE_GUARD ) ), true );
			$backups[] = [
				'filename'   => basename( $file ),
				'name'       => isset( $content['name'] ) ? $content['name'] : basename( $file, '.php' ),
				'created_at' => isset( $content['created_at'] ) ? $content['created_at'] : date( 'Y-m-d H:i:s', filemtime( $file ) ),
				'size'       => filesize( $file ),
			];
		}

		usort( $backups, function( $a, $b ) { return strcmp( $b['created_at'], $a['created_at'] ); } );

		return Response::success( $backups );
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
		$filename = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) . '-' . date( 'Ymd-His' ) . '-' . bin2hex( random_bytes(4) ) . '.php';
		if ( ! \Upress\EzCache\Utilities\AtomicFile::write( $dir . '/' . $filename, Settings::FILE_GUARD . json_encode( $backup, JSON_PRETTY_PRINT ) ) ) {
            return new \WP_Error( 'backup_write_failed', 'Could not save backup.', ['status'=>500] );
        }

		return Response::success( [
			'filename' => $filename,
			'message'  => 'Backup created: ' . $name,
		] );
	}

	function restoreBackup( $request ) {
		$value = $request->get_param('filename');
        $filename = sanitize_file_name(is_string($value) ? $value : '');

		// Handle file upload
		$upload = $request->get_param( 'settings_json' );
		if ( ! empty( $upload ) ) {
			if (!is_string($upload)) { return new \WP_Error('invalid_backup','Invalid backup data.',['status'=>400]); }
            $backup = json_decode( $upload, true );
		} else {
			$dir = $this->get_backup_dir();
			$filename = preg_replace('/\.json$/', '.php', $filename);
            if ( ! preg_match('/^[a-zA-Z0-9_-]+\.php$/D', $filename) || $filename === 'index.php' ) { return new \WP_Error('invalid_backup', 'Invalid backup filename.', ['status'=>400]); }
            $filepath = $dir . '/' . $filename;

			if ( is_link($filepath) || ! is_file( $filepath ) ) {
				return new \WP_Error( 'not_found', 'Backup file not found', [ 'status' => 404 ] );
			}

			$backup = json_decode( substr( file_get_contents( $filepath ), strlen( Settings::FILE_GUARD ) ), true );
		}

		if ( ! is_array( $backup ) || empty( $backup['settings'] ) || ! is_array( $backup['settings'] ) ) {
			return new \WP_Error( 'invalid_backup', 'Invalid backup file', [ 'status' => 400 ] );
		}

		$saved = Settings::set_settings( (array) $backup['settings'] );
		if ( is_wp_error( $saved ) ) { return $saved; }

		return Response::success( [
			'message'  => 'Settings restored from: ' . ( $backup['name'] ?? $filename ),
			'settings' => \Upress\EzCache\Settings::get_settings(),
		] );
	}

}
