<?php
namespace Upress\EzCache;

use Upress\EzCache\Utilities\Logger;
use WP_Error;

class WebpApi {
	protected $domain;
	protected $license_tail;
	protected $license_len;
	protected $api_url = 'https://api.ezcache-wp.com';

	function __construct( $key = '' ) {
		$this->domain = str_replace( [ 'https://', 'http://' ], '', get_bloginfo( 'wpurl' ) );
		$this->license_tail = ! empty( $key ) ? substr( $key, -4 ) : '';
		$this->license_len = strlen( $key );
	}

	public static function is_available() {
		return PremiumFeatures::is_premium();
	}

	public function convert( $image_path ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'premium_required', 'WebP conversion requires a premium license' );
		}

		wp_raise_memory_limit( 'image' );

		$file = @fopen( $image_path, 'r' );
		if ( ! $file ) {
			return new WP_Error( 'invalid_input_file', 'Unable to read source image file' );
		}

		$file_size = filesize( $image_path );
		$file_data = fread( $file, $file_size );
		@fclose( $file );

		if ( false === $file_data || $file_size === 0 ) {
			return new WP_Error( 'invalid_input_file', 'Unable to read source image file' );
		}

		$mime = wp_check_filetype( $image_path );
		$content_type = $mime['type'] ?: 'application/binary';

		$quality = 80;
		if ( strpos( $content_type, 'png' ) !== false || strpos( $content_type, 'gif' ) !== false ) {
			$quality = 100;
		}

		$url = $this->api_url . '/convert?' . http_build_query( [
			'quality'  => $quality,
			'domain'   => $this->domain,
			'key_len'  => $this->license_len,
		] );

		$request_args = [
			'headers' => [
				'Content-Type'    => $content_type,
				'X-Domain'        => $this->domain,
				'X-License-Tail'  => $this->license_tail,
				'X-Requested-By'  => 'wp_remote_request',
			],
			'body'       => $file_data,
			'timeout'    => 120,
			'user-agent' => 'ezCache WebP Converter/2.0',
		];

		$max_retries = 3;
		$retry_delay = 2;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				Logger::log( 'ezCache WebP API Error (attempt ' . $attempt . '): ' . $response->get_error_message() );
				if ( $attempt < $max_retries ) {
					sleep( $retry_delay * $attempt );
					continue;
				}
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code === 429 && $attempt < $max_retries ) {
				Logger::log( 'ezCache WebP API rate limited (attempt ' . $attempt . '), retrying in ' . ($retry_delay * $attempt) . 's' );
				sleep( $retry_delay * $attempt );
				continue;
			}

			break;
		}

		$info = wp_remote_retrieve_headers( $response );
		$result = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			$error_msg = 'WebP API error (HTTP ' . $status_code . ')';
			if ( strpos( $result, '"error"' ) !== false ) {
				$json = json_decode( $result, true );
				if ( isset( $json['error'] ) ) {
					$error_msg = $json['error'];
				}
			}
			Logger::log( 'ezCache WebP API Error: ' . $error_msg );
			return new WP_Error( 'api_error', $error_msg );
		}

		$response_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( strpos( $response_type, 'webp' ) === false ) {
			Logger::log( 'ezCache WebP API Error: response is not WebP (got ' . $response_type . ')' );
			return new WP_Error( 'invalid_response', 'API did not return a WebP image' );
		}

		return [ 'info' => $info, 'data' => $result ];
	}
}
