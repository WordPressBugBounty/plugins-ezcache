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

		// Self-heal: file-replacement updates can leave this table missing, which
		// previously made scan silently queue 0 images and still return success.
		// Create it on demand, and fail loudly if that's not possible.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			\Upress\EzCache\Updater::ensure_tables();
		}
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return new \WP_Error(
				'ezcache_webp_no_table',
				'The WebP image table is missing and could not be created automatically. Please check the database user permissions.',
				[ 'status' => 500 ]
			);
		}

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

		// Kick off (or re-arm) the background conversion cron whenever anything is
		// still pending — this both starts a fresh scan and revives a queue that
		// previously stalled, so conversion completes server-side without the browser.
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" );
		if ( $pending > 0 ) {
			self::schedule_cron();
		}

		return [ 'success' => true, 'data' => [
			'queued'  => $queued,
			'pending' => $pending,
			'message' => "{$queued} images queued",
		] ];
	}

	function process() {
		if ( ! PremiumFeatures::is_premium() ) {
			return new \WP_Error( 'premium_required', 'Premium required', [ 'status' => 403 ] );
		}

		$result = self::convert_batch( 5 );

		// If done, update existing cache files to point at the WebP URLs.
		if ( $result['remaining'] === 0 ) {
			self::update_cache_files();
		}

		return [ 'success' => true, 'data' => $result ];
	}

	/**
	 * Convert a batch of pending images. Shared by the REST endpoint and the
	 * background cron so both drive the exact same conversion path.
	 *
	 * @param int $limit
	 * @return array processed/remaining counts.
	 */
	public static function convert_batch( $limit = 5 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezcache_webp_images';

		// Self-heal the table so the background process never dies on a missing one.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			\Upress\EzCache\Updater::ensure_tables();
		}
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [ 'processed' => 0, 'remaining' => 0 ];
		}

		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit
		));

		if ( empty( $pending ) ) {
			return [ 'processed' => 0, 'remaining' => 0 ];
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

		return [ 'processed' => $processed, 'remaining' => $remaining ];
	}

	/**
	 * WP-Cron callback: convert a batch server-side and re-schedule itself until
	 * the queue drains. This makes conversion resilient — it finishes even if the
	 * browser tab that started the scan is closed.
	 */
	public static function cron_process() {
		if ( ! PremiumFeatures::is_premium() ) {
			return;
		}

		$result = self::convert_batch( 10 );

		if ( $result['remaining'] > 0 ) {
			self::schedule_cron();
		} else {
			self::update_cache_files();
		}
	}

	/**
	 * Schedule (and nudge) the background conversion cron if it isn't already queued.
	 */
	public static function schedule_cron() {
		if ( wp_next_scheduled( 'ezcache_webp_process_batch' ) ) {
			return;
		}
		wp_schedule_single_event( time() + 15, 'ezcache_webp_process_batch' );

		// Nudge wp-cron so the batch starts promptly instead of waiting for traffic.
		wp_safe_remote_get( add_query_arg( [ 'doing_wp_cron' => microtime( true ) ], site_url( '/wp-cron.php' ) ), [
			'timeout'   => 0.1,
			'blocking'  => false,
			'sslverify' => false,
		] );
	}

	/**
	 * After all conversions done, update cached HTML files to use WebP URLs
	 */
	private static function update_cache_files() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezcache_webp_images';

		$completed = $wpdb->get_results( "SELECT url, webp_url FROM `{$table}` WHERE status = 'completed'" );
		if ( empty( $completed ) ) return;

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
