<?php
/**
 * Premium Feature Gate
 * 
 * Central function to check if a feature requires premium.
 * Uses Freemius license OR built-in 7-day trial from activation.
 */

namespace Upress\EzCache;

class PremiumFeatures {

    const TRIAL_DAYS = 7;
    const TRIAL_OPTION = 'ezcache_trial_started';

    /**
     * Features that require premium license
     */
    private static $premium_features = [
        // CSS/JS optimization
        'minify_css',
        'combine_css',
        'combine_css_footer',
        'minify_js',
        'combine_head_js',
        'combine_body_js',
        'combine_head_inline_js',
        'combine_body_inline_js',
        'minify_inline_js',
        'minify_inline_css',
        'critical_css',
        'optimize_google_fonts',
        'defer_js',
        'remove_query_strings',
        
        // WebP
        'enable_webp_support',
        
        // Preload
        'enable_preload',
        'preload_on_cache_clear',
        
        // CDN
        'cdn_enabled',
        
        // Heartbeat & DNS
        'heartbeat_control',
        'dns_prefetch',
        'preconnect',
        
        // Database cleanup
        'db_cleanup_revisions',
        'db_cleanup_auto_drafts',
        'db_cleanup_trashed_posts',
        'db_cleanup_spam_comments',
        'db_cleanup_trashed_comments',
        'db_cleanup_expired_transients',
        'db_cleanup_orphan_postmeta',
        'db_optimize_tables',
        'db_cleanup_schedule',

        // Redis (2.2.0+)
        'enable_redis_object_cache',
        'enable_redis_fullpage',

        // Critical CSS (2.2.0+)
        'enable_critical_css',
    ];

    /**
     * Start the built-in trial (called on plugin activation)
     */
    public static function maybe_start_trial() {
        if ( ! get_option( self::TRIAL_OPTION ) ) {
            update_option( self::TRIAL_OPTION, time() );
        }
    }

    /**
     * Check if built-in trial is active (within 7 days of activation)
     * @return bool
     */
    public static function is_builtin_trial() {
        $trial_started = get_option( self::TRIAL_OPTION );
        if ( ! $trial_started ) {
            return false;
        }
        $elapsed = time() - (int) $trial_started;
        return $elapsed < ( self::TRIAL_DAYS * DAY_IN_SECONDS );
    }

    /**
     * Get trial days remaining
     * @return int
     */
    public static function trial_days_remaining() {
        $trial_started = get_option( self::TRIAL_OPTION );
        if ( ! $trial_started ) {
            return 0;
        }
        $remaining = ( self::TRIAL_DAYS * DAY_IN_SECONDS ) - ( time() - (int) $trial_started );
        return max( 0, (int) ceil( $remaining / DAY_IN_SECONDS ) );
    }

    /**
     * Check if user has premium access (paid, Freemius trial, or built-in trial)
     * @return bool
     */
    public static function is_premium() {
        // Freemius license or trial
        if ( function_exists( 'ezc_fs' ) && ( ezc_fs()->is_paying() || ezc_fs()->is_trial() ) ) {
            return true;
        }
        // Built-in 7-day trial
        return self::is_builtin_trial();
    }

    /**
     * Check if a specific feature requires premium
     * @param string $feature
     * @return bool
     */
    public static function is_premium_feature( $feature ) {
        return in_array( $feature, self::$premium_features, true );
    }

    /**
     * Check if a feature is available (either free or user has premium)
     * @param string $feature
     * @return bool
     */
    public static function is_feature_available( $feature ) {
        if ( ! self::is_premium_feature( $feature ) ) {
            return true; // Free feature
        }
        return self::is_premium();
    }

    /**
     * Get list of premium features
     * @return array
     */
    public static function get_premium_features() {
        return self::$premium_features;
    }

    /**
     * Filter settings to disable premium features for free users
     * @param object $settings
     * @return object
     */
    public static function enforce_settings( $settings ) {
        if ( self::is_premium() ) {
            return $settings;
        }

        foreach ( self::$premium_features as $feature ) {
            if ( isset( $settings->{$feature} ) ) {
                if ( is_bool( $settings->{$feature} ) ) {
                    $settings->{$feature} = false;
                } elseif ( is_string( $settings->{$feature} ) ) {
                    $settings->{$feature} = '';
                }
            }
        }

        // Special: force db_cleanup_schedule to never
        if ( isset( $settings->db_cleanup_schedule ) ) {
            $settings->db_cleanup_schedule = 'never';
        }

        return $settings;
    }
}
