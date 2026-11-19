<?php
/**
 * Resource preload hints. Reverse proxies may promote Link headers to HTTP 103.
 *
 * @package Upress\EzCache
 */
namespace Upress\EzCache;

class EarlyHints {

    private static $instance;
    private $settings;

    /** @var array Collected Link header strings */
    private $hints = [];

    private function __construct() {
        $this->settings = Settings::get_settings();
    }

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register WordPress hooks.
     */
    public function register() {
        if ( empty( $this->settings->enable_early_hints ) ) {
            return;
        }

        // Collect enqueued styles and scripts early
        add_action( 'wp_enqueue_scripts', [ $this, 'collect_assets' ], PHP_INT_MAX );

        // Emit after assets are collected, without changing the response status.
        add_action( 'wp_enqueue_scripts', [ $this, 'send_early_hints' ], PHP_INT_MAX );
    }

    /**
     * Walk through WP's registered styles & scripts and build Link headers.
     */
    public function collect_assets() {
        global $wp_styles, $wp_scripts;
        $this->hints = [];

        if ( $wp_styles instanceof \WP_Styles ) {
            foreach ( $wp_styles->queue as $handle ) {
                $dep = $wp_styles->registered[ $handle ] ?? null;
                if ( $dep && $dep->src ) {
                    $src = $this->normalize_src( $dep->src );
                    if ( $src ) {
                        $this->hints[] = sprintf( '<%s>; rel=preload; as=style', esc_url_raw( $src ) );
                    }
                }
            }
        }

        if ( $wp_scripts instanceof \WP_Scripts ) {
            foreach ( $wp_scripts->queue as $handle ) {
                $dep = $wp_scripts->registered[ $handle ] ?? null;
                if ( $dep && $dep->src ) {
                    $src = $this->normalize_src( $dep->src );
                    if ( $src ) {
                        $this->hints[] = sprintf( '<%s>; rel=preload; as=script', esc_url_raw( $src ) );
                    }
                }
            }
        }
    }

    /** Emit preload Link headers without replacing the final HTTP status. */
    public function send_early_hints() {
        if ( is_admin() || empty( $this->hints ) ) {
            return;
        }

        // PHP's header() cannot emit a portable interim response. Keep the final
        // status untouched and send standard Link headers. A reverse proxy may
        // promote these to 103; without one they are ordinary preload hints.
        if ( headers_sent() ) { return; }

        // Deduplicate and send Link headers
        $seen = [];
        foreach ( array_slice(array_unique( $this->hints ),0,12) as $hint ) {
            $key = md5( $hint );
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            @header( 'Link: ' . $hint, false );
        }
    }

    /**
     * Normalize an asset src to an absolute URL, filtering out non-HTTP sources.
     *
     * @param  string $src
     * @return string|false
     */
    private function normalize_src( $src ) {
        if ( ! $src ) { return false; }

        // Already absolute
        if ( preg_match('#^https?://#i', $src) ) {
            return $src;
        }

        // Protocol-relative
        if ( strpos( $src, '//' ) === 0 ) {
            return ( is_ssl() ? 'https:' : 'http:' ) . $src;
        }

        // Site-relative
        if ( strpos( $src, '/' ) === 0 ) {
            return get_site_url( null, $src );
        }

        return false;
    }
}
