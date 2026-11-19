<?php
namespace Upress\EzCache\Rest;

use Upress\EzCache\Cache;

class StatusController {

	function show() {
		return Response::success( Cache::instance()->get_status() );
	}

}
