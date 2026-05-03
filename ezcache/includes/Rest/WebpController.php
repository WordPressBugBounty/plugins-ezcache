<?php
namespace Upress\EzCache\Rest;

use Upress\EzCache\Cache;
use Upress\EzCache\PremiumFeatures;
use Upress\EzCache\WebpApi;
use Upress\EzCache\Utilities\Logger;

class WebpController {

	/**
	 * Generate webp URL matching original WebpConverter format
	 * e.g., image.jpg → image.4a5fec.webp (md5 suffix of extension)
	 */
	private static function make_webp_name( $path_or_url ) {
		$ext = pathinfo( $path_or_url, PATHINFO_EXTENSION );
		$ext_hash = substr( md5( $ext ), -6 );
		return preg_replace( '/\.' . preg_quote( $ext, '/' ) . '$/', '.' . $ext_hash . '.webp', $path_or_url );
	}

	function status() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezcache_webp_images';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [ 'success' => true, 'data' => [
				'total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0,
				'saved_bytes' => 0, 'is_premium' => PremiumFeatures::is_premium(),
			]];
		}

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'completed'" );
		$pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" );
		$failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'failed'" );
		$saved     = $wpdb->get_row( "SELECT COALESCE(SUM(original_size),0) as orig, COALESCE(SUM(webp_size),0) as webp FROM `{$table}` WHERE status = 'completed'" );
		$saved_bytes = $saved ? (int)$saved->orig - (int)$saved->webp : 0;

		return [ 'success' => true, 'data' => [
			'total' => $total, 'completed' => $completed, 'pending' => $pending,
			'failed' => $failed, 'saved_bytes' => max(0, $saved_bytes),
			'is_premium' => PremiumFeatures::is_premium(),
		]];
	}

	function scan() {
		global $wpdb;
		if ( ! PremiumFeatures::is_premium() ) {
			return new \WP_Error( 'premium_required', 'Premium license required', [ 'status' => 403 ] );
		}

		$table = $wpdb->prefix . 'ezcache_webp_images';
		$attachments = $wpdb->get_results(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' 
			 AND post_mime_type IN ('image/jpeg','image/png','image/gif') ORDER BY ID DESC"
		);

		$queued = 0;
		foreach ( $attachments as $att ) {
			$file = get_attached_file( $att->ID );
			if ( ! $file || ! file_exists( $file ) ) continue;

			$url = wp_get_attachment_url( $att->ID );
			$files_to_process = [ [ 'path' => $file, 'url' => $url ] ];

			// Add thumbnails
			$meta = wp_get_attachment_metadata( $att->ID );
			if ( isset( $meta['sizes'] ) ) {
				$dir = dirname( $file );
				$url_dir = dirname( $url );
				foreach ( $meta['sizes'] as $data ) {
					$tp = $dir . '/' . $data['file'];
					$tu = $url_dir . '/' . $data['file'];
					if ( file_exists( $tp ) ) {
						$files_to_process[] = [ 'path' => $tp, 'url' => $tu ];
					}
				}
			}

			foreach ( $files_to_process as $item ) {
				$uid = sha1( $item['path'] );
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE uid = %s", $uid ) );
				if ( $exists ) continue;

				$webp_url  = self::make_webp_name( $item['url'] );
				$webp_path = self::make_webp_name( $item['path'] );

				$wpdb->insert( $table, [
					'uid' => $uid, 'url' => $item['url'], 'path' => $item['path'],
					'webp_url' => $webp_url, 'webp_path' => $webp_path,
					'status' => 'pending',
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				]);
				if ( $wpdb->insert_id ) $queued++;
			}
		}

		return [ 'success' => true, 'data' => [ 'queued' => $queued, 'message' => "{$queued} images queued" ] ];
	}

	function process() {
		global $wpdb;
		if ( ! PremiumFeatures::is_premium() ) {
			return new \WP_Error( 'premium_required', 'Premium required', [ 'status' => 403 ] );
		}

		$table = $wpdb->prefix . 'ezcache_webp_images';
		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT %d", 5
		));

		if ( empty( $pending ) ) {
			return [ 'success' => true, 'data' => [ 'processed' => 0, 'remaining' => 0, 'message' => 'Done' ] ];
		}

		// Freemius removed — Pro is unlocked for everyone.
		$converter = new WebpApi( 'unlocked_pro' );
		$processed = 0;

		foreach ( $pending as $image ) {
			if ( ! file_exists( $image->path ) ) {
				$wpdb->update( $table, [ 'status' => 'failed', 'updated_at' => current_time('mysql') ], [ 'id' => $image->id ] );
				continue;
			}

			$result = $converter->convert( $image->path );
			if ( is_wp_error( $result ) ) {
				$wpdb->update( $table, [ 'status' => 'failed', 'updated_at' => current_time('mysql') ], [ 'id' => $image->id ] );
				Logger::log( "WebP failed: {$image->url} — " . $result->get_error_message() );
				continue;
			}

			$dir = dirname( $image->webp_path );
			if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
			file_put_contents( $image->webp_path, $result['data'] );

			$wpdb->update( $table, [
				'status' => 'completed',
				'original_size' => filesize( $image->path ),
				'webp_size' => filesize( $image->webp_path ),
				'updated_at' => current_time('mysql'),
			], [ 'id' => $image->id ] );
			$processed++;
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" );

		// If done, update existing cache files
		if ( $remaining === 0 ) {
			$this->update_cache_files();
		}

		return [ 'success' => true, 'data' => [ 'processed' => $processed, 'remaining' => $remaining ] ];
	}

	/**
	 * After all conversions done, update cached HTML files to use WebP URLs
	 */
	private function update_cache_files() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezcache_webp_images';

		$completed = $wpdb->get_results( "SELECT url, webp_url FROM `{$table}` WHERE status = 'completed'" );
		if ( empty( $completed ) ) return;

		$upload_dir = wp_upload_dir();
		$cache_dir = WP_CONTENT_DIR . '/cache/ezcache/';
		if ( ! is_dir( $cache_dir ) ) return;

		$cache_files = glob( $cache_dir . '*/*-webp.html.gz' );
		if ( ! $cache_files ) return;

		foreach ( $cache_files as $file ) {
			$content = @gzdecode( file_get_contents( $file ) );
			if ( ! $content ) continue;

			$changed = false;
			foreach ( $completed as $img ) {
				if ( strpos( $content, $img->url ) !== false ) {
					$content = str_replace( $img->url, $img->webp_url, $content );
					$changed = true;
				}
			}

			if ( $changed ) {
				file_put_contents( $file, gzencode( $content, 6, FORCE_GZIP ) );
			}
		}
	}

	function destroy() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezcache_webp_images';

		$images = $wpdb->get_results( "SELECT webp_path FROM `{$table}` WHERE status = 'completed'" );
		foreach ( $images as $img ) {
			if ( file_exists( $img->webp_path ) ) @unlink( $img->webp_path );
		}
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
		Cache::instance()->clear_cache( true );

		return [ 'success' => true, 'data' => [ 'message' => 'Cleared' ] ];
	}
}
