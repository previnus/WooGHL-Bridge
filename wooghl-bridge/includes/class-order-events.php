<?php

namespace WooGHL\Bridge;

defined( 'ABSPATH' ) || exit;

final class Order_Events {
	public const GROUP = 'wooghl-bridge';
	private const DELIVERY_HOOK = 'wooghl_deliver_order_event';
	private const RETRY_DELAYS = array( 60, 300, 900, 3600 );
	private const DRAFT_STATUSES = array( 'auto-draft', 'checkout-draft', 'draft', 'trash' );

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', static function ( $order_id, $posted_data, \WC_Order $order ): void {
			self::checkout_submitted( $order );
		}, 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'checkout_submitted' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'status_changed' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', static function ( $order_id ): void {
			self::enqueue( (int) $order_id, 'order.payment_completed' );
		} );
		add_action( 'woocommerce_order_refunded', static function ( $order_id, $refund_id ): void {
			self::enqueue( (int) $order_id, 'order.refunded', array( 'refund_id' => (int) $refund_id ) );
		}, 10, 2 );
		add_action( self::DELIVERY_HOOK, array( self::class, 'process' ) );
	}

	public static function checkout_submitted( \WC_Order $order ): void {
		if ( self::can_send_order( $order ) ) {
			$created = $order->get_date_created();
			self::enqueue( $order->get_id(), 'order.submitted', array(), $created?->getTimestamp() ?? 0 );
		}
	}

	public static function status_changed( int $order_id, string $from, string $to ): void {
		if ( ! in_array( $to, self::DRAFT_STATUSES, true ) ) {
			self::enqueue( $order_id, 'order.status_changed', array(
				'previous_status' => sanitize_key( $from ),
				'new_status'      => sanitize_key( $to ),
			) );
		}
	}

	public static function can_send_order( mixed $order ): bool {
		return $order instanceof \WC_Order && ! in_array( $order->get_status(), self::DRAFT_STATUSES, true );
	}

	public static function scheduler_ready(): bool {
		return function_exists( 'as_schedule_single_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	public static function enqueue_snapshot( \WC_Order $order, bool $verify_contact ): bool {
		$details = array();
		if ( $verify_contact ) {
			$details['verified_contact'] = self::contact_signature( $order->get_id(), self::contact_fields( $order ) );
		}
		return self::enqueue( $order->get_id(), 'order.snapshot', $details );
	}

	private static function enqueue( int $order_id, string $event, array $details = array(), ?int $occurred_at = null ): bool {
		$destination = Destination::load();
		$fingerprint = $destination?->active_fingerprint() ?? '';
		if ( '' === $fingerprint || $order_id <= 0 ) {
			return false;
		}

		$submission = 'order.submitted' === $event;
		$job = array(
			'order_id'    => $order_id,
			'event_type'  => $event,
			'event_id'    => $submission ? hash( 'sha256', $event . '|' . $fingerprint . '|' . $order_id ) : wp_generate_uuid4(),
			'occurred_at' => gmdate( 'c', $occurred_at ?? time() ),
			'target'      => $fingerprint,
			'attempt'     => 0,
			'details'     => $details,
		);
		if ( ! self::schedule( $job, time(), $submission ) ) {
			self::log( 'error', 'queue_error', $job );
			return false;
		}
		return true;
	}

	public static function process( array $job ): void {
		try {
			$result = self::deliver( $job );
		} catch ( \Throwable ) {
			$result = new \WP_Error( 'worker_error' );
		}

		if ( ! is_wp_error( $result ) ) {
			self::log( 'info', $result, $job );
			return;
		}

		$reason = $result->get_error_code();
		$retry_after = $result->get_error_data()['retry_after'] ?? null;
		$delay = self::RETRY_DELAYS[ $job['attempt'] ] ?? null;
		if ( is_int( $retry_after ) && $retry_after <= DAY_IN_SECONDS && null !== $delay ) {
			$retry_job = $job;
			++$retry_job['attempt'];
			$retry_at = time() + max( $delay, $retry_after ) + wp_rand( 0, 15 );
			if ( self::schedule( $retry_job, $retry_at ) ) {
				self::log( 'warning', $reason . '_retry_scheduled', $job );
				return;
			}
			$reason = 'retry_queue_error';
		}

		self::log( 'error', $reason, $job );
		throw new \RuntimeException( $reason );
	}

	private static function deliver( array $job ): string|\WP_Error {
		$destination = Destination::load();
		if ( null === $destination || $job['target'] !== $destination->active_fingerprint() ) {
			return 'skipped_inactive_destination';
		}
		$order = wc_get_order( $job['order_id'] );
		if ( ! self::can_send_order( $order ) ) {
			return 'skipped_order_unavailable';
		}
		$payload = self::order_payload( $order, $job );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$client = new HighLevel_Client( $destination );
		$contact = self::contact_fields( $order );
		$verified = false;
		if ( 'order.snapshot' === $job['event_type'] ) {
			$signature = $job['details']['verified_contact'] ?? '';
			if ( '' !== $signature ) {
				$verified = is_email( $contact['email'] ) && hash_equals( self::contact_signature( $order->get_id(), $contact ), $signature );
				if ( ! $verified ) {
					return new \WP_Error( 'contact_verification_changed' );
				}
			}
		} elseif ( is_email( $contact['email'] ) ) {
			// Trusted integrations must verify ownership of this exact order billing address.
			$verified = true === apply_filters( 'wooghl_billing_email_verified', false, $order, $contact['email'] );
		}
		if ( $verified ) {
			$contact = array_filter( $contact, static fn ( string $value ): bool => '' !== $value );
			$contact_id = $client->upsert_contact( $contact );
			if ( is_wp_error( $contact_id ) ) {
				return $contact_id;
			}
			$payload['contact_id'] = $contact_id;
		}

		if ( $job['target'] !== $destination->active_fingerprint() ) {
			return 'skipped_inactive_destination';
		}
		$result = $client->send_event( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return '' === $payload['contact_id'] ? 'delivered_contactless' : 'delivered';
	}

	private static function contact_fields( \WC_Order $order ): array {
		return array(
			'email'     => trim( $order->get_billing_email() ),
			'firstName' => sanitize_text_field( $order->get_billing_first_name() ),
			'lastName'  => sanitize_text_field( $order->get_billing_last_name() ),
		);
	}

	private static function contact_signature( int $order_id, array $contact ): string {
		return hash_hmac( 'sha256', serialize( array( $order_id, $contact ) ), wp_salt( 'auth' ) );
	}

	private static function order_payload( \WC_Order $order, array $job ): array|\WP_Error {
		$details = $job['details'];
		$refund_id = $details['refund_id'] ?? 0;
		$refund_amount = '';
		if ( $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof \WC_Order_Refund || $refund->get_parent_id() !== $order->get_id() ) {
				return new \WP_Error( 'refund_unavailable' );
			}
			$refund_amount = (string) $refund->get_amount();
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'line_total'   => (string) $item->get_total(),
			);
		}
		$created = $order->get_date_created();
		$paid = $order->get_date_paid();

		return array(
			'schema_version' => 1,
			'event_id'       => $job['event_id'],
			'event_type'     => $job['event_type'],
			'occurred_at'    => $job['occurred_at'],
			'store_url'      => home_url( '/' ),
			'order_id'       => $order->get_id(),
			'contact_id'     => '',
			'order_status'   => $order->get_status(),
			'previous_status' => $details['previous_status'] ?? '',
			'new_status'     => $details['new_status'] ?? '',
			'created_at'     => $created ? gmdate( 'c', $created->getTimestamp() ) : '',
			'paid_at'        => $paid ? gmdate( 'c', $paid->getTimestamp() ) : '',
			'currency'       => $order->get_currency(),
			'order_total'    => (string) $order->get_total(),
			'refunded_total' => (string) $order->get_total_refunded(),
			'refund_id'      => $refund_id,
			'refund_amount'  => $refund_amount,
			'items'          => $items,
		);
	}

	private static function schedule( array $job, int $timestamp, bool $submission = false ): bool {
		if ( ! self::scheduler_ready() ) {
			return false;
		}
		try {
			$args = array( $job );
			if ( $submission && self::submission_exists( $args ) ) {
				return true;
			}
			return as_schedule_single_action( $timestamp, self::DELIVERY_HOOK, $args, self::GROUP, $submission ) > 0
				|| ( $submission && self::submission_exists( $args ) );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private static function submission_exists( array $args ): bool {
		return (bool) as_get_scheduled_actions( array(
			'hook'     => self::DELIVERY_HOOK,
			'group'    => self::GROUP,
			'args'     => $args,
			'per_page' => 1,
		), 'ids' );
	}

	private static function log( string $level, string $reason, array $job ): void {
		wc_get_logger()->log( $level, $reason, array(
			'source'   => self::GROUP,
			'order_id' => $job['order_id'],
			'event_id' => $job['event_id'],
			'event'    => $job['event_type'],
			'attempt'  => $job['attempt'] + 1,
		) );
	}
}
