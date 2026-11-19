<?php
/**
 * Redis REST Controller
 *
 * Handles GET /redis-status and POST /redis-flush endpoints.
 *
 * @package Upress\EzCache
 */
namespace Upress\EzCache\Rest;

use Upress\EzCache\RedisObjectCache;
use Upress\EzCache\Settings;
use Upress\EzCache\PremiumFeatures;
use WP_REST_Request;

class RedisController {

    /**
     * GET /redis-status
     * Returns Redis connection status, memory, hit rate, and key count.
     */
    public function status() {
        $status = RedisObjectCache::get_status();
        return Response::success( $status );
    }

    /**
     * POST /redis-flush
     * Flushes all ezcache:* keys from Redis.
     */
    public function flush() {
        if ( ! PremiumFeatures::is_premium() ) {
            return Response::error( [ 'message' => 'Premium required' ], 403 );
        }

        $result = RedisObjectCache::flush();
        if ( $result ) {
            return Response::success( [ 'message' => 'Redis cache flushed' ] );
        }
        return Response::error( [ 'message' => 'Failed to flush Redis — is it running?' ] );
    }

    /**
     * POST /redis-toggle
     * Enable or disable the Redis object cache (deploys / removes drop-in).
     */
    public function toggle( WP_REST_Request $request ) {
        if ( ! PremiumFeatures::is_premium() ) {
            return Response::error( [ 'message' => 'Premium required' ], 403 );
        }

        $params  = (array) $request->get_json_params();
        $enable  = filter_var($params['enable'] ?? false,FILTER_VALIDATE_BOOLEAN);
        $fullpage = filter_var($params['enable_fullpage'] ?? false,FILTER_VALIDATE_BOOLEAN);

        $saved = Settings::set_settings( [
            'enable_redis_object_cache' => $enable,
            'enable_redis_fullpage'     => $fullpage,
        ] );

        if ( is_wp_error($saved) ) { return $saved; }

        return Response::success( [
            'enabled'  => $enable,
            'status'   => RedisObjectCache::get_status(),
        ] );
    }
}
