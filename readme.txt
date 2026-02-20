=== SouthPay Gateway for WooCommerce ===
Contributors: southpay
Tags: woocommerce, crypto, cryptocurrency, bitcoin, payment gateway
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce using SouthPay.

== Description ==

SouthPay Gateway for WooCommerce allows you to accept cryptocurrency payments directly in your WooCommerce store.

Customers can pay using supported cryptocurrencies such as Bitcoin, Ethereum, and other digital assets powered by SouthPay.

== Features ==

* Accept cryptocurrency payments
* Automatic payment status updates via secure webhooks
* HMAC-SHA512 webhook signature verification
* Configurable invoice prefix
* Debug logging via WooCommerce logs
* Customizable checkout title and description
* Redirect-based hosted crypto checkout

== Requirements ==

* WordPress 5.5 or higher
* WooCommerce 4.9.4 or higher
* PHP 7.4 or higher
* A SouthPay merchant account

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **WooCommerce → Settings → Payments**.
4. Enable **SouthPay** and click **Manage**.
5. Enter your SouthPay API URL and Webhook Secret.
6. Save changes.

== Configuration ==

After activation:

1. Navigate to **WooCommerce → Settings → Payments → SouthPay**.
2. Configure:
   * Title (shown to customers at checkout)
   * Description
   * API URL
   * Webhook Secret
   * Invoice Prefix
   * Debug Mode (optional)

== Webhook Setup ==

In your SouthPay dashboard, configure the webhook URL as:

https://yourstore.com/?wc-api=wc_gateway_southpay


Make sure the Webhook Secret matches the one configured in WooCommerce.

== How It Works ==

1. Customer selects SouthPay at checkout.
2. The order is created with "Pending payment" status.
3. Customer is redirected to SouthPay hosted checkout.
4. SouthPay processes the crypto payment.
5. A secure webhook updates the WooCommerce order status automatically.

== External services ==

This plugin connects to the SouthPay API to create cryptocurrency payment sessions and update order statuses. It is required for the gateway to function.

When a customer places an order using SouthPay as the payment method, the plugin sends the order total, currency, customer billing email, and return URLs to https://api.southpay.io/v1/payments to generate a hosted checkout session. No data is sent at any other time during normal store operation.

This service is provided by SouthPay: https://southpay.io/
Terms of service: https://southpay.io/terms
Privacy policy: https://southpay.io/privacy

== Frequently Asked Questions ==

= Does this plugin handle wallets directly? =

No. Payments are processed securely through SouthPay's hosted checkout.

= What happens if a payment expires? =

The order will automatically be marked as cancelled.

= What happens if a payment fails? =

The order will be marked as failed.

= Is webhook verification secure? =

Yes. The plugin verifies webhook payloads using HMAC-SHA512 signatures.

== Screenshots ==

1. SouthPay payment method displayed at checkout.
2. SouthPay settings page in WooCommerce.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
