<?php

namespace Upress\EzCache;

/**
 * Front-end performance optimizations inspired by WP Rocket.
 *
 * Each optimization is opt-in via the plugin settings. Heavy lifting that
 * needs to alter the HTML of cached pages happens through buffer filters that
 * run before the cache file is written, so the work only happens once per
 * cache generation.
 */
class Optimizations {

	/** @var object */
	protected $settings;

	public function __construct() {
		$this->settings = Settings::get_settings();

		add_action( 'init', [ $this, 'maybe_control_heartbeat' ], 1 );
		add_filter( 'heartbeat_settings', [ $this, 'filter_heartbeat_settings' ] );

		add_action( 'wp_head', [ $this, 'output_resource_hints' ], 1 );

		add_filter( 'ezcache_before_save_cache', [ $this, 'lazy_load_html' ], 20 );
		add_filter( 'ezcache_before_save_cache', [ $this, 'remove_query_strings' ], 30 );
		add_filter( 'ezcache_before_save_cache', [ $this, 'rewrite_cdn_urls' ], 40 );
		add_filter( 'ezcache_before_save_cache', [ $this, 'defer_js' ], 50 );
	}

	/**
	 * Disable or throttle the WP Heartbeat API based on settings.
	 */
	public function maybe_control_heartbeat() {
		if ( empty( $this->settings->heartbeat_control ) ) {
			return;
		}

		$mode = isset( $this->settings->heartbeat_mode ) ? $this->settings->heartbeat_mode : 'reduce';

		if ( 'disable' === $mode ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * Slow the heartbeat tick interval down (default 60s) when "reduce" mode is on.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function filter_heartbeat_settings( $settings ) {
		if ( empty( $this->settings->heartbeat_control ) ) {
			return $settings;
		}

		$mode = isset( $this->settings->heartbeat_mode ) ? $this->settings->heartbeat_mode : 'reduce';

		if ( 'reduce' === $mode ) {
			$settings['interval']    = 60;
			$settings['minimalInterval'] = 60;
		}

		return $settings;
	}

	/**
	 * Output `<link rel="dns-prefetch">` and `<link rel="preconnect">` tags
	 * configured by the user.
	 */
	public function output_resource_hints() {
		if ( ! empty( $this->settings->dns_prefetch ) ) {
			$hosts = preg_split( "/\r\n|\r|\n/", trim( $this->settings->dns_prefetch ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $hosts as $host ) {
				$host = esc_url( trim( $host ) );
				if ( $host ) {
					echo "<link rel='dns-prefetch' href='" . esc_attr( $host ) . "'>\n";
				}
			}
		}

		if ( ! empty( $this->settings->preconnect ) ) {
			$hosts = preg_split( "/\r\n|\r|\n/", trim( $this->settings->preconnect ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $hosts as $host ) {
				$host = esc_url( trim( $host ) );
				if ( $host ) {
					echo "<link rel='preconnect' href='" . esc_attr( $host ) . "' crossorigin>\n";
				}
			}
		}
	}

	/**
	 * Add native lazy-loading to images and iframes that don't already opt out.
	 *
	 * @param string $html
	 * @return string
	 */
	public function lazy_load_html( $html ) {
		if ( empty( $this->settings->lazy_load_images ) && empty( $this->settings->lazy_load_iframes ) ) {
			return $html;
		}

		if ( ! empty( $this->settings->lazy_load_images ) ) {
			$html = preg_replace_callback(
				'#<img\b([^>]*)>#i',
				function ( $match ) {
					$attrs = $match[1];
					if ( false !== stripos( $attrs, 'data-no-lazy' ) || preg_match( '/loading\s*=\s*["\'][^"\']*["\']/i', $attrs ) ) {
						return $match[0];
					}
					return '<img loading="lazy" decoding="async"' . $attrs . '>';
				},
				$html
			);
		}

		if ( ! empty( $this->settings->lazy_load_iframes ) ) {
			$html = preg_replace_callback(
				'#<iframe\b([^>]*)>#i',
				function ( $match ) {
					$attrs = $match[1];
					if ( false !== stripos( $attrs, 'data-no-lazy' ) || preg_match( '/loading\s*=\s*["\'][^"\']*["\']/i', $attrs ) ) {
						return $match[0];
					}
					return '<iframe loading="lazy"' . $attrs . '>';
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Remove the `?ver=` query string from local static asset URLs to improve
	 * proxy/CDN cacheability.
	 *
	 * @param string $html
	 * @return string
	 */
	public function remove_query_strings( $html ) {
		if ( empty( $this->settings->remove_query_strings ) ) {
			return $html;
		}

		$home_host = preg_quote( wp_parse_url( home_url(), PHP_URL_HOST ), '#' );

		return preg_replace_callback(
			'#(["\'])((?:https?:)?//' . $home_host . '/[^"\']+\.(?:css|js))\?[^"\']*\1#i',
			function ( $match ) {
				return $match[1] . $match[2] . $match[1];
			},
			$html
		);
	}

	/**
	 * Rewrite local static URLs to point at the configured CDN host.
	 *
	 * @param string $html
	 * @return string
	 */
	public function rewrite_cdn_urls( $html ) {
		if ( empty( $this->settings->cdn_enabled ) || empty( $this->settings->cdn_url ) ) {
			return $html;
		}

		$cdn = untrailingslashit( $this->settings->cdn_url );
		$cdn = preg_replace( '#^https?:#', '', $cdn );

		$home_url    = home_url();
		$home_no_proto = preg_replace( '#^https?:#', '', $home_url );
		$home_no_proto = rtrim( $home_no_proto, '/' );

		// Rewrite /wp-content/ and /wp-includes/ URLs.
		$pattern = '#(["\'(=\s])((?:https?:)?' . preg_quote( $home_no_proto, '#' ) . ')?(/wp-content/(?:uploads|themes|plugins)/[^"\'\s)]+\.(?:jpg|jpeg|png|gif|webp|svg|css|js|woff|woff2|ttf|eot|ico))#i';

		return preg_replace_callback(
			$pattern,
			function ( $match ) use ( $cdn ) {
				return $match[1] . $cdn . $match[3];
			},
			$html
		);
	}

	/**
	 * Add `defer` to local script tags to reduce render-blocking JS.
	 *
	 * @param string $html
	 * @return string
	 */
	public function defer_js( $html ) {
		if ( empty( $this->settings->defer_js ) ) {
			return $html;
		}

		$excluded = [ 'jquery-core', 'jquery-migrate', 'jquery.js' ];
		if ( ! empty( $this->settings->defer_js_exclusions ) ) {
			$excluded = array_merge(
				$excluded,
				preg_split( "/\r\n|\r|\n/", trim( $this->settings->defer_js_exclusions ), -1, PREG_SPLIT_NO_EMPTY )
			);
		}

		return preg_replace_callback(
			'#<script\b([^>]*?)src=(["\'])([^"\']+)\2([^>]*)></script>#i',
			function ( $match ) use ( $excluded ) {
				$attrs_before = $match[1];
				$src          = $match[3];
				$attrs_after  = $match[4];
				$all_attrs    = $attrs_before . ' ' . $attrs_after;

				// Already deferred / async / module.
				if ( preg_match( '/\b(defer|async|type\s*=\s*["\']?module)\b/i', $all_attrs ) ) {
					return $match[0];
				}

				foreach ( $excluded as $needle ) {
					$needle = trim( $needle );
					if ( '' !== $needle && false !== strpos( $src, $needle ) ) {
						return $match[0];
					}
				}

				return '<script' . $attrs_before . 'defer src=' . $match[2] . $src . $match[2] . $attrs_after . '></script>';
			},
			$html
		);
	}
}
