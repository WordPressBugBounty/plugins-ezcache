<?php

namespace Upress\EzCache;

/**
 * Server-rendered settings page for the 1.7.0 modules.
 *
 * The original ezCache options screen is a compiled JS app that we cannot
 * extend without re-building it. Instead, this class registers an additional
 * submenu page (`ezcache-performance`) underneath the ezCache top-level menu
 * and renders a plain PHP form for the new Preload, Optimizations, CDN,
 * Heartbeat and Database modules. The form posts to admin-post.php and is
 * persisted through the existing Settings class so all storage stays in the
 * single ezcache-config.json file.
 */
class AdvancedSettingsPage {

	const MENU_SLUG = 'ezcache-performance';
	const NONCE     = 'ezcache_perf_nonce';

	public function __construct() {
		// Performance page is now integrated into the Vue app
		// Keep legacy form handlers for backwards compatibility
		add_action( 'admin_post_ezcache_save_performance', [ $this, 'handle_save' ] );
		add_action( 'admin_post_ezcache_run_preload', [ $this, 'handle_run_preload' ] );
		add_action( 'admin_post_ezcache_stop_preload', [ $this, 'handle_stop_preload' ] );
		add_action( 'admin_post_ezcache_run_db_cleanup', [ $this, 'handle_run_db_cleanup' ] );
	}

	public function register_menu() {
		// No longer needed - Performance is now a Vue route
	}

