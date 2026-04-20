<?php
/**
 * Redis Object Cache Manager
 * @package Upress\EzCache
 */
namespace Upress\EzCache;

class RedisObjectCache {
    const HOST    = '127.0.0.1';
    const PORT    = 6379;
    const TIMEOUT = 1;

    private static $dropin_path;
    private static $template_path;

    public static function init() {
        self::$dropin_path  = WP_CONTENT_DIR . '/object-cache.php';
        self::$template_path = EZCACHE_DIR . '/includes/object-cache-dropin.php';
        $settings = Settings::get_settings();
        if ( ! empty( $settings->enable_redis_object_cache ) ) {
            self::maybe_deploy_dropin();
        }
    }

    public static function is_available() {
        $fp = @fsockopen( self::HOST, self::PORT, $errno, $errstr, self::TIMEOUT );
        if ( $fp ) { fclose( $fp ); return true; }
        return false;
    }

    public static function maybe_deploy_dropin() {
        if ( ! isset( self::$dropin_path ) ) {
            self::$dropin_path  = WP_CONTENT_DIR . '/object-cache.php';
            self::$template_path = EZCACHE_DIR . '/includes/object-cache-dropin.php';
        }
        if ( file_exists( self::$dropin_path ) && ! self::is_our_dropin() ) {
            return false;
        }
        if ( ! file_exists( self::$template_path ) ) {
            self::write_dropin_file( self::$template_path );
        }
        return copy( self::$template_path, self::$dropin_path );
    }

    public static function remove_dropin() {
        if ( ! isset( self::$dropin_path ) ) {
            self::$dropin_path = WP_CONTENT_DIR . '/object-cache.php';
        }
        if ( file_exists( self::$dropin_path ) && self::is_our_dropin() ) {
            return unlink( self::$dropin_path );
        }
        return false;
    }

    public static function is_our_dropin() {
        if ( ! isset( self::$dropin_path ) ) {
            self::$dropin_path = WP_CONTENT_DIR . '/object-cache.php';
        }
        if ( ! file_exists( self::$dropin_path ) ) { return false; }
        $header = file_get_contents( self::$dropin_path, false, null, 0, 256 );
        return strpos( $header, 'ezCache Redis Object Cache Drop-in' ) !== false;
    }

    public static function get_status() {
        $settings      = Settings::get_settings();
        $enabled       = ! empty( $settings->enable_redis_object_cache );
        $available     = self::is_available();
        $connected     = false;
        $hit_rate      = 0;
        $memory        = '--';
        $keys          = 0;
        $dropin_active = file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && self::is_our_dropin();

        if ( $enabled && $available ) {
            $connected = true;
            $info = self::redis_info();
            if ( $info ) {
                $hits   = isset( $info['keyspace_hits'] )   ? (int) $info['keyspace_hits']   : 0;
                $misses = isset( $info['keyspace_misses'] ) ? (int) $info['keyspace_misses'] : 0;
                $total  = $hits + $misses;
                $hit_rate = $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0;
                $memory = isset( $info['used_memory_human'] ) ? $info['used_memory_human'] : '--';
                $keys   = self::count_keys();
            }
        }

        return [
            'enabled'       => $enabled,
            'available'     => $available,
            'connected'     => $connected,
            'dropin_active' => $dropin_active,
            'our_dropin'    => self::is_our_dropin(),
            'hit_rate'      => $hit_rate,
            'memory'        => $memory,
            'keys'          => $keys,
        ];
    }

    public static function flush() {
        $redis = self::get_connection();
        if ( ! $redis ) { return false; }
        try {
            $keys = $redis->keys( 'ezcache:*' );
            if ( $keys ) { $redis->del( $keys ); }
            return true;
        } catch ( \Exception $e ) { return false; }
    }

    public static function get_page( $url ) {
        $redis = self::get_connection();
        if ( ! $redis ) { return false; }
        try {
            $data = $redis->get( 'ezcache:page:' . (defined('DB_NAME') ? DB_NAME . ':' : '') . md5( $url ) );
            return $data !== false ? $data : false;
        } catch ( \Exception $e ) { return false; }
    }

    public static function set_page( $url, $html, $ttl = 604800 ) {
        $redis = self::get_connection();
        if ( ! $redis ) { return false; }
        try {
            return (bool) $redis->setex( 'ezcache:page:' . (defined('DB_NAME') ? DB_NAME . ':' : '') . md5( $url ), $ttl, $html );
        } catch ( \Exception $e ) { return false; }
    }

