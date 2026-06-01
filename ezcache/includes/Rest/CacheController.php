<?php
namespace Upress\EzCache\Rest;

use Exception;
use Upress\EzCache\Cache;
use WP_REST_Request;

class CacheController {

	function show() {
		try {
			$stats = Cache::instance()->get_cache_stats();
		} catch( Exception $ex) {
			return wp_send_json_error( [ 'error' => $ex->getMessage() ] );
		}

		// Restructure flat stats into nested format expected by Vue frontend
		$structured_stats = [
			'desktop' => [
				'count' => $stats['desktop_count'] ?? 0,
				'size'  => $stats['desktop_size'] ?? 0,
			],
			'mobile' => [
				'count' => $stats['mobile_count'] ?? 0,
				'size'  => $stats['mobile_size'] ?? 0,
			],
			'expired' => [
				'count' => ( $stats['desktop_expired_count'] ?? 0 ) + ( $stats['mobile_expired_count'] ?? 0 ),
				'size'  => ( $stats['desktop_expired_size'] ?? 0 ) + ( $stats['mobile_expired_size'] ?? 0 ),
			],
			'js' => [
				'count' => $stats['js_count'] ?? 0,
				'size'  => $stats['js_size'] ?? 0,
			],
			'css' => [
				'count' => $stats['css_count'] ?? 0,
				'size'  => $stats['css_size'] ?? 0,
			],
			'webp' => [
				'count'         => $stats['webp_images'] ?? 0,
				'size'          => $stats['webp_size'] ?? 0,
				'original_size' => $stats['webp_original_size'] ?? 0,
			],
		];

		return wp_send_json_success( [
			'stats'   => $structured_stats,
			'notices' => [],
		] );
	}

	/**
	 * @param WP_REST_Request $request
	 */
	function destroy( $request ) {
		$input = $request->get_json_params();

		Cache::instance()->clear_cache();

		return wp_send_json_success( [ 'input' => $input ] );
	}

}
