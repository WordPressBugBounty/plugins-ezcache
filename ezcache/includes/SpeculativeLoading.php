<?php
/**
 * Speculative Loading
 *
 * Injects a <script type="speculationrules"> block to enable browser-native
 * speculative prefetch / prerender of same-origin links.
 *
 * @package Upress\EzCache
 */
namespace Upress\EzCache;

class SpeculativeLoading {

    private static $instance;
    private $settings;

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
        if ( empty( $this->settings->enable_speculative_loading ) ) {
            return;
        }
        add_action( 'wp_head', [ $this, 'inject_speculation_rules' ], 2 );
    }

    /**
     * Print the speculationrules script tag.
     */
    public function inject_speculation_rules() {
        if ( is_admin() ) { return; }

        $mode  = isset( $this->settings->speculative_mode ) ? $this->settings->speculative_mode : 'moderate';
        $rules = $this->build_rules( $mode );
        echo '<script type="speculationrules">' . wp_json_encode( $rules ) . '</script>' . "\n";
    }

    /**
     * Build the speculation rules JSON object.
     *
     * @param  string $mode  conservative | moderate | eager
     * @return array
     */
    private function build_rules( $mode ) {
        switch ( $mode ) {
            case 'eager':
                // Prerender all same-origin links eagerly
                return [
                    'prerender' => [
                        [
                            'where'     => [ 'href_matches' => '/*' ],
                            'eagerness' => 'eager',
                        ],
                    ],
                ];

            case 'conservative':
                // Only prefetch same-origin links user hovers on
                return [
                    'prefetch' => [
                        [
                            'where'     => [
                                'and' => [
                                    [ 'href_matches' => '/*' ],
                                    [ 'not' => [ 'selector_matches' => '.no-prefetch' ] ],
                                ],
                            ],
                            'eagerness' => 'conservative',
                        ],
                    ],
                ];

            case 'moderate':
            default:
                // Prerender likely navigations (moderate eagerness)
                return [
                    'prerender' => [
                        [
                            'where'     => [
                                'and' => [
                                    [ 'href_matches' => '/*' ],
                                    [ 'not' => [ 'selector_matches' => '[rel~=nofollow]' ] ],
                                    [ 'not' => [ 'href_matches' => '/wp-admin/*' ] ],
                                    [ 'not' => [ 'href_matches' => '/wp-login.php' ] ],
                                ],
                            ],
                            'eagerness' => 'moderate',
                        ],
                    ],
                ];
        }
    }
}
