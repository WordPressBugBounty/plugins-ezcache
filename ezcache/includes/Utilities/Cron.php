<?php
namespace Upress\EzCache\Utilities;

/**
 * Keeps ezCache's background events actually running.
 *
 * WordPress runs due events only when a page view spawns WP-Cron. With
 * DISABLE_WP_CRON and no system cron nothing ever runs them: an event stays
 * scheduled in the past, wp_next_scheduled() keeps returning it, and code that
 * only asks "is it scheduled?" never re-arms or triggers anything. On one site
 * this left 26,000 WebP conversions waiting for two weeks while every page
 * render kept adding to the queue.
 *
 * ensure() schedules an event when missing, re-arms one stuck in the past, and
 * when WordPress will not spawn WP-Cron itself fires one throttled loopback
 * request to wp-cron.php, which runs due events regardless of DISABLE_WP_CRON.
 */
class Cron {
	/** An event this many seconds in the past counts as stuck. */
	const OVERDUE = 600;
	/** A requested delay this long is a deliberate back-off and is always honoured. */
	const BACKOFF = 300;
	/** Minimum seconds between two loopback requests to wp-cron.php. */
	const KICK_INTERVAL = 60;
	const KICK_OPTION = 'ezcache_cron_kick';
	/** Option: unix time of the last WP-Cron run seen on this site. */
	const LAST_RUN_OPTION = 'ezcache_cron_last_run';
	/** ezCache's own hooks, in the order they matter for the stall notice. */
	const HOOKS = [ 'ezcache_webp_process_batch', 'ezcache_preload_process_queue', 'ezcache_purge_trash', 'ezcache_clear_expired_cache' ];

	/** @var bool|null Test seam: whether WordPress spawns WP-Cron itself (null = read the constant). */
	public static $wp_spawns_cron = null;

	/**
	 * Make sure $hook is scheduled and will run.
	 *
	 * @param string      $hook
	 * @param int         $delay      Seconds from now for a newly scheduled event. When WordPress
	 *                                will not run it itself, a short delay is dropped (the loopback
	 *                                below runs the event right away in its own request); a
	 *                                deliberate back-off of BACKOFF seconds or more is kept.
	 * @param string|null $recurrence Schedule name for a recurring event; null for a single event.
	 *
	 * @return bool Whether a loopback request to wp-cron.php was sent.
	 */
	public static function ensure( $hook, $delay = 30, $recurrence = null ) {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		$next  = wp_next_scheduled( $hook );
		$stuck = $next && $next < time() - self::OVERDUE;
		// A long-overdue event is already due: asking wp-cron.php to run (below) is all
		// it needs. It is not unscheduled and scheduled again; that could leave a second
		// copy of a recurring event next to the one a cron run is just rescheduling.

		$spawns = self::wp_spawns_cron();
		if ( ! $next ) {
			$delay = max( 0, (int) $delay );
			$when  = ( $spawns || $delay >= self::BACKOFF ) ? time() + $delay : time();
			if ( $recurrence ) {
				wp_schedule_event( $when, $recurrence, $hook );
			} else {
				wp_schedule_single_event( $when, $hook );
			}
		}

		if ( $stuck || ! $spawns ) {
			return self::kick();
		}

		return false;
	}

	/**
	 * Ask WordPress to run due events now, at most once a minute. wp-cron.php
	 * runs when requested directly even with DISABLE_WP_CRON; it exits at once
	 * if another cron run is in progress.
	 *
	 * @return bool Whether the request was sent.
	 */
	public static function kick() {
		// Inside a WP-Cron run there is nobody to wake up; the request would only wait behind this run.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}
		// An option, not a transient: with a persistent object cache a transient lives
		// in that cache, and every cache flush would lift the limit again.
		if ( time() - (int) get_option( self::KICK_OPTION, 0 ) < self::KICK_INTERVAL ) {
			return false;
		}
		update_option( self::KICK_OPTION, time(), false );

		// No doing_wp_cron argument on purpose. wp-cron.php treats a request without
		// it as an external cron job and takes the lock itself; a request that
		// carries a value must match the doing_cron transient set by spawn_cron(),
		// otherwise wp-cron.php returns without running anything.
		wp_safe_remote_get( site_url( 'wp-cron.php' ), [
			'timeout'    => 0.01,
			'blocking'   => false,
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent' => 'ezCache cron kick',
		] );

		return true;
	}

	/**
	 * Whether WordPress spawns WP-Cron on page views (false under DISABLE_WP_CRON).
	 *
	 * @return bool
	 */
	public static function wp_spawns_cron() {
		if ( null !== self::$wp_spawns_cron ) {
			return (bool) self::$wp_spawns_cron;
		}

		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Remember that WP-Cron ran. Hooked on init; only acts inside a cron request.
	 */
	public static function record_run() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			update_option( self::LAST_RUN_OPTION, time(), false );
		}
	}

	/**
	 * The ezCache event that has been due the longest, when it is more than an
	 * hour overdue; otherwise null. Used for the admin notice.
	 *
	 * @return array|null [ 'hook' => string, 'due' => int, 'last_run' => int ]
	 */
	public static function stalled_event() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return null;
		}
		$oldest = null;
		foreach ( self::HOOKS as $hook ) {
			$due = wp_next_scheduled( $hook );
			if ( $due && ( null === $oldest || $due < $oldest['due'] ) ) {
				$oldest = [ 'hook' => $hook, 'due' => (int) $due ];
			}
		}
		if ( ! $oldest || $oldest['due'] > time() - HOUR_IN_SECONDS ) {
			return null;
		}
		$oldest['last_run'] = (int) get_option( self::LAST_RUN_OPTION, 0 );

		return $oldest;
	}
}
