<?php

/**
 * @wordpress-plugin
 * Plugin Name:             SouthPay Gateway for WooCommerce
 * Description:             Cryptocurrency Payment Gateway powered by SouthPay.
 * Version:                 1.0.0
 * Author:                  SouthPay
 * Author URI:              https://southpay.io/
 * License:                 GPL-2.0-or-later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             southpay-gateway-for-woocommerce
 * Requires at least:       5.5
 * Requires PHP:            7.4
 * WC requires at least:    4.9.4
 * Requires Plugins:        woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SOUTHPAY_FOR_WOOCOMMERCE_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Add SouthPay to WooCommerce gateways
 */
function southpay_wc_add_to_gateways($gateways)
{
    if (!in_array('WC_Gateway_SouthPay', $gateways, true)) {
        $gateways[] = 'WC_Gateway_SouthPay';
    }
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'southpay_wc_add_to_gateways');

/**
 * Add plugin action links
 */
function southpay_wc_gateway_plugin_links($links)
{
    $plugin_links = array(
        '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=southpay_gateway')) . '">' .
            esc_html__('Configure', 'southpay-gateway-for-woocommerce') .
            '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'southpay_wc_gateway_plugin_links');

add_action('plugins_loaded', 'southpay_wc_gateway_init', 11);

