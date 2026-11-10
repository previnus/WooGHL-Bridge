<?php
/**
 * Plugin Name: WooGHL Bridge
 * Description: Sync WooCommerce billing contacts and send order events to a HighLevel workflow.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.3
 * Author: Previn Kalisetty Appadu
 * Author URI: https://previn.me
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Text Domain: wooghl-bridge
 */

namespace WooGHL\Bridge;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-destination.php';
require_once __DIR__ . '/includes/class-highlevel-client.php';
require_once __DIR__ . '/includes/class-order-events.php';
require_once __DIR__ . '/includes/class-admin.php';

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', static function (): void {
	if ( function_exists( 'wc_get_order' ) ) {
		Order_Events::register();
		Admin::register();
	}
} );

register_deactivation_hook( __FILE__, static function (): void {
	delete_option( Destination::SESSION_OPTION );
} );
