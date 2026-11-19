<?php
namespace Upress\EzCache;

class RedisObjectCache {
    public static function init() {
        // Repair file-replacement upgrades once; matching drop-ins are never rewritten.
        if (!empty(Settings::get_settings()->enable_redis_object_cache)) { self::maybe_deploy_dropin(); }
    }
    public static function is_available() { return (bool) self::get_connection(); }
    public static function is_our_dropin() {
        $path = WP_CONTENT_DIR . '/object-cache.php';
        return is_file($path) && strpos((string) @file_get_contents($path, false, null, 0, 256), 'ezCache Redis Object Cache Drop-in') !== false;
    }
    public static function maybe_deploy_dropin() {
        if (Settings::is_disabled()) { return false; }
        $path = WP_CONTENT_DIR . '/object-cache.php';
        if ( file_exists($path) && ! self::is_our_dropin() ) { return false; }
        $template = __DIR__ . '/object-cache-dropin.php';
        $source = @file_get_contents($template);
        // The copy in wp-content must find this folder by its full path.
        $needle = '$ezcache_includes = __DIR__;';
        if ( false === $source || false === strpos($source, $needle) ) { return false; }
        $source = str_replace($needle, '$ezcache_includes = ' . var_export(__DIR__, true) . ';', $source);
        if ( is_file($path) && hash_file('sha256', $path) === hash('sha256', $source) ) { return true; }
        // The drop-in is new or about to change. What the object cache still holds from
        // an earlier time (the feature was off, an older version wrote it) must not be
        // read as current: it is emptied before the first read, or right now.
        if ( ! RedisConnection::mark_dirty() && ! self::flush_objects() ) { return false; }
        if ( ! Utilities\AtomicFile::write($path, $source) ) { return false; }
        // With opcache.validate_timestamps off PHP would keep running the old drop-in.
        if ( function_exists('opcache_invalidate') ) { @opcache_invalidate($path, true); }
        return true;
    }
    /**
     * Whether Redis can hold the object cache: phpredis is loaded and a value
     * survives a round trip. Asked before the feature is switched on, so nobody
     * enables a cache that cannot store anything.
     */
    public static function can_persist() {
        if ( ! class_exists('Redis', false) ) { return false; }
        $client = RedisConnection::connect(false);
        if ( ! $client ) { return false; }
        try {
            $key = RedisConnection::prefix() . 'probe:' . bin2hex(random_bytes(6));
            $ok = $client->set($key, '1', ['ex'=>10]) && '1' === $client->get($key);
            $client->del($key);
            return (bool) $ok;
        } catch (\Throwable $e) { return false; }
    }
    public static function remove_dropin() {
        $path = WP_CONTENT_DIR . '/object-cache.php';
        if (!self::is_our_dropin()) { return true; }
        if (!@unlink($path)) { return false; }
        if ( function_exists('opcache_invalidate') ) { @opcache_invalidate($path, true); }
        // From now on options and transients change in the database only: the object
        // cache has to be emptied before it is ever read again, and is emptied now if possible.
        RedisConnection::mark_dirty();
        self::flush_objects();
        // The rest of this request must not repopulate persistent options after removal.
        if (isset($GLOBALS['wp_object_cache']) && method_exists($GLOBALS['wp_object_cache'],'disable_persistence')) { $GLOBALS['wp_object_cache']->disable_persistence(); }
        return true;
    }
    public static function get_status() {
        $settings = Settings::get_settings();
        $client = self::get_connection();
        $enabled = ! empty($settings->enable_redis_object_cache);
        $extension = class_exists('Redis');
        $active = self::is_our_dropin();
        $info = [];
        try { if ($client) { $info = $client->info() ?: []; } } catch (\Throwable $e) { $client = false; }
        return [
            'enabled'=>$enabled, 'available'=>(bool)$client,
            'connected'=>$enabled && $extension && $active && (bool)$client,
            'fullpage_connected'=>!empty($settings->enable_redis_fullpage) && (bool)$client,
            'phpredis_available'=>$extension, 'dropin_active'=>$active, 'our_dropin'=>$active,
            // Redis INFO is server-wide, never label it an installation's hit rate.
            'hit_rate'=>null, 'memory'=>$info['used_memory_human'] ?? '--', 'metrics_scope'=>'redis-server',
            'keys'=>self::count_keys($client), 'keys_scope'=>'installation',
        ];
    }
    public static function flush() {
        $client = self::get_connection();
        if ( ! $client ) { return false; }
        try {
            self::invalidate_pages();
            $ok = RedisConnection::delete_pattern($client, RedisConnection::prefix() . '*', 0);
            if ( $ok && function_exists('wp_cache_flush_runtime') ) { wp_cache_flush_runtime(); }
            return $ok;
        } catch (\Throwable $e) { return false; }
    }
    /** Everything the object cache stored for this installation; runs to the end. */
    public static function flush_objects() {
        $client = self::get_connection();
        if ( ! $client ) { return false; }
        try { return RedisConnection::delete_pattern($client, RedisConnection::prefix() . 'obj:*', 0); } catch (\Throwable $e) { self::connection_lost($client); return false; }
    }
    /**
     * Cached pages only. Options, transients and locks in the object cache are not
     * part of a page-cache purge.
     *
     * The purge is a point in time: every page stored up to now is ignored from here
     * on (see get_page()). That holds when Redis cannot be reached at this moment
     * and when its keyspace is too large to search in a few seconds; finding and
     * deleting the pages only frees memory, so it may be cut short.
     */
    public static function flush_pages() {
        $marked = self::invalidate_pages();
        $client = self::get_connection(); $deleted = false;
        if ( $client ) {
            try { $deleted = RedisConnection::delete_pattern($client, RedisConnection::prefix() . 'page:*', $marked ? 2 : 0); } catch (\Throwable $e) { self::connection_lost($client); }
        }
        return $marked || $deleted;
    }
    /** The file whose modification time is the moment of the last purge of all pages. */
    private static function purge_file() {
        $dir = WP_CONTENT_DIR . '/cache';
        if ( ! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir) ) { return ''; }
        return $dir . '/ezcache-redis-pages-purged';
    }
    private static $purged_at = null;
    private static function pages_purged_at() {
        if ( null === self::$purged_at ) { $file = self::purge_file(); self::$purged_at = '' === $file ? 0 : (int) @filemtime($file); }
        return self::$purged_at;
    }
    /** Every page stored in Redis up to this second is no longer served. */
    private static function invalidate_pages() {
        $file = self::purge_file();
        if ( '' === $file || ! function_exists('touch') ) { return false; }
        $new = ! file_exists($file);
        if ( ! @touch($file, time()) && ! @touch($file) ) { return false; }
        if ( $new && method_exists('\Upress\EzCache\Utilities\AtomicFile', 'match_owner') ) { Utilities\AtomicFile::match_owner($file, dirname($file)); }
        clearstatcache(true, $file);
        self::$purged_at = null;
        return true;
    }
    /** After a failed call: is Redis gone, or did it only refuse this one command? */
    private static function connection_lost( $client ) {
        try {
            if ( method_exists($client, 'connected') ) { if ( $client->connected() ) { return false; } }
            elseif ( $client->ping() ) { return false; }
        } catch (\Throwable $e) {}
        RedisConnection::mark_down();
        return true;
    }
    /** Most variants (query string, device, WebP) kept for one page path. */
    const MAX_VARIANTS = 64;
    /** Larger pages stay on disk only. */
    const MAX_PAGE_BYTES = 4194304;
    /**
     * [ key, field ]: one Redis hash per page path, one field per variant. Purging
     * a page is then a single DEL; with one key per variant it took a SCAN of the
     * whole Redis database (every site on a shared server) on every post save.
     * The path is lower-cased like the file cache's key, so /%D7%A9/ and /%d7%a9/
     * are one page and a purge by permalink removes what visitors were served.
     */
    private static function page_key( $url ) {
        $parts = explode('#ezcache=', $url, 2);
        $url = $parts[0];
        // A request URL has no fragment. With one (a "Host: shop.test#" header) parse_url()
        // would drop the path and file the page under the home page.
        if ( false !== strpos($url, '#') ) { return false; }
        if ( strpos($url, '/') === 0 ) { $home=parse_url(home_url()); $url=($home['scheme'] ?? 'http').'://'.$home['host'].(isset($home['port'])?':'.$home['port']:'').$url; }
        $parsed = parse_url($url);
        if ( ! $parsed || empty($parsed['host']) ) { return false; }
        $origin = strtolower(($parsed['scheme'] ?? 'http') . '://' . $parsed['host']) . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $path = strtolower($parsed['path'] ?? '/');
        $query = $parsed['query'] ?? '';
        return [ RedisConnection::prefix() . 'page:' . hash('sha256', $origin . $path), hash('sha256', $query . '|' . ($parts[1] ?? 'index.html')) ];
    }
    public static function get_page( $url ) {
        $client = self::get_connection(); $key = self::page_key($url);
        if ( ! $client || ! $key ) { return false; }
        try {
            $raw = $client->hGet($key[0], $key[1]);
            // The plugin's own socket client reports a timeout as "no value".
            if ( false === $raw && method_exists($client, 'connected') && ! $client->connected() ) { RedisConnection::mark_down(); }
            $entry = is_string($raw) ? json_decode($raw, true) : null;
            if ( ! is_array($entry) || ! isset($entry['created'], $entry['html']) ) { return false; }
            $ttl = (int) Settings::get_settings()->cache_lifetime;
            // Stored before the last purge, or past its lifetime.
            if ( $entry['created'] <= self::pages_purged_at() || ( $ttl > 0 && $entry['created'] + $ttl <= time() ) ) { $client->hDel($key[0], $key[1]); return false; }
            return $entry['html'];
        } catch (\Throwable $e) { self::connection_lost($client); return false; }
    }
    public static function set_page( $url, $html, $ttl = 604800 ) {
        $client = self::get_connection(); $key = self::page_key($url);
        if ( ! $client || ! $key ) { return false; }
        // Unescaped: with \uXXXX escapes a Hebrew page is three times its size.
        $value = json_encode(['created'=>time(), 'html'=>$html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ( false === $value || strlen($value) > self::MAX_PAGE_BYTES ) { return false; }
        try {
            // Bounded: a path requested with endless query strings starts over instead of growing.
            if ( (int) $client->hLen($key[0]) >= self::MAX_VARIANTS ) { $client->del([$key[0]]); }
            if ( false === $client->hSet($key[0], $key[1], $value) ) { return false; } // 0 = field replaced, also a success
            if ( $ttl > 0 ) { $client->expire($key[0], (int) $ttl); }
            return true;
        } catch (\Throwable $e) { self::connection_lost($client); return false; } // a refused write is not an outage
    }
    public static function delete_page( $url ) {
        // Every site that edits a post gets here: only talk to Redis when its page cache is in use.
        if ( empty(Settings::get_settings()->enable_redis_fullpage) ) { return false; }
        $key = self::page_key($url);
        if ( ! $key ) { return false; }
        $client = self::get_connection();
        // The hash holds every query/device/WebP variant of this path.
        if ( $client ) {
            try { if ( false !== $client->del([$key[0]]) ) { return true; } } catch (\Throwable $e) { self::connection_lost($client); }
        }
        // Redis could not be told. Once it answers again it would go on serving the page
        // as it was before the edit, so all its pages are set aside instead.
        self::invalidate_pages();
        return false;
    }
    private static function get_connection() {
        static $client;
        if ( null === $client ) {
            // method_exists: right after an update PHP can still hold an older RedisConnection.
            $guarded = method_exists('\Upress\EzCache\RedisConnection', 'is_down');
            $client = ( $guarded && RedisConnection::is_down() ) ? false : RedisConnection::connect();
            if ( ! $client && $guarded && ! RedisConnection::is_down() ) { RedisConnection::mark_down(); }
        }
        return $client;
    }
    private static function count_keys( $client ) {
        if ( ! $client ) { return 0; }
        $count = 0; $cursor = null; $deadline = microtime(true) + 1;
        try {
            if ($client instanceof \Redis) { $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NORETRY); } // one SCAN per call, so the limit below is looked at
            while (($keys = $client->scan($cursor, RedisConnection::prefix() . '*', 500)) !== false) {
                $count += count($keys);
                if (microtime(true) >= $deadline) { return null; } // Don't present a partial count as exact.
            }
        } catch (\Throwable $e) { return null; }
        return $count;
    }
}
