<?php

namespace Upress\EzCache\FileOptimizer;

use Upress\EzCache\BackgroundProcesses\ConvertWebpProcess;
use Upress\EzCache\Utilities\Logger;
use wpdb;

class WebpConverter extends BaseFileOptimizer {
	/** @var string $cache_dir */
	protected $cache_dir;
	/** @var string $cache_file */
	protected $cache_file;
	/** @var ConvertWebpProcess $webp_processor */
	protected $webp_processor;
	/** @var wpdb $wpdb */
	protected $wpdb;

	/**
	 * Same as Rest\WebpController::make_webp_name(); the two must stay identical.
	 * Kept here as well so that rendering a page never depends on another class
	 * whose older copy PHP may still hold right after an update.
	 */
	public static function webp_name( $path, $is_url = false ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$tail = '';
		$cut  = $is_url ? strcspn( $path, '?#' ) : strlen( $path );
		if ( $cut < strlen( $path ) ) {
			$tail = substr( $path, $cut );
			$path = substr( $path, 0, $cut );
		}
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );
		$base = '' === $ext ? $path : substr( $path, 0, - strlen( $ext ) - 1 );

		return $base . '.' . substr( md5( $ext ), - 6 ) . '.webp' . $tail;
	}

	function __construct( $cache_dir, $cache_file, $webp_processor, $wpdb ) {
		$this->cache_dir      = $cache_dir;
		$this->cache_file     = $cache_file;
		$this->webp_processor = $webp_processor;
		$this->wpdb           = $wpdb;
	}

	/**
	 * Minifies CSS files
	 *
	 * @param string $html HTML content.
	 *
	 * @return string
	 */
	public function optimize( $html ) {
		// "Clear WebP images" is deleting the files in background batches: do not point pages at them, do not queue new ones.
		if ( function_exists( 'get_option' ) && get_option( 'ezcache_webp_delete_pending', false ) ) {
			return $html;
		}
		/*
		 * we need to make sure to handle multiple formats
		 * <img src="elva-fairy-800w.jpg" alt="Elva dressed as a fairy">
		 * <img src="elva-fairy-320w.jpg" data-src="elva-fairy-800w.jpg" alt="Elva dressed as a fairy">
		 * <img srcset="elva-fairy-320w.jpg 320w, elva-fairy-480w.jpg 480w, elva-fairy-800w.jpg 800w" sizes="(max-width: 320px) 280px, (max-width: 480px) 440px, 800px" src="elva-fairy-800w.jpg" alt="Elva dressed as a fairy">
		 * <picture><source media="(max-width: 799px)" srcset="elva-480w-close-portrait.jpg"><img src="elva-480w-close-portrait.jpg" alt="Elva dressed as a fairy"></picture>
		 * <div style="background: url(elva-480w-close-portrait.jpg);">
		 * <div style="background-image: url(elva-480w-close-portrait.jpg);">
		 * <style>.foo { background-image: url(elva-480w-close-portrait.jpg); }</style>
		 */
		$img_tags    = $this->find( '<(?:img|source)\s+([^>]+[\s"\'])?(?:data-src|src|srcset)\s*=\s*[\'"]\s*?([^\'"]+(?:\?[^\'"]*)?)\s*?[\'"]([^>]+)?\/?>', $html );
		$bg_images = $this->find( 'background(?:-image)\s*:.*url\s*\(\s*([\'"]?)([^\'"]+)\1\s*\)', $html );

		if ( ! $img_tags ) {
			$img_tags = [];
		}
		if ( ! $bg_images ) {
			$bg_images = [];
		}

		$images = [];

		foreach ( $img_tags as $img ) {
			// handle srcset, normally $images[2] will catch only one of the images in the set
			if ( isset( $img[0] ) && stripos( $img[0], 'srcset=' ) !== false ) {
				$srcset = preg_replace( '/^.+srcset=[\'"](.+?)[\'"].+$/iu', '$1', $img[0] );
				$srcset = explode( ',', $srcset );

				foreach ( $srcset as $item ) {
					$images[] = trim( preg_replace( '/^(.+?)\s+.+$/ui', '$1', $item ), " \t\n\r\0\x0B\"'" );
				}
			}

			// same with data-src
			if ( isset( $img[0] ) && stripos( $img[0], 'data-src=' ) !== false ) {
				$src = preg_replace( '/^.+data-src=[\'"](.+?)[\'"].+$/iu', '$1', $img[0] );
				$images[] = trim( preg_replace( '/^(.+?)\s+.+$/ui', '$1', $src ), " \t\n\r\0\x0B\"'" );
			}

			// for other images just grab the url part
			$images[] = $img[2];
		}

		// for bg images grab the url part
		foreach ( $bg_images as $img ) {
			$images[] = trim( $img[2], " \t\n\r\0\x0B\"'" );
		}

		$images = array_unique( $images );

		$need_to_process = false;
		foreach ( $images as $image_url ) {
			// we don't process external files or files that don't exist

			if ( $this->is_external_file( $image_url ) ) {
				Logger::log("{$image_url} is external");
				continue;
			}

			$image_path = $this->get_file_path( $image_url );
			// method_exists: right after an update PHP may still hold an older WebpApi for up to a minute.
			if ( ! $image_path || ! method_exists( '\Upress\EzCache\WebpApi', 'allowed_path' ) || ! \Upress\EzCache\WebpApi::allowed_path($image_path) || ! file_exists( $image_path ) ) {
				Logger::log("{$image_url} doesn't exist");
				continue;
			}

			$ext  = pathinfo( $image_path, PATHINFO_EXTENSION );
			$mime = mime_content_type( $image_path );


			// The replacement used to be '$1' . <6 hex digits>: when the digits began with a
			// number (.PNG, .JPEG, .jpe, .bmp) PHP read "$13…" as group 13 and the name broke.
			$image_webp_url  = self::webp_name( $image_url, true );
			$image_webp_path = self::webp_name( $image_path );

			// and skip non-images or images already in webp format
			if ( 'webp' == $ext || ! preg_match( '/image\/.+/i', $mime ) || preg_match( '/image\/(svg.*|ico|gif|webp)/i', $mime ) ) {
				Logger::log("{$image_url} is already webp");
				continue;
			}

			// check if the image has a webp version and if it has then just replace it in the html
			$image = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM `{$this->wpdb->prefix}ezcache_webp_images` WHERE `uid` = %s LIMIT 1",
					[ sha1( $image_path ) ]
				)
			);

			if ( $image && 'completed' == $image->status && is_file($image->webp_path) ) {
				$html = preg_replace( '/\b' . preg_quote( $image_url, '/' ) . '\b/u', $image->webp_url, $html );
				Logger::log("{$image_url} already processed");
				continue;
			}

			if ( $image && 'pending' == $image->status ) {
				// image is being processed in background
				Logger::log("{$image_url} still waiting to be processed");

				// need to make sure that the scheuled task for process is running
				$need_to_process = true;

				// 'add_to_queue' will not add it again if already queued
				$this->webp_processor->add_to_queue( $image->id, $this->cache_file );

				continue;
			}

			if ($image && 'failed' === $image->status) { continue; }

			// otherwise send the image to process
			// this should parse the images and set a callback to update the cache file
			$this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT IGNORE INTO `{$this->wpdb->prefix}ezcache_webp_images` (`uid`, `url`, `webp_url`, `path`, `webp_path`, `status`, `created_at`, `updated_at`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
					[
						sha1( $image_path ),
						$image_url,
						$image_webp_url,
						$image_path,
						$image_webp_path,
						'pending',
						date( 'Y-m-d H:i:s' ),
						date( 'Y-m-d H:i:s' )
					]
				)
			);
			if ( empty( $this->wpdb->last_error ) && $this->wpdb->insert_id ) {
				$need_to_process = true;
				$this->webp_processor->add_to_queue( $this->wpdb->insert_id, $this->cache_file );
			} else {
				Logger::log( 'ezCache WebP Converter Error: ' . $this->wpdb->last_error );
			}
		}

		if ( $need_to_process ) {
			$this->webp_processor->schedule();
		}

		return $html;
	}
}
