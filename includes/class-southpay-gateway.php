<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SOUTPAYGW_Gateway extends WC_Payment_Gateway {

	const SUPPORTED_CURRENCIES = array( 'EUR' );

	const PAYMENTS_SCOPE = 'payments:write';

	const CONNECTION_OPTION = 'soutpaygw_connection';

	const REFRESH_LOCK_OPTION = 'soutpaygw_refresh_lock';

	const OAUTH_CALLBACK = 'southpay';

	const LEGACY_SETTINGS = array( 'api_key', 'refresh_token', 'access_token_expires_at', 'webhook_secret', 'webhook_endpoint_id', 'oauth_store_id' );

	private $connection;

	private $access_token;

	private $refresh_token;

	private $access_token_expires_at;

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
		$this->invoice_prefix   = $this->get_option( 'invoice_prefix', 'WC-' );
		$this->min_order_amount = (float) $this->get_option( 'min_order_amount', '1' );
		$this->debug_mode       = 'yes' === $this->get_option( 'debug_mode' );
		$this->log              = wc_get_logger();

		$this->load_connection( get_option( self::CONNECTION_OPTION, array() ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . self::OAUTH_CALLBACK, array( $this, 'handle_oauth_callback' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'soutpaygw_sync_orders', array( $this, 'sync_open_orders' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'keep_paid_order' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_soutpaygw_test_connection', array( $this, 'ajax_test_connection' ) );
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

		if ( ! $this->is_connected() ) {
			$this->log_debug( 'is_available: false — not connected to SouthPay' );
			return false;
		}

		if ( ! $this->can_take_payments() ) {
			$this->log_debug( 'is_available: false — this connection cannot take payments yet' );
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
		$reconnect_required = get_transient( 'soutpaygw_reconnect_required' );

		if ( $reconnect_required && $this->is_connected() ) {
			delete_transient( 'soutpaygw_reconnect_required' );
			$reconnect_required = false;
		}

		if ( $reconnect_required ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: link to SouthPay settings page */
						__( 'This site is no longer connected to SouthPay. <a href="%s">Connect it again</a> to keep using SouthPay here.', 'southpay-gateway-for-woocommerce' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$oauth_status = isset( $_GET['soutpaygw_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['soutpaygw_oauth'] ) ) : '';
		if ( 'connected' === $oauth_status && $this->is_connected() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'This site is now connected to your SouthPay store.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		} elseif ( 'denied' === $oauth_status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'SouthPay connection was cancelled.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		} elseif ( 'error' === $oauth_status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'SouthPay connection failed. Please try again.', 'southpay-gateway-for-woocommerce' ) .
				'</p></div>';
		}

		if ( 'yes' !== $this->enabled ) {
			return;
		}

		if ( ! $this->is_connected() ) {
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

		if ( ! $this->can_take_payments() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: link to SouthPay settings page */
						__( 'SouthPay needs permission to take payments before customers can pay with it. <a href="%s">Connect again</a> and approve taking payments.', 'southpay-gateway-for-woocommerce' ),
						esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
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
				'default' => 'yes',
			),

			'section_account' => array(
				'title' => __( 'Your SouthPay Account', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'title',
			),

			'connection_status' => array(
				'title' => __( 'Status', 'southpay-gateway-for-woocommerce' ),
				'type'  => 'connection_status',
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
		);
	}

	public function generate_connection_status_html( $key, $data ) {
		$is_connected = $this->is_connected();
		$store_name   = (string) ( $this->connection['store_name'] ?? '' );
		$nonce        = wp_create_nonce( 'soutpaygw_admin' );

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
								<?php
								if ( '' !== $store_name ) {
									/* translators: %s: name of the connected SouthPay store */
									echo esc_html( sprintf( __( 'Connected to %s', 'southpay-gateway-for-woocommerce' ), $store_name ) );
								} else {
									esc_html_e( 'Connected to SouthPay', 'southpay-gateway-for-woocommerce' );
								}
								?>
							</span>
						</div>
						<div class="soutpaygw-actions">
							<button type="button" class="button button-secondary" id="soutpaygw-test-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Test connection', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
							<button type="button" class="button button-link soutpaygw-disconnect-link" id="soutpaygw-disconnect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Disconnect', 'southpay-gateway-for-woocommerce' ); ?>
							</button>
						</div>
						<?php if ( ! $this->can_take_payments() ) : ?>
							<div class="soutpaygw-connect-cta">
								<p class="soutpaygw-hint">
									<?php esc_html_e( 'This connection was made before SouthPay could take payments at checkout. Connect again and approve taking payments so customers can pay with SouthPay.', 'southpay-gateway-for-woocommerce' ); ?>
								</p>
								<button type="button" class="button button-primary" id="soutpaygw-connect-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Connect again', 'southpay-gateway-for-woocommerce' ); ?>
								</button>
							</div>
						<?php endif; ?>

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
								<?php
								if ( $this->has_legacy_credentials() ) {
									esc_html_e( 'This store was connected to the previous SouthPay platform, which has been retired. Connect again to link it to your SouthPay account.', 'southpay-gateway-for-woocommerce' );
								} else {
									esc_html_e( 'Sign in to SouthPay, choose the store to link and approve. Only owners and admins of a SouthPay business can connect.', 'southpay-gateway-for-woocommerce' );
								}
								?>
							</p>
						</div>
					<?php endif; ?>

					<p id="soutpaygw-status-msg"></p>

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
							$msg.text((r && r.data) || '<?php echo esc_js( __( 'Could not start connecting. Please try again.', 'southpay-gateway-for-woocommerce' ) ); ?>').css('color','#d63638');
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Connect with SouthPay', 'southpay-gateway-for-woocommerce' ) ); ?>');
						}
					})
					.fail(function(xhr){
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

			$('#soutpaygw-disconnect-btn').on('click', function(){
				if (!confirm('<?php echo esc_js( __( 'Disconnect this site from your SouthPay store? You will need to connect again to use SouthPay here.', 'southpay-gateway-for-woocommerce' ) ); ?>')) return;
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

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $this->can_take_payments() || 'EUR' !== $order->get_currency() ) {
			wc_add_notice( esc_html__( 'Payment could not be started. Please choose another payment method.', 'southpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount_cents = (int) round( (float) $order->get_total() * 100 );
		$reference    = $this->invoice_prefix . $order->get_order_number();

		$checkout = $this->api_request(
			'POST',
			'/api/v1/connection/checkouts',
			array(
				'amount_cents'    => $amount_cents,
				'order_reference' => $reference,
				/* translators: %s: order number */
				'description'     => sprintf( __( 'Order %s', 'southpay-gateway-for-woocommerce' ), $order->get_order_number() ),
				'return_url'      => $this->get_return_url( $order ),
			),
			array( 'Idempotency-Key' => 'wc-' . $order->get_id() . '-' . $order->get_order_key() . '-' . $amount_cents )
		);

		if ( is_wp_error( $checkout ) || empty( $checkout['id'] ) || empty( $checkout['code'] ) ) {
			$this->log_debug( 'process_payment: ' . ( is_wp_error( $checkout ) ? $checkout->get_error_message() : 'checkout missing from response' ) );
			wc_add_notice( esc_html__( 'Payment could not be started. Please try again or choose another payment method.', 'southpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$checkout_id   = sanitize_text_field( $checkout['id'] );
		$checkout_code = sanitize_text_field( $checkout['code'] );
		$is_new        = $order->get_meta( '_soutpaygw_checkout_id' ) !== $checkout_id;

		$order->update_meta_data( '_soutpaygw_checkout_id', $checkout_id );
		$order->update_meta_data( '_soutpaygw_checkout_code', $checkout_code );
		$order->save();

		if ( 'paid' === ( $checkout['status'] ?? '' ) ) {
			$this->complete_order( $order, $checkout );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( $is_new ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: SouthPay checkout code */
					__( 'Customer sent to SouthPay checkout %s.', 'southpay-gateway-for-woocommerce' ),
					$checkout_code
				)
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => apply_filters(
				'soutpaygw_checkout_url',
				trailingslashit( SOUTPAYGW_PAY_BASE ) . rawurlencode( $checkout_code ),
				$checkout_code,
				$order
			),
		);
	}

	public function sync_order( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id || $order->is_paid() || ! $this->can_take_payments() ) {
			return;
		}

		$checkout_id = (string) $order->get_meta( '_soutpaygw_checkout_id' );

		if ( '' === $checkout_id ) {
			return;
		}

		$checkout = $this->api_request( 'GET', '/api/v1/connection/checkouts/' . rawurlencode( $checkout_id ) );

		if ( is_wp_error( $checkout ) ) {
			$this->log_debug( 'sync_order ' . $order->get_id() . ': ' . $checkout->get_error_message() );
			return;
		}

		if ( 'paid' === ( $checkout['status'] ?? '' ) ) {
			$this->complete_order( $order, $checkout );
		}
	}

	public function sync_open_orders() {
		if ( ! $this->can_take_payments() ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'payment_method' => $this->id,
				'status'         => array( 'pending', 'on-hold', 'failed', 'cancelled' ),
				'date_created'   => '>' . ( time() - 2 * DAY_IN_SECONDS ),
				'limit'          => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $orders as $order ) {
			$this->sync_order( $order );
		}
	}

	public function keep_paid_order( $cancel, $order ) {
		if ( ! $cancel || ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id ) {
			return $cancel;
		}

		$this->sync_order( $order );

		return ! wc_get_order( $order->get_id() )->is_paid();
	}

	private function complete_order( $order, array $checkout ) {
		$order = wc_get_order( $order->get_id() );

		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$payment_reference = sanitize_text_field( (string) ( $checkout['payment_reference'] ?? '' ) );

		$order->add_order_note(
			sprintf(
				/* translators: %s: SouthPay payment reference */
				__( 'Paid with SouthPay. Payment reference: %s', 'southpay-gateway-for-woocommerce' ),
				'' !== $payment_reference ? $payment_reference : '-'
			)
		);
		$order->payment_complete( $payment_reference );
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$this->sync_order( $order );

		if ( wc_get_order( $order_id )->is_paid() ) {
			return;
		}

		echo '<div class="woocommerce-info">' .
			esc_html__( 'We are waiting for SouthPay to confirm your payment. Crypto payments usually confirm within a few minutes; reload this page to see the latest status. We will email you once it is confirmed.', 'southpay-gateway-for-woocommerce' ) .
			'</div>';
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$response = $this->api_request( 'GET', '/api/v1/connection' );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach SouthPay: %s', 'southpay-gateway-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$store_name = isset( $response['store']['name'] ) ? sanitize_text_field( $response['store']['name'] ) : '';

		if ( '' === $store_name ) {
			wp_send_json_success( __( 'Connection successful!', 'southpay-gateway-for-woocommerce' ) );
		}

		wp_send_json_success(
			sprintf(
				/* translators: %s: name of the connected SouthPay store */
				__( 'Connection successful! This site is linked to %s.', 'southpay-gateway-for-woocommerce' ),
				$store_name
			)
		);
	}

	private function api_request( $method, $endpoint, $data = null, $extra_headers = array(), $allow_refresh = true ) {
		if ( $this->should_proactively_refresh() ) {
			$refresh = $this->refresh_access_token();

			if ( is_wp_error( $refresh ) && $this->is_fatal_grant_error( $refresh ) ) {
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
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $this->access_token,
					'User-Agent'    => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
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

		if ( 401 === $status_code && $allow_refresh && '' !== $this->refresh_token ) {
			$refresh = $this->refresh_access_token();
			if ( ! is_wp_error( $refresh ) ) {
				return $this->api_request( $method, $endpoint, $data, $extra_headers, false );
			}
		}

		if ( $status_code >= 400 ) {
			$this->log_debug( sprintf( 'api_request body: %s', $body ) );

			$message = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: ( isset( $decoded['error_description'] ) ? $decoded['error_description'] : __( 'An error occurred. Please try again.', 'southpay-gateway-for-woocommerce' ) );

			return new WP_Error(
				'soutpaygw_api_error',
				sanitize_text_field( $message ),
				array(
					'status' => $status_code,
					'code'   => isset( $decoded['error']['code'] ) ? sanitize_text_field( $decoded['error']['code'] ) : '',
				)
			);
		}

		return $decoded;
	}

	private function is_connected() {
		return '' !== $this->access_token && '' !== $this->refresh_token;
	}

	private function can_take_payments() {
		return $this->is_connected() && in_array( self::PAYMENTS_SCOPE, explode( ' ', (string) ( $this->connection['scope'] ?? '' ) ), true );
	}

	private function has_legacy_credentials() {
		return ! $this->is_connected() && '' !== (string) $this->get_option( 'api_key', '' );
	}

	private function should_proactively_refresh() {
		if ( '' === $this->refresh_token || $this->access_token_expires_at <= 0 ) {
			return false;
		}
		return ( $this->access_token_expires_at - time() ) < 60;
	}

	private function log_debug( $message ) {
		if ( $this->debug_mode ) {
			$this->log->debug( $message, array( 'source' => 'southpay-gateway' ) );
		}
	}

	private function oauth_callback_url() {
		return add_query_arg( 'wc-api', self::OAUTH_CALLBACK, trailingslashit( home_url() ) );
	}

	public function ajax_init_oauth_flow() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$code_verifier  = bin2hex( random_bytes( 48 ) );
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$state = wp_generate_password( 32, false );

		set_transient( 'soutpaygw_pkce_verifier', $code_verifier, 600 );
		set_transient( 'soutpaygw_oauth_state', $state, 600 );

		$authorize_url = trailingslashit( SOUTPAYGW_DASHBOARD_BASE ) . 'connect/authorize?' . http_build_query(
			array(
				'client_id'             => SOUTPAYGW_OAUTH_CLIENT_ID,
				'response_type'         => 'code',
				'redirect_uri'          => $this->oauth_callback_url(),
				'scope'                 => SOUTPAYGW_OAUTH_SCOPES,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => 'S256',
				'state'                 => $state,
			)
		);

		wp_send_json_success( array( 'url' => $authorize_url ) );
	}

	public function handle_oauth_callback() {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=soutpaygw_gateway' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$saved_state   = get_transient( 'soutpaygw_oauth_state' );
		$code_verifier = get_transient( 'soutpaygw_pkce_verifier' );

		delete_transient( 'soutpaygw_oauth_state' );
		delete_transient( 'soutpaygw_pkce_verifier' );

		if ( empty( $state ) || empty( $saved_state ) || ! hash_equals( (string) $saved_state, $state ) ) {
			$this->log_debug( 'handle_oauth_callback: state mismatch' );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		if ( $error ) {
			$this->log_debug( 'handle_oauth_callback: authorization refused — ' . $error );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'access_denied' === $error ? 'denied' : 'error', $settings_url ) );
			exit;
		}

		if ( empty( $code ) || empty( $code_verifier ) ) {
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		$body = $this->post_token_endpoint(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'redirect_uri'  => $this->oauth_callback_url(),
				'client_id'     => SOUTPAYGW_OAUTH_CLIENT_ID,
			)
		);

		if ( is_wp_error( $body ) ) {
			$this->log_debug( 'handle_oauth_callback token exchange failed: ' . $body->get_error_message() );
			wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'error', $settings_url ) );
			exit;
		}

		$this->connection = array();
		$this->persist_token_response( $body );
		$this->forget_legacy_credentials();
		$this->remember_connected_store();

		wp_safe_redirect( add_query_arg( 'soutpaygw_oauth', 'connected', $settings_url ) );
		exit;
	}

	private function post_token_endpoint( array $payload ) {
		$response = wp_remote_post(
			trailingslashit( SOUTPAYGW_API_BASE ) . 'api/v1/oauth/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			$error_code = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'unknown';

			return new WP_Error(
				'soutpaygw_token_error',
				sprintf( 'status=%d error=%s', $status, $error_code ),
				array(
					'status'      => $status,
					'oauth_error' => $error_code,
				)
			);
		}

		return $body;
	}

	private function persist_token_response( array $body ) {
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 0;
		$connection = $this->connection;

		$connection['access_token']            = sanitize_text_field( $body['access_token'] );
		$connection['refresh_token']           = sanitize_text_field( $body['refresh_token'] );
		$connection['access_token_expires_at'] = $expires_in > 0 ? time() + $expires_in : 0;

		if ( ! empty( $body['connection_id'] ) ) {
			$connection['connection_id'] = sanitize_text_field( $body['connection_id'] );
		}
		if ( isset( $body['scope'] ) ) {
			$connection['scope'] = sanitize_text_field( $body['scope'] );
		}

		$this->save_connection( $connection );

		delete_transient( 'soutpaygw_reconnect_required' );
	}

	private function remember_connected_store() {
		$response = $this->api_request( 'GET', '/api/v1/connection' );

		if ( is_wp_error( $response ) || ! isset( $response['store'] ) || ! is_array( $response['store'] ) ) {
			return;
		}

		$store      = $response['store'];
		$connection = $this->connection;

		$connection['store_id']         = isset( $store['id'] ) ? sanitize_text_field( $store['id'] ) : '';
		$connection['store_name']       = isset( $store['name'] ) ? sanitize_text_field( $store['name'] ) : '';
		$connection['display_currency'] = isset( $store['display_currency'] ) ? sanitize_text_field( $store['display_currency'] ) : '';

		$this->save_connection( $connection );
	}

	private function forget_legacy_credentials() {
		$settings = get_option( 'woocommerce_soutpaygw_gateway_settings', array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$legacy = array_intersect_key( $settings, array_flip( self::LEGACY_SETTINGS ) );

		if ( empty( $legacy ) ) {
			return;
		}

		update_option( 'woocommerce_soutpaygw_gateway_settings', array_diff_key( $settings, $legacy ) );
		$this->init_settings();
	}

	private function load_connection( $connection ) {
		$this->connection              = is_array( $connection ) ? $connection : array();
		$this->access_token            = (string) ( $this->connection['access_token'] ?? '' );
		$this->refresh_token           = (string) ( $this->connection['refresh_token'] ?? '' );
		$this->access_token_expires_at = (int) ( $this->connection['access_token_expires_at'] ?? 0 );
	}

	private function save_connection( array $connection ) {
		update_option( self::CONNECTION_OPTION, $connection, false );
		$this->load_connection( $connection );
	}

	private function clear_connection() {
		delete_option( self::CONNECTION_OPTION );
		$this->load_connection( array() );
	}

	private function read_stored_connection() {
		wp_cache_delete( self::CONNECTION_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return get_option( self::CONNECTION_OPTION, array() );
	}

	private function refresh_access_token() {
		if ( '' === $this->refresh_token ) {
			return new WP_Error( 'soutpaygw_no_refresh_token', __( 'This site is not connected to SouthPay.', 'southpay-gateway-for-woocommerce' ) );
		}

		if ( ! $this->acquire_refresh_lock() ) {
			if ( $this->await_concurrent_refresh() ) {
				$this->log_debug( 'refresh_access_token: adopted token from concurrent refresh' );
				return true;
			}

			$this->log_debug( 'refresh_access_token: refresh already in progress, backing off' );
			return new WP_Error( 'soutpaygw_refresh_busy', __( 'A token refresh is already in progress.', 'southpay-gateway-for-woocommerce' ) );
		}

		try {
			$previous = $this->access_token;
			$this->load_connection( $this->read_stored_connection() );

			if ( '' === $this->refresh_token ) {
				return new WP_Error( 'soutpaygw_no_refresh_token', __( 'This site is not connected to SouthPay.', 'southpay-gateway-for-woocommerce' ) );
			}

			if ( $this->access_token !== $previous && ! $this->should_proactively_refresh() ) {
				$this->log_debug( 'refresh_access_token: token was already rotated by another request' );
				return true;
			}

			$body = $this->post_token_endpoint(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $this->refresh_token,
					'client_id'     => SOUTPAYGW_OAUTH_CLIENT_ID,
				)
			);

			if ( is_wp_error( $body ) ) {
				$this->log_debug( 'refresh_access_token failed: ' . $body->get_error_message() );

				if ( $this->is_fatal_grant_error( $body ) ) {
					$this->handle_refresh_failure();
				} else {
					$this->log_debug( 'refresh_access_token: transient failure, credentials kept for retry' );
				}

				return $body;
			}

			$this->persist_token_response( $body );
			$this->log_debug( 'refresh_access_token: rotated successfully' );
			return true;
		} finally {
			delete_option( self::REFRESH_LOCK_OPTION );
		}
	}

	private function acquire_refresh_lock( $timeout = 30 ) {
		wp_cache_delete( self::REFRESH_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$held = get_option( self::REFRESH_LOCK_OPTION );

		if ( false !== $held ) {
			if ( ( time() - (int) $held ) < $timeout ) {
				return false;
			}
			delete_option( self::REFRESH_LOCK_OPTION );
		}

		return add_option( self::REFRESH_LOCK_OPTION, (string) time(), '', false );
	}

	private function await_concurrent_refresh( $max_wait_ms = 5000 ) {
		$previous = $this->access_token;
		$waited   = 0;

		while ( $waited < $max_wait_ms ) {
			usleep( 250000 );
			$waited += 250;

			$stored = $this->read_stored_connection();

			if ( is_array( $stored ) && ! empty( $stored['access_token'] ) && $stored['access_token'] !== $previous ) {
				$this->load_connection( $stored );
				return true;
			}
		}

		return false;
	}

	private function is_fatal_grant_error( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;

		return is_array( $data ) && isset( $data['oauth_error'] ) && 'invalid_grant' === $data['oauth_error'];
	}

	private function handle_refresh_failure() {
		$this->clear_connection();
		set_transient( 'soutpaygw_reconnect_required', 1, DAY_IN_SECONDS );
	}

	public function ajax_disconnect_oauth() {
		check_ajax_referer( 'soutpaygw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'southpay-gateway-for-woocommerce' ) );
		}

		$this->revoke_token( '' !== $this->refresh_token ? $this->refresh_token : $this->access_token );
		$this->clear_connection();

		delete_transient( 'soutpaygw_reconnect_required' );

		wp_send_json_success( __( 'Disconnected successfully.', 'southpay-gateway-for-woocommerce' ) );
	}

	private function revoke_token( $token ) {
		if ( '' === (string) $token ) {
			return;
		}

		wp_remote_post(
			trailingslashit( SOUTPAYGW_API_BASE ) . 'api/v1/oauth/revoke',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'SouthPay-WooCommerce/' . SOUTPAYGW_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'client_id' => SOUTPAYGW_OAUTH_CLIENT_ID,
						'token'     => $token,
					)
				),
			)
		);
	}
}
