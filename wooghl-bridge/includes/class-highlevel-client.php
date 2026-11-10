<?php

namespace WooGHL\Bridge;

defined( 'ABSPATH' ) || exit;

final class HighLevel_Client {
	public function __construct( private readonly Destination $destination ) {}

	public function upsert_contact( array $contact ): string|\WP_Error {
		$contact['locationId'] = $this->destination->location;
		$response = $this->post(
			'contact',
			Destination::API_ORIGIN . '/contacts/upsert',
			$contact,
			array(
				'Authorization' => 'Bearer ' . $this->destination->token,
				'Version'       => '2021-07-28',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true, 16 );
		$id = $body['contact']['id'] ?? null;
		$location = $body['contact']['locationId'] ?? $this->destination->location;
		if ( ! is_string( $id ) || ! Destination::valid_id( $id ) || $location !== $this->destination->location ) {
			return new \WP_Error( 'contact_invalid_response' );
		}

		return $id;
	}

	public function send_event( array $payload ): bool|\WP_Error {
		$response = $this->post( 'workflow', $this->destination->workflow_url, $payload );
		return is_wp_error( $response ) ? $response : true;
	}

	private function post( string $stage, string $url, array $payload, array $headers = array() ): array|\WP_Error {
		$body = wp_json_encode( $payload );
		if ( false === $body || strlen( $body ) > 131072 ) {
			return new \WP_Error( $stage . '_invalid_payload' );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'headers'             => $headers + array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'                => $body,
				'timeout'             => 12,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65536,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( $stage . '_transport_error', '', array( 'retry_after' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return $response;
		}

		if ( in_array( $status, array( 408, 429 ), true ) || ( $status >= 500 && $status < 600 ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			return new \WP_Error(
				$stage . '_http_' . $status,
				'',
				array( 'retry_after' => self::retry_after_seconds( $retry_after ) )
			);
		}

		return new \WP_Error( $stage . '_http_' . $status );
	}

	private static function retry_after_seconds( mixed $header ): int {
		if ( ! is_string( $header ) || '' === $header || strlen( $header ) > 64 ) {
			return 0;
		}

		$header = trim( $header );
		$seconds = preg_match( '/\A[0-9]+\z/', $header ) ? (int) $header : (int) strtotime( $header ) - time();
		return max( 0, $seconds );
	}
}
