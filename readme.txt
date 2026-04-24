=== SouthPay Gateway for WooCommerce ===
Contributors: southpay
Tags: woocommerce, crypto, cryptocurrency, bitcoin, payment gateway
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce using SouthPay.

== Description ==

SouthPay Gateway for WooCommerce allows you to accept cryptocurrency payments directly in your WooCommerce store.

Customers are redirected to a secure SouthPay-hosted checkout where they can pay using supported cryptocurrencies. The order status updates automatically via webhook once payment is confirmed on-chain.

== Features ==

* Accept cryptocurrency payments via a hosted checkout
* Automatic order status updates via secure webhooks
* HMAC-SHA256 webhook signature verification (Stripe-compatible format)
* Configurable invoice prefix
* Minimum order amount filter
* Debug logging via WooCommerce → Status → Logs
* Customisable checkout title and description
* Block checkout (Gutenberg) support

== Requirements ==

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* A SouthPay merchant account

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **WooCommerce → Settings → Payments**.
4. Enable **SouthPay** and click **Manage**.
5. Enter your API Key and Webhook Signing Secret.
6. Save changes.

== Configuration ==

After activation:

1. Navigate to **WooCommerce → Settings → Payments → SouthPay**.
2. Configure:
   * Title and Description (shown to customers at checkout)
   * API Key (from SouthPay dashboard → Settings → API Keys)
   * Webhook Signing Secret (from your webhook endpoint in SouthPay dashboard)
   * Invoice Prefix (optional)
   * Minimum Order Amount (optional)
   * Debug Mode (optional)

== Webhook Setup ==

1. In your SouthPay dashboard, go to **Settings → Webhook Endpoints → Add Endpoint**.
2. Set the platform to **WooCommerce** and paste your store URL.
3. For the webhook URL, use the value shown in your SouthPay settings page in WooCommerce.
4. Copy the signing secret shown after creating the endpoint.
5. Paste the signing secret into **WooCommerce → Settings → Payments → SouthPay → Webhook Signing Secret**.

== How It Works ==

1. Customer selects SouthPay at checkout.
2. The order is created with "Pending payment" status.
3. Customer is redirected to the SouthPay hosted checkout.
4. Customer completes payment using their preferred cryptocurrency.
5. SouthPay confirms the on-chain transaction and fires a webhook.
6. The WooCommerce order status updates to "Processing" automatically.
7. Customer is redirected back to your store's order confirmation page.

== External Services ==

This plugin connects to the SouthPay API to create cryptocurrency payment sessions and receive order status updates via webhook. Connection to this service is required for the gateway to function.

When a customer places an order using SouthPay as the payment method, the plugin sends the order total, currency, and return URLs to https://api.southpay.io/api/v2/payments to generate a hosted checkout session. No data is sent at any other time during normal store operation.

This service is provided by SouthPay: https://southpay.io/
Terms of service: https://southpay.io/terms
Privacy policy: https://southpay.io/privacy

== Frequently Asked Questions ==

= Does this plugin handle crypto wallets directly? =

No. All payments are processed through SouthPay's secure hosted checkout.

= What happens if a payment expires? =

The order is automatically marked as cancelled.

= What happens if a payment fails? =

The order is marked as failed and the customer can retry.

= How is the webhook secured? =

Each webhook delivery is signed with HMAC-SHA256. The plugin verifies the signature before processing any status update.

= Which cryptocurrencies are supported? =

Supported cryptocurrencies depend on your SouthPay account configuration. Contact SouthPay support for details.

== Changelog ==

= 2.0.0 =
* Integrated SouthPay API v2
* Replaced popup checkout with direct hosted checkout redirect
* Updated webhook signature verification to HMAC-SHA256 Stripe-style format
* Improved WordPress plugin review compliance: split into include files, added uninstall.php, hardened escaping
* Added WooCommerce Block checkout support

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Requires updated API key and new Webhook Signing Secret from your SouthPay dashboard. The old HMAC-SHA512 webhook secret is not compatible — please create a new webhook endpoint and update your settings.
