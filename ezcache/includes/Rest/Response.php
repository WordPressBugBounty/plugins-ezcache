<?php
namespace Upress\EzCache\Rest;

/** REST controllers return responses; they must not terminate WordPress with wp_die(). */
class Response {
    public static function success( $data = null, $status = 200 ) {
        return new \WP_REST_Response( ['success'=>true, 'data'=>$data], $status ?: 200 );
    }
    public static function error( $data = null, $status = 400 ) {
        return new \WP_REST_Response( ['success'=>false, 'data'=>$data], $status ?: 400 );
    }
}