function southpay_wc_gateway_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_SouthPay extends WC_Payment_Gateway
    {
        private $api_url;
        private $webhook_secret;
        private $invoice_prefix;
        private $debug_mode;
        private $log;

        public function __construct()
        {
            $this->id = 'southpay_gateway';
            $this->icon = apply_filters(
                'southpay_wc_gateway_icon',
                plugins_url('assets/images/icons/southpay.png', __FILE__)
            );
            $this->has_fields = false;
            $this->method_title = __('SouthPay', 'southpay-gateway-for-woocommerce');
            $this->method_description = __('Accept cryptocurrency payments via SouthPay', 'southpay-gateway-for-woocommerce');

            $this->init_form_fields();
            $this->init_settings();

            $this->title          = $this->get_option('title');
            $this->description    = $this->get_option('description');
            $this->api_url        = $this->get_option('api_url', 'https://api.southpay.io');
            $this->webhook_secret = $this->get_option('webhook_secret');
            $this->invoice_prefix = $this->get_option('invoice_prefix', 'WC-');
            $this->debug_mode     = 'yes' === $this->get_option('debug_mode');

            $this->log = wc_get_logger();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_southpay', array($this, 'handle_webhook'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'title' => array(
                    'title'       => __('Title', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Payment method title shown to customers at checkout.', 'southpay-gateway-for-woocommerce'),
                    'default'     => __('Crypto via SouthPay', 'southpay-gateway-for-woocommerce'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description shown to customers at checkout.', 'southpay-gateway-for-woocommerce'),
                    'default'     => __('Pay securely with cryptocurrency via SouthPay.', 'southpay-gateway-for-woocommerce'),
                    'desc_tip'    => true,
                ),
                'api_url' => array(
                    'title'       => __('API URL', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'text',
                    'description' => __('SouthPay API base URL.', 'southpay-gateway-for-woocommerce'),
                    'default'     => 'https://api.southpay.io',
                    'desc_tip'    => true,
                ),
                'webhook_secret' => array(
                    'title'       => __('Webhook Secret', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'password',
                    'description' => __('Your store webhook secret from the SouthPay dashboard. Used for both API authentication and webhook signature verification.', 'southpay-gateway-for-woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'invoice_prefix' => array(
                    'title'       => __('Invoice Prefix', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Prefix added to WooCommerce order numbers in merchant references.', 'southpay-gateway-for-woocommerce'),
                    'default'     => 'WC-',
                    'desc_tip'    => true,
                ),
                'debug_mode' => array(
                    'title'       => __('Debug Mode', 'southpay-gateway-for-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable debug logging', 'southpay-gateway-for-woocommerce'),
                    'description' => __('Log debug messages to the WooCommerce log.', 'southpay-gateway-for-woocommerce'),
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $payment_data = array(
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                'currency' => $order->get_currency(),
                'merchant_reference' => $this->invoice_prefix . $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'success_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url_raw(),
                'metadata' => array(
                    'order_id' => (string) $order_id,
                ),
            );

            $response = $this->api_request(
                'POST',
                '/v1/payments',
                $payment_data,
                array('Idempotency-Key' => 'WC-' . $order_id)
            );

            if (is_wp_error($response)) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: error message returned from SouthPay API */
                        __('Payment error: %s', 'southpay-gateway-for-woocommerce'),
                        esc_html($response->get_error_message())
                    ),
                    'error'
                );
                return array('result' => 'failure');
            }

            if (empty($response['data']['checkout_url'])) {
                wc_add_notice(
                    esc_html__('Payment error: Invalid response from payment gateway.', 'southpay-gateway-for-woocommerce'),
                    'error'
                );
                return array('result' => 'failure');
            }

            if (!empty($response['data']['payment_id'])) {
                $order->update_meta_data('_southpay_payment_id', sanitize_text_field($response['data']['payment_id']));
                $order->save();
            }

            $order->update_status(
                'pending',
                __('Awaiting crypto payment via SouthPay.', 'southpay-gateway-for-woocommerce')
            );

            return array(
                'result'   => 'success',
                'redirect' => esc_url_raw($response['data']['checkout_url']),
            );
        }

        public function handle_webhook()
        {
            $raw_body = file_get_contents('php://input');
            $payload  = json_decode($raw_body, true);

            if (!$payload) {
                wp_die('Invalid payload', 'SouthPay Webhook', array('response' => 400));
            }

            // Verify HMAC-SHA512 signature
            // PHP normalises HTTP_* headers from $_SERVER regardless of web server.
            $signature = isset($_SERVER['HTTP_X_SOUTHPAY_SIG'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SOUTHPAY_SIG']))
                : '';

            if (empty($signature) || !$this->verify_webhook_signature($raw_body, $signature)) {
                wp_die('Unauthorized', 'SouthPay Webhook', array('response' => 403));
            }

            $data = isset($payload['data']) ? $payload['data'] : array();

            // Look up order via metadata.order_id embedded in webhook data
            $order_id = isset($data['metadata']['order_id']) ? absint($data['metadata']['order_id']) : 0;
            $order    = $order_id ? wc_get_order($order_id) : false;

            if (!$order) {
                wp_die('Order not found', 'SouthPay Webhook', array('response' => 404));
            }

            $event  = isset($payload['event']) ? sanitize_text_field($payload['event']) : '';
            $status = isset($data['status']) ? sanitize_text_field($data['status']) : '';

            switch ($event) {
                case 'payment.completed':
                    if ('paid' === $status) {
                        $this->payment_completed($order, $data);
                    }
                    break;

                case 'payment.failed':
                    $order->update_status(
                        'failed',
                        __('SouthPay payment failed.', 'southpay-gateway-for-woocommerce')
                    );
                    break;

                case 'payment.expired':
                    $order->update_status(
                        'cancelled',
                        __('SouthPay payment expired.', 'southpay-gateway-for-woocommerce')
                    );
                    break;
            }

            status_header(200);
            exit;
        }

        private function verify_webhook_signature($raw_body, $signature)
        {
            $payload = json_decode($raw_body, true);
            if (!is_array($payload)) {
                return false;
            }

            $sorted   = $this->sort_keys_recursive($payload);
            $json     = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

            if (false === $json) {
                return false;
            }

            $expected = hash_hmac('sha512', $json, $this->webhook_secret);

            return hash_equals($expected, $signature);
        }

        private function sort_keys_recursive($data)
        {
            if (!is_array($data)) {
                return $data;
            }

            if (array_keys($data) === range(0, count($data) - 1)) {
                return array_map(array($this, 'sort_keys_recursive'), $data);
            }

            ksort($data);

            foreach ($data as $key => $value) {
                $data[$key] = $this->sort_keys_recursive($value);
            }

            return $data;
        }

        private function payment_completed($order, $payload)
        {
            if ($order->has_status(array('processing', 'completed'))) {
                return;
            }

            $payment_id      = sanitize_text_field($payload['payment_id']);
            $crypto_amount   = !empty($payload['crypto_amount']) ? sanitize_text_field($payload['crypto_amount']) : '';
            $crypto_currency = !empty($payload['crypto_currency']) ? strtoupper(sanitize_text_field($payload['crypto_currency'])) : '';
            $tx_hash         = !empty($payload['transaction_hash']) ? sanitize_text_field($payload['transaction_hash']) : '';

            $note = sprintf(
                /* translators: 1: Payment ID, 2: Crypto amount, 3: Crypto currency code, 4: Blockchain transaction hash */
                __(
                    'SouthPay payment completed. Payment ID: %1$s | Amount: %2$s %3$s | TX: %4$s',
                    'southpay-gateway-for-woocommerce'
                ),
                $payment_id,
                $crypto_amount,
                $crypto_currency,
                $tx_hash
            );

            $order->add_order_note($note);
            $order->payment_complete($payment_id);
        }

        private function api_request($method, $endpoint, $data = null, $extra_headers = array())
        {
            $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');

            $args = array(
                'method'  => $method,
                'timeout' => 30,
                'headers' => array_merge(
                    array(
                        'Content-Type' => 'application/json',
                        'X-API-Key'    => $this->webhook_secret,
                    ),
                    $extra_headers
                ),
            );

            if ($data && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
                $args['body'] = wp_json_encode($data);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $body        = wp_remote_retrieve_body($response);
            $status_code = wp_remote_retrieve_response_code($response);

            $decoded = json_decode($body, true);

            if ($status_code >= 400) {
                return new WP_Error(
                    'api_error',
                    isset($decoded['error']['message'])
                        ? $decoded['error']['message']
                        : __('Unknown error.', 'southpay-gateway-for-woocommerce')
                );
            }

            return $decoded;
        }

        public function thankyou_page()
        {
            if ($this->description) {
                echo wp_kses_post(wpautop(wptexturize($this->description)));
            }
        }
    }
}
