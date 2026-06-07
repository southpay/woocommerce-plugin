=== SouthPay Crypto Payment Gateway ===
Contributors: southpay
Tags: woocommerce, crypto, cryptocurrency, bitcoin, payment gateway
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce via SouthPay's hosted checkout, with one-click OAuth connect and automatic webhook registration.

== Description ==

SouthPay Crypto Payment Gateway lets your WooCommerce store accept cryptocurrency payments through SouthPay's secure hosted checkout. Connect in one click via OAuth, and the plugin handles webhook setup, token refresh, and on-chain confirmation automatically — no key copy-pasting and no manual webhook configuration.

Customers are redirected to a secure SouthPay-hosted checkout where they can pay using supported cryptocurrencies. The order status updates automatically via webhook once payment is confirmed on-chain.

== Features ==

* One-click "Connect with SouthPay" — OAuth 2.0 with PKCE, no key copy-pasting
* Automatic background token refresh — the connection stays live without merchant action
* Accept cryptocurrency payments via a hosted checkout
* Automatic order status updates via secure webhooks
* Automatic webhook endpoint registration after first connection
* HMAC-SHA256 webhook signature verification
* Configurable invoice prefix
* Minimum order amount filter
* Debug logging via WooCommerce → Status → Logs
* Customisable checkout title and description
* Block checkout (Gutenberg) support

== Requirements ==

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher (cURL and OpenSSL extensions enabled)
* HTTPS on your store domain — required for OAuth redirect and webhook delivery
* A publicly reachable site URL so SouthPay can POST webhook events
* A SouthPay merchant account at https://southpay.io/

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin screen (Plugins → Add New → Upload).
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **WooCommerce → Settings → Payments**, find **SouthPay** in the list, and click **Manage**.
4. Tick **Enable SouthPay** and click **Connect with SouthPay**.
5. You are redirected to SouthPay to sign in, pick the store to connect, and approve the requested permissions.
6. On return, the plugin stores the OAuth tokens and registers a webhook endpoint automatically. The settings page will show **Status: Connected**.

That's it — you are ready to accept crypto payments. No API keys to copy, no webhook URLs to paste.

== Configuration ==

All settings live at **WooCommerce → Settings → Payments → SouthPay**.

**Your SouthPay Account**

* **Status** — shows the current connection state (Connected, Disconnected, or Reconnect Required) and the connected store name.
* **Connect / Disconnect** — manages the OAuth connection. Disconnecting revokes the refresh-token family on SouthPay's side, not just the local copy.
* **API Key** — only used as a manual fallback when OAuth isn't possible (see *Authentication* below). Leave blank in the normal OAuth flow.

**Checkout Appearance**

* **Payment Method Name** — what customers see at checkout (default: *SouthPay*). Customise this to something like "Pay with Crypto".
* **Payment Method Description** — short text shown below the name at checkout.

**Advanced**

* **Minimum Order Amount** — hides SouthPay at checkout when the cart total is below this value. Set to `0` to always show it. Default: `1`.
* **Order Reference Prefix** — prepended to the order number sent to SouthPay (default: `WC-`), so orders are easy to identify in the SouthPay dashboard.
* **Debug Logging** — writes detailed request/response logs to **WooCommerce → Status → Logs** under the `southpay` source. Enable only when troubleshooting; the logs can contain order metadata.

== Authentication ==

The plugin uses **OAuth 2.0 with PKCE** and the `offline_access` scope, so the connection survives access-token expiry without merchant intervention:

* Access tokens are short-lived (one hour) and refreshed automatically before each API call.
* Refresh tokens rotate on every use, and the previous refresh token is invalidated server-side.
* If the refresh-token family is ever revoked (for example, an admin clicks *Disconnect* in the SouthPay dashboard), the plugin surfaces a **Reconnect your account** admin notice in WooCommerce and the gateway is hidden at checkout until reconnected.
* Disconnecting from the WooCommerce side calls SouthPay's revoke endpoint to invalidate the entire token family — not just the locally cached access token.

**Manual API Key fallback.** For environments without browser access (air-gapped boxes, whitelabel deployments, automated provisioning), paste a long-lived API key from **SouthPay Dashboard → Developers → API Keys** into the **API Key** field. This mode bypasses OAuth and does not auto-refresh; you are responsible for rotating the key yourself. The webhook still needs to be created manually in this mode.

== Webhook Setup ==

In the standard OAuth flow there is nothing to configure — the plugin registers a webhook endpoint pointing at your site automatically the first time you connect, and stores the signing secret locally.

* The active endpoint URL is shown in the **Webhook URL** row on the settings page (it is the WooCommerce REST route `/wc-api/southpay_webhook`).
* Every inbound delivery is signed with **HMAC-SHA256** over the raw request body plus a timestamp. The plugin rejects requests with a missing, malformed, or stale signature before any order state is touched.
* If you ever need to rotate the signing secret (for example, after a security incident or when migrating environments), click **Reconnect webhook** on the settings page. The plugin will deregister the old endpoint and create a new one with a fresh secret in one step.

== How It Works ==

1. The customer selects SouthPay at checkout and clicks **Place order**.
2. WooCommerce creates the order with status **Pending payment**.
3. The plugin calls `POST https://api.southpay.io/api/v2/payments` with the order total, currency, order reference, and return URLs to create a hosted-checkout session.
4. The customer is redirected to SouthPay's hosted checkout, where they choose a cryptocurrency, see the quoted amount and exchange rate, and send the funds from their wallet.
5. Once the transaction is confirmed on-chain, SouthPay POSTs a signed webhook to your store.
6. The plugin verifies the HMAC-SHA256 signature, matches the event to the WooCommerce order, and transitions the order:
   * **paid** → order moves to *Processing* (or *Completed* for virtual orders), and a payment note is added.
   * **expired** → order moves to *Cancelled*.
   * **failed** → order moves to *Failed*; the customer can retry from the order-pay page.
7. The customer is redirected back to your store's order-received page.

All state transitions are driven by webhooks — the customer's browser returning is not required to mark the order as paid.

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

= 2.1.4 =
* Enforce live mode: refuse and revoke any OAuth token that comes back in test mode instead of silently connecting in test
* Reject pasted test API keys (sp_test_) at save time so the gateway can never operate in test mode

= 2.1.3 =
* Fix oversized payment method logo on the classic (shortcode) checkout by constraining the icon height
* Add a "Payment Method Logo" setting to hide the logo at checkout (classic and blocks)

= 2.1.2 =
* Rename plugin to "SouthPay Crypto Payment Gateway"
* Expanded plugin description and readme (requirements, configuration, authentication, webhook setup, end-to-end payment flow)

= 2.1.1 =
* Request a live-mode OAuth token at authorize time so webhook endpoints
  register in the correct mode (previously fell back to test mode, which
  caused "Connected — webhook setup incomplete" on first connect for
  live-only stores)

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
* Updated webhook signature verification to timestamped HMAC-SHA256
* Improved WordPress plugin review compliance: split into include files, added uninstall.php, hardened escaping
* Added WooCommerce Block checkout support

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Requires updated API key and new Webhook Signing Secret from your SouthPay dashboard. The old HMAC-SHA512 webhook secret is not compatible — please create a new webhook endpoint and update your settings.
