<?php
/**
 * LicenseApi — Freemius Bridge
 * 
 * Replaces the old ezcache-wp.com license system with Freemius.
 * Maintains the same public API so existing code doesn't break.
 */

namespace Upress\EzCache;

class LicenseApi {

	/**
	 * Check if the user has a valid paid license
	 * @return bool
	 */
	public function is_premium() {
		return function_exists( 'ezc_fs' ) && ezc_fs()->is_paying();
	}

	/**
	 * Get the masked license key for display
	 * @return string
	 */
	public function get_masked_license_key() {
		if ( ! function_exists( 'ezc_fs' ) ) {
			return '';
		}

		$license = ezc_fs()->_get_license();
		if ( ! $license || empty( $license->secret_key ) ) {
			return '';
		}

		$key = $license->secret_key;
		return substr( $key, 0, 4 ) . str_repeat( '*', max( 0, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * Get the raw license key
	 * @return string
	 */
	public function get_license_key() {
		if ( ! function_exists( 'ezc_fs' ) ) {
			return '';
		}

		$license = ezc_fs()->_get_license();
		if ( ! $license || empty( $license->secret_key ) ) {
			return '';
		}

		return $license->secret_key;
	}

	/**
	 * Get the license status
	 * @return array
	 */
	public function get_status() {
		if ( ! function_exists( 'ezc_fs' ) ) {
			return [
				'type'             => 'free',
				'status'           => 'inactive',
				'expires_at'       => '',
				'conversions_left' => 0,
			];
		}

		$is_paying = ezc_fs()->is_paying();
		$license   = ezc_fs()->_get_license();
		$plan      = ezc_fs()->get_plan();

		return [
			'type'             => $is_paying ? 'pro' : 'free',
			'status'           => $is_paying ? 'active' : 'inactive',
			'expires_at'       => $license ? $license->expiration : '',
			'conversions_left' => $is_paying ? 'unlimited' : 0,
			'plan'             => $plan ? $plan->title : 'Free',
		];
	}

	/**
	 * Activate a license key (handled by Freemius SDK)
	 * @param string $key
	 * @return array|\WP_Error
	 */
	public function activate( $key ) {
		// Freemius handles activation through its own UI
		// This method is kept for backward compatibility
		return [
			'success' => true,
			'message' => __( 'Please use the Freemius account page to manage your license.', 'ezcache' ),
		];
	}

	/**
	 * Deactivate the license (handled by Freemius SDK)
	 * @return array|\WP_Error
	 */
	public function deactivate() {
		return [
			'success' => true,
			'message' => __( 'Please use the Freemius account page to manage your license.', 'ezcache' ),
		];
	}
}
