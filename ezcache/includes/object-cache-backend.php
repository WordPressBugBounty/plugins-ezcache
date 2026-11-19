<?php
/**
 * ezCache Redis object cache: the WP_Object_Cache class and the wp_cache_*() functions.
 *
 * Loaded by wp-content/object-cache.php (see object-cache-dropin.php) only after
 * Redis answered. Do not copy these declarations into the drop-in itself.
 */
if ( ! defined('ABSPATH') ) { exit; }

class WP_Object_Cache {
    public $cache_hits = 0;
    public $cache_misses = 0;
    public $global_groups = [];
    public $blog_prefix = '';
    private $cache = [];
    private $expires = [];
    private $non_persistent_groups = [];
    private $redis;
    private $multisite;

    public function __construct() {
        $this->multisite = function_exists('is_multisite') && is_multisite();
        $this->switch_to_blog($GLOBALS['blog_id'] ?? 1);
        $this->redis = \Upress\EzCache\RedisConnection::shared();
    }
    private function valid_key($key) { return is_int($key) || (is_string($key) && $key !== ''); }
    private function group($group) { return $group === '' ? 'default' : (string)$group; }
    private function key($key,$group) {
        $scope = isset($this->global_groups[$group]) ? 'global:' : $this->blog_prefix;
        return \Upress\EzCache\RedisConnection::prefix() . 'obj:' . $scope . rawurlencode($group) . ':' . hash('sha256',(string)$key);
    }
    private function persistent($group) { return $this->redis && !isset($this->non_persistent_groups[$group]); }
    private function remember($key,$data,$ttl=0) {
        $this->cache[$key] = is_object($data) ? clone $data : $data;
        $this->expires[$key] = $ttl > 0 ? microtime(true)+$ttl : 0;
    }
    public function add_global_groups($groups) {
        foreach((array)$groups as $group) { $this->global_groups[(string)$group]=true; }
    }
    public function add_non_persistent_groups($groups) {
        foreach((array)$groups as $group) { $this->non_persistent_groups[(string)$group]=true; }
    }
    public function switch_to_blog($blog_id) { $this->blog_prefix = 'blog:' . ($this->multisite ? (int)$blog_id : 1) . ':'; }
    public function get($key,$group='default',$force=false,&$found=null) {
        $found=false;
        if(!$this->valid_key($key)){return false;}
        $group=$this->group($group);$rkey=$this->key($key,$group);
        if(isset($this->expires[$rkey]) && $this->expires[$rkey]>0 && $this->expires[$rkey]<=microtime(true)) {unset($this->cache[$rkey],$this->expires[$rkey]);}
        if((!$force || !$this->persistent($group)) && array_key_exists($rkey,$this->cache)) {
            $found=true;$this->cache_hits++;return is_object($this->cache[$rkey])?clone $this->cache[$rkey]:$this->cache[$rkey];
        }
        if($this->persistent($group)) {
            $raw=false;$ttl=-2;
            try {
                $raw=$this->redis->get($rkey);
                if($raw!==false){$ttl=$this->redis->pttl($rkey);}
            } catch(\Throwable $e) { $raw=false; $this->lost(); /* and fall through to a cache miss */ }
            if($raw!==false && $ttl!==-2){
                // A value that cannot be read back (its class changed, for instance) is a miss
                // for this key, not a failure of Redis.
                $readable=true;
                try { $data=unserialize($raw); } catch(\Throwable $e) { $readable=false; }
                if($readable){
                    $this->remember($rkey,$data,$ttl>0?$ttl/1000:0);
                    $found=true;$this->cache_hits++;return is_object($data)?clone $data:$data;
                }
            }
        }
        unset($this->cache[$rkey],$this->expires[$rkey]);$this->cache_misses++;return false;
    }
    private function store($key,$data,$group,$expire,$condition=null) {
        if(!$this->valid_key($key)){return false;}
        $group=$this->group($group);$rkey=$this->key($key,$group);$expire=max(0,(int)$expire);
        if($this->persistent($group)) {
            $options=[];if($expire>0){$options['ex']=$expire;}if($condition){$options[]=$condition;}
            // Not every value can be stored (one that holds a Closure, for instance). That is
            // the value's problem, not a failure of Redis.
            try { $payload=serialize($data); } catch(\Throwable $e) { unset($this->cache[$rkey],$this->expires[$rkey]); return false; }
            try {
                if(!$this->redis->set($rkey,$payload,$options)){
                    unset($this->cache[$rkey],$this->expires[$rkey]);
                    // A refused plain SET must not leave the previous value behind to be read
                    // back as current. NX/XX report "condition not met" the same way, and there
                    // the stored value is the right one.
                    if(!$condition){$this->redis->del($rkey);}
                    return false;
                }
            } catch(\Throwable $e){
                unset($this->cache[$rkey],$this->expires[$rkey]);
                // phpredis reports a refused write (Redis out of memory, a read-only replica)
                // by throwing, while Redis itself still answers. Take the old value away so it
                // is not read back as current (after a failed add() it is still the right
                // one). Only when that fails as well is the connection really gone.
                try { if('nx'!==$condition){$this->redis->del($rkey);} } catch(\Throwable $e2){ $this->lost(); }
                return false;
            }
        } elseif($condition) {
            $this->get($key,$group,false,$found);
            if(($condition==='nx' && $found)||($condition==='xx' && !$found)){return false;}
        }
        $this->remember($rkey,$data,$expire);return true;
    }
    public function set($key,$data,$group='default',$expire=0){return $this->store($key,$data,$group,$expire);}
    public function add($key,$data,$group='default',$expire=0){return $this->store($key,$data,$group,$expire,'nx');}
    public function replace($key,$data,$group='default',$expire=0){return $this->store($key,$data,$group,$expire,'xx');}
    public function delete($key,$group='default',$deprecated=false) {
        if(!$this->valid_key($key)){return false;}
        $group=$this->group($group);$rkey=$this->key($key,$group);$found=array_key_exists($rkey,$this->cache);
        unset($this->cache[$rkey],$this->expires[$rkey]);
        if($this->persistent($group)){try{return (bool)$this->redis->del($rkey);}catch(\Throwable $e){$this->lost();return false;}}
        return $found;
    }
    private function change($key,$offset,$group) {
        if(!$this->valid_key($key)){return false;}
        $group=$this->group($group);$rkey=$this->key($key,$group);
        if($this->persistent($group)) {
            // Redis serializes this read-modify-write and retains the remaining TTL.
            $script=<<<'LUA'
local v=redis.call('GET',KEYS[1]); if not v then return false end
local raw=string.match(v,'^i:([%-]?%d+);$') or string.match(v,'^d:([%+%-]?[%d%.]+);$') or string.match(v,'^s:%d+:"([%+%-]?[%d%.]+)";$')
local n=math.max(0,math.floor(tonumber(raw) or 0)+tonumber(ARGV[1]))
local ttl=redis.call('PTTL',KEYS[1]); redis.call('SET',KEYS[1],'i:'..string.format('%.0f',n)..';')
if ttl>0 then redis.call('PEXPIRE',KEYS[1],ttl) end
return n
LUA;
            try{$value=$this->redis->eval($script,[$rkey,(int)$offset],1);}catch(\Throwable $e){$this->lost();return false;}
            unset($this->cache[$rkey],$this->expires[$rkey]);return $value===false?false:(int)$value;
        }
        $value=$this->get($key,$group,false,$found);if(!$found){return false;}
        $value=max(0,(int)$value+(int)$offset);$this->cache[$rkey]=$value;return $value;
    }
    public function incr($key,$offset=1,$group='default'){return $this->change($key,abs((int)$offset),$group);}
    public function decr($key,$offset=1,$group='default'){return $this->change($key,-abs((int)$offset),$group);}
    public function disable_persistence(){ $this->redis=false; return $this->reset(); }
    /**
     * Redis stopped answering in the middle of a request. Finish the request in
     * memory instead of paying a timeout on every further call, and let the next
     * requests skip Redis for RedisConnection::RETRY_SECONDS (they then run on
     * WordPress's own cache, with transients in the database).
     */
    private function lost(){ $this->redis=false; \Upress\EzCache\RedisConnection::mark_down(); }
    public function reset(){ $this->cache=[];$this->expires=[];return true; }
    public function flush_runtime(){ return $this->reset(); }
    public function flush(){
        $this->reset();if(!$this->redis){return true;}
        try{return \Upress\EzCache\RedisConnection::delete_pattern($this->redis,\Upress\EzCache\RedisConnection::prefix().'obj:*');}catch(\Throwable $e){return false;}
    }
    public function flush_group($group){
        $group=$this->group($group);$key=$this->key('',$group);$prefix=substr($key,0,strrpos($key,':')+1);
        foreach(array_keys($this->cache) as $cached){if(strpos($cached,$prefix)===0){unset($this->cache[$cached],$this->expires[$cached]);}}
        if(!$this->persistent($group)){return true;}
        try{return \Upress\EzCache\RedisConnection::delete_pattern($this->redis,$prefix.'*');}catch(\Throwable $e){return false;}
    }
    public function get_multiple($keys,$group='default',$force=false){$r=[];foreach($keys as $key){$r[$key]=$this->get($key,$group,$force);}return $r;}
    public function set_multiple($data,$group='default',$expire=0){$r=[];foreach($data as $key=>$value){$r[$key]=$this->set($key,$value,$group,$expire);}return $r;}
    public function add_multiple($data,$group='default',$expire=0){$r=[];foreach($data as $key=>$value){$r[$key]=$this->add($key,$value,$group,$expire);}return $r;}
    public function delete_multiple($keys,$group='default'){$r=[];foreach($keys as $key){$r[$key]=$this->delete($key,$group);}return $r;}
    public function stats(){echo '<p>Cache hits: '.(int)$this->cache_hits.'; misses: '.(int)$this->cache_misses.'</p>';}
}
function wp_cache_init(){global $wp_object_cache;$wp_object_cache=new WP_Object_Cache();}
function wp_cache_add($key,$data,$group='',$expire=0){global $wp_object_cache;return $wp_object_cache->add($key,$data,$group,$expire);}
function wp_cache_set($key,$data,$group='',$expire=0){global $wp_object_cache;return $wp_object_cache->set($key,$data,$group,$expire);}
function wp_cache_replace($key,$data,$group='',$expire=0){global $wp_object_cache;return $wp_object_cache->replace($key,$data,$group,$expire);}
function wp_cache_get($key,$group='',$force=false,&$found=null){global $wp_object_cache;return $wp_object_cache->get($key,$group,$force,$found);}
function wp_cache_delete($key,$group=''){global $wp_object_cache;return $wp_object_cache->delete($key,$group);}
function wp_cache_incr($key,$offset=1,$group=''){global $wp_object_cache;return $wp_object_cache->incr($key,$offset,$group);}
function wp_cache_decr($key,$offset=1,$group=''){global $wp_object_cache;return $wp_object_cache->decr($key,$offset,$group);}
function wp_cache_flush(){global $wp_object_cache;return $wp_object_cache->flush();}
function wp_cache_flush_runtime(){global $wp_object_cache;return $wp_object_cache->flush_runtime();}
function wp_cache_flush_group($group){global $wp_object_cache;return $wp_object_cache->flush_group($group);}
function wp_cache_reset(){global $wp_object_cache;return $wp_object_cache->reset();}
function wp_cache_switch_to_blog($blog_id){global $wp_object_cache;$wp_object_cache->switch_to_blog($blog_id);}
function wp_cache_add_global_groups($groups){global $wp_object_cache;$wp_object_cache->add_global_groups($groups);}
function wp_cache_add_non_persistent_groups($groups){global $wp_object_cache;$wp_object_cache->add_non_persistent_groups($groups);}
function wp_cache_get_multiple($keys,$group='',$force=false){global $wp_object_cache;return $wp_object_cache->get_multiple($keys,$group,$force);}
function wp_cache_set_multiple($data,$group='',$expire=0){global $wp_object_cache;return $wp_object_cache->set_multiple($data,$group,$expire);}
function wp_cache_add_multiple($data,$group='',$expire=0){global $wp_object_cache;return $wp_object_cache->add_multiple($data,$group,$expire);}
function wp_cache_delete_multiple($keys,$group=''){global $wp_object_cache;return $wp_object_cache->delete_multiple($keys,$group);}
function wp_cache_close(){return true;}
function wp_cache_supports($feature){return in_array($feature,['add_multiple','set_multiple','get_multiple','delete_multiple','flush_runtime','flush_group'],true);}
