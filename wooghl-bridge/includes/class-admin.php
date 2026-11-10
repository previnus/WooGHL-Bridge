<?php

namespace WooGHL\Bridge;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const PAGE = 'wooghl-bridge';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_wooghl_save', array( self::class, 'save' ) );
		add_action( 'admin_post_wooghl_send_order', array( self::class, 'send_order' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WooGHL Bridge', 'wooghl-bridge' ),
			__( 'WooGHL Bridge', 'wooghl-bridge' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$destination = Destination::load();
		$configured = null !== $destination;
		$enabled = $configured && '' !== $destination->active_fingerprint();
		$ready = $configured && Order_Events::scheduler_ready();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooGHL Bridge', 'wooghl-bridge' ); ?></h1>
			<?php self::notice(); ?>
			<?php if ( is_multisite() ) : ?>
				<p><?php esc_html_e( 'This release supports single-site WordPress installations.', 'wooghl-bridge' ); ?></p>
			<?php elseif ( ! $configured ) : ?>
				<p><?php esc_html_e( 'Set WOOGHL_LOCATION_ID, WOOGHL_PRIVATE_TOKEN and WOOGHL_WORKFLOW_URL in wp-config.php. The workflow URL must belong to the configured HighLevel location.', 'wooghl-bridge' ); ?></p>
			<?php elseif ( ! $ready ) : ?>
				<p><?php esc_html_e( 'WooCommerce Scheduled Actions is unavailable.', 'wooghl-bridge' ); ?></p>
			<?php elseif ( ! $enabled && get_option( Destination::SESSION_OPTION ) ) : ?>
				<p><?php esc_html_e( 'Delivery is paused. Confirm this store and workflow are correct, then enable delivery again.', 'wooghl-bridge' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wooghl_save">
				<?php wp_nonce_field( 'wooghl_save' ); ?>
				<label>
					<input type="checkbox" name="enabled" value="1" <?php checked( $enabled && $ready ); ?> <?php disabled( ! $ready ); ?>>
					<?php esc_html_e( 'Enable contact sync and order event delivery', 'wooghl-bridge' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Pausing invalidates pending deliveries from this session.', 'wooghl-bridge' ); ?></p>
				<?php submit_button(); ?>
			</form>
			<?php if ( $enabled && $ready ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Send an order snapshot', 'wooghl-bridge' ); ?></h2>
				<p><?php esc_html_e( 'This triggers the configured workflow with an order.snapshot event.', 'wooghl-bridge' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wooghl_send_order">
					<?php wp_nonce_field( 'wooghl_send_order' ); ?>
					<label for="wooghl-order-id"><?php esc_html_e( 'WooCommerce order ID', 'wooghl-bridge' ); ?></label>
					<input id="wooghl-order-id" name="order_id" type="number" min="1" required>
					<p><label>
						<input type="checkbox" name="verify_contact" value="1">
						<?php esc_html_e( 'I verified this order’s billing identity. Create or update its HighLevel contact.', 'wooghl-bridge' ); ?>
					</label></p>
					<?php submit_button( __( 'Queue snapshot', 'wooghl-bridge' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Delivery history', 'wooghl-bridge' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ); ?>"><?php esc_html_e( 'Scheduled Actions', 'wooghl-bridge' ); ?></a>
				|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php esc_html_e( 'WooCommerce logs', 'wooghl-bridge' ); ?></a>
			</p>
			<p><?php esc_html_e( 'Look for the wooghl-bridge group in Scheduled Actions and source in WooCommerce logs.', 'wooghl-bridge' ); ?></p>
		</div>
		<?php
	}

	public static function save(): void {
		self::authorize( 'wooghl_save' );
		if ( '1' === self::post( 'enabled' ) ) {
			$destination = Destination::load();
			if ( null === $destination || ! Order_Events::scheduler_ready() ) {
				self::redirect( 'not_ready' );
			}
			if ( ! $destination->activate() ) {
				self::redirect( 'save_failed' );
			}
		} else {
			delete_option( Destination::SESSION_OPTION );
			if ( get_option( Destination::SESSION_OPTION, false ) ) {
				self::redirect( 'save_failed' );
			}
		}
		self::redirect( 'saved' );
	}

	public static function send_order(): void {
		self::authorize( 'wooghl_send_order' );
		$order_id = filter_var( self::post( 'order_id' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			self::redirect( 'invalid_order' );
		}
		$order = wc_get_order( $order_id );
		if ( ! Order_Events::can_send_order( $order ) ) {
			self::redirect( 'invalid_order' );
		}
		$verify_contact = '1' === self::post( 'verify_contact' );
		if ( $verify_contact && ! is_email( trim( $order->get_billing_email() ) ) ) {
			self::redirect( 'invalid_contact' );
		}
		self::redirect( Order_Events::enqueue_snapshot( $order, $verify_contact ) ? 'queued' : 'queue_failed' );
	}

	private static function authorize( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( self::post( '_wpnonce' ), $action ) ) {
			wp_die( esc_html__( 'This action is unauthorized.', 'wooghl-bridge' ), '', array( 'response' => 403 ) );
		}
	}

	private static function post( string $key ): string {
		$value = $_POST[ $key ] ?? '';
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&wooghl_notice=' . $notice ), 303 );
		exit;
	}

	private static function notice(): void {
		$code = $_GET['wooghl_notice'] ?? '';
		$code = is_string( $code ) ? sanitize_key( wp_unslash( $code ) ) : '';
		$messages = array(
			'saved'         => __( 'Settings saved.', 'wooghl-bridge' ),
			'queued'        => __( 'Order snapshot queued. Check delivery history for the result.', 'wooghl-bridge' ),
			'not_ready'     => __( 'Check the HighLevel configuration and Scheduled Actions before enabling the bridge.', 'wooghl-bridge' ),
			'save_failed'   => __( 'The bridge setting could not be saved.', 'wooghl-bridge' ),
			'invalid_order' => __( 'Enter the ID of a valid order you can edit.', 'wooghl-bridge' ),
			'invalid_contact' => __( 'The order needs a valid billing email to sync its contact.', 'wooghl-bridge' ),
			'queue_failed'  => __( 'Could not queue the snapshot. Verify the bridge is enabled and check the logs.', 'wooghl-bridge' ),
		);
		if ( isset( $messages[ $code ] ) ) {
			$level = in_array( $code, array( 'saved', 'queued' ), true ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $level ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
		}
	}
}
