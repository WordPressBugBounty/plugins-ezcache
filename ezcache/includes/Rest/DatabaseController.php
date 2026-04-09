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

		return wp_send_json_success( $results );
	}
}
