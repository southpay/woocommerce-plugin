<?php
/**
 * @wordpress-plugin
 * Plugin Name:             SouthPay Gateway for WooCommerce
 * Description:             Cryptocurrency Payment Gateway powered by SouthPay.
 * Version:                 2.0.0
 * Author:                  SouthPay
 * Author URI:              https://southpay.io/
 * License:                 GPL-2.0-or-later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             southpay-gateway-for-woocommerce
 * Requires at least:       5.8
 * Requires PHP:            7.4
 * WC requires at least:    6.0
 * WC tested up to:         9.9
 * Requires Plugins:        woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOUTPAYGW_VERSION', '2.0.0' );
define( 'SOUTPAYGW_PLUGIN_FILE', __FILE__ );
define( 'SOUTPAYGW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOUTPAYGW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOUTPAYGW_API_BASE', 'https://api.southpay.io' );
define( 'SOUTPAYGW_DASHBOARD_BASE', 'https://app.southpay.io' );
define( 'SOUTPAYGW_PAY_BASE', 'https://pay.southpay.io' );

add_action( 'before_woocommerce_init', 'soutpaygw_declare_wc_compatibility' );

function soutpaygw_declare_wc_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SOUTPAYGW_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SOUTPAYGW_PLUGIN_FILE, true );
	}
}

add_action( 'init', 'soutpaygw_load_textdomain' );

function soutpaygw_load_textdomain() {
	load_plugin_textdomain(
		'southpay-gateway-for-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}

add_filter( 'woocommerce_payment_gateways', 'soutpaygw_add_gateway' );

function soutpaygw_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_SoutPayGW';
	return $gateways;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'soutpaygw_plugin_action_links' );

function soutpaygw_plugin_action_links( $links ) {
	$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'southpay-gateway-for-woocommerce' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'plugins_loaded', 'soutpaygw_init', 11 );

function soutpaygw_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once SOUTPAYGW_PLUGIN_DIR . 'includes/class-southpay-gateway.php';

	if ( wp_doing_ajax() ) {
		add_action( 'woocommerce_init', function() {
			WC()->payment_gateways()->payment_gateways();
		} );
	}
}

add_action( 'woocommerce_blocks_payment_method_type_registration', 'soutpaygw_register_blocks_support' );

function soutpaygw_register_blocks_support( $registry ) {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once SOUTPAYGW_PLUGIN_DIR . 'includes/class-southpay-blocks.php';
	$registry->register( new WC_SoutPayGW_Blocks_Support() );
}
