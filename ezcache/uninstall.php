<?php
// WordPress only calls this after the administrator explicitly deletes the plugin.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
require_once __DIR__.'/vendor/autoload.php';

use Upress\EzCache\Cache;
use Upress\EzCache\RedisObjectCache;
use Upress\EzCache\Rest\WebpController;

$cleanup = function() {
    global $wpdb;
    foreach (['ezcache_clear_expired_cache','ezcache_purge_trash','ezcache_preload_process_queue','ezcache_db_cleanup','ezcache_webp_process_batch','ezcache_convert_images_to_webp_hook','ezcache_convert_images_to_webp_cron'] as $hook) { wp_clear_scheduled_hook($hook); }
    $table=$wpdb->prefix.'ezcache_webp_images';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table) {
        $deadline=microtime(true)+20;
        do {
            $result=(new WebpController())->destroy();
            $data=is_wp_error($result)?[]:$result['data'];
        } while (!empty($data['remaining']) && !empty($data['processed']) && microtime(true)<$deadline);
        // Remaining image derivatives can safely stay; never delete original media.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
    foreach (['ezcache_clear_expired_cache','ezcache_purge_trash','ezcache_preload_process_queue','ezcache_db_cleanup','ezcache_webp_process_batch','ezcache_convert_images_to_webp_hook','ezcache_convert_images_to_webp_cron'] as $hook) { wp_clear_scheduled_hook($hook); }
    $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('ezcache_').'%'));
    foreach($names as $name) { delete_option($name); }
    delete_site_option('ezcache_convert_images_to_webp_convert_queue');
    delete_site_option('ezcache_first_run');
    delete_transient('ezcache_activating'); delete_transient('ezcache_deactivating');
};
if (is_multisite()) {
    $offset=0;
    do {
        $ids=get_sites(['fields'=>'ids','number'=>100,'offset'=>$offset]);
        foreach($ids as $id) { switch_to_blog($id); $cleanup(); restore_current_blog(); }
        $offset+=count($ids);
    } while(count($ids)===100);
} else { $cleanup(); }
RedisObjectCache::flush();
RedisObjectCache::remove_dropin();
$advanced=WP_CONTENT_DIR.'/advanced-cache.php';
if (is_file($advanced) && strpos((string)file_get_contents($advanced),'ezCache Advanced Cache')!==false) { @unlink($advanced); }
$cache=Cache::instance();
foreach(['cache/ezcache','ezcache-backups','ezcache/critical-css','ezcache-locks'] as $name) { $cache->rmdir_recursive(WP_CONTENT_DIR.'/'.$name,microtime(true)+5); }
foreach(['ezcache-config.php','ezcache-config.json','ezcache-disabled.php','cache/ezcache-redis-down','cache/ezcache-redis-pages-purged'] as $name) { if (!is_link(WP_CONTENT_DIR.'/'.$name)) { @unlink(WP_CONTENT_DIR.'/'.$name); } }
