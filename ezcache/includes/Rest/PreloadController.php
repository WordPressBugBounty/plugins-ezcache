<?php

namespace Upress\EzCache\Rest;

use Upress\EzCache\Preload;
use WP_REST_Request;

class PreloadController {

	/**
	 * Get the current state of the preload queue.
	 */
	public function show() {
		return Response::success( Preload::instance()->get_status() );
	}

	/**
	 * Start (or restart) the preload process.
	 *
	 * @param WP_REST_Request $request
	 */
	public function start( $request ) {
		$status = Preload::instance()->start();

		return is_wp_error($status) ? $status : Response::success($status);
	}

	/**
	 * Cancel a running preload.
	 */
	public function destroy() {
		$status = Preload::instance()->stop();

		return is_wp_error($status) ? $status : Response::success($status);
	}
}
