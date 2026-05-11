=== SouthPay Gateway for WooCommerce ===
Contributors: southpay
Tags: woocommerce, crypto, cryptocurrency, bitcoin, payment gateway
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce using SouthPay.

== Description ==

SouthPay Gateway for WooCommerce allows you to accept cryptocurrency payments directly in your WooCommerce store.

Customers are redirected to a secure SouthPay-hosted checkout where they can pay using supported cryptocurrencies. The order status updates automatically via webhook once payment is confirmed on-chain.

== Features ==

* One-click "Connect with SouthPay" — OAuth 2.0 with PKCE, no key copy-pasting
* Automatic background token refresh — the connection stays live without merchant action
* Accept cryptocurrency payments via a hosted checkout
* Automatic order status updates via secure webhooks
* Automatic webhook endpoint registration after first connection
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
3. Go to **WooCommerce → Settings → Payments**, enable **SouthPay**, and click **Manage**.
4. Click **Connect with SouthPay**. You will be redirected to SouthPay to authorise the connection.
5. Choose the store to connect, approve the requested permissions, and you are returned to WooCommerce. The webhook endpoint is registered automatically.

That's it — you are ready to accept crypto payments.

== Configuration ==

After connecting:

1. Navigate to **WooCommerce → Settings → Payments → SouthPay**.
2. Configure the checkout-facing settings:
   * Title and Description (shown to customers at checkout)
   * Invoice Prefix (optional)
   * Minimum Order Amount (optional)
   * Debug Mode (optional)
3. The connection itself is managed via the **Connect / Disconnect** buttons at the top of the page.

== Authentication ==

The plugin uses OAuth 2.0 with PKCE and a refresh token (the `offline_access` scope) so the connection stays alive without merchant intervention. Access tokens are short-lived (one hour) and are refreshed automatically before each API call. If the refresh token is ever revoked (for example by an admin in your SouthPay dashboard), the plugin will surface a "Reconnect your account" notice in WooCommerce.

For environments that cannot use the OAuth flow (rare — typically air-gapped or whitelabel setups), you can paste a long-lived API key from **SouthPay Dashboard → Developers → API Keys** into the **API Key** field. This mode does not auto-refresh.

== Webhook Setup ==

The webhook endpoint is registered automatically the first time you connect. The plugin uses the URL shown in the **Webhook URL** row of the settings page, and HMAC-SHA256 signature verification (Stripe-compatible) protects every inbound request.

If you ever need to rotate the signing secret, use the **Reconnect webhook** button on the settings page.

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

= 2.1.0 =
* One-click "Connect with SouthPay" using OAuth 2.0 with PKCE
* Automatic refresh-token rotation — connections survive token expiry without merchant action
* Reconnect-required admin notice when the refresh family is revoked server-side
* Disconnect now revokes the entire token family server-side, not just the active access token
* Webhook endpoint is registered automatically after OAuth completes — no more secret copy-paste
* Manual API key paste is still supported as a fallback for environments without browser access

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
