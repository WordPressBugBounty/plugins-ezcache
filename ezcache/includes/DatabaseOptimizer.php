<?php

namespace Upress\EzCache;

/**
 * One-click database cleanup, similar to WP Rocket's Database tab.
 *
 * Available cleanup tasks:
 *  - Revisions, auto-drafts, trashed posts
 *  - Spam and trashed comments
 *  - Expired transients
 *  - Optimize one small MyISAM table per run
 *
 * Each task is opt-in via the corresponding setting flag and `clean()` returns
 * a per-task summary so the UI can show what was cleaned up.
 */
class DatabaseOptimizer {

	/**
	 * Run the cleanup tasks enabled by the user.
	 *
	 * @param array $tasks Optional explicit list of tasks; defaults to all enabled in settings.
	 * @return array Per task counts.
	 */
    public function clean( $tasks = null ) {
        global $wpdb;
        $lock = Utilities\Lock::acquire('database-cleanup-' . get_current_blog_id());
        if (!$lock) { return ['busy'=>true]; }
        try {
            $settings = Settings::get_settings(); $results = []; $deadline = microtime(true)+8;
            $enabled = function($key) use($tasks,$settings) { return is_array($tasks) ? in_array($key,$tasks,true) : !empty($settings->$key); };
            foreach ([ 'revisions'=>"post_type = 'revision'", 'auto_drafts'=>"post_status = 'auto-draft'", 'trashed_posts'=>"post_status = 'trash'" ] as $name=>$where) {
                if (!$enabled('db_cleanup_'.$name)) { continue; }
                $results[$name]=0;
                $ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID LIMIT 100");
                foreach($ids as $id) { if(microtime(true)>$deadline){break;} if(wp_delete_post((int)$id,true)){$results[$name]++;} }
                if(count($ids)===100 || microtime(true)>$deadline){$results['has_more']=true;}
            }
            foreach(['spam_comments'=>"comment_approved = 'spam'",'trashed_comments'=>"comment_approved IN ('trash','post-trashed')"] as $name=>$where){
                if(!$enabled('db_cleanup_'.$name)){continue;}$results[$name]=0;
                $ids=$wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments} WHERE {$where} ORDER BY comment_ID LIMIT 100");
                foreach($ids as $id){if(microtime(true)>$deadline){break;}if(wp_delete_comment((int)$id,true)){$results[$name]++;}}
                if(count($ids)===100 || microtime(true)>$deadline){$results['has_more']=true;}
            }
            if($enabled('db_cleanup_expired_transients') && microtime(true)<$deadline){
                $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 200",$wpdb->esc_like('_transient_timeout_').'%',time()));
                $results['expired_transients']=0;
                foreach($names as $name){if(delete_transient(substr($name,19))){$results['expired_transients']++;}}
                if(count($names)===200){$results['has_more']=true;}
            }
            if($enabled('db_cleanup_orphan_postmeta') && microtime(true)<$deadline){
                $ids=$wpdb->get_col("SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL LIMIT 200");
                $results['orphan_postmeta']=0;
                foreach($ids as $id){if(delete_metadata_by_mid('post',(int)$id)){$results['orphan_postmeta']++;}}
                if(count($ids)===200){$results['has_more']=true;}
            }
            if($enabled('db_optimize_tables')){
                // Rebuilding large InnoDB tables in a web request can block the site.
                // Only small MyISAM tables are maintained here, at most one per run.
                $tables=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'));
                $results['optimized_tables']=0;$results['optimization_skipped']=0;
                foreach($tables as $table){
                    if($results['optimized_tables'] || microtime(true)>$deadline || $table->Engine!=='MyISAM' || $table->Data_length+$table->Index_length>10*1024*1024){$results['optimization_skipped']++;continue;}
                    $name=str_replace('`','``',$table->Name);
                    if(false!==$wpdb->query("OPTIMIZE TABLE `{$name}`")){$results['optimized_tables']++;}
                }
            }
            Cache::instance()->clear_cache();
            return $results;
        } finally { Utilities\Lock::release($lock); }
    }

	/**
	 * Schedule a recurring cleanup based on the settings.
	 */
	public function schedule() {
        if (Settings::is_disabled()) { wp_clear_scheduled_hook('ezcache_db_cleanup'); return; }
		$settings = Settings::get_settings();

		$timestamp = wp_next_scheduled( 'ezcache_db_cleanup' );
		if ( empty( $settings->db_cleanup_schedule ) || 'never' === $settings->db_cleanup_schedule ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'ezcache_db_cleanup' );
			}
			return;
		}

		        $schedules = wp_get_schedules();
        if (!isset($schedules[$settings->db_cleanup_schedule])) { return; }
        $schedule = wp_get_schedule('ezcache_db_cleanup');
        if ($schedule && $schedule !== $settings->db_cleanup_schedule) { wp_clear_scheduled_hook('ezcache_db_cleanup'); $timestamp=false; }
		if ( ! $timestamp ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, $settings->db_cleanup_schedule, 'ezcache_db_cleanup' );
		}
	}
}
