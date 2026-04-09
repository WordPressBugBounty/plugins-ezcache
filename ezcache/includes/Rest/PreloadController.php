<?php

namespace Upress\EzCache\Rest;

use Upress\EzCache\Preload;
use WP_REST_Request;

class PreloadController {

	/**
	 * Get the current state of the preload queue.
	 */
	public function show() {
		return wp_send_json_success( Preload::instance()->get_status() );
	}

	/**
	 * Start (or restart) the preload process.
	 *
	 * @param WP_REST_Request $request
	 */
	public function start( $request ) {
		$status = Preload::instance()->start();

		return wp_send_json_success( $status );
	}

	/**
	 * Cancel a running preload.
	 */
	public function destroy() {
		$status = Preload::instance()->stop();

		return wp_send_json_success( $status );
	}
}
