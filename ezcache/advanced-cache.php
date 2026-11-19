<?php
/*
 * ezCache Advanced Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'NO direct access!' );
}

if (is_file(__DIR__.'/ezcache-disabled.php')) { return; }

// require utilities functions from the plugin
/** @noinspection PhpIncludeInspection */
$ezcache_autoload = __DIR__ . '/plugins/ezcache/vendor/autoload.php';
if (!is_file($ezcache_autoload)) { return; }
include_once $ezcache_autoload;
unset($ezcache_autoload);

if ( class_exists( '\Upress\EzCache\Cache' ) && ! function_exists( 'wp_cache_postload' ) ) {
	global $ezcache;

	// Nothing in here may end the request. Right after an update PHP can hold a mix of
	// old and new plugin files for up to a minute, and an error at this point would be
	// an error page for every visitor; without the cache the page is merely slower.
	try {
		/** @noinspection PhpFullyQualifiedNameUsageInspection */
		$ezcache = \Upress\EzCache\Cache::instance();
		$ezcache->maybe_serve_cached_data();

		// this needs to run before wp_cache_postload but after checking a cache file exists
		$ezcache->do_frontend_optimizations();
	} catch ( \Throwable $e ) {
		$ezcache = null;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ezCache: page served without the cache: ' . $e->getMessage() );
		}
	}

	/**
	 * Start saving the cache file
	 * This function is called at wp-settings.php when WP_CACHE is true in wp-config.php
	 */
	function wp_cache_postload() {
		global $ezcache;

		if ( ! $ezcache ) {
			return;
		}
		try {
			$ezcache->maybe_write_cache_file();
		} catch ( \Throwable $e ) {
			// as above: the page goes out without being cached
		}
	}
}
