<?php

namespace Upress\EzCache;

use Exception;
use Upress\EzCache\Utilities\Logger;
use wpdb;

class Updater {
    private static function protect_legacy_config_backup() {
        $path=is_file(ABSPATH.'wp-config.php') ? ABSPATH.'wp-config.php.backup' : dirname(ABSPATH).'/wp-config.php.backup';
        if (!is_file($path) || is_link($path)) { return; }
        $raw=@file_get_contents($path);
        if ($raw===false) { return; }
        $target=WP_CONTENT_DIR.'/ezcache-protected/wp-config-legacy-'.bin2hex(random_bytes(12)).'.php';
        if (Utilities\AtomicFile::write($target,Settings::FILE_GUARD.$raw,0600)) { @unlink($path); }
    }

	/** @var string $current_version */
	protected static $current_version;
	/** @var string $collate */
	protected static $collate;

	protected static function wpdb() {
		global $wpdb;
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

			if ( version_compare( self::$current_version, '2.6.6', '<' ) ) {
				self::update_2_6_6();
			}

			// Always verify the required tables exist and are up to date. This is
			// intentionally NOT gated behind a version match: updates deployed by
			// replacing files (no deactivate/activate cycle) never fire the activation
			// hook, so an unconditional verify_tables() is what lets a missing table
			// self-heal on the next run instead of staying broken forever.
			// A database user without ALTER cannot add the WebP queue's columns. That
			// stops the WebP queue, not the rest of the update; ensure_tables() keeps
			// trying on admin requests, and the reason is shown on the plugin's screen.
			$table_error = null;
			try {
				self::verify_tables();
			} catch ( \Throwable $e ) {
				$table_error = $e->getMessage();
				Logger::log( 'ezCache Updater: tables could not be verified: ' . $table_error );
			}
            Cache::instance()->migrate_legacy_cache();
            if (!empty(Settings::get_settings()->enable_redis_object_cache)) { RedisObjectCache::maybe_deploy_dropin(); }
            (new Rest\SettingsController())->get_backup_dir();
            self::protect_legacy_config_backup();
            // Only now, with no process left on the previous version, does the old-format
            // settings file go (see Settings::get_settings()).
            $legacy_settings = WP_CONTENT_DIR . '/ezcache-config.json';
            if ( is_file( Settings::settings_file_path() ) && is_file( $legacy_settings ) && ! is_link( $legacy_settings ) ) { @unlink( $legacy_settings ); }
            self::refresh_advanced_cache();
            delete_site_option('ezcache_convert_images_to_webp_convert_queue');
            wp_clear_scheduled_hook('ezcache_convert_images_to_webp_hook');
            // Once: images that earlier versions marked "failed" for good (the conversion
            // service was down, or the server had no WebP encoder) return to the queue.
            if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < 272 && null === $table_error && method_exists( '\Upress\EzCache\Rest\WebpController', 'requeue_failed' ) ) {
                try { Rest\WebpController::requeue_failed(); } catch ( \Throwable $e ) { Logger::log( 'ezCache Updater: failed WebP images were not re-queued: ' . $e->getMessage() ); }
            }

			// Record the new version BEFORE flushing the page cache. The flush is
			// best-effort and must never keep the upgrade from completing: on a
			// cache with 1.28 million entries the old synchronous delete never
			// finished, the version was never recorded, and every admin request
			// re-ran the whole routine until the site went down.
			$version_changed = version_compare( (string) self::$current_version, EZCACHE_VERSION, '!=' );
			update_option( 'ezcache_version', EZCACHE_VERSION );
			// The schema marker is separate from the version on purpose: only this
			// routine writes it, so a site whose version was recorded by another
			// code path (see maybe_upgrade()) still gets the steps above.
			$schema_before = (int) get_option( self::SCHEMA_OPTION, 0 );
			if ( null === $table_error ) {
				update_option( self::SCHEMA_OPTION, self::SCHEMA );
				delete_transient( self::RETRY_TRANSIENT );
				delete_option( self::ERROR_OPTION );
			} else {
				// The schema stays unrecorded, so this routine comes back (every five minutes,
				// not on every request) until the table can be prepared; the failed images
				// are re-queued then.
				set_transient( self::RETRY_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );
				self::remember_error( 'The WebP image queue could not be prepared: ' . $table_error );
			}

