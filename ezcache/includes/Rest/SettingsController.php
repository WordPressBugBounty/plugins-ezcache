<?php

namespace Upress\EzCache\Rest;

use Upress\EzCache\Settings;
use Upress\EzCache\PremiumFeatures;

class SettingsController {

	function show() {
		$settings = Settings::get_settings();

		return [
			'success' => true,
			'data'    => $settings,
		];
	}

	function update( $request ) {
		$json             = $request->get_json_params();
		$updated_settings = [];
		foreach ( $json as $key => $value ) {
			$updated_settings[ $key ] = $value;
		}

		// Strip premium features for free users
		if ( ! PremiumFeatures::is_premium() ) {
			$premium = PremiumFeatures::get_premium_features();
			foreach ( $premium as $key ) {
				unset( $updated_settings[ $key ] );
			}
		}

		Settings::set_settings( $updated_settings );

		return [
			'success' => true,
			'data'    => Settings::get_settings(),
		];
	}

	function destroy() {
		$default_settings = (array) Settings::get_default_settings();
		Settings::set_settings( $default_settings );

		return [
			'success' => true,
			'data'    => Settings::get_settings(),
		];
	}

	// ── Dev Mode ──────────────────────────────────────────

	function devModeStatus() {
		$status = \Upress\EzCache\Cache::get_dev_mode_status();
		return [ 'success' => true, 'data' => $status ];
	}

	function enableDevMode( $request ) {
		$duration = $request->get_param( 'duration' );
		if ( $duration === 'permanent' || $duration === '0' ) {
			$seconds = 0;
		} else {
			$seconds = max( (int) $duration, 3600 );
		}
		\Upress\EzCache\Cache::enable_dev_mode( $seconds );
		return [
			'success' => true,
			'data' => [ 'active' => true, 'message' => 'Development mode enabled' ],
		];
	}

	function disableDevMode() {
		\Upress\EzCache\Cache::disable_dev_mode();
		return [
			'success' => true,
			'data' => [ 'active' => false, 'message' => 'Development mode disabled' ],
		];
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
		return [ 'success' => true, 'data' => $data ];
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

		return [ 'success' => true, 'data' => $backups ];
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
			'version'    => defined( 'EZCACHE_VERSION' ) ? EZCACHE_VERSION : '2.1.0',
			'site_url'   => home_url(),
			'settings'   => $settings,
		];

		$dir = $this->get_backup_dir();
		$filename = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) . '-' . date( 'Ymd-His' ) . '.json';
		file_put_contents( $dir . '/' . $filename, json_encode( $backup, JSON_PRETTY_PRINT ) );

		return [
			'success' => true,
			'data'    => [
				'filename' => $filename,
				'message'  => 'Backup created: ' . $name,
			],
		];
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

		return [
			'success' => true,
			'data'    => [
				'message'  => 'Settings restored from: ' . ( $backup['name'] ?? $filename ),
				'settings' => \Upress\EzCache\Settings::get_settings(),
			],
		];
	}
}
