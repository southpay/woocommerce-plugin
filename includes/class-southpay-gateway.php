<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SOUTPAYGW_Gateway extends WC_Payment_Gateway {

	const SUPPORTED_CURRENCIES = array( 'AUD', 'BRL', 'EUR', 'GBP', 'NGN', 'RUB', 'TRY', 'UAH', 'USD', 'USDT', 'USDC' );

	private $api_key;

	private $refresh_token;

	private $access_token_expires_at;

	private $webhook_secret;

	private $oauth_store_id;

	private $show_icon;

	private $invoice_prefix;

	private $min_order_amount;

	private $debug_mode;

	private $log;

	public function __construct() {
		$this->id                 = 'soutpaygw_gateway';
		$this->icon               = apply_filters( 'soutpaygw_icon', SOUTPAYGW_PLUGIN_URL . 'assets/images/icons/southpay.png' );
		$this->has_fields         = false;
		$this->method_title       = __( 'SouthPay', 'southpay-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept cryptocurrency payments via SouthPay. Customers are redirected to a secure hosted checkout.', 'southpay-gateway-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled          = $this->get_option( 'enabled' );
		$this->title            = $this->get_option( 'title' );
		$this->description      = $this->get_option( 'description' );
		$this->show_icon        = 'yes' === $this->get_option( 'show_icon', 'yes' );
		$this->api_key                 = $this->get_option( 'api_key' );
		$this->refresh_token           = $this->get_option( 'refresh_token' );
		$this->access_token_expires_at = (int) $this->get_option( 'access_token_expires_at', '0' );
		$this->webhook_secret          = $this->get_option( 'webhook_secret' );
		$this->oauth_store_id          = $this->get_option( 'oauth_store_id' );
		$this->invoice_prefix   = $this->get_option( 'invoice_prefix', 'WC-' );
		$this->min_order_amount = (float) $this->get_option( 'min_order_amount', '1' );
		$this->debug_mode       = 'yes' === $this->get_option( 'debug_mode' );
		$this->log              = wc_get_logger();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_gateway_soutpaygw', array( $this, 'handle_webhook' ) );
		add_action( 'woocommerce_api_wc_gateway_soutpaygw_oauth', array( $this, 'handle_oauth_callback' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_soutpaygw_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_soutpaygw_reconnect_webhook', array( $this, 'ajax_reconnect_webhook' ) );
		add_action( 'wp_ajax_soutpaygw_init_oauth', array( $this, 'ajax_init_oauth_flow' ) );
		add_action( 'wp_ajax_soutpaygw_disconnect_oauth', array( $this, 'ajax_disconnect_oauth' ) );
	}

	public function get_icon() {
		if ( ! $this->show_icon ) {
			return '';
		}
		$icon_url = SOUTPAYGW_PLUGIN_URL . 'assets/images/icons/southpay.png';
		$icon     = '<img src="' . esc_url( WC_HTTPS::force_https_url( apply_filters( 'soutpaygw_icon', $icon_url ) ) ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-height:24px;width:auto;display:inline-block;vertical-align:middle;" />';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	public function admin_enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( $_GET['tab'] ?? '' ) !== 'checkout' || ( $_GET['section'] ?? '' ) !== $this->id ) {
			return;
		}
		wp_enqueue_style(
			'soutpaygw-admin',
			SOUTPAYGW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SOUTPAYGW_VERSION
		);
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			$this->log_debug( 'is_available: false — gateway not enabled (parent check failed)' );
			return false;
		}

		if ( empty( $this->api_key ) ) {
			$this->log_debug( 'is_available: false — api_key is empty' );
			return false;
		}

		if ( empty( $this->webhook_secret ) ) {
			$this->log_debug( 'is_available: false — webhook_secret is empty' );
			return false;
		}

		$currency = get_woocommerce_currency();
		if ( ! in_array( $currency, self::SUPPORTED_CURRENCIES, true ) ) {
			$this->log_debug( 'is_available: false — unsupported currency: ' . $currency );
			return false;
		}

		if ( $this->min_order_amount > 0 && WC()->cart ) {
			$cart_total = (float) WC()->cart->get_total( 'edit' );
			if ( $cart_total > 0 && $cart_total < $this->min_order_amount ) {
				$this->log_debug( 'is_available: false — cart total ' . $cart_total . ' below minimum ' . $this->min_order_amount );
				return false;
			}
		}

		$this->log_debug( 'is_available: true' );
		return true;
	}

	public function admin_notices() {
		if ( get_transient( 'soutpaygw_reconnect_required' ) ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: link to SouthPay settings page */
						__( 'SouthPay session expired and could not be refreshed. <a href="%s">Reconnect your account</a> to resume accepting payments.', 'southpay-gateway-for-woocommerce' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL param set by this plugin's own OAuth redirect; no form processing occurs.
		$oauth_status = isset( $_GET['soutpaygw_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['soutpaygw_oauth'] ) ) : '';
		if ( 'connected' === $oauth_status ) {
			delete_transient( 'soutpaygw_reconnect_required' );
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'SouthPay connected successfully! Your store is ready to accept crypto payments.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		} elseif ( 'denied' === $oauth_status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'SouthPay connection was cancelled.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		} elseif ( 'error' === $oauth_status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'SouthPay connection failed. Please try again.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		} elseif ( 'testmode' === $oauth_status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'SouthPay returned a test-mode connection, but this gateway only operates in live mode. Nothing was saved. Please reconnect with a live SouthPay account.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		}

		if ( 'yes' !== $this->enabled ) {
			return;
		}

		if ( empty( $this->api_key ) ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: link to SouthPay settings page */
						__( 'SouthPay is enabled but not connected. <a href="%s">Connect your account</a> to start accepting crypto payments.', 'southpay-gateway-for-woocommerce' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
			return;
		}

		if ( ! in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: current store currency code, 2: comma-separated list of supported currencies */
						__( 'SouthPay does not support your store currency (%1$s). Supported currencies: %2$s.', 'southpay-gateway-for-woocommerce' ),
						get_woocommerce_currency(),
						implode( ', ', self::SUPPORTED_CURRENCIES )
					)
				)
			);
		}
	}

	public function init_form_fields() {
		$this->form_fields = array(

			'enabled' => array(
				'title'   => __( 'Enable SouthPay', 'southpay-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Allow customers to pay with cryptocurrency', 'southpay-gateway-for-woocommerce' ),
				'default' => 'no',
			),

			'section_account' => array(
				'title' => __( 'Your SouthPay Account', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'title',
			),

			'connection_status' => array(
				'title' => __( 'Status', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'connection_status',
			),

			'api_key' => array(
				'title'       => __( 'API Key', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: URL to SouthPay dashboard */
					__( 'Optional. Use "Connect with SouthPay" above, or paste a live key from <a href="%s" target="_blank" rel="noopener noreferrer">your SouthPay dashboard</a> (starts with <code>sp_live_</code>). Test keys (<code>sp_test_</code>) are not accepted.', 'southpay-gateway-for-woocommerce' ),
					esc_url( 'https://dashboard.southpay.io/settings/api-keys' )
				),
				'default'     => '',
			),

			'section_appearance' => array(
				'title' => __( 'Checkout Appearance', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'title',
				'description' => __( 'What customers see when choosing a payment method.', 'southpay-gateway-for-woocommerce' ),
			),

			'show_icon' => array(
				'title'       => __( 'Payment Method Logo', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show the SouthPay logo next to the payment method name', 'southpay-gateway-for-woocommerce' ),
				'description' => __( 'Uncheck to hide the logo at checkout and show only the payment method name.', 'southpay-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			'title' => array(
				'title'       => __( 'Payment Method Name', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers at checkout, e.g. "Pay with Crypto".', 'southpay-gateway-for-woocommerce' ),
				'default'     => __( 'Crypto via SouthPay', 'southpay-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),

			'description' => array(
				'title'       => __( 'Payment Method Description', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Optional short text shown below the payment method name.', 'southpay-gateway-for-woocommerce' ),
				'default'     => __( 'Pay securely with cryptocurrency via SouthPay.', 'southpay-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),

			'section_advanced' => array(
				'title' => __( 'Advanced', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'title',
			),

			'min_order_amount' => array(
				'title'             => __( 'Minimum Order Amount', 'southpay-gateway-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Hide SouthPay at checkout when the cart total is below this amount. Set to 0 to always show it.', 'southpay-gateway-for-woocommerce' ),
				'default'           => '1',
				'desc_tip'          => false,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),

			'invoice_prefix' => array(
				'title'       => __( 'Order Reference Prefix', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Added to order numbers in your SouthPay dashboard, e.g. WC-100.', 'southpay-gateway-for-woocommerce' ),
				'default'     => 'WC-',
				'desc_tip'    => true,
			),

			'debug_mode' => array(
				'title'       => __( 'Debug Logging', 'southpay-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable', 'southpay-gateway-for-woocommerce' ),
				'description' => __( 'Writes detailed logs to WooCommerce → Status → Logs. Only enable when troubleshooting.', 'southpay-gateway-for-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),

			'webhook_secret'           => array(
				'type'    => 'hidden',
				'default' => '',
			),
			'webhook_endpoint_id'      => array(
				'type'    => 'hidden',
				'default' => '',
			),
			'oauth_store_id'           => array(
				'type'    => 'hidden',
				'default' => '',
			),
			'refresh_token'            => array(
				'type'    => 'hidden',
				'default' => '',
			),
			'access_token_expires_at'  => array(
				'type'    => 'hidden',
				'default' => '0',
			),
		);
	}

	public function generate_connection_status_html( $key, $data ) {
		$has_key      = ! empty( $this->api_key );
		$has_secret   = ! empty( $this->webhook_secret );
		$is_oauth     = $has_key && strpos( $this->api_key, 'spo_' ) === 0;
		$is_connected = $has_key && $has_secret;
		$nonce        = wp_create_nonce( 'soutpaygw_admin' );
		$webhook_url  = add_query_arg( 'wc-api', 'wc_gateway_soutpaygw', trailingslashit( home_url() ) );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="soutpaygw-connection-card">

					<?php if ( $is_connected ) : ?>
						<div class="soutpaygw-status soutpaygw-status--connected">
							<span class="soutpaygw-status-dot"></span>
							<span class="soutpaygw-status-label">
								<?php echo $is_oauth
									? esc_html__( 'Connected via SouthPay', 'southpay-gateway-for-woocommerce' )
									: esc_html__( 'Connected to SouthPay', 'southpay-gateway-for-woocommerce' );
								?>
							</span>
						</div>
						<div class="soutpaygw-actions">
							<button type="button" class="button button-secondary" id="soutpaygw-test-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Test connection', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
							<button type="button" class="button button-link" id="soutpaygw-reconnect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Reconnect webhook', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
							<?php if ( $is_oauth ) : ?>
								<button type="button" class="button button-link soutpaygw-disconnect-link" id="soutpaygw-disconnect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Disconnect', 'southpay-gateway-for-woocommerce' ); ?>
								</button>
							<?php endif; ?>
						</div>

					<?php elseif ( $is_oauth && $has_key ) : ?>
						<div class="soutpaygw-status soutpaygw-status--warning">
							<span class="soutpaygw-status-dot"></span>
							<span class="soutpaygw-status-label"><?php esc_html_e( 'Connected — webhook setup incomplete', 'southpay-gateway-for-woocommerce' ); ?></span>
						</div>
						<div class="soutpaygw-actions">
							<button type="button" class="button button-primary" id="soutpaygw-reconnect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Reconnect webhook', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
							<button type="button" class="button button-link soutpaygw-disconnect-link" id="soutpaygw-disconnect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Disconnect', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
						</div>

					<?php elseif ( $has_key ) : ?>
						<div class="soutpaygw-status soutpaygw-status--warning">
							<span class="soutpaygw-status-dot"></span>
							<span class="soutpaygw-status-label"><?php esc_html_e( 'API key saved — save settings to finish connecting', 'southpay-gateway-for-woocommerce' ); ?></span>
						</div>

					<?php else : ?>
						<div class="soutpaygw-status soutpaygw-status--disconnected">
							<span class="soutpaygw-status-dot"></span>
							<span class="soutpaygw-status-label"><?php esc_html_e( 'Not connected', 'southpay-gateway-for-woocommerce' ); ?></span>
						</div>
						<div class="soutpaygw-connect-cta">
							<button type="button" class="button button-primary button-hero" id="soutpaygw-connect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Connect with SouthPay', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
							<p class="soutpaygw-hint">
								<?php esc_html_e( 'One click — sign in to your SouthPay account and your store is set up automatically. Or enter an API key below instead.', 'southpay-gateway-for-woocommerce' ); ?>
							</p>
						</div>
					<?php endif; ?>

					<p id="soutpaygw-status-msg"></p>

					<?php if ( $is_connected ) : ?>
						<div class="soutpaygw-webhook-row">
							<span><?php esc_html_e( 'Webhook URL:', 'southpay-gateway-for-woocommerce' ); ?></span>
							<input type="text" value="<?php echo esc_attr( $webhook_url ); ?>" readonly onclick="this.select()" />
						</div>
					<?php endif; ?>

				</div>
			</td>
		</tr>
		<script>
		(function($){
			$('#soutpaygw-connect-btn').on('click', function(){
				var $btn = $(this), $msg = $('#soutpaygw-status-msg');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Redirecting…', 'southpay-gateway-for-woocommerce' ) ); ?>');
				$.post(ajaxurl, { action: 'soutpaygw_init_oauth', nonce: $btn.data('nonce') })
					.done(function(r){
						if (r && r.success && r.data && r.data.url) {
							window.location.assign(r.data.url);
						} else {
							$msg.text((r && r.data) || '<?php echo esc_js( __( 'Could not start OAuth flow. Please try again.', 'southpay-gateway-for-woocommerce' ) ); ?>').css('color','#d63638');
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Connect with SouthPay', 'southpay-gateway-for-woocommerce' ) ); ?>');
						}
					})
					.fail(function(xhr, status, error){
						$msg.text('<?php echo esc_js( __( 'Request failed (', 'southpay-gateway-for-woocommerce' ) ); ?>' + xhr.status + '). <?php echo esc_js( __( 'Check browser console for details.', 'southpay-gateway-for-woocommerce' ) ); ?>').css('color','#d63638');
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Connect with SouthPay', 'southpay-gateway-for-woocommerce' ) ); ?>');
					});
			});

			$('#soutpaygw-test-btn').on('click', function(){
				var $btn = $(this), $msg = $('#soutpaygw-status-msg');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Testing…', 'southpay-gateway-for-woocommerce' ) ); ?>');
				$.post(ajaxurl, { action: 'soutpaygw_test_connection', nonce: $btn.data('nonce') }, function(r){
					$msg.text(r.data).css('color', r.success ? '#00a32a' : '#d63638');
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test connection', 'southpay-gateway-for-woocommerce' ) ); ?>');
				});
			});

			$('#soutpaygw-reconnect-btn').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'This will create a new webhook endpoint and replace the current one. Continue?', 'southpay-gateway-for-woocommerce' ) ); ?>')) return;
				var $btn = $(this), $msg = $('#soutpaygw-status-msg');
				$btn.prop('disabled', true);
				$.post(ajaxurl, { action: 'soutpaygw_reconnect_webhook', nonce: $btn.data('nonce') }, function(r){
					$msg.text(r.data).css('color', r.success ? '#00a32a' : '#d63638');
					if (r.success) location.reload();
					else $btn.prop('disabled', false);
				});
			});

			$('#soutpaygw-disconnect-btn').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'Disconnect your SouthPay account? You will need to reconnect to continue accepting payments.', 'southpay-gateway-for-woocommerce' ) ); ?>')) return;
				var $btn = $(this), $msg = $('#soutpaygw-status-msg');
				$btn.prop('disabled', true);
				$.post(ajaxurl, { action: 'soutpaygw_disconnect_oauth', nonce: $btn.data('nonce') }, function(r){
					if (r.success) location.reload();
					else {
						$msg.text(r.data).css('color','#d63638');
						$btn.prop('disabled', false);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
		return ob_get_clean();
	}

	public function generate_hidden_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$value     = $this->get_option( $key, isset( $data['default'] ) ? $data['default'] : '' );
		return '<input type="hidden" name="' . esc_attr( $field_key ) . '" value="' . esc_attr( $value ) . '" />';
	}

	public function process_admin_options() {
		$result = parent::process_admin_options();

		$this->api_key        = $this->get_option( 'api_key' );
		$this->webhook_secret = $this->get_option( 'webhook_secret' );

		if ( 0 === strpos( (string) $this->api_key, 'sp_test_' ) ) {
			$settings            = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
			$settings['api_key'] = '';
			update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );
			$this->api_key = '';

			WC_Admin_Settings::add_error( __( 'SouthPay only operates in live mode. The key you entered is a test key (sp_test_…) and was not saved. Paste a live key (sp_live_…) or use "Connect with SouthPay".', 'southpay-gateway-for-woocommerce' ) );
			return $result;
		}

		if ( ! empty( $this->api_key ) && empty( $this->webhook_secret ) ) {
			$this->register_webhook_endpoint();
		}

		return $result;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$line_items = array();
		$image_url  = '';

		foreach ( $order->get_items() as $item ) {
			$quantity   = max( 1, (int) $item->get_quantity() );
			$unit_cents = (int) round( ( (float) $item->get_subtotal() / $quantity ) * 100 );

			$item_image = '';
			$product    = $item->get_product();
			if ( $product && $product->get_image_id() ) {
				$item_image = wp_get_attachment_url( $product->get_image_id() );
			}

			if ( '' === $image_url && $item_image ) {
				$image_url = $item_image;
			}

			$line_item = array(
				'description'       => $item->get_name(),
				'quantity'          => $quantity,
				'unit_amount_cents' => $unit_cents,
			);

			if ( $item_image ) {
				$line_item['image_url'] = esc_url_raw( $item_image );
			}

			$line_items[] = $line_item;
		}

		$body = array(
			'payment_intent' => array(
				'amount'      => number_format( (float) $order->get_total(), 2, '.', '' ),
				'currency'    => $order->get_currency(),
				'success_url' => $this->get_return_url( $order ),
				'failed_url'  => $order->get_cancel_order_url_raw(),
				'metadata'    => array(
					'order_id'  => (string) $order_id,
					'order_ref' => $this->invoice_prefix . $order->get_order_number(),
					'source'    => 'woocommerce',
				),
			),
		);

		if ( ! empty( $line_items ) ) {
			$body['payment_intent']['line_items'] = $line_items;
		}

		if ( '' !== $image_url ) {
			$body['payment_intent']['image_url'] = esc_url_raw( $image_url );
		}

		$response = $this->api_request(
			'POST',
			'/api/v2/payments',
			$body,
			array( 'Idempotency-Key' => 'WC-' . $order_id . '-' . $order->get_order_key() )
		);

		if ( is_wp_error( $response ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message returned from SouthPay API */
					__( 'Payment error: %s', 'southpay-gateway-for-woocommerce' ),
					esc_html( $response->get_error_message() )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$reference = isset( $response['reference'] ) ? sanitize_text_field( $response['reference'] ) : '';

		if ( empty( $reference ) ) {
			$this->log_debug( 'process_payment: missing reference in response' );
			wc_add_notice( esc_html__( 'Payment could not be started. Please try again.', 'southpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$checkout_url = apply_filters(
			'soutpaygw_checkout_url',
			trailingslashit( SOUTPAYGW_PAY_BASE ) . rawurlencode( $reference ),
			$reference,
			$order
		);

		$order->update_meta_data( '_soutpaygw_reference', $reference );
		$order->update_status( 'pending', __( 'Awaiting SouthPay crypto payment.', 'southpay-gateway-for-woocommerce' ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	public function handle_webhook() {
		$raw_body = file_get_contents( 'php://input' );

		if ( empty( $raw_body ) ) {
			wp_die( 'Empty payload', 'SouthPay Webhook', array( 'response' => 400 ) );
		}

		$signature = isset( $_SERVER['HTTP_SOUTHPAY_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SOUTHPAY_SIGNATURE'] ) )
			: '';

		if ( empty( $signature ) || ! $this->verify_webhook_signature( $raw_body, $signature ) ) {
			$this->log_debug( 'handle_webhook: signature verification failed' );
			wp_die( 'Unauthorized', 'SouthPay Webhook', array( 'response' => 403 ) );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) ) {
			wp_die( 'Invalid JSON', 'SouthPay Webhook', array( 'response' => 400 ) );
		}

		$event_type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';
		$object     = isset( $payload['data']['object'] ) ? $payload['data']['object'] : array();
		$order_id   = isset( $object['metadata']['order_id'] ) ? absint( $object['metadata']['order_id'] ) : 0;
		$order      = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_die( 'Order not found', 'SouthPay Webhook', array( 'response' => 404 ) );
		}

		$this->log_debug( 'handle_webhook: event=' . $event_type . ' order=' . $order_id );

		switch ( $event_type ) {
			case 'payment_intent.completed':
				$this->payment_completed( $order, $object );
				break;

			case 'payment_intent.failed':
				if ( ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
					$order->update_status( 'failed', __( 'SouthPay payment failed.', 'southpay-gateway-for-woocommerce' ) );
				}
				break;

			case 'payment_intent.expired':
				if ( ! $order->has_status( array( 'failed', 'cancelled', 'processing', 'completed' ) ) ) {
					$order->update_status( 'cancelled', __( 'SouthPay payment expired without being completed.', 'southpay-gateway-for-woocommerce' ) );
				}
				break;
		}

		status_header( 200 );
		exit;
	}

	private function verify_webhook_signature( $raw_body, $signature_header ) {
		if ( empty( $this->webhook_secret ) ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', $signature_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$signed_payload = $parts['t'] . '.' . $raw_body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $this->webhook_secret );

		return hash_equals( $expected, $parts['v1'] );
	}

	private function payment_completed( $order, $object ) {
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}

		$reference = isset( $object['id'] ) ? sanitize_text_field( $object['id'] ) : '';
		$amount    = isset( $object['settlement_amount_cents'] ) ? absint( $object['settlement_amount_cents'] ) : 0;
		$currency  = isset( $object['settlement_currency'] ) ? strtoupper( sanitize_text_field( $object['settlement_currency'] ) ) : '';

		$note = sprintf(
			/* translators: 1: SouthPay payment reference, 2: formatted settlement amount, 3: currency code */
			__( 'Payment confirmed by SouthPay. Reference: %1$s | Amount: %2$s %3$s', 'southpay-gateway-for-woocommerce' ),
			$reference,
			number_format( $amount / 100, 2 ),
			$currency
		);

		$order->add_order_note( $note );
		$order->payment_complete( $reference );
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}

		echo '<div class="woocommerce-info">' .
			esc_html__( 'Your crypto payment is being confirmed on the blockchain. We\'ll send you an email as soon as it clears — usually within a few minutes.', 'southpay-gateway-for-woocommerce' ) .
			'</div>';
	}

	private function register_webhook_endpoint( $force = false ) {
		$webhook_url = add_query_arg( 'wc-api', 'wc_gateway_soutpaygw', trailingslashit( home_url() ) );
		$this->log_debug( 'register_webhook_endpoint: url=' . $webhook_url . ' force=' . ( $force ? 'yes' : 'no' ) . ' api_key_prefix=' . substr( $this->api_key, 0, 12 ) );

		$response = $this->api_request( 'POST', '/api/v2/webhook_endpoints', array(
			'webhook_endpoint' => array(
				'platform'           => 'woocommerce',
				'platform_store_url' => home_url(),
				'url'                => $webhook_url,
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'register_webhook_endpoint error: ' . $response->get_error_message() . ' code=' . $response->get_error_code() );

			if ( 'soutpaygw_api_conflict' === $response->get_error_code() || $force ) {
				$this->fetch_and_rotate_webhook_secret( $webhook_url );
			}
			return;
		}

		$this->log_debug( 'register_webhook_endpoint: created — id=' . ( $response['id'] ?? 'n/a' ) . ' has_secret=' . ( ! empty( $response['signing_secret'] ) ? 'yes' : 'no' ) );

		if ( ! empty( $response['signing_secret'] ) && ! empty( $response['id'] ) ) {
			$this->persist_webhook_credentials( $response['id'], $response['signing_secret'] );
		}
	}

	private function fetch_and_rotate_webhook_secret( $webhook_url ) {
		$this->log_debug( 'fetch_and_rotate_webhook_secret: fetching endpoint list' );
		$list = $this->api_request( 'GET', '/api/v2/webhook_endpoints' );

		if ( is_wp_error( $list ) ) {
			$this->log_debug( 'fetch_and_rotate_webhook_secret: list request failed — ' . $list->get_error_message() );
			return;
		}

		if ( ! is_array( $list ) ) {
			$this->log_debug( 'fetch_and_rotate_webhook_secret: unexpected response type: ' . gettype( $list ) . ' value=' . wp_json_encode( $list ) );
			return;
		}

		$this->log_debug( 'fetch_and_rotate_webhook_secret: got ' . count( $list ) . ' endpoints' );

		foreach ( $list as $i => $endpoint ) {
			$platform = isset( $endpoint['platform'] ) ? $endpoint['platform'] : '(none)';
			$ep_id    = isset( $endpoint['id'] ) ? $endpoint['id'] : '(none)';
			$ep_url   = isset( $endpoint['url'] ) ? $endpoint['url'] : '(none)';
			$this->log_debug( 'fetch_and_rotate_webhook_secret: endpoint[' . $i . '] id=' . $ep_id . ' platform=' . $platform . ' url=' . $ep_url );

			if ( 'woocommerce' !== $platform || empty( $ep_id ) ) {
				continue;
			}

			$endpoint_id = sanitize_text_field( $ep_id );
			$this->log_debug( 'fetch_and_rotate_webhook_secret: rotating secret for endpoint ' . $endpoint_id );
			$rotate = $this->api_request( 'POST', '/api/v2/webhook_endpoints/' . $endpoint_id . '/rotate_secret' );

			if ( is_wp_error( $rotate ) ) {
				$this->log_debug( 'fetch_and_rotate_webhook_secret: rotate request failed — ' . $rotate->get_error_message() );
			} elseif ( empty( $rotate['signing_secret'] ) ) {
				$this->log_debug( 'fetch_and_rotate_webhook_secret: rotate response missing signing_secret — ' . wp_json_encode( $rotate ) );
			} else {
				$this->persist_webhook_credentials( $endpoint_id, $rotate['signing_secret'] );
				$this->log_debug( 'fetch_and_rotate_webhook_secret: success — webhook_secret saved' );
			}
			return;
		}

		$this->log_debug( 'fetch_and_rotate_webhook_secret: no woocommerce endpoint found among ' . count( $list ) . ' endpoints' );
	}

	private function persist_webhook_credentials( $endpoint_id, $signing_secret ) {
		$this->log_debug( 'persist_webhook_credentials: saving endpoint_id=' . $endpoint_id );
		$settings                        = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
		$settings['webhook_secret']      = $signing_secret;
		$settings['webhook_endpoint_id'] = $endpoint_id;
		update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );
		$this->webhook_secret = $signing_secret;
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$response = $this->api_request( 'GET', '/api/v2/payments' );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach SouthPay: %s', 'southpay-gateway-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		wp_send_json_success( __( 'Connection successful!', 'southpay-gateway-for-woocommerce' ) );
	}

	public function ajax_reconnect_webhook() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$this->log_debug( 'ajax_reconnect_webhook: starting — api_key_prefix=' . substr( $this->api_key, 0, 12 ) );

		$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
		$settings['webhook_secret']      = '';
		$settings['webhook_endpoint_id'] = '';
		update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );
		$this->webhook_secret = '';

		$this->register_webhook_endpoint( true );

		if ( ! empty( $this->webhook_secret ) ) {
			$this->log_debug( 'ajax_reconnect_webhook: success' );
			wp_send_json_success( __( 'Webhook reconnected successfully.', 'southpay-gateway-for-woocommerce' ) );
		} else {
			$this->log_debug( 'ajax_reconnect_webhook: failed — webhook_secret still empty after registration attempt' );
			wp_send_json_error( __( 'Could not reconnect. Check your API key and try again.', 'southpay-gateway-for-woocommerce' ) );
		}
	}

	private function api_request( $method, $endpoint, $data = null, $extra_headers = array(), $allow_refresh = true ) {
		if ( $this->should_proactively_refresh() ) {
			$refresh = $this->refresh_access_token();
			if ( is_wp_error( $refresh ) ) {
				return $refresh;
			}
		}

		$url  = trailingslashit( SOUTPAYGW_API_BASE ) . ltrim( $endpoint, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
					'User-Agent'    => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
					'X-Livemode'    => 'true',
				),
				$extra_headers
			),
		);

		if ( $data && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'api_request error: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		$this->log_debug( sprintf( '%s %s → %d', $method, $endpoint, $status_code ) );

		if ( 401 === $status_code && $allow_refresh && ! empty( $this->refresh_token ) && $this->is_oauth_token() ) {
			$refresh = $this->refresh_access_token();
			if ( ! is_wp_error( $refresh ) ) {
				return $this->api_request( $method, $endpoint, $data, $extra_headers, false );
			}
		}

		if ( $status_code >= 400 ) {
			$this->log_debug( sprintf( 'api_request body: %s', $body ) );
		}

		if ( 409 === $status_code ) {
			return new WP_Error( 'soutpaygw_api_conflict', __( 'A webhook endpoint already exists for this store.', 'southpay-gateway-for-woocommerce' ) );
		}

		if ( $status_code >= 400 ) {
			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: ( isset( $decoded['error_description'] ) ? $decoded['error_description'] : __( 'An error occurred. Please try again.', 'southpay-gateway-for-woocommerce' ) );
			return new WP_Error( 'soutpaygw_api_error', $message );
		}

		return $decoded;
	}

	private function is_oauth_token() {
		return ! empty( $this->api_key ) && strpos( $this->api_key, 'spo_' ) === 0;
	}

	private function should_proactively_refresh() {
		if ( empty( $this->refresh_token ) || $this->access_token_expires_at <= 0 ) {
			return false;
		}
		return ( $this->access_token_expires_at - time() ) < 60;
	}

	private function log_debug( $message ) {
		if ( $this->debug_mode ) {
			$this->log->debug( $message, array( 'source' => 'southpay-gateway' ) );
		}
	}

	public function ajax_init_oauth_flow() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$code_verifier  = bin2hex( random_bytes( 64 ) );
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$state = wp_generate_password( 24, false );

		set_transient( 'soutpaygw_pkce_verifier', $code_verifier, 600 );
		set_transient( 'soutpaygw_oauth_state', $state, 600 );

		$callback_url = add_query_arg( 'wc-api', 'wc_gateway_soutpaygw_oauth', trailingslashit( home_url() ) );

		$authorize_url = trailingslashit( SOUTPAYGW_DASHBOARD_BASE ) . 'oauth/authorize?' . http_build_query( array(
			'client_id'             => SOUTPAYGW_OAUTH_CLIENT_ID,
			'response_type'         => 'code',
			'redirect_uri'          => $callback_url,
			'scope'                 => SOUTPAYGW_OAUTH_SCOPES,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
			'state'                 => $state,
			'livemode'              => 'true',
		) );

		wp_send_json_success( array( 'url' => $authorize_url ) );
	}

	public function handle_oauth_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is handled by the state/PKCE verifier checked below.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );

		if ( $error ) {
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'denied', $settings_url ) );
			exit;
		}

		$saved_state    = get_transient( 'soutpaygw_oauth_state' );
		$code_verifier  = get_transient( 'soutpaygw_pkce_verifier' );

		delete_transient( 'soutpaygw_oauth_state' );
		delete_transient( 'soutpaygw_pkce_verifier' );

		if ( empty( $state ) || ! hash_equals( (string) $saved_state, $state ) ) {
			$this->log_debug( 'handle_oauth_callback: state mismatch' );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		if ( empty( $code ) || empty( $code_verifier ) ) {
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		$callback_url = add_query_arg( 'wc-api', 'wc_gateway_soutpaygw_oauth', trailingslashit( home_url() ) );

		$body = $this->post_token_endpoint( array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'code_verifier' => $code_verifier,
			'redirect_uri'  => $callback_url,
			'client_id'     => SOUTPAYGW_OAUTH_CLIENT_ID,
		) );

		if ( is_wp_error( $body ) ) {
			$this->log_debug( 'handle_oauth_callback token exchange failed: ' . $body->get_error_message() );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		if ( array_key_exists( 'livemode', $body ) && ! filter_var( $body['livemode'], FILTER_VALIDATE_BOOLEAN ) ) {
			$this->log_debug( 'handle_oauth_callback: refusing test-mode token' );
			$this->revoke_token_value( ! empty( $body['refresh_token'] ) ? $body['refresh_token'] : ( $body['access_token'] ?? '' ) );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'testmode', $settings_url ) );
			exit;
		}

		$this->persist_token_response( $body );
		$this->register_webhook_endpoint();

		wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'connected', $settings_url ) );
		exit;
	}

	private function post_token_endpoint( array $payload ) {
		$response = wp_remote_post(
			trailingslashit( SOUTPAYGW_API_BASE ) . 'api/v2/oauth/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status !== 200 || empty( $body['access_token'] ) ) {
			$error_code = is_array( $body ) && isset( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'unknown';
			return new WP_Error( 'soutpaygw_token_error', sprintf( 'status=%d error=%s', $status, $error_code ) );
		}

		return $body;
	}

	private function persist_token_response( array $body ) {
		$access_token  = sanitize_text_field( $body['access_token'] );
		$refresh_token = isset( $body['refresh_token'] ) ? sanitize_text_field( $body['refresh_token'] ) : '';
		$store_id      = isset( $body['store_id'] ) ? sanitize_text_field( $body['store_id'] ) : '';
		$expires_in    = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 0;
		$expires_at    = $expires_in > 0 ? time() + $expires_in : 0;

		$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
		$settings['api_key']                 = $access_token;
		$settings['refresh_token']           = $refresh_token;
		$settings['access_token_expires_at'] = (string) $expires_at;
		if ( $store_id !== '' ) {
			$settings['oauth_store_id'] = $store_id;
		}
		if ( ! isset( $settings['webhook_secret'] ) || empty( $settings['webhook_secret'] ) ) {
			$settings['webhook_secret']      = '';
			$settings['webhook_endpoint_id'] = '';
		}
		update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );

		$this->api_key                 = $access_token;
		$this->refresh_token           = $refresh_token;
		$this->access_token_expires_at = $expires_at;
		if ( $store_id !== '' ) {
			$this->oauth_store_id = $store_id;
		}
	}

	private function refresh_access_token() {
		if ( empty( $this->refresh_token ) ) {
			return new WP_Error( 'soutpaygw_no_refresh_token', __( 'No refresh token available — manual reconnect required.', 'southpay-gateway-for-woocommerce' ) );
		}

		$lock_key = 'soutpaygw_refresh_lock';
		if ( ! wp_cache_add( $lock_key, 1, '', 30 ) ) {
			usleep( 250000 );
			$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
			if ( ! empty( $settings['api_key'] ) ) {
				$this->api_key                 = $settings['api_key'];
				$this->refresh_token           = $settings['refresh_token'] ?? '';
				$this->access_token_expires_at = (int) ( $settings['access_token_expires_at'] ?? 0 );
				return true;
			}
		}

		try {
			$body = $this->post_token_endpoint( array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->refresh_token,
				'client_id'     => SOUTPAYGW_OAUTH_CLIENT_ID,
			) );

			if ( is_wp_error( $body ) ) {
				$this->log_debug( 'refresh_access_token failed: ' . $body->get_error_message() );
				$this->handle_refresh_failure();
				return $body;
			}

			$this->persist_token_response( $body );
			$this->log_debug( 'refresh_access_token: rotated successfully' );
			return true;
		} finally {
			wp_cache_delete( $lock_key );
		}
	}

	private function handle_refresh_failure() {
		$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
		$settings['api_key']                 = '';
		$settings['refresh_token']           = '';
		$settings['access_token_expires_at'] = '0';
		$settings['webhook_secret']          = '';
		$settings['webhook_endpoint_id']     = '';
		update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );

		$this->api_key                 = '';
		$this->refresh_token           = '';
		$this->access_token_expires_at = 0;
		$this->webhook_secret          = '';

		set_transient( 'soutpaygw_reconnect_required', 1, DAY_IN_SECONDS );
	}

	public function ajax_disconnect_oauth() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$this->revoke_token_value( ! empty( $this->refresh_token ) ? $this->refresh_token : $this->api_key );

		$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );
		$settings['api_key']                 = '';
		$settings['refresh_token']           = '';
		$settings['access_token_expires_at'] = '0';
		$settings['oauth_store_id']          = '';
		$settings['webhook_secret']          = '';
		$settings['webhook_endpoint_id']     = '';
		update_option( 'woocommerce_soutpaygw_gateway_settings', $settings );

		delete_transient( 'soutpaygw_reconnect_required' );

		wp_send_json_success( __( 'Disconnected successfully.', 'southpay-gateway-for-woocommerce' ) );
	}

	private function revoke_token_value( $token ) {
		if ( empty( $token ) ) {
			return;
		}

		wp_remote_post(
			trailingslashit( SOUTPAYGW_API_BASE ) . 'api/v2/oauth/revoke',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
				),
				'body' => '{}',
			)
		);
	}
}
