<?php
/**
 * LicenseController — Freemius Bridge
 * 
 * REST API endpoints for license management.
 * Returns data from Freemius instead of ezcache-wp.com.
 */

namespace Upress\EzCache\Rest;

use Upress\EzCache\LicenseApi;

class LicenseController {

	/**
	 * GET /license — Show license status
	 */
	public function show() {
		$manager = new LicenseApi();
		$status  = $manager->get_status();
		$key     = $manager->get_masked_license_key();

		return [
			'success' => true,
			'data'    => [
				'key'              => $key,
				'type'             => $status['type'],
				'status'           => $status['status'],
				'expires_at'       => $status['expires_at'],
				'conversions_left' => $status['conversions_left'],
				'plan'             => isset( $status['plan'] ) ? $status['plan'] : '',
				'freemius'         => true,
				'account_url'      => function_exists( 'ezc_fs' ) ? ezc_fs()->get_account_url() : '',
			],
		];
	}

	/**
	 * PATCH /license — Activate license
	 * With Freemius, activation is handled through the SDK UI.
	 */
	public function update( $request ) {
		return [
			'success' => true,
			'data'    => [
				'message'     => __( 'License management is handled through Freemius. Please use the Account page.', 'ezcache' ),
				'freemius'    => true,
				'account_url' => function_exists( 'ezc_fs' ) ? ezc_fs()->get_account_url() : '',
			],
		];
	}

	/**
	 * DELETE /license — Deactivate license
	 */
	public function destroy() {
		return [
			'success' => true,
			'data'    => [
				'message'     => __( 'License management is handled through Freemius. Please use the Account page.', 'ezcache' ),
				'freemius'    => true,
				'account_url' => function_exists( 'ezc_fs' ) ? ezc_fs()->get_account_url() : '',
			],
		];
	}
}