			// A plugin update can change generated markup/minification, so flush the
			// page cache once per version change. This ensures stale or corrupted
			// cached pages (e.g. JSON-LD altered by an older inline-JS minifier) are
			// regenerated on the next request instead of persisting until TTL expiry.
			// clear_cache() moves the old files aside and deletes them in the background.
			// Also once for a schema step under an unchanged version number: an earlier
			// 2.7.1 build could store another page under the home page's key.
			if ( ( $version_changed || ( null === $table_error && $schema_before < self::SCHEMA ) ) && class_exists( '\\Upress\\EzCache\\Cache' ) ) {
				try {
					Cache::instance()->clear_cache();
				} catch ( \Throwable $e ) {
					Logger::log( 'ezCache: cache flush after upgrade failed: ' . $e->getMessage() );
				}
			}
		} catch ( \Throwable $ex ) {
			// Errors as well as exceptions: a failed step must not take down every
			// admin and admin-ajax request. Nothing is recorded, so the routine runs
			// again later; the pause keeps a persistent failure (for example a
			// database user without ALTER) from repeating on every request.
			Logger::log( 'ezCache Updater Error: ' . $ex );
			set_transient( self::RETRY_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );
			// The log is written only under WP_DEBUG; without this nobody would learn
			// that the update is failing and being retried every five minutes.
			self::remember_error( $ex->getMessage() );
			// While activating, the administrator is looking at the result: say why.
			if ( defined( 'EZCACHE_ACTIVATING' ) && EZCACHE_ACTIVATING ) {
				wp_die( esc_html( $ex->getMessage() ) );
			}
		}
	}

	/** Bump whenever upgrade() gains a step that has to run once on every site. */
	const SCHEMA = 272;
	const SCHEMA_OPTION = 'ezcache_schema';
	/** Set for five minutes after upgrade() failed. */
	const RETRY_TRANSIENT = 'ezcache_upgrade_retry';
	/** Which set of files the opcode cache was last reset for ("version:time of ezcache.php"). */
	const OPCACHE_OPTION = 'ezcache_opcache_reset';
	/** Why the last upgrade attempt failed: [ time, version, message ]. Shown on the plugin's admin screens. */
	const ERROR_OPTION = 'ezcache_upgrade_error';
	/** Test seam: stands in for PHP_SAPI. */
	public static $sapi = null;

	protected static function remember_error( $message ) {
		update_option( self::ERROR_OPTION, [ 'time' => time(), 'version' => EZCACHE_VERSION, 'message' => substr( (string) $message, 0, 300 ) ], false );
	}

	/**
	 * Run the upgrade routine when the stored DB version doesn't match the code
	 * version. Hooked on admin_init so that file-replacement updates (which skip
	 * the activation hook) still create/verify tables the first time an admin
	 * page loads, without waiting for a deactivate/activate cycle.
	 *
	 * Two things can go wrong right after the files were replaced, and both did
	 * on a live site (39 failed admin-ajax requests in 44 seconds):
	 *
	 * - PHP's opcode cache re-checks each file only every opcache.revalidate_freq
	 *   seconds, so for up to a minute a process can run this new file together
	 *   with old copies of the classes it calls ("Call to undefined method
	 *   Cache::migrate_legacy_cache()"). A dashboard update resets the cache;
	 *   WP-CLI, rsync and deploy agents do not. So: reset it ourselves, and do
	 *   not start until every file is older than the re-check window.
	 * - In the same window an old copy of this class can run under the new
	 *   version constant and record the new version without the new steps.
	 *   Hence the separate schema marker.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ezcache_version' ) === EZCACHE_VERSION && (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA ) {
			return;
		}
		if ( get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}
		self::reset_opcache();
		if ( ! self::code_is_settled() ) {
			return; // tried again on the next admin request, at most about a minute later
		}
		self::upgrade();
	}

	/**
	 * Every PHP file of the plugin, plus the plugin folder itself.
	 *
	 * @param string|null $dir Plugin folder; defaults to this installation.
	 * @return string[]
	 */
	protected static function code_files( $dir = null ) {
		$dir   = rtrim( null === $dir ? EZCACHE_DIR : $dir, '/\\' );
		$files = [];
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $entry ) {
				if ( $entry->isFile() && 'php' === strtolower( $entry->getExtension() ) ) {
					$files[] = $entry->getPathname();
				}
			}
		} catch ( \Throwable $e ) {
			return [];
		}

		return $files;
	}

	/**
	 * Drop the plugin's files from the opcode cache so that the next request
	 * compiles all of them from disk, instead of a mix of old and new copies
	 * for up to opcache.revalidate_freq seconds. Runs once per version.
	 *
	 * @param string|null $dir Plugin folder; defaults to this installation.
	 * @return int Files invalidated.
	 */
	public static function reset_opcache( $dir = null ) {
		// The command line has its own opcode cache, normally switched off. Invalidating
		// there does nothing for PHP-FPM, and recording the reset as done made PHP-FPM
		// skip its own: exactly the WP-CLI install this is meant for.
		$sapi = null === self::$sapi ? PHP_SAPI : self::$sapi;
		if ( 'cli' === $sapi || 'phpdbg' === $sapi ) {
			return 0;
		}
		if ( ! function_exists( 'opcache_invalidate' ) || ! filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 0;
		}
		// Once per set of files, not once per ten minutes: a site whose version stays
		// unrecorded (an upgrade that keeps failing, a network site nobody opens)
		// would otherwise throw its compiled files away again and again.
		$main  = rtrim( null === $dir ? EZCACHE_DIR : $dir, '/\\' ) . '/ezcache.php';
		$stamp = EZCACHE_VERSION . ':' . (int) max( (int) @filemtime( $main ), (int) @filectime( $main ) );
		// Remembered per server: on a site served by several machines the file times
		// differ from one to the next, and with a single shared value each of them
		// would reset again whenever another one had.
		$host = function_exists( 'gethostname' ) ? (string) gethostname() : '';
		$seen = get_option( self::OPCACHE_OPTION );
		$seen = is_array( $seen ) ? $seen : [];
		if ( ( $seen[ $host ] ?? '' ) === $stamp ) {
			return 0;
		}
		$seen[ $host ] = $stamp;
		update_option( self::OPCACHE_OPTION, array_slice( $seen, -20, null, true ), false );

		$count = 0;
		foreach ( self::code_files( $dir ) as $file ) {
			// Returns false (with a warning) when opcache.restrict_api forbids it.
			if ( @opcache_invalidate( $file, true ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * When the plugin's code last changed on disk: the newest modification or
	 * inode-change time of any PHP file (the inode time also catches archives
	 * and rsync runs that keep the original modification time).
	 *
	 * @param string|null $dir Plugin folder; defaults to this installation.
	 * @return int Unix time.
	 */
	public static function newest_code_change( $dir = null ) {
		$dir    = rtrim( null === $dir ? EZCACHE_DIR : $dir, '/\\' );
		// A time in the future (an archive packed in another time zone, a restored
		// backup) says nothing about when the file arrived here; the other of the
		// two times does.
		$now    = time();
		$newest = 0;
		foreach ( array_merge( [ $dir ], self::code_files( $dir ) ) as $file ) {
			foreach ( [ (int) @filemtime( $file ), (int) @filectime( $file ) ] as $changed ) {
				if ( $changed <= $now && $changed > $newest ) {
					$newest = $changed;
				}
			}
		}

		return $newest;
	}

	/**
	 * Whether every process is certain to be running the files that are on
	 * disk now. With timestamp validation on, opcache keeps using a cached file
	 * for up to opcache.revalidate_freq seconds after it changed, so the code
	 * is settled once the newest change is older than that window. Without
	 * opcache, or with validation off (nothing is re-read until a reset, so
	 * waiting changes nothing), there is no such window.
	 *
	 * @param string|null $dir Plugin folder; defaults to this installation.
	 * @return bool
	 */
	public static function code_is_settled( $dir = null ) {
		$window = null;
		if ( filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) && filter_var( ini_get( 'opcache.validate_timestamps' ), FILTER_VALIDATE_BOOLEAN ) ) {
			// +2: opcache.file_update_protection keeps very fresh files out of the cache.
			$window = (int) ini_get( 'opcache.revalidate_freq' ) + 2;
		}

		/**
		 * Seconds after the last code change during which the upgrade waits.
		 * Null means no opcode cache can serve an old copy.
		 *
		 * @param int|null $window
		 */
		$window = apply_filters( 'ezcache_code_settle_seconds', $window );
		if ( null === $window ) {
			return true;
		}

		$age = time() - self::newest_code_change( $dir );

		// A change dated in the future (clock skew, a restored backup) says nothing
		// about opcache and would otherwise postpone the upgrade until that date.
		return $age < 0 || $age > (int) $window;
	}

	/**
	 * Bring wp-content/advanced-cache.php in line with this version. The copy
	 * there is ours only if it carries our marker; another plugin's drop-in is
	 * left alone. Until now only an administrator's visit to wp-admin did this,
	 * so auto-updated sites kept the previous version's drop-in indefinitely.
	 */
	protected static function refresh_advanced_cache() {
		$path = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( Settings::is_disabled() || ! is_file( $path ) ) {
			return;
		}
		// Another plugin's drop-in is left alone; an empty file is nobody's.
		$current = (string) @file_get_contents( $path );
		if ( '' !== trim( $current ) && false === strpos( $current, 'ezCache Advanced Cache' ) ) {
			return;
		}
		$source = @file_get_contents( EZCACHE_DIR . '/advanced-cache.php' );
		if ( is_string( $source ) ) {
			try { token_get_all( $source, TOKEN_PARSE ); } catch ( \Throwable $e ) { $source = false; } // cut off half-way
		}
		if ( ! is_string( $source ) || false === strpos( $source, 'ezCache Advanced Cache' ) || false === strpos( $source, 'wp_cache_postload' ) ) {
			return;
		}
		// Same substitution as Plugin::activation_hook() and maybe_repair_installation().
		$source = str_replace( "__DIR__ . '/plugins/ezcache/vendor/autoload.php'", var_export( EZCACHE_DIR . '/vendor/autoload.php', true ), $source );
		if ( hash_file( 'sha256', $path ) !== hash( 'sha256', $source ) ) {
			Utilities\AtomicFile::write( $path, $source );
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
		} catch ( \Throwable $ex ) {
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
        $table=$wpdb->prefix.'ezcache_webp_images';
        $columns=$wpdb->get_col("SHOW COLUMNS FROM `{$table}`",0);
        foreach(['attempts'=>"smallint unsigned NOT NULL DEFAULT 0",'next_attempt'=>"bigint unsigned NOT NULL DEFAULT 0",'last_error'=>"varchar(250) NOT NULL DEFAULT ''"] as $name=>$definition) {
            if (!in_array($name,$columns,true)) { $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}"); }
        }
        if ($wpdb->last_error) { throw new Exception($wpdb->last_error); }

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


	/**
	 * Update to 2.6.6: the default list of ignored tracking parameters grew
	 * (srsltid, gad_source, ttclid and others). Sites still on the previous
	 * default list, or with an empty list, get the new default; a customized
	 * list is left alone.
	 */
	protected static function update_2_6_6() {
		$settings    = Settings::get_settings();
		$old_default = "gclid\ngbraid\nwbraid\nfbclid\nmsclkid\ndclid\nyclid\ntwclid\nutm_*\nmc_cid\nmc_eid\n_ga\n_gl";
		$current     = isset( $settings->ignored_query_params_list ) ? (string) $settings->ignored_query_params_list : '';
		$normalize   = function ( $list ) {
			return implode( "\n", preg_split( '/[\s,]+/', strtolower( trim( $list ) ), -1, PREG_SPLIT_NO_EMPTY ) );
		};

		if ( '' === trim( $current ) || $normalize( $current ) === $normalize( $old_default ) ) {
			Settings::set_settings( [
				'ignored_query_params_list' => Settings::get_default_settings()->ignored_query_params_list,
			] );
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