<?php
namespace Upress\EzCache;

/** Shared connection and namespace contract for the manager and standalone drop-in. */
class RedisConnection {
    public static function prefix() {
        static $prefix;
        if ( null === $prefix ) {
            global $wpdb, $table_prefix;
            $base = isset($wpdb->base_prefix) ? $wpdb->base_prefix : ($table_prefix ?? 'wp_');
            $salt = defined('EZCACHE_CACHE_SALT') ? EZCACHE_CACHE_SALT : (ABSPATH . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . $base);
            $prefix = 'ezcache:v2:' . hash('sha256', $salt) . ':';
        }
        return $prefix;
    }

    /** After a failed connection or command, Redis is left alone for this long. */
    const RETRY_SECONDS = 30;
    private static $shared;

    /**
     * The object cache's connection (phpredis only: it needs SET options, PTTL and
     * EVAL), opened once per request. False when Redis cannot be used right now.
     *
     * The marker file says "this site has been running without the Redis object
     * cache". WordPress then keeps options and transients in the database only,
     * so whatever Redis still holds is older than the database: a plugin that was
     * deactivated meanwhile would be active again, a saved option would be back at
     * its old value. The first request that gets through to Redis again therefore
     * empties the object cache before anybody reads from it, and only then removes
     * the marker. While the marker is younger than RETRY_SECONDS nobody tries, so
     * a Redis that is down costs one connection attempt per window instead of a
     * timeout in every request.
     */
    public static function shared() {
        if ( null !== self::$shared ) { return self::$shared; }
        self::$shared = false;
        if ( ! class_exists('Redis', false) ) { return false; }
        $marker = self::marker();
        $dirty  = '' !== $marker && file_exists($marker);
        if ( $dirty ) {
            if ( self::is_down() ) { return false; }
            // One request tries; the others see a fresh marker and keep away until it is gone.
            if ( function_exists('touch') && ! @touch($marker, time()) ) { @touch($marker); }
        }
        $client = self::connect(false);
        if ( ! $client ) { self::mark_down(); return false; }
        if ( $dirty ) {
            try { $clean = self::delete_pattern($client, self::prefix() . 'obj:*', 0); } catch ( \Throwable $e ) { $clean = false; }
            if ( ! $clean || ! @unlink($marker) ) { self::mark_down(); return false; }
        }
        self::$shared = $client;
        return $client;
    }

    /** No cache folder and no way to create one: no memory, every request tries. */
    private static function marker() {
        if ( ! defined('WP_CONTENT_DIR') ) { return ''; }
        $dir = WP_CONTENT_DIR . '/cache';
        if ( ! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir) ) { return ''; }
        return $dir . '/ezcache-redis-down';
    }
    private static function write_marker( $time ) {
        $marker = self::marker();
        // A host that disables a function removes it altogether on PHP 8: calling it is fatal.
        if ( '' === $marker || ! function_exists('touch') ) { return false; }
        $new = ! file_exists($marker);
        // A given time needs ownership of the file; the current time only write access.
        if ( ! @touch($marker, $time) && ! @touch($marker) ) { return false; }
        if ( $new && function_exists('fileowner') && function_exists('filegroup') && function_exists('chown') && function_exists('chgrp') ) {
            // Created by a command run as root it would be out of the web server's reach.
            $owner = @fileowner(dirname($marker)); $group = @filegroup(dirname($marker));
            if ( false !== $owner && $owner !== @fileowner($marker) ) { @chown($marker, $owner); }
            if ( false !== $group && $group !== @filegroup($marker) ) { @chgrp($marker, $group); }
        }
        return true;
    }
    /** Whether Redis failed within the last RETRY_SECONDS. */
    public static function is_down() {
        $marker = self::marker();
        if ( '' === $marker ) { return false; }
        clearstatcache(true, $marker);
        return (int) @filemtime($marker) > time() - self::RETRY_SECONDS;
    }
    /** Redis failed just now: leave it alone for RETRY_SECONDS. */
    public static function mark_down() {
        self::$shared = false;
        return self::write_marker(time());
    }
    /**
     * The site is going to run, or has run, without the Redis object cache (the
     * feature was switched off, the drop-in is being installed or replaced): the
     * object cache must be emptied before it is read again. Starts no waiting time.
     */
    public static function mark_dirty() {
        $marker = self::marker();
        if ( '' === $marker ) { return false; }
        return file_exists($marker) ? true : self::write_marker(time() - self::RETRY_SECONDS - 1);
    }

    public static function connect( $allow_fallback = true ) {
        $host = defined('EZCACHE_REDIS_HOST') ? EZCACHE_REDIS_HOST : '127.0.0.1';
        $port = defined('EZCACHE_REDIS_PORT') ? (int) EZCACHE_REDIS_PORT : 6379;
        $timeout = defined('EZCACHE_REDIS_TIMEOUT') ? max(0.1, (float) EZCACHE_REDIS_TIMEOUT) : 1.0;
        try {
            if ( class_exists('Redis') ) {
                $client = new \Redis();
                if ( ! $client->connect($host, $port, $timeout) ) { return false; }
                $client->setOption(\Redis::OPT_READ_TIMEOUT, $timeout);
                $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            } elseif ( $allow_fallback ) {
                require_once __DIR__ . '/RedisFallbackSocket.php';
                $client = new RedisFallbackSocket($host, $port, $timeout);
                if ( ! $client->connected() ) { return false; }
            } else { return false; }
            if ( defined('EZCACHE_REDIS_PASSWORD') && EZCACHE_REDIS_PASSWORD !== '' && ! $client->auth(EZCACHE_REDIS_PASSWORD) ) { return false; }
            if ( defined('EZCACHE_REDIS_DATABASE') && ! $client->select((int) EZCACHE_REDIS_DATABASE) ) { return false; }
            if ( ! $client->ping() ) { return false; }
            return $client;
        } catch ( \Throwable $e ) { return false; }
    }

    /**
     * Delete every key matching a pattern. SCAN walks all keys of the Redis
     * database, whoever owns them, so this is for rare, explicit purges only.
     *
     * @param int|float $seconds Give up (and return false) after this long; 0 = run to the end.
     */
    public static function delete_pattern( $client, $pattern, $seconds = 5 ) {
        $cursor = null; $deadline = $seconds > 0 ? microtime(true) + (float) $seconds : 0;
        // One SCAN per call. With SCAN_RETRY phpredis stays inside a single call until
        // something matches, and the time limit below would never be looked at.
        if ( $client instanceof \Redis ) { $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NORETRY); }
        while ( ($keys = $client->scan($cursor, $pattern, 500)) !== false ) {
            if ( $keys ) {
                $result = method_exists($client, 'unlink') ? $client->unlink($keys) : false;
                // Redis before 4.0 has no UNLINK.
                if ( false === $result ) { $result = $client->del($keys); }
                if ( false === $result ) { return false; }
            }
            if ( $deadline && microtime(true) > $deadline ) { return false; }
        }
        return true;
    }
}