    public static function delete_page( $url ) {
        $redis = self::get_connection();
        if ( ! $redis ) { return false; }
        try {
            return (bool) $redis->del( [ 'ezcache:page:' . (defined('DB_NAME') ? DB_NAME . ':' : '') . md5( $url ) ] );
        } catch ( \Exception $e ) { return false; }
    }

    private static function get_connection() {
        static $conn = null;
        if ( $conn !== null ) { return $conn; }
        if ( class_exists( 'Redis' ) ) {
            try {
                $r = new \Redis();
                $r->connect( self::HOST, self::PORT, self::TIMEOUT );
                $conn = $r;
                return $conn;
            } catch ( \Exception $e ) { return false; }
        }
        $conn = new RedisFallbackSocket( self::HOST, self::PORT, self::TIMEOUT );
        if ( ! $conn->connected() ) { $conn = false; }
        return $conn;
    }

    private static function redis_info() {
        $redis = self::get_connection();
        if ( ! $redis ) { return false; }
        try {
            if ( class_exists( 'Redis' ) && $redis instanceof \Redis ) { return $redis->info(); }
            return $redis->info();
        } catch ( \Exception $e ) { return false; }
    }

    private static function count_keys() {
        $redis = self::get_connection();
        if ( ! $redis ) { return 0; }
        try {
            $keys = $redis->keys( 'ezcache:*' );
            return is_array( $keys ) ? count( $keys ) : 0;
        } catch ( \Exception $e ) { return 0; }
    }

    private static function write_dropin_file( $path ) {
        $content = self::dropin_source();
        @file_put_contents( $path, $content );
    }

    private static function dropin_source() {
        // Return the object-cache drop-in source (stored separately)
        $src_path = EZCACHE_DIR . '/includes/object-cache-dropin.php';
        if ( file_exists( $src_path ) ) {
            return file_get_contents( $src_path );
        }
        return '';
    }
}

/**
 * Raw-socket Redis client for environments without the php-redis extension.
 */
class RedisFallbackSocket {
    private $socket = null;
    private $host;
    private $port;
    private $timeout;

    public function __construct( $host, $port, $timeout ) {
        $this->host    = $host;
        $this->port    = $port;
        $this->timeout = $timeout;
        $this->socket  = @fsockopen( $host, $port, $errno, $errstr, $timeout );
        if ( $this->socket ) {
            stream_set_timeout( $this->socket, $timeout );
        }
    }

    public function connected() { return (bool) $this->socket; }

    private function send( ...$args ) {
        if ( ! $this->socket ) { return false; }
        $cmd = '*' . count( $args ) . "\r\n";
        foreach ( $args as $a ) { $cmd .= '$' . strlen( $a ) . "\r\n" . $a . "\r\n"; }
        fwrite( $this->socket, $cmd );
        return $this->read_response();
    }

    private function read_response() {
        $line = fgets( $this->socket );
        if ( $line === false ) { return false; }
        $type = $line[0];
        $data = rtrim( substr( $line, 1 ) );
        switch ( $type ) {
            case '+': return $data;
            case '-': return false;
            case ':': return (int) $data;
            case '$':
                $len = (int) $data;
                if ( $len === -1 ) { return false; }
                $bulk = '';
                while ( strlen( $bulk ) < $len + 2 ) { $bulk .= fread( $this->socket, $len + 2 - strlen( $bulk ) ); }
                return rtrim( $bulk, "\r\n" );
            case '*':
                $count = (int) $data;
                if ( $count === -1 ) { return []; }
                $arr = [];
                for ( $i = 0; $i < $count; $i++ ) { $arr[] = $this->read_response(); }
                return $arr;
        }
        return false;
    }

    public function get( $key ) { return $this->send( 'GET', $key ); }
    public function set( $key, $value ) { return $this->send( 'SET', $key, $value ); }
    public function setex( $key, $ttl, $value ) { return $this->send( 'SETEX', $key, (string) $ttl, $value ); }
    public function del( array $keys ) { return $this->send( ...array_merge( [ 'DEL' ], $keys ) ); }
    public function keys( $pattern ) { $r = $this->send( 'KEYS', $pattern ); return is_array( $r ) ? $r : []; }
    public function info() {
        $raw = $this->send( 'INFO' );
        if ( ! $raw ) { return false; }
        $info = [];
        foreach ( explode( "\r\n", $raw ) as $line ) {
            if ( strpos( $line, ':' ) !== false ) {
                list( $k, $v ) = explode( ':', $line, 2 );
                $info[ trim( $k ) ] = trim( $v );
            }
        }
        return $info;
    }
}
