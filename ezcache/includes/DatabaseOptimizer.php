<?php

namespace Upress\EzCache;

/**
 * One-click database cleanup, similar to WP Rocket's Database tab.
 *
 * Available cleanup tasks:
 *  - Revisions, auto-drafts, trashed posts
 *  - Spam and trashed comments
 *  - Expired transients
 *  - Optimize MyISAM/InnoDB tables (`OPTIMIZE TABLE`)
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

		$settings = Settings::get_settings();
		$results  = [];

		$enabled = function ( $key ) use ( $tasks, $settings ) {
			if ( is_array( $tasks ) ) {
				return in_array( $key, $tasks, true );
			}
			return ! empty( $settings->{ $key } );
		};

		if ( $enabled( 'db_cleanup_revisions' ) ) {
			$results['revisions'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		}

		if ( $enabled( 'db_cleanup_auto_drafts' ) ) {
			$results['auto_drafts'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		}

		if ( $enabled( 'db_cleanup_trashed_posts' ) ) {
			$results['trashed_posts'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		}

		if ( $enabled( 'db_cleanup_spam_comments' ) ) {
			$results['spam_comments'] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
		}

		if ( $enabled( 'db_cleanup_trashed_comments' ) ) {
			$results['trashed_comments'] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' OR comment_approved = 'post-trashed'" );
		}

		if ( $enabled( 'db_cleanup_expired_transients' ) ) {
			$now     = time();
			$results['expired_transients'] = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
					 WHERE a.option_name LIKE %s
					 AND a.option_name NOT LIKE %s
					 AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					 AND b.option_value < %d",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					$now
				)
			);
		}

		if ( $enabled( 'db_cleanup_orphan_postmeta' ) ) {
			$results['orphan_postmeta'] = (int) $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		}

		if ( $enabled( 'db_optimize_tables' ) ) {
			$tables    = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
			$optimized = 0;
			foreach ( $tables as $table ) {
				if ( false !== $wpdb->query( "OPTIMIZE TABLE `{$table}`" ) ) {
					$optimized++;
				}
			}
			$results['optimized_tables'] = $optimized;
		}

		// Clear post cache so updates are reflected immediately.
		Cache::instance()->clear_cache();

		return $results;
	}

	/**
	 * Schedule a recurring cleanup based on the settings.
	 */
	public function schedule() {
		$settings = Settings::get_settings();

		$timestamp = wp_next_scheduled( 'ezcache_db_cleanup' );
		if ( empty( $settings->db_cleanup_schedule ) || 'never' === $settings->db_cleanup_schedule ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'ezcache_db_cleanup' );
			}
			return;
		}

		if ( ! $timestamp ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, $settings->db_cleanup_schedule, 'ezcache_db_cleanup' );
		}
	}
}
