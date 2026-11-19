<?php

namespace Upress\EzCache\Rest;

use Upress\EzCache\DatabaseOptimizer;

class DatabaseController {

	/**
	 * Run the database cleanup.
	 */
	public function clean() {
		$optimizer = new DatabaseOptimizer();
		$results   = $optimizer->clean();

		return Response::success( $results );
	}
}
