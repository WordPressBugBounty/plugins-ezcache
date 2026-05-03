<?php
/**
 * LicenseController — stub (licensing system removed).
 *
 * Pro is unlocked for everyone. These endpoints are kept so the Vue admin
 * UI doesn't 404, and they always report an active Pro plan.
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

		return [
			'success' => true,
			'data'    => [
				'key'              => '',
				'type'             => $status['type'],
				'status'           => $status['status'],
				'expires_at'       => $status['expires_at'],
				'conversions_left' => $status['conversions_left'],
				'plan'             => isset( $status['plan'] ) ? $status['plan'] : '',
				'freemius'         => false,
				'account_url'      => '',
			],
		];
	}

	/**
	 * PATCH /license — Activate license (no-op).
	 */
	public function update( $request ) {
		return [
			'success' => true,
			'data'    => [
				'message'     => __( 'Pro is already unlocked.', 'ezcache' ),
				'freemius'    => false,
				'account_url' => '',
			],
		];
	}

	/**
	 * DELETE /license — Deactivate license (no-op).
	 */
	public function destroy() {
		return [
			'success' => true,
			'data'    => [
				'message'     => __( 'Pro is unlocked for everyone; nothing to deactivate.', 'ezcache' ),
				'freemius'    => false,
				'account_url' => '',
			],
		];
	}
}
