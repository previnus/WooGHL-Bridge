<?php

namespace WooGHL\Bridge;

defined( 'ABSPATH' ) || exit;

final class Destination {
	public const SESSION_OPTION = 'wooghl_delivery_session';
	public const API_ORIGIN = 'https://services.leadconnectorhq.com';

	private function __construct(
		public readonly string $location,
		public readonly string $token,
		public readonly string $workflow_url,
	) {}

	public static function load(): ?self {
		if ( is_multisite() ) {
			return null;
		}

		$location = self::setting( 'WOOGHL_LOCATION_ID' );
		$token = self::setting( 'WOOGHL_PRIVATE_TOKEN' );
		$workflow_url = self::setting( 'WOOGHL_WORKFLOW_URL' );
		if ( ! self::valid_id( $location ) || ! preg_match( '~\A[A-Za-z0-9._+/=\x7e-]{1,2048}\z~', $token ) ) {
			return null;
		}

		$prefix = self::API_ORIGIN . '/hooks/' . $location . '/webhook-trigger/';
		if ( ! str_starts_with( $workflow_url, $prefix ) || ! self::valid_id( substr( $workflow_url, strlen( $prefix ) ) ) ) {
			return null;
		}

		return new self( $location, $token, $workflow_url );
	}

	public static function valid_id( string $value ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/', $value );
	}

	public function active_fingerprint(): string {
		$session = get_option( self::SESSION_OPTION );
		$id = $session['id'] ?? null;
		$binding = $session['binding'] ?? null;
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || $binding !== $this->site_binding() ) {
			return '';
		}

		return hash( 'sha256', $id . '|' . $binding );
	}

	public function activate(): bool {
		if ( '' !== $this->active_fingerprint() ) {
			return true;
		}
		update_option( self::SESSION_OPTION, array(
			'id'      => wp_generate_uuid4(),
			'binding' => $this->site_binding(),
		), false );
		return '' !== $this->active_fingerprint();
	}

	private function site_binding(): string {
		return hash( 'sha256', get_option( 'home' ) . '|' . $this->workflow_url );
	}

	private static function setting( string $name ): string {
		$value = defined( $name ) ? constant( $name ) : '';
		return is_string( $value ) ? trim( $value ) : '';
	}
}
