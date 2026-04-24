<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_SoutPayGW_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	protected $name = 'soutpaygw_gateway';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'soutpaygw-blocks',
			SOUTPAYGW_PLUGIN_URL . 'assets/js/southpay-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			SOUTPAYGW_VERSION,
			true
		);
		return array( 'soutpaygw-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Crypto via SouthPay', 'southpay-gateway-for-woocommerce' ) ),
			'description' => $this->get_setting( 'description', __( 'Pay securely with cryptocurrency via SouthPay.', 'southpay-gateway-for-woocommerce' ) ),
			'icon'        => SOUTPAYGW_PLUGIN_URL . 'assets/images/icons/southpay.png',
			'supports'    => array( 'products' ),
		);
	}
}
