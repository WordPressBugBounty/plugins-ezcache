<?php
/**
 * Critical CSS Generator
 *
 * Generates above-the-fold CSS and inlines it in the page <head>,
 * deferring the full stylesheets to load asynchronously.
 *
 * @package Upress\EzCache
 */
namespace Upress\EzCache;

class CriticalCSS {

    /** Storage directory for generated critical CSS files */
    const STORAGE_DIR = 'ezcache/critical-css/';

    /** Diagnostic API endpoint for critical CSS extraction */
    const DIAG_PATH = '/critical-css';

    /** Viewport height used for above-the-fold extraction (px) */
    const VIEWPORT_HEIGHT = 1200;

    private static $instance;
    private $settings;
    private $storage_path;

    private function __construct() {
        $this->settings     = Settings::get_settings();
        $this->storage_path = WP_CONTENT_DIR . '/' . self::STORAGE_DIR;
    }

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register hooks
     */
    public function register() {
        if ( empty( $this->settings->enable_critical_css ) ) {
            return;
        }

        if ( ! PremiumFeatures::is_premium() ) {
            return;
        }

        add_action( 'wp_head', [ $this, 'inject_critical_css' ], 1 );
        add_filter( 'style_loader_tag', [ $this, 'defer_stylesheet' ], 10, 4 );
    }

    /**
     * Inject stored critical CSS for the current URL.
     */
    public function inject_critical_css() {
        $css = $this->get_critical_css( $this->get_url_pattern() );
        if ( ! $css ) { return; }
        echo '<style id="ezcache-critical-css">' . wp_strip_all_tags( $css ) . '</style>' . "\n";
    }

    /**
     * Convert stylesheet link tags to async load + noscript fallback.
     */
    public function defer_stylesheet( $tag, $handle, $href, $media ) {
        // Don't defer admin or login stylesheets
        if ( is_admin() || ! $this->get_critical_css($this->get_url_pattern()) ) { return $tag; }
        $media_attr = $media ?: 'all';
        $deferred = sprintf(
            '<link rel="preload" href="%s" as="style" onload="this.onload=null;this.rel=\'stylesheet\';this.media=\'%s\'">' . "\n" .
            '<noscript><link rel="stylesheet" href="%s" media="%s"></noscript>' . "\n",
            esc_url( $href ), esc_attr( $media_attr ),
            esc_url( $href ), esc_attr( $media_attr )
        );
        return $deferred;
    }

    /**
     * Generate critical CSS for a URL using the diagnostic API.
     *
     * @param  string $page_url  Full URL of the page.
     * @param  string $pattern   Storage pattern key (home, post, page, archive).
     * @return array{success:bool, message:string, css:string}
     */
    public function generate( $page_url, $pattern = null ) {
        if ( ! PremiumFeatures::is_premium() ) {
            return [ 'success' => false, 'message' => 'Premium required' ];
        }

        $diag_url = $this->get_diag_api_url();
        if ( ! $diag_url ) {
            return [ 'success' => false, 'message' => 'Diagnostic API not configured' ];
        }

        $response = wp_remote_post( $diag_url . self::DIAG_PATH, [
            'timeout' => 30,
            'body'    => wp_json_encode( [
                'url'             => $page_url,
                'viewport_height' => self::VIEWPORT_HEIGHT,
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
            'sslverify' => true,
            'limit_response_size' => 512*1024,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== (int) wp_remote_retrieve_response_code($response) || empty( $body['css'] ) || ! is_string($body['css']) || strlen($body['css']) > 500000 ) {
            return [ 'success' => false, 'message' => 'No CSS returned from API' ];
        }

        if (!$pattern) {
            $id=url_to_postid($page_url);
            $pattern=untrailingslashit($page_url)===untrailingslashit(home_url('/')) ? 'home' : ($id ? (get_post_type($id)==='page' ? 'page' : 'post') : 'default');
        }
        if ( ! $this->save_critical_css( $pattern, $body['css'] ) ) { return ['success'=>false,'message'=>'Could not save Critical CSS.']; }

        return [ 'success' => true, 'message' => 'Critical CSS generated', 'css' => $body['css'] ];
    }

    /**
     * Get stored critical CSS for a URL pattern.
     *
     * @param  string $pattern
     * @return string|false
     */
    public function get_critical_css( $pattern ) {
        $file = $this->storage_path . sanitize_file_name( $pattern ) . '.css';
        if ( file_exists( $file ) ) {
            return file_get_contents( $file );
        }
        return false;
    }

    /**
     * Save critical CSS for a pattern.
     *
     * @param string $pattern
     * @param string $css
     */
    public function save_critical_css( $pattern, $css ) {
        wp_mkdir_p( $this->storage_path );
        $file = $this->storage_path . sanitize_file_name( $pattern ) . '.css';
        return Utilities\AtomicFile::write( $file, wp_strip_all_tags( $css ) );
    }

    /**
     * Delete all stored critical CSS files.
     */
    public function clear_all() {
        if ( ! is_dir( $this->storage_path ) ) { return; }
        $files = glob( $this->storage_path . '*.css' );
        if ( $files ) {
            foreach ( $files as $f ) { @unlink( $f ); }
        }
    }

    /**
     * Get a list of patterns that have generated critical CSS.
     *
     * @return array
     */
    public function get_patterns() {
        if ( ! is_dir( $this->storage_path ) ) { return []; }
        $files    = glob( $this->storage_path . '*.css' ) ?: [];
        $patterns = [];
        foreach ( $files as $f ) {
            $patterns[] = basename( $f, '.css' );
        }
        return $patterns;
    }

    /**
     * Determine a canonical pattern key for the current URL.
     *
     * @return string  e.g. 'home', 'post', 'page', 'archive'
     */
    public function get_url_pattern() {
        if ( is_front_page() || is_home() ) { return 'home'; }
        if ( is_single() )                  { return 'post'; }
        if ( is_page() )                    { return 'page'; }
        if ( is_archive() )                 { return 'archive'; }
        return 'default';
    }

    /**
     * Build the diagnostic API base URL from plugin settings.
     *
     * @return string|false
     */
    private function get_diag_api_url() {
        // Try to find the local diagnostic API (Booting AI diagnostic server).
        $diag_url = \get_option( 'ezcache_diag_api_url' );
        if ( $diag_url ) {
            $parts = wp_parse_url($diag_url);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https','http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return false; }
            if ($parts['scheme'] !== 'https' && !in_array($parts['host'], ['localhost','127.0.0.1','[::1]'], true)) { return false; }
            return rtrim( $diag_url, '/' );
        }
        // No implicit service: an operator must configure an endpoint explicitly.
        return false;
    }
}
