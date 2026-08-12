<?php

namespace Upress\EzCache;

use Exception;
use Upress\EzCache\Utilities\Logger;
use wpdb;

class Updater {
	/** @var string $current_version */
	protected static $current_version;
	/** @var string $collate */
	protected static $collate;

	protected static function wpdb() {
		global $wpdb;
	}

	public static function uninstall() {
		global $wpdb;

		Cache::instance()->clear_cache( true );
		$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}ezcache_webp_images`");
		delete_option( 'ezcache_version' );
	}

	/**
	 * Run any necessary db updates, file upgrades etc.
	 */
	public static function upgrade() {
		global $wpdb;

		self::$current_version = get_option( 'ezcache_version', 0 );
		self::$collate = $wpdb->get_charset_collate();

		/** @noinspection PhpIncludeInspection */
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		try {
			if ( version_compare( self::$current_version, '0.1-20190811', '<' ) ) {
				self::update_0_1_20190811();
			}

			if ( version_compare( self::$current_version, '0.1-20190812', '<' ) ) {
				self::update_0_1_20190812();
			}

			if ( version_compare( self::$current_version, '1.5', '<' ) ) {
				self::update_1_5();
			}

			if ( version_compare( self::$current_version, '2.0.0', '<' ) ) {
				self::update_2_0();
			}

			// Always verify the required tables exist and are up to date. This is
			// intentionally NOT gated behind a version match: updates deployed by
			// replacing files (no deactivate/activate cycle) never fire the activation
			// hook, so an unconditional verify_tables() is what lets a missing table
			// self-heal on the next run instead of staying broken forever.
			self::verify_tables();

			// A plugin update can change generated markup/minification, so flush the
			// page cache once per version change. This ensures stale or corrupted
			// cached pages (e.g. JSON-LD altered by an older inline-JS minifier) are
			// regenerated on the next request instead of persisting until TTL expiry.
			if ( version_compare( (string) self::$current_version, EZCACHE_VERSION, '!=' ) && class_exists( '\\Upress\\EzCache\\Cache' ) ) {
				Cache::instance()->clear_cache();
			}

			// make sure we update the version in the database so we can run upgrades at later times
			update_option( 'ezcache_version', EZCACHE_VERSION );
		} catch ( Exception $ex ) {
			Logger::log( 'ezCache Updater Error: ' . $ex );
			wp_die( $ex->getMessage() );
		}
	}

	/**
	 * Run the upgrade routine when the stored DB version doesn't match the code
	 * version. Hooked on admin_init so that file-replacement updates (which skip
	 * the activation hook) still create/verify tables the first time an admin
	 * page loads, without waiting for a deactivate/activate cycle.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ezcache_version' ) !== EZCACHE_VERSION ) {
			self::upgrade();
		}
	}

	/**
	 * Ensure the plugin's tables exist without running the full upgrade flow and
	 * without wp_die() on failure. Used as a last-resort self-heal from REST
	 * endpoints (e.g. the WebP scan) that need the table to be present.
	 */
	public static function ensure_tables() {
		global $wpdb;

		self::$current_version = get_option( 'ezcache_version', 0 );
		self::$collate         = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		try {
			self::verify_tables();
		} catch ( Exception $ex ) {
			Logger::log( 'ezCache ensure_tables error: ' . $ex->getMessage() );
		}
	}

	/**
	 * Verify all tables exists and run their creation if not
	 * @throws Exception
	 */
	protected static function verify_tables() {
		global $wpdb;

		if ( ! $wpdb->get_row( "SHOW TABLES LIKE '{$wpdb->prefix}ezcache_webp_images'" ) ) {
			// this creates the table
			self::update_0_1_20190811();
		}

		// and this makes sure the table has the correct columns
		self::update_0_1_20190812();
	}

	/**
	 * Update to the 1.0 version
	 * create the 404 database
	 * @throws Exception
	 */
	protected static function update_0_1_20190811() {
		global $wpdb;

		// Note: TEXT columns must not carry a DEFAULT (rejected before MySQL 8.0.13)
		// and datetime columns must not default to the zero date '0000-00-00'
		// (rejected under the NO_ZERO_DATE / strict SQL mode used by MySQL 8+).
		// Both are made nullable — every INSERT already sets these values explicitly.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ezcache_webp_images` (
			`id` bigint(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			`uid` varchar(191) NOT NULL DEFAULT '',
			`url` text NULL,
			`webp_url` text NULL,
			`status` enum('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
			`created_at` datetime NULL DEFAULT NULL,
			`updated_at` datetime NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE INDEX (`uid`) USING BTREE
		) ". ( self::$collate ) );

		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception( $wpdb->last_error );
		}
	}

	/**
	 * Update to the 1.0 version
	 * @throws Exception
	 */
	protected static function update_0_1_20190812() {
		global $wpdb;

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}ezcache_webp_images`", 0 );
		if( ! in_array( 'path', $cols ) ) {
			$wpdb->query( "ALTER TABLE `{$wpdb->prefix}ezcache_webp_images`
				ADD COLUMN `path` text NULL AFTER `webp_url`,
				ADD COLUMN `webp_path` text NULL AFTER `path`,
				ADD COLUMN `original_size` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `webp_path`,
				ADD COLUMN `webp_size` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `original_size`
			" );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception( $wpdb->last_error );
		}
	}

	/**
	 * Update to the 1.4.2 version
	 * @throws Exception
	 */
	protected static function update_1_5() {
		global $wpdb;

		delete_site_option( 'ezcache_convert_images_to_webp_reprocess_queue' );
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE 'wp_ezcache_convert_images_to_webp_batch_%'");

		if ( wp_next_scheduled( 'ezcache_convert_images_to_webp_cron' ) ) {
			wp_unschedule_hook( 'ezcache_convert_images_to_webp_cron' );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception( $wpdb->last_error );
		}
	}


	protected static function update_2_0() {
		global $wpdb;
		$table = $wpdb->prefix . "ezcache_webp_images";

		if ( ! $wpdb->get_row( "SHOW TABLES LIKE '{$table}'" ) ) {
			self::update_0_1_20190811();
			self::update_0_1_20190812();
			return;
		}

		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
		foreach ( $cols as $col ) {
			if ( $col->Field === "original_size" && strpos( $col->Type, "bigint" ) === false ) {
				$wpdb->query( "ALTER TABLE `{$table}` MODIFY `original_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0" );
			}
			if ( $col->Field === "webp_size" && strpos( $col->Type, "bigint" ) === false ) {
				$wpdb->query( "ALTER TABLE `{$table}` MODIFY `webp_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0" );
			}
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Column_name = 'status'" );
		if ( empty( $indexes ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `status_idx` (`status`)" );
		}
	}

}