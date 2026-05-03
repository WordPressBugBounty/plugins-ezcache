<?php
/**
 * LicenseApi — stub (licensing system removed).
 *
 * Pro is unlocked for everyone. Methods are kept for backward compatibility
 * with existing callers and return neutral "everything is fine" values.
 */

namespace Upress\EzCache;

class LicenseApi {

	/**
	 * @return bool
	 */
	public function is_premium() {
		return true;
	}

	/**
	 * @return string
	 */
	public function get_masked_license_key() {
		return '';
	}

	/**
	 * @return string
	 */
	public function get_license_key() {
		return '';
	}

	/**
	 * @return array
	 */
	public function get_status() {
		return [
			'type'             => 'pro',
			'status'           => 'active',
			'expires_at'       => '',
			'conversions_left' => 'unlimited',
			'plan'             => 'Pro',
		];
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public function activate( $key ) {
		return [
			'success' => true,
			'message' => __( 'Pro is already unlocked.', 'ezcache' ),
		];
	}

	/**
	 * @return array
	 */
	public function deactivate() {
		return [
			'success' => true,
			'message' => __( 'Pro is unlocked for everyone; nothing to deactivate.', 'ezcache' ),
		];
	}
}
