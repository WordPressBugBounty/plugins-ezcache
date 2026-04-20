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
        return wp_send_json_success( $status );
    }

    /**
     * POST /redis-flush
     * Flushes all ezcache:* keys from Redis.
     */
    public function flush() {
        if ( ! PremiumFeatures::is_premium() ) {
            return wp_send_json_error( [ 'message' => 'Premium required' ], 403 );
        }

        $result = RedisObjectCache::flush();
        if ( $result ) {
            return wp_send_json_success( [ 'message' => 'Redis cache flushed' ] );
        }
        return wp_send_json_error( [ 'message' => 'Failed to flush Redis — is it running?' ] );
    }

    /**
     * POST /redis-toggle
     * Enable or disable the Redis object cache (deploys / removes drop-in).
     */
    public function toggle( WP_REST_Request $request ) {
        if ( ! PremiumFeatures::is_premium() ) {
            return wp_send_json_error( [ 'message' => 'Premium required' ], 403 );
        }

        $params  = (array) $request->get_json_params();
        $enable  = ! empty( $params['enable'] );
        $fullpage = ! empty( $params['enable_fullpage'] );

        Settings::set_settings( [
            'enable_redis_object_cache' => $enable,
            'enable_redis_fullpage'     => $fullpage,
        ] );

        if ( $enable ) {
            $deployed = RedisObjectCache::maybe_deploy_dropin();
            if ( ! $deployed && ! RedisObjectCache::is_our_dropin() ) {
                return wp_send_json_error( [
                    'message' => 'A foreign object-cache.php already exists. Remove it first.',
                ] );
            }
        } else {
            RedisObjectCache::remove_dropin();
        }

        return wp_send_json_success( [
            'enabled'  => $enable,
            'status'   => RedisObjectCache::get_status(),
        ] );
    }
}