	/**
	 * Render the settings screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings        = Settings::get_settings();
		$preload_status  = Preload::instance()->get_status();
		$action_url      = admin_url( 'admin-post.php' );
		$flash           = isset( $_GET['ezcache_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ezcache_msg'] ) ) : '';
		$flash_messages  = [
			'saved'        => __( 'Settings saved.', 'ezcache' ),
			'preload_run'  => __( 'Preload started in the background.', 'ezcache' ),
			'preload_stop' => __( 'Preload cancelled.', 'ezcache' ),
			'db_cleaned'   => __( 'Database cleanup completed.', 'ezcache' ),
		];

		$checkbox = function ( $key ) use ( $settings ) {
			$checked = ! empty( $settings->{$key} ) ? 'checked' : '';
			printf(
				'<input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s>',
				esc_attr( $key ),
				$checked
			);
		};

		$text = function ( $key, $placeholder = '' ) use ( $settings ) {
			$value = isset( $settings->{$key} ) ? $settings->{$key} : '';
			printf(
				'<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="%3$s">',
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $placeholder )
			);
		};

		$number = function ( $key, $min = 1, $max = 100 ) use ( $settings ) {
			$value = isset( $settings->{$key} ) ? (int) $settings->{$key} : $min;
			printf(
				'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="%3$d" max="%4$d">',
				esc_attr( $key ),
				$value,
				$min,
				$max
			);
		};

		$textarea = function ( $key, $placeholder = '' ) use ( $settings ) {
			$value = isset( $settings->{$key} ) ? $settings->{$key} : '';
			printf(
				'<textarea name="%1$s" id="%1$s" rows="4" class="large-text code" placeholder="%3$s">%2$s</textarea>',
				esc_attr( $key ),
				esc_textarea( $value ),
				esc_attr( $placeholder )
			);
		};

		$select = function ( $key, $options ) use ( $settings ) {
			$current = isset( $settings->{$key} ) ? $settings->{$key} : '';
			printf( '<select name="%1$s" id="%1$s">', esc_attr( $key ) );
			foreach ( $options as $value => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $value ),
					selected( $current, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		};

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ezCache Performance', 'ezcache' ); ?></h1>

			<?php if ( $flash && isset( $flash_messages[ $flash ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash_messages[ $flash ] ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'These options expose the new performance modules introduced in ezCache 1.7.0. They are stored in the same configuration file as the rest of the ezCache settings.', 'ezcache' ); ?>
			</p>

			<h2 class="title"><?php esc_html_e( 'Cache Preload', 'ezcache' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-bottom: 20px;">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="ezcache_run_preload">
				<p>
					<strong><?php esc_html_e( 'Status:', 'ezcache' ); ?></strong>
					<?php echo esc_html( $preload_status['status'] ); ?>
					&middot;
					<?php
					/* translators: 1: processed, 2: total, 3: remaining */
					printf(
						esc_html__( 'Processed: %1$d / %2$d (%3$d remaining)', 'ezcache' ),
						(int) $preload_status['processed'],
						(int) $preload_status['total'],
						(int) $preload_status['remaining']
					);
					?>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Preload Now', 'ezcache' ); ?></button>
					<button type="submit" class="button" formaction="<?php echo esc_url( $action_url ); ?>" name="action" value="ezcache_stop_preload"><?php esc_html_e( 'Stop Preload', 'ezcache' ); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="ezcache_save_performance">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="enable_preload"><?php esc_html_e( 'Enable Preload', 'ezcache' ); ?></label></th>
						<td>
							<?php $checkbox( 'enable_preload' ); ?>
							<p class="description"><?php esc_html_e( 'Activate the cache preloader. Pages will be crawled in the background to keep the cache warm.', 'ezcache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="preload_on_cache_clear"><?php esc_html_e( 'Preload after cache clear', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'preload_on_cache_clear' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="preload_crawl_homepage_links"><?php esc_html_e( 'Crawl homepage links', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'preload_crawl_homepage_links' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="preload_sitemap_url"><?php esc_html_e( 'Sitemap URL (optional)', 'ezcache' ); ?></label></th>
						<td>
							<?php $text( 'preload_sitemap_url', home_url( '/sitemap.xml' ) ); ?>
							<p class="description"><?php esc_html_e( 'Leave blank to auto-detect /wp-sitemap.xml, /sitemap_index.xml or /sitemap.xml.', 'ezcache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="preload_batch_size"><?php esc_html_e( 'URLs per batch', 'ezcache' ); ?></label></th>
						<td><?php $number( 'preload_batch_size', 1, 50 ); ?></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Front-end Optimizations', 'ezcache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lazy_load_images"><?php esc_html_e( 'Lazy load images', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'lazy_load_images' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="lazy_load_iframes"><?php esc_html_e( 'Lazy load iframes', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'lazy_load_iframes' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="defer_js"><?php esc_html_e( 'Defer JavaScript', 'ezcache' ); ?></label></th>
						<td>
							<?php $checkbox( 'defer_js' ); ?>
							<p class="description"><?php esc_html_e( 'Adds defer to local script tags to reduce render-blocking JS.', 'ezcache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="defer_js_exclusions"><?php esc_html_e( 'Defer JS exclusions', 'ezcache' ); ?></label></th>
						<td>
							<?php $textarea( 'defer_js_exclusions', "jquery.js\njquery-migrate" ); ?>
							<p class="description"><?php esc_html_e( 'One match per line. URLs containing any of these strings will not be deferred.', 'ezcache' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="remove_query_strings"><?php esc_html_e( 'Remove query strings from static assets', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'remove_query_strings' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="dns_prefetch"><?php esc_html_e( 'DNS prefetch hosts', 'ezcache' ); ?></label></th>
						<td>
							<?php $textarea( 'dns_prefetch', "//fonts.googleapis.com\n//cdn.example.com" ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="preconnect"><?php esc_html_e( 'Preconnect hosts', 'ezcache' ); ?></label></th>
						<td>
							<?php $textarea( 'preconnect', "https://fonts.gstatic.com" ); ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Heartbeat Control', 'ezcache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="heartbeat_control"><?php esc_html_e( 'Enable heartbeat control', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'heartbeat_control' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="heartbeat_mode"><?php esc_html_e( 'Mode', 'ezcache' ); ?></label></th>
						<td>
							<?php $select( 'heartbeat_mode', [
								'reduce'  => __( 'Reduce activity (60s ticks)', 'ezcache' ),
								'disable' => __( 'Disable completely', 'ezcache' ),
							] ); ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'CDN', 'ezcache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cdn_enabled"><?php esc_html_e( 'Enable CDN rewriting', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'cdn_enabled' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="cdn_url"><?php esc_html_e( 'CDN URL', 'ezcache' ); ?></label></th>
						<td>
							<?php $text( 'cdn_url', 'https://cdn.example.com' ); ?>
							<p class="description"><?php esc_html_e( 'Static assets under /wp-content/uploads, /themes and /plugins will be rewritten to this host.', 'ezcache' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Database Cleanup', 'ezcache' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="db_cleanup_revisions"><?php esc_html_e( 'Delete post revisions', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_revisions' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_auto_drafts"><?php esc_html_e( 'Delete auto drafts', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_auto_drafts' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_trashed_posts"><?php esc_html_e( 'Delete trashed posts', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_trashed_posts' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_spam_comments"><?php esc_html_e( 'Delete spam comments', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_spam_comments' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_trashed_comments"><?php esc_html_e( 'Delete trashed comments', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_trashed_comments' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_expired_transients"><?php esc_html_e( 'Delete expired transients', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_expired_transients' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_orphan_postmeta"><?php esc_html_e( 'Delete orphan post meta', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_cleanup_orphan_postmeta' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_optimize_tables"><?php esc_html_e( 'Run OPTIMIZE TABLE on all tables', 'ezcache' ); ?></label></th>
						<td><?php $checkbox( 'db_optimize_tables' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="db_cleanup_schedule"><?php esc_html_e( 'Automatic cleanup schedule', 'ezcache' ); ?></label></th>
						<td>
							<?php $select( 'db_cleanup_schedule', [
								'never'  => __( 'Never (manual only)', 'ezcache' ),
								'daily'  => __( 'Daily', 'ezcache' ),
								'weekly' => __( 'Weekly', 'ezcache' ),
							] ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Performance Settings', 'ezcache' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="ezcache_run_db_cleanup">
				<p>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Run Database Cleanup Now', 'ezcache' ); ?></button>
					<span class="description"><?php esc_html_e( 'Runs the cleanup tasks ticked above immediately.', 'ezcache' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the form values to the ezCache settings.
	 */
	public function handle_save() {
		$this->verify();

		$boolean_keys = [
			'enable_preload',
			'preload_on_cache_clear',
			'preload_crawl_homepage_links',
			'lazy_load_images',
			'lazy_load_iframes',
			'defer_js',
			'remove_query_strings',
			'heartbeat_control',
			'cdn_enabled',
			'db_cleanup_revisions',
			'db_cleanup_auto_drafts',
			'db_cleanup_trashed_posts',
			'db_cleanup_spam_comments',
			'db_cleanup_trashed_comments',
			'db_cleanup_expired_transients',
			'db_cleanup_orphan_postmeta',
			'db_optimize_tables',
		];

		$text_keys = [
			'preload_sitemap_url',
			'cdn_url',
		];

		$textarea_keys = [
			'defer_js_exclusions',
			'dns_prefetch',
			'preconnect',
		];

		$select_keys = [
			'heartbeat_mode'      => [ 'reduce', 'disable' ],
			'db_cleanup_schedule' => [ 'never', 'daily', 'weekly' ],
		];

		$new_settings = [];

		foreach ( $boolean_keys as $key ) {
			$new_settings[ $key ] = ! empty( $_POST[ $key ] );
		}

		foreach ( $text_keys as $key ) {
			$new_settings[ $key ] = isset( $_POST[ $key ] ) ? esc_url_raw( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		foreach ( $textarea_keys as $key ) {
			$new_settings[ $key ] = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		foreach ( $select_keys as $key => $allowed ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( ! in_array( $value, $allowed, true ) ) {
				$value = $allowed[0];
			}
			$new_settings[ $key ] = $value;
		}

		$batch = isset( $_POST['preload_batch_size'] ) ? max( 1, min( 50, (int) $_POST['preload_batch_size'] ) ) : 5;
		$new_settings['preload_batch_size'] = $batch;

		Settings::set_settings( $new_settings );

		$this->redirect_back( 'saved' );
	}

	public function handle_run_preload() {
		$this->verify();
		Preload::instance()->start();
		$this->redirect_back( 'preload_run' );
	}

	public function handle_stop_preload() {
		$this->verify();
		Preload::instance()->stop();
		$this->redirect_back( 'preload_stop' );
	}

	public function handle_run_db_cleanup() {
		$this->verify();
		( new DatabaseOptimizer() )->clean();
		$this->redirect_back( 'db_cleaned' );
	}

	protected function verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ezcache' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	protected function redirect_back( $message ) {
		wp_safe_redirect( add_query_arg(
			[
				'page'        => self::MENU_SLUG,
				'ezcache_msg' => $message,
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * No longer needed - Performance is now a Vue route
	 */
	public function reorder_menu() {
		// Deprecated - Performance is integrated into Vue app
	}
}
