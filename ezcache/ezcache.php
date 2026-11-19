<?php
/*
	Plugin Name: ezCache
	Description: 🚀 ezCache 2026 — Lightning-fast WordPress caching with CSS/JS optimization, WebP images, preloading, database cleanup &amp; more. All features are free.
	Plugin URI: https://ezcache-wp.com
	Version: 2.7.1
	Requires at least: 5.0
	Requires PHP: 7.4
	Author: uPress
	Author URI: https://www.upress.io
	Text Domain: ezcache
	Domain Path: /languages/
	License: GPLv2 or later
	License URI: http://www.gnu.org/licenses/gpl-2.0.html

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		die( 'NO direct access!' );
	}

	// Freemius SDK removed — Pro is unlocked for everyone, no licensing layer.
	define( 'EZCACHE_DIR', __DIR__ );
	define( 'EZCACHE_FILE', __FILE__ );
	define( 'EZCACHE_URL', plugin_dir_url( __FILE__ ) );
	define( 'EZCACHE_BASEBANE', basename( __FILE__ ) );
	define( 'EZCACHE_VERSION', '2.7.1' );
	define( 'EZCACHE_SETTINGS_KEY', 'ezcache' );

	register_activation_hook( EZCACHE_FILE, 'upress_ezcache_activation_hook' );
	register_deactivation_hook( EZCACHE_FILE, 'upress_ezcache_deactivation_hook' );

	function upress_ezcache_activation_hook() {
		$ezcache = Upress\EzCache\Plugin::initialize();
		$ezcache->activation_hook();
	}

	function upress_ezcache_deactivation_hook() {
		$ezcache = Upress\EzCache\Plugin::initialize();
		$ezcache->deactivation_hook();
	}
}

namespace Upress\EzCache {
	class Plugin {
		private static $instance;
		public $plugin_dir;
		public $plugin_file;
		public $plugin_url;
		public $plugin_basename;
		public $plugin_version;
		public $plugin_settings_key;
		/** @var Cache $ezcache */
		public $ezcache;

		/**
		 * @return Plugin
		 */
		public static function initialize() {
			if ( ! self::$instance ) {
				self::$instance = new self;
			}

			return self::$instance;
		}


		private function __construct() {
			$this->plugin_dir          = EZCACHE_DIR;
			$this->plugin_file         = EZCACHE_FILE;
			$this->plugin_url          = EZCACHE_URL;
			$this->plugin_basename     = EZCACHE_BASEBANE;
			$this->plugin_version      = EZCACHE_VERSION;
			$this->plugin_settings_key = EZCACHE_SETTINGS_KEY;

			$this->load_dependencies();
			$this->initialize_plugin();
		}

		/**
		 * Import required files
		 */
		protected function load_dependencies() {
			require_once EZCACHE_DIR . '/vendor/autoload.php';
		}

		/**
		 * Init all the plugin parts
		 */
		protected function initialize_plugin() {
			$this->ezcache = Cache::instance();

			add_action( 'init', [ $this, 'load_translation' ] );
			add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
			add_action( 'admin_init', [ $this, 'maybe_repair_installation' ] );
			// Self-heal DB tables after file-replacement updates (no activation hook).
			// maybe_upgrade() runs version migrations; ensure_tables() runs unconditionally
			// so a site already on the current version but missing a table still recovers.
			add_action( 'admin_init', [ '\Upress\EzCache\Updater', 'maybe_upgrade' ] );
			add_action( 'admin_init', [ '\Upress\EzCache\Updater', 'ensure_tables' ] );

			// Background WebP conversion cron — resilient, self-rescheduling queue drain.
			add_action( 'ezcache_webp_process_batch', [ '\Upress\EzCache\Rest\WebpController', 'cron_process' ] );
			// Remember when WP-Cron last ran, so the admin can be told when background work is stuck.
			add_action( 'init', [ '\Upress\EzCache\Utilities\Cron', 'record_run' ], 1 );

			new RestApi( $this );
			new Admin( $this );
			new AdvancedSettingsPage();
			new Optimizations();
			Preload::instance();

			// v2.2.0 features
			RedisObjectCache::init();
            add_action('activated_plugin', function($plugin) {
                if ($plugin===plugin_basename(EZCACHE_FILE) && !empty(Settings::get_settings()->enable_redis_object_cache)) { RedisObjectCache::flush(); }
            });
			SpeculativeLoading::instance()->register();
			EarlyHints::instance()->register();
			CriticalCSS::instance()->register();

			add_action( 'ezcache_clear_expired_cache', [ $this->ezcache, 'clear_expired_cache' ] );
			// Background deletion of caches that clear_cache() moved aside.
			add_action( Cache::PURGE_HOOK, [ $this->ezcache, 'purge_trash' ] );
			add_action( 'ezcache_db_cleanup', function () {
				( new DatabaseOptimizer() )->clean();
			} );
		}

		function load_translation() {
			$locale = apply_filters( 'plugin_locale', get_locale(), 'ezcache' );
			load_textdomain( 'ezcache', EZCACHE_DIR . "/languages/ezcache-{$locale}.mo" );
		}

		function get_wp_config_path() {
			if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
				return ABSPATH . 'wp-config.php';
			}

			return dirname( ABSPATH ) . '/wp-config.php';
		}

		function activation_hook() {
            $disabled=WP_CONTENT_DIR.'/ezcache-disabled.php';
            if (is_file($disabled) && !@unlink($disabled)) { wp_die('Unable to enable ezCache. Check wp-content permissions.'); }
            if (!defined('EZCACHE_ACTIVATING')) { define('EZCACHE_ACTIVATING',true); }
			set_transient( 'ezcache_activating', true );

			// copy advanced-cache
            $path = WP_CONTENT_DIR . '/advanced-cache.php';
            // An empty file is nobody's drop-in (see maybe_repair_installation()).
            $current = is_file($path) ? (string) @file_get_contents($path) : '';
            if ( '' !== trim($current) && strpos($current, 'ezCache Advanced Cache') === false ) {
                wp_die('Another advanced-cache.php is installed. ezCache has not replaced it.');
            }
            $source = @file_get_contents(EZCACHE_DIR . '/advanced-cache.php');
            if ( is_string($source) ) { try { token_get_all( $source, TOKEN_PARSE ); } catch ( \Throwable $e ) { $source = false; } } // a template cut off half-way does not parse
            if ( ! is_string($source) || strpos($source, 'ezCache Advanced Cache') === false || strpos($source, 'wp_cache_postload') === false ) {
                wp_die('Unable to read the ezCache drop-in from the plugin folder.');
            }
            $source = str_replace("__DIR__ . '/plugins/ezcache/vendor/autoload.php'", var_export(EZCACHE_DIR . '/vendor/autoload.php', true), $source);
            if ( ! Utilities\AtomicFile::write($path, $source) ) { wp_die('Unable to install advanced-cache.php. Check directory permissions.'); }

			if ( ! wp_next_scheduled( 'ezcache_clear_expired_cache' ) ) {
				Settings::maybe_update_cronjobs( Settings::get_settings() );
			}
			Updater::upgrade();

            $updated = $this->update_wp_config_const( [ 'WP_CACHE' => true ] );
            if ( is_wp_error($updated) ) { wp_die(esc_html($updated->get_error_message())); }

			$this->ezcache->preload_homepage();

			delete_transient( 'ezcache_activating' );
		}

        function deactivation_hook() {
            // A durable marker also stops in-flight workers from reinstalling the drop-in.
            if (!Utilities\AtomicFile::write(WP_CONTENT_DIR.'/ezcache-disabled.php', Settings::FILE_GUARD)) { wp_die('Unable to disable ezCache safely. Check wp-content permissions.'); }
            if (!defined('EZCACHE_DEACTIVATING')) { define('EZCACHE_DEACTIVATING',true); }
            set_transient('ezcache_deactivating', true, 60);
            try {
                $path = WP_CONTENT_DIR . '/advanced-cache.php';
                if ( is_file($path) && strpos((string)file_get_contents($path), 'ezCache Advanced Cache') !== false ) { @unlink($path); }
                RedisObjectCache::remove_dropin();
                if ( ! is_file($path) ) { $this->update_wp_config_const(['WP_CACHE'=>false]); }
                $this->ezcache->clear_cache();
                $this->ezcache->purge_trash(5);
                Preload::instance()->stop();
                foreach ( ['ezcache_clear_expired_cache', Cache::PURGE_HOOK, Preload::CRON_HOOK, 'ezcache_db_cleanup', 'ezcache_webp_process_batch', 'ezcache_convert_images_to_webp_hook'] as $hook ) { wp_clear_scheduled_hook($hook); }
                delete_site_option('ezcache_first_run');
            } finally { delete_transient('ezcache_deactivating'); }
        }

        /** In-place backup copies are never created; rename is the commit point. */
        function update_wp_config_const( $args ) {
            if ( ! is_array($args) || array_keys($args) !== ['WP_CACHE'] || ! is_bool($args['WP_CACHE']) ) {
                return new \WP_Error('invalid_config_update', 'Invalid configuration update.');
            }
            $lock = Utilities\Lock::acquire('wp-config');
            if ( ! $lock ) { return new \WP_Error('config_busy', 'Another configuration update is running.'); }
            try {
                $path = $this->get_wp_config_path();
                $contents = @file_get_contents($path);
                if ( false === $contents ) { return new \WP_Error('config_read_failed', 'Cannot read wp-config.php.'); }
                $value = $args['WP_CACHE'] ? 'true' : 'false';
                $pattern = '/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(?:true|false|0|1)\s*\)/i';
                $updated = preg_replace_callback($pattern, function() use($value) { return "define( 'WP_CACHE', {$value} )"; }, $contents, -1, $count);
                if ( ! $count ) {
                    if ( preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $contents) ) { return new \WP_Error('custom_wp_cache', 'Set WP_CACHE manually in wp-config.php; its value is an expression.'); }
                    $updated = preg_replace_callback('/<\?php\s/', function($match) use($value) { return $match[0] . "\ndefine( 'WP_CACHE', {$value} );\n"; }, $contents, 1);
                }
                if ( $updated === $contents ) { return true; }
                try { token_get_all($updated, TOKEN_PARSE); } catch (\Throwable $e) { return new \WP_Error('invalid_config', 'Configuration validation failed.'); }
                if ( ! Utilities\AtomicFile::write($path, $updated, fileperms($path) & 0777) ) { return new \WP_Error('config_write_failed', 'Cannot update wp-config.php. Check file permissions.'); }
                return true;
            } finally { Utilities\Lock::release($lock); }
        }

		function add_cron_schedules( $schedules ) {
            $schedules['weekly'] = $schedules['weekly'] ?? ['interval'=>7*DAY_IN_SECONDS,'display'=>__('Weekly','ezcache')];
            $schedules['monthly'] = $schedules['monthly'] ?? ['interval'=>30*DAY_IN_SECONDS,'display'=>__('Every 30 days','ezcache')];
			if ( ! isset( $schedules['every_10_minutes'] ) ) {
				$schedules['every_10_minutes'] = [
					'interval' => 600,
					'display'  => __( 'Every 10 Minutes', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['hourly'] ) ) {
				$schedules['hourly'] = [
					'interval' => 3600,
					'display'  => __( 'Hourly', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_3_hours'] ) ) {
				$schedules['every_3_hours'] = [
					'interval' => 10800,
					'display'  => __( 'Every 3 Hours', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_6_hours'] ) ) {
				$schedules['every_6_hours'] = [
					'interval' => 21600,
					'display'  => __( 'Every 6 Hours', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_12_hours'] ) ) {
				$schedules['every_12_hours'] = [
					'interval' => 43200,
					'display'  => __( 'Every 12 Hours', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['daily'] ) ) {
				$schedules['daily'] = [
					'interval' => 86400,
					'display'  => __( 'Daily', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_3_days'] ) ) {
				$schedules['every_3_days'] = [
					'interval' => 259200,
					'display'  => __( 'Every 3 Days', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_5_days'] ) ) {
				$schedules['every_5_days'] = [
					'interval' => 432000,
					'display'  => __( 'Every 5 Days', 'ezcache' ),
				];
			}
			if ( ! isset( $schedules['every_7_days'] ) ) {
				$schedules['every_7_days'] = [
					'interval' => 604800,
					'display'  => __( 'Every 7 Days', 'ezcache' ),
				];
			}

			return $schedules;
		}

		/**
		 * Repaire the plugin installation when POST variable is set
		 */
        function maybe_repair_installation() {
            if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options') || is_file(WP_CONTENT_DIR.'/ezcache-disabled.php')) { return; }
            $path=WP_CONTENT_DIR.'/advanced-cache.php';
            // A conflict must not lock administrators out of every wp-admin screen.
            // An empty file is nobody's drop-in: an update caught half-way left one behind,
            // and reading it as another plugin's switched the page cache off for good.
            $current=is_file($path) ? (string)@file_get_contents($path) : '';
            if (''!==trim($current) && strpos($current,'ezCache Advanced Cache')===false) { return; }
            // While the plugin folder is being replaced the template can be missing, empty
            // or half written. Never install that; the next admin request tries again.
            $source=@file_get_contents(EZCACHE_DIR.'/advanced-cache.php');
            if (is_string($source)) { try { token_get_all( $source, TOKEN_PARSE ); } catch ( \Throwable $e ) { $source = false; } } // a template cut off half-way does not parse
            if (!is_string($source) || strpos($source,'ezCache Advanced Cache')===false || strpos($source,'wp_cache_postload')===false) { return; }
            $source=str_replace("__DIR__ . '/plugins/ezcache/vendor/autoload.php'",var_export(EZCACHE_DIR.'/vendor/autoload.php',true),$source);
            $error=null;
            if ((!is_file($path) || hash_file('sha256',$path)!==hash('sha256',$source)) && !Utilities\AtomicFile::write($path,$source)) {
                $error=new \WP_Error('dropin_write_failed','Could not repair advanced-cache.php. Check wp-content permissions.');
            }
            if (!is_wp_error($error) && (!defined('WP_CACHE') || !WP_CACHE)) { $error=$this->update_wp_config_const(['WP_CACHE'=>true]); }
            if (is_wp_error($error)) {
                add_action('admin_notices',function()use($error){echo '<div class="notice notice-error"><p>'.esc_html($error->get_error_message()).'</p></div>';});
                return;
            }
            if (!wp_next_scheduled('ezcache_clear_expired_cache')) { Settings::maybe_update_cronjobs(Settings::get_settings()); }
        }

	}

	// Right after the files were replaced, PHP's opcode cache can keep serving old
	// copies of some of them for up to a minute. The first request that runs this
	// (new) file ends that mix for everyone by resetting the cache for the plugin.
	// method_exists: the Updater class loaded in this very request may be an old copy.
	add_action( 'plugins_loaded', function () {
		if ( get_option( 'ezcache_version' ) !== EZCACHE_VERSION && method_exists( '\Upress\EzCache\Updater', 'reset_opcache' ) ) {
			\Upress\EzCache\Updater::reset_opcache();
		}
	}, 0 );
	add_action( 'plugins_loaded', '\Upress\EzCache\Plugin::initialize' );
	add_action( 'plugins_loaded', function() { \Upress\EzCache\PremiumFeatures::maybe_start_trial(); }, 20 );
}
