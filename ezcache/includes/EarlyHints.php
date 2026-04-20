<?php
/**
 * 103 Early Hints
 *
 * Sends HTTP 103 Early Hints headers for CSS and JS assets so the browser
 * can start fetching them before the main response body arrives.
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

        // Send Early Hints headers before the page is rendered.
        // The 'send_headers' hook fires before output — ideal for 103.
        add_action( 'send_headers', [ $this, 'send_early_hints' ] );
    }

    /**
     * Walk through WP's registered styles & scripts and build Link headers.
     */
    public function collect_assets() {
        global $wp_styles, $wp_scripts;

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

    /**
     * Emit 103 Early Hints response headers.
     *
     * Note: PHP can only send one status line, so we output the 103 block
     * via header() calls before WordPress sets the 200. On servers that
     * buffer (nginx + fastcgi_buffering off or LiteSpeed), this correctly
     * flushes a 103 interim response first.
     */
    public function send_early_hints() {
        if ( is_admin() || empty( $this->hints ) ) {
            return;
        }

        // Only attempt on servers/PHP versions that support 103
        if ( ! function_exists( 'header' ) ) {
            return;
        }

        // Send 103 header line
        if ( PHP_MAJOR_VERSION >= 8 && PHP_MINOR_VERSION >= 0 ) {
            // PHP 8+ header() supports arbitrary status codes without output
            @header( 'HTTP/1.1 103 Early Hints' );
        }

        // Deduplicate and send Link headers
        $seen = [];
        foreach ( array_unique( $this->hints ) as $hint ) {
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
        if ( strpos( $src, 'http' ) === 0 ) {
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
