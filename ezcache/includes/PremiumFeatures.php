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
     * No-op — trial system removed. Pro is unlocked for everyone.
     * Also cleans up legacy trial option from previous installs.
     */
    public static function maybe_start_trial() {
        if ( get_option( self::TRIAL_OPTION ) ) {
            delete_option( self::TRIAL_OPTION );
        }
    }

    /**
     * Legacy stub — trial system removed. Always returns false.
     * @return bool
     */
    public static function is_builtin_trial() {
        return false;
    }

    /**
     * Legacy stub — trial system removed. Always returns 0.
     * @return int
     */
    public static function trial_days_remaining() {
        return 0;
    }

    /**
     * Check if user has premium access.
     * Licensing system removed — Pro is unlocked for everyone.
     * @return bool
     */
    public static function is_premium() {
        return true;
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
     * No-op — Pro is unlocked for everyone, so settings pass through unchanged.
     * @param object $settings
     * @return object
     */
    public static function enforce_settings( $settings ) {
        return $settings;
    }
}
