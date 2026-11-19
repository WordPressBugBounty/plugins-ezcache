<?php
namespace Upress\EzCache\Rest;

use Upress\EzCache\Cache;
use Upress\EzCache\Settings;
use Upress\EzCache\Updater;
use Upress\EzCache\WebpApi;
use Upress\EzCache\Utilities\AtomicFile;
use Upress\EzCache\Utilities\Cron;
use Upress\EzCache\Utilities\Lock;

class WebpController {
    const SCAN_OPTION = 'ezcache_webp_scan_cursor';
    const DELETE_OPTION = 'ezcache_webp_delete_pending';
    /** Set when images were converted and cached pages still show the originals. */
    const REFRESH_OPTION = 'ezcache_webp_refresh_due';
    /** When the page cache was last emptied because conversions finished. */
    const REFRESHED_OPTION = 'ezcache_webp_refreshed';
    /** The external service gets up to 8 s per image; a request is not started with less than this left. */
    const MIN_EXTERNAL_BUDGET = 4;
    /** Rows charged an attempt for a failure of the conversion service since it last answered. */
    const SUSPECTS_TRANSIENT = 'ezcache_webp_suspects';

    /**
     * image.jpg -> image.<6 hex of md5("jpg")>.webp, for a path or, with $is_url,
     * a URL (its query string or fragment is kept; in a file name "?" and "#" are
     * ordinary characters). FileOptimizer\WebpConverter::webp_name() is
     * the same function and must stay identical: the page optimizer cannot call
     * this class while PHP may still hold an older copy of it after an update.
     *
     * A name without an extension gets the suffix appended. The pattern used
     * before replaced "nothing" in that case and returned the original's own
     * name, so the converted data was written over the original image.
     */
    public static function make_webp_name($path, $is_url=false) {
        if (!is_string($path) || $path==='') { return ''; }
        $tail=''; $cut=$is_url ? strcspn($path,'?#') : strlen($path);
        if ($cut<strlen($path)) { $tail=substr($path,$cut); $path=substr($path,0,$cut); }
        $ext=pathinfo($path,PATHINFO_EXTENSION);
        $base=$ext==='' ? $path : substr($path,0,-strlen($ext)-1);
        return $base.'.'.substr(md5($ext),-6).'.webp'.$tail;
    }
    private static function table() { global $wpdb; return $wpdb->prefix.'ezcache_webp_images'; }
    private static function lock() { return Lock::acquire('webp-'.get_current_blog_id()); }
    private static function ensure_table() {
        global $wpdb; $table=self::table();
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
        $column=$exists ? $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'next_attempt'") : null;
        if (!$column) { Updater::ensure_tables(); $column=$wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'next_attempt'"); }
        return (bool)$column;
    }
    private static function count_pending() {
        global $wpdb; $table=self::table();
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status='pending'");
    }
    public function status() {
        if (!self::ensure_table()) { return new \WP_Error('ezcache_webp_no_table','WebP storage could not be prepared. Check database permissions.',['status'=>500]); }
        global $wpdb; $table=self::table();
        $row=$wpdb->get_row("SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status='pending') pending, SUM(status='failed') failed, SUM(CASE WHEN status='completed' THEN CAST(original_size AS SIGNED)-CAST(webp_size AS SIGNED) ELSE 0 END) saved_bytes FROM `{$table}`",ARRAY_A);
        if (!$row) { return new \WP_Error('webp_stats_failed','Could not read WebP statistics.',['status'=>500]); }
        $data=array_map('intval',$row ?: ['total'=>0,'completed'=>0,'pending'=>0,'failed'=>0,'saved_bytes'=>0]);
        $data['saved_bytes']=max(0,$data['saved_bytes']);
        $data['is_premium']=true; $data['scanning']=get_option(self::SCAN_OPTION,false)!==false;
        $data['deleting']=(bool)get_option(self::DELETE_OPTION,false);
        $data['local_encoder']=WebpApi::local_available();
        $data['external_enabled']=!empty(Settings::get_settings()->webp_external_api);
        return ['success'=>true,'data'=>$data];
    }
    public function scan() {
        $lock=self::lock();
        if (!$lock) { return new \WP_Error('webp_busy','WebP worker is busy.',['status'=>409]); }
        try {
            if (!self::ensure_table()) { return new \WP_Error('ezcache_webp_no_table','WebP storage could not be prepared. Check database permissions.',['status'=>500]); }
            if (get_option(self::DELETE_OPTION,false)) { return new \WP_Error('webp_deleting','Wait for WebP deletion to finish.',['status'=>409]); }
            if (get_option(self::SCAN_OPTION,false)===false) { update_option(self::SCAN_OPTION,0,false); }
            // The administrator asked for a fresh pass: images that failed earlier get another chance too.
            self::requeue_failed();
            $queued=self::scan_batch(); self::schedule_cron();
            return ['success'=>true,'data'=>['queued'=>$queued,'pending'=>self::count_pending(),'message'=>'Media scan queued; it continues in bounded background batches.']];
        } finally { Lock::release($lock); }
    }
    private static function scan_batch() {
        global $wpdb; $cursor=get_option(self::SCAN_OPTION,false);
        if ($cursor===false) { return 0; }
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') AND ID>%d ORDER BY ID LIMIT 100",(int)$cursor));
        $queued=0; $deadline=microtime(true)+3; $processed=0; $table=self::table();
        foreach($ids as $id) {
            if (microtime(true)>$deadline) { break; }
            $file=get_attached_file($id); $url=wp_get_attachment_url($id); $files=[$file=>$url];
            $meta=wp_get_attachment_metadata($id);
            foreach(array_slice($meta['sizes'] ?? [],0,100) as $size) {
                if (empty($size['file']) || basename($size['file'])!==$size['file']) { continue; }
                $files[dirname($file).'/'.$size['file']]=dirname($url).'/'.$size['file'];
            }
            foreach($files as $path=>$image_url) {
                if (!WebpApi::allowed_path($path) || !is_file($path)) { continue; }
                $changed=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$table}` (uid,url,path,webp_url,webp_path,status,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,'pending',%s,%s)",sha1($path),$image_url,$path,self::make_webp_name($image_url,true),self::make_webp_name($path),current_time('mysql'),current_time('mysql')));
                if ($changed) { $queued++; }
            }
            $cursor=(int)$id; $processed++; update_option(self::SCAN_OPTION,$cursor,false);
        }
        if (count($ids)<100 && $processed===count($ids)) { delete_option(self::SCAN_OPTION); }
        return $queued;
    }
    /**
     * Put failed images back in the queue with a clean attempt count. "Failed" used
     * to be final, so a queue that failed while the conversion service was down,
     * or while the server had no WebP encoder, stayed failed for good.
     *
     * @return int Rows re-queued.
     */
    public static function requeue_failed() {
        if (!self::ensure_table()) { return 0; }
        global $wpdb; $table=self::table();
        $rows=(int)$wpdb->query("UPDATE `{$table}` SET status='pending', attempts=0, next_attempt=0 WHERE status='failed'");
        if ($rows && !empty(Settings::get_settings()->enable_webp_support)) { self::schedule_cron(); }
        return $rows;
    }
    /**
     * Cached pages keep the original image until they are rendered again, so the
     * page cache is emptied once the queue is done. Not after every batch: that
     * emptied the whole cache (with Varnish, Redis and a preload run) once a
     * minute for as long as a backlog lasted. At most once per interval (filter
     * ezcache_webp_refresh_interval, seconds; 0 = never).
     *
     * @return int Seconds until it may run, 0 when done or nothing is due.
     */
    private static function refresh_pages_when_done() {
        if (!get_option(self::REFRESH_OPTION,false)) { return 0; }
        $interval=(int)apply_filters('ezcache_webp_refresh_interval',HOUR_IN_SECONDS);
        if ($interval<=0) { delete_option(self::REFRESH_OPTION); return 0; }
        $wait=(int)get_option(self::REFRESHED_OPTION,0)+$interval-time();
        if ($wait>0) { return $wait; }
        delete_option(self::REFRESH_OPTION); update_option(self::REFRESHED_OPTION,time(),false);
        Cache::instance()->clear_cache();
        return 0;
    }
    /** Cached pages must not keep pointing at WebP files that are being deleted. */
    private static function purge_pages() {
        $cache=Cache::instance(); $cache->discard_cache_contents(1); $cache->purge_varnish_cache();
        if (!empty(Settings::get_settings()->enable_redis_fullpage) && method_exists('\Upress\EzCache\RedisObjectCache','flush_pages')) { \Upress\EzCache\RedisObjectCache::flush_pages(); }
    }
    public function process() { $result=self::convert_batch(5);return is_wp_error($result)?$result:['success'=>true,'data'=>$result]; }
    public static function convert_batch($limit=5) {
        if (!self::ensure_table()) { return new \WP_Error('ezcache_webp_no_table','WebP storage could not be prepared. Check database permissions.',['status'=>500]); }
        global $wpdb; $lock=self::lock();
        if (!$lock) { return ['processed'=>0,'remaining'=>self::count_pending(),'busy'=>true]; }
        try {
            if (get_option(self::DELETE_OPTION,false)) { return self::delete_batch(); }
            self::scan_batch(); $table=self::table(); $processed=0; $deadline=microtime(true)+10;
            // Nothing on this server can produce WebP right now: no local encoder and the
            // external service is switched off. That says nothing about the images, so
            // no row is touched; look again in an hour.
            if (!WebpApi::is_available()) {
                $remaining=self::count_pending();
                if ($remaining || get_option(self::SCAN_OPTION,false)!==false) { self::schedule_cron(HOUR_IN_SECONDS); }
                return ['processed'=>0,'remaining'=>$remaining,'retry_after'=>HOUR_IN_SECONDS,'paused'=>true,'unavailable'=>true];
            }
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE status='pending' AND next_attempt<=%d ORDER BY id LIMIT %d",time(),max(1,min(5,(int)$limit))));
            $paused=false; $local=WebpApi::local_available();
            foreach($rows as $image) {
                if (Settings::is_disabled() || microtime(true)>$deadline-1) { break; }
                // Circuit breaker: the external service failed a moment ago and no local
                // encoder exists. Leave the rows untouched (no attempt is charged) and
                // come back in ten minutes instead of burning attempts on every image.
                if (!$local && WebpApi::external_paused()) { $paused=true; break; }
                // Do not start an upload that can only time out because this batch is nearly over.
                if (!$local && $deadline-microtime(true)<self::MIN_EXTERNAL_BUDGET) { break; }
                // Store the attempt BEFORE I/O: a killed worker cannot retry forever.
                $attempts=(int)$image->attempts+1;
                $wpdb->update($table,['attempts'=>$attempts,'next_attempt'=>time()+60],['id'=>$image->id]);
                $result=$attempts>3 ? new \WP_Error('retry_limit','Conversion retry limit reached.') : (new WebpApi())->convert($image->path,$deadline-microtime(true));
                // Always the name computed here, never the one stored in the row.
                $destination=self::make_webp_name($image->path);
                if (!is_wp_error($result) && (!WebpApi::allowed_path($destination,false) || !AtomicFile::write($destination,$result['data']))) {
                    $result=new \WP_Error('webp_write_failed','Cannot safely write the WebP image.');
                }
                if (is_wp_error($result)) {
                    $code=$result->get_error_code();
                    // No request was made (the service is paused, or this batch had no time left
                    // for one): the attempt is handed back and the batch stops.
                    if (in_array($code,['webp_api_paused','webp_no_time'],true)) {
                        $wpdb->update($table,['attempts'=>$attempts-1,'next_attempt'=>0],['id'=>$image->id]);
                        $paused='webp_api_paused'===$code; break;
                    }
                    // The service failed (network error, timeout, HTTP 5xx or 429). Whether that
                    // was the image or an outage shows only afterwards. The attempt stays for
                    // now; when the failures add up to a pause, every image charged since the
                    // service last answered gets its attempt back. An image that keeps failing
                    // while others convert is charged like any other and ends as "failed".
                    if (in_array($code,['webp_api_timeout','webp_api_error','webp_api_unreachable'],true)) {
                        // row id => attempts charged; an outage can catch the same image in two batches.
                        $suspects=get_transient(self::SUSPECTS_TRANSIENT);
                        $suspects=is_array($suspects) ? $suspects : [];
                        $suspects[(int)$image->id]=(int)($suspects[(int)$image->id] ?? 0)+1;
                        $suspects=array_slice($suspects,-50,null,true);
                        if (WebpApi::external_paused()) {
                            $by_count=[];
                            foreach($suspects as $id=>$count) { $by_count[max(1,(int)$count)][]=(int)$id; }
                            foreach($by_count as $count=>$ids) {
                                $wpdb->query("UPDATE `{$table}` SET status='pending', next_attempt=0, attempts=CASE WHEN attempts>{$count} THEN attempts-{$count} ELSE 0 END WHERE id IN (".implode(',',$ids).")");
                            }
                            delete_transient(self::SUSPECTS_TRANSIENT);
                            $paused=true; break;
                        }
                        set_transient(self::SUSPECTS_TRANSIENT,$suspects,DAY_IN_SECONDS);
                    } elseif ('invalid_webp_response'===$code) {
                        // The service answered: earlier failures were about those images.
                        delete_transient(self::SUSPECTS_TRANSIENT);
                    }
                    // Only what is wrong with the image itself is final at once; a failed
                    // write (disk full, permissions) or a timeout gets its three attempts.
                    $permanent=in_array($code,['invalid_input_file','invalid_input_image','image_memory_limit'],true);
                    $wpdb->update($table,['status'=>($attempts>=3 || $permanent)?'failed':'pending','last_error'=>substr($result->get_error_message(),0,250),'updated_at'=>current_time('mysql')],['id'=>$image->id]);
                    continue;
                }
                if ('external'===($result['info']['source'] ?? '')) { delete_transient(self::SUSPECTS_TRANSIENT); }
                clearstatcache(true,$destination);
                // webp_path/webp_url are rewritten: older versions stored a broken name for some extensions (.PNG, .JPEG, .jpe, .bmp).
                $wpdb->update($table,['status'=>'completed','last_error'=>'','webp_path'=>$destination,'webp_url'=>self::make_webp_name((string)$image->url,true),'original_size'=>filesize($image->path),'webp_size'=>filesize($destination),'updated_at'=>current_time('mysql')],['id'=>$image->id]);
                $processed++;
            }
            $remaining=self::count_pending(); $retry=$paused ? WebpApi::PAUSE_SECONDS : 60;
            if ($processed && !get_option(self::REFRESH_OPTION,false)) { update_option(self::REFRESH_OPTION,time(),false); }
            if ($remaining || get_option(self::SCAN_OPTION,false)!==false) { self::schedule_cron($retry); }
            else { $wait=self::refresh_pages_when_done(); if ($wait) { self::schedule_cron($wait); } }
            return ['processed'=>$processed,'remaining'=>$remaining,'retry_after'=>$retry,'paused'=>$paused];
        } finally { Lock::release($lock); }
    }
    public static function cron_process() {
        // Background conversion is part of the WebP feature. With the feature switched
        // off a queue left from earlier stays as it is; a deletion in progress goes on.
        if (empty(Settings::get_settings()->enable_webp_support) && !get_option(self::DELETE_OPTION,false)) { return ['processed'=>0,'disabled'=>true]; }
        return self::convert_batch();
    }
    public static function schedule_cron($delay = 60) {
        if (Settings::is_disabled()) { return; }
        // Cron::ensure also gets an event that is stuck in the past run and, when WordPress
        // does not spawn WP-Cron itself (DISABLE_WP_CRON), triggers one run.
        Cron::ensure('ezcache_webp_process_batch', $delay);
    }
    private static function delete_batch() {
        global $wpdb; $table=self::table(); $rows=$wpdb->get_results("SELECT id,path,webp_path FROM `{$table}` ORDER BY id LIMIT 200");
        $deleted=0;
        foreach($rows as $image) {
            $path=(string)$image->webp_path;
            // A file that cannot be removed must not keep the deletion, and with it the
            // whole WebP feature, waiting forever: its row goes, the file stays behind.
            if (is_file($path) && $path===self::make_webp_name($image->path) && WebpApi::allowed_path($path)) { @unlink($path); }
            $wpdb->delete($table,['id'=>$image->id]); $deleted++;
        }
        $remaining=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($remaining && $deleted) { self::schedule_cron(); }
        // Once more at the end: a page rendered while the files were going away may have been cached with them.
        if (!$remaining) { delete_option(self::DELETE_OPTION); delete_option(self::REFRESH_OPTION); self::purge_pages(); }
        return ['processed'=>$deleted,'remaining'=>$remaining,'message'=>$remaining?'Deletion is continuing in background batches.':'WebP images cleared.'];
    }
    public function destroy() {
        $lock=self::lock();
        if (!$lock) { return new \WP_Error('webp_busy','WebP worker is busy.',['status'=>409]); }
        try {
            delete_option(self::SCAN_OPTION); update_option(self::DELETE_OPTION,true,false);
            // Varnish and the Redis page cache too, not only the files; and while the
            // deletion runs the page optimizer leaves images alone (see WebpConverter).
            self::purge_pages(); $result=self::delete_batch();
            return ['success'=>true,'data'=>$result];
        } finally { Lock::release($lock); }
    }
}
