<?php
/** ezCache Redis Object Cache Drop-in — managed by ezCache. */
/*
 * This file must not declare a function or a class.
 *
 * PHP declares the top-level functions and classes of a file when it compiles the
 * file, before the first line runs, so a "return" above them does not take them
 * back. WordPress treats an existing wp_cache_init() as "a persistent object cache
 * is installed": it stops keeping transients in the database and calls
 * wp_cache_init(). A drop-in that declares the cache here and then finds the
 * plugin folder gone, the plugin switched off, phpredis missing or Redis down
 * either ends every request with a fatal error or runs the site on a cache that
 * forgets everything between requests.
 *
 * So the cache itself lives in object-cache-backend.php and is loaded only when
 * it can work. In every other case this file returns without declaring anything
 * and WordPress uses its built-in cache.
 */
if ( ! defined('ABSPATH') ) { exit; }
if ( function_exists('wp_cache_init') ) { return; }
$ezcache_includes = __DIR__;
$ezcache_ready = class_exists('Redis', false)
    && ! is_file(WP_CONTENT_DIR.'/ezcache-disabled.php')
    && is_file($ezcache_includes.'/RedisConnection.php')
    && is_file($ezcache_includes.'/object-cache-backend.php');
if ( $ezcache_ready ) {
    // advanced-cache.php may have loaded the class already, possibly from another copy of the plugin folder.
    if ( ! class_exists('\Upress\EzCache\RedisConnection', false) ) { require_once $ezcache_includes.'/RedisConnection.php'; }
    // method_exists: right after an update PHP can still hold an older copy of that file.
    $ezcache_ready = method_exists('\Upress\EzCache\RedisConnection', 'shared') && (bool) \Upress\EzCache\RedisConnection::shared();
}
if ( ! $ezcache_ready ) {
    // From here on the site runs without the Redis object cache. Leave a note, so that
    // the cache is emptied before it is read again (see RedisConnection::shared()).
    if ( function_exists('touch') && is_dir(WP_CONTENT_DIR.'/cache') && ! file_exists(WP_CONTENT_DIR.'/cache/ezcache-redis-down') ) {
        @touch(WP_CONTENT_DIR.'/cache/ezcache-redis-down', time() - 3600);
    }
    // WordPress 5.0 starts the object cache a second time on multisite and then takes
    // the mere existence of this file for a persistent cache.
    if ( function_exists('add_action') && function_exists('is_multisite') && is_multisite() ) {
        add_action('ms_loaded', static function () {
            if ( function_exists('wp_using_ext_object_cache') && ! ( class_exists('WP_Object_Cache', false) && method_exists('WP_Object_Cache', 'disable_persistence') ) ) {
                wp_using_ext_object_cache(false);
            }
        }, 0);
    }
    unset($ezcache_includes, $ezcache_ready);
    return;
}
require_once $ezcache_includes.'/object-cache-backend.php';
unset($ezcache_includes, $ezcache_ready);
