<?php
/**
 * ezCache Redis Object Cache Drop-in
 *
 * Automatically managed by the ezCache plugin. Do not edit manually.
 *
 * @package ezCache Redis Object Cache Drop-in
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_Object_Cache {
    private $redis      = null;
    private $connected  = false;
    private $cache      = [];
    private $non_persistent_groups = [];
    public  $cache_hits   = 0;
    public  $cache_misses = 0;
    private $prefix       = '';

    public function __construct() {
        $this->prefix = (defined('DB_NAME') ? DB_NAME : 'wp') . ':' . ($GLOBALS['table_prefix'] ?? 'wp_') . ':';
        $this->connect();
    }

    private function connect() {
        if ( class_exists( 'Redis' ) ) {
            try {
                $this->redis = new Redis();
                $this->redis->connect( '127.0.0.1', 6379, 1 );
                $this->connected = true;
            } catch ( Exception $e ) {
                $this->connected = false;
            }
        }
    }

    private function build_key( $key, $group ) {
        return 'ezcache:obj:' . $this->prefix . ( $group ?: 'default' ) . ':' . $key;
    }

    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        if ( $this->get( $key, $group ) !== false ) { return false; }
        return $this->set( $key, $data, $group, $expire );
    }

    public function add_global_groups( $groups ) {}
    public function add_non_persistent_groups( $groups ) {
        $this->non_persistent_groups = array_merge( $this->non_persistent_groups, (array) $groups );
    }

    public function decr( $key, $offset = 1, $group = 'default' ) {
        $val = (int) $this->get( $key, $group ) - $offset;
        $this->set( $key, $val, $group );
        return $val;
    }

    public function delete( $key, $group = 'default' ) {
        $rkey = $this->build_key( $key, $group );
        unset( $this->cache[ $rkey ] );
        if ( $this->connected ) {
            try { $this->redis->del( [ $rkey ] ); } catch ( Exception $e ) {}
        }
        return true;
    }

    public function flush() {
        $this->cache = [];
        if ( $this->connected ) {
            try {
                $keys = $this->redis->keys( 'ezcache:obj:' . $this->prefix . '*' );
                if ( $keys ) { $this->redis->del( $keys ); }
            } catch ( Exception $e ) {}
        }
        return true;
    }

    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        $rkey = $this->build_key( $key, $group );
        if ( ! $force && array_key_exists( $rkey, $this->cache ) ) {
            $found = true; $this->cache_hits++;
            return is_object( $this->cache[ $rkey ] ) ? clone $this->cache[ $rkey ] : $this->cache[ $rkey ];
        }
        if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->connected ) {
            $found = false; $this->cache_misses++; return false;
        }
        try {
            $raw = $this->redis->get( $rkey );
            if ( $raw === false ) { $found = false; $this->cache_misses++; return false; }
            $data = maybe_unserialize( $raw );
            $this->cache[ $rkey ] = $data;
            $found = true; $this->cache_hits++;
            return is_object( $data ) ? clone $data : $data;
        } catch ( Exception $e ) { $found = false; $this->cache_misses++; return false; }
    }

    public function get_multiple( $keys, $group = 'default', $force = false ) {
        $result = [];
        foreach ( $keys as $key ) { $result[ $key ] = $this->get( $key, $group, $force ); }
        return $result;
    }

    public function incr( $key, $offset = 1, $group = 'default' ) {
        $val = (int) $this->get( $key, $group ) + $offset;
        $this->set( $key, $val, $group );
        return $val;
    }

    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        if ( $this->get( $key, $group ) === false ) { return false; }
        return $this->set( $key, $data, $group, $expire );
    }

    public function reset() { $this->cache = []; }

    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        $rkey  = $this->build_key( $key, $group );
        $value = is_object( $data ) ? clone $data : $data;
        $this->cache[ $rkey ] = $value;
        if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->connected ) { return true; }
        try {
            $s = maybe_serialize( $value );
            if ( $expire > 0 ) { $this->redis->setex( $rkey, $expire, $s ); }
            else                { $this->redis->set( $rkey, $s ); }
        } catch ( Exception $e ) {}
        return true;
    }

    public function set_multiple( $data, $group = 'default', $expire = 0 ) {
        $result = [];
        foreach ( $data as $k => $v ) { $result[ $k ] = $this->set( $k, $v, $group, $expire ); }
        return $result;
    }

    public function stats() {
        echo '<p><strong>Cache Hits:</strong> ' . $this->cache_hits . '<br />';
        echo '<strong>Cache Misses:</strong> ' . $this->cache_misses . '</p>';
    }

    public function switch_to_blog( $blog_id ) {}
    public function delete_multiple( $keys, $group = 'default' ) {
        $result = [];
        foreach ( $keys as $key ) { $result[ $key ] = $this->delete( $key, $group ); }
        return $result;
    }
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->add( $key, $data, $group, $expire ); }
function wp_cache_add_global_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_global_groups( $groups ); }
function wp_cache_add_non_persistent_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_non_persistent_groups( $groups ); }
function wp_cache_close() { return true; }
function wp_cache_decr( $key, $offset = 1, $group = '' ) { global $wp_object_cache; return $wp_object_cache->decr( $key, $offset, $group ); }
function wp_cache_delete( $key, $group = '' ) { global $wp_object_cache; return $wp_object_cache->delete( $key, $group ); }
function wp_cache_delete_multiple( $keys, $group = '' ) { global $wp_object_cache; return $wp_object_cache->delete_multiple( $keys, $group ); }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }
function wp_cache_flush_runtime() { global $wp_object_cache; $wp_object_cache->reset(); return true; }
function wp_cache_flush_group( $group ) { return true; }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { global $wp_object_cache; return $wp_object_cache->get( $key, $group, $force, $found ); }
function wp_cache_get_multiple( $keys, $group = '', $force = false ) { global $wp_object_cache; return $wp_object_cache->get_multiple( $keys, $group, $force ); }
function wp_cache_incr( $key, $offset = 1, $group = '' ) { global $wp_object_cache; return $wp_object_cache->incr( $key, $offset, $group ); }
function wp_cache_init() { global $wp_object_cache; $wp_object_cache = new WP_Object_Cache(); }
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->replace( $key, $data, $group, $expire ); }
function wp_cache_reset() { global $wp_object_cache; $wp_object_cache->reset(); }
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->set( $key, $data, $group, $expire ); }
function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->set_multiple( $data, $group, $expire ); }
function wp_cache_switch_to_blog( $blog_id ) { global $wp_object_cache; $wp_object_cache->switch_to_blog( $blog_id ); }
