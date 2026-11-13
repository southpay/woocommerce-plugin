=== SouthPay Crypto Payment Gateway ===
Contributors: southpay
Tags: woocommerce, crypto, cryptocurrency, bitcoin, payment gateway
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto payments in WooCommerce through SouthPay's hosted checkout. Connect with one sign-in; paid orders update on their own.

== Description ==

SouthPay Crypto Payment Gateway links your WooCommerce site to a store in your SouthPay account and lets customers pay for orders in crypto. You sign in to SouthPay once, choose the store and approve; the plugin keeps the connection current on its own.

At checkout the customer is sent to SouthPay's hosted checkout at pay.southpay.io, chooses a coin and pays from their wallet. Once paid they can return to your shop, and the order moves to Processing (or Completed for virtual orders) by itself.

Sites connected with version 3.0 need to connect again once so SouthPay can take payments.

== Features ==

* "Connect with SouthPay": OAuth 2.0 authorization code with PKCE, no keys to copy
* The connection is tied to one SouthPay store, named on the settings page
* Hosted checkout: customers choose a coin and pay at pay.southpay.io
* Orders are marked paid automatically when the customer returns and on a five-minute schedule
* Access tokens last one hour and are renewed in the background
* Disconnect from WooCommerce or from the SouthPay dashboard at any time
* Debug logging via WooCommerce → Status → Logs
* Block checkout (Gutenberg) support

== Requirements ==

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher (cURL and OpenSSL extensions enabled)
* Store currency set to EUR
* HTTPS on your store domain. SouthPay only returns to https addresses
* A SouthPay account at https://southpay.io/ in which you are an owner or admin

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin screen (Plugins → Add New → Upload).
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **WooCommerce → Settings → Payments**, find **SouthPay** in the list, and click **Manage**.
4. Click **Connect with SouthPay**.
5. SouthPay shows which site is asking, what it may do and lets you pick the store to link. Approve.
6. Back in WooCommerce the settings page shows **Connected to** followed by the store name.

== Configuration ==

All settings live at **WooCommerce → Settings → Payments → SouthPay**.

**Your SouthPay Account**

* **Status** shows whether the site is connected and to which SouthPay store.
* **Test connection** asks SouthPay which store this site is linked to.
* **Disconnect** ends the connection on SouthPay's side and removes the tokens from this site.

**Checkout Appearance**

* **Payment Method Name** is what customers see at checkout.
* **Payment Method Description** is the short text shown below the name.

**Advanced**

* **Minimum Order Amount** hides SouthPay at checkout when the cart total is below this value. Set to `0` to always show it. Default: `1`.
* **Order Reference Prefix** is prepended to the order number sent to SouthPay (default: `WC-`).
* **Debug Logging** writes request logs to **WooCommerce → Status → Logs** under the `southpay-gateway` source. Enable only when troubleshooting.

== Authentication ==

The plugin uses **OAuth 2.0 authorization code with PKCE**:

* Connecting sends you to `https://hub.southpay.io/connect/authorize`. SouthPay returns to `https://your-site/?wc-api=southpay` with a single-use code, which the plugin exchanges at `https://api.southpay.io/api/v1/oauth/token`.
* Access tokens last one hour and are renewed before they run out.
* Refresh tokens change on every use. The plugin serialises renewals so two requests on a busy shop do not use the same one twice.
* If the connection is ended from the SouthPay dashboard, the plugin shows a notice asking you to connect again.
* Disconnecting from WooCommerce calls `https://api.southpay.io/api/v1/oauth/revoke`.

Tokens are stored in your site's database and are never printed into a page.

== External Services ==

This plugin connects to SouthPay, and needs that connection to work.

* When you click **Connect with SouthPay** your browser is sent to https://hub.southpay.io with the address of your site.
* When you return, and afterwards whenever the access token needs renewing, your server calls https://api.southpay.io/api/v1/oauth/token.
* **Test connection** and the step right after connecting call https://api.southpay.io/api/v1/connection to read the linked store's name and display currency.
* **Disconnect** calls https://api.southpay.io/api/v1/oauth/revoke.
* When a customer places an order with SouthPay, your server calls https://api.southpay.io/api/v1/connection/checkouts with the order total, the order number and the address of the order-received page. The customer's browser is then sent to https://pay.southpay.io.
* To learn whether an order has been paid, your server calls https://api.southpay.io/api/v1/connection/checkouts/{id} when the customer returns and every five minutes for recent unpaid orders.

No customer names, addresses or contact details are sent.

This service is provided by SouthPay: https://southpay.io/
Terms of service: https://southpay.io/terms
Privacy policy: https://southpay.io/privacy

== Frequently Asked Questions ==

= Which currency does my store need? =

EUR. SouthPay is not offered at checkout in other currencies.

= How does an order get marked as paid? =

The plugin asks SouthPay about the order's checkout when the customer comes back to the order-received page, and every five minutes for unpaid orders from the last two days. Paid orders move to Processing, or Completed for virtual orders. If WooCommerce would cancel an unpaid order, the plugin checks with SouthPay first.

= I connected with version 3.0. What do I need to do? =

Click **Connect again** on the SouthPay settings page and approve taking payments.

= I connected with version 2. What do I need to do? =

Open the SouthPay settings and click **Connect with SouthPay** again. The previous platform's keys and tokens no longer work and are removed when you connect.

= Who can connect a site? =

Owners and admins of the SouthPay business the store belongs to.

= Does this plugin handle crypto wallets directly? =

No.

== Changelog ==

= 3.1.0 =
* Customers can pay at checkout: each order opens a SouthPay checkout at pay.southpay.io, and the customer returns to the order-received page once paid
* Orders are marked paid automatically, on the customer's return, every five minutes and before WooCommerce would cancel them as unpaid
* SouthPay is enabled by default once the site is connected
* Requires a EUR store currency
* Sites connected with 3.0 are asked to connect again to allow taking payments

= 3.0.0 =
* Connect to the current SouthPay platform: authorization at hub.southpay.io, tokens from api.southpay.io/api/v1
* The connection is bound to one SouthPay store, shown on the settings page
* Tokens are kept out of the settings form, so saving settings can no longer write back an outdated token
* A temporary refusal of the app by SouthPay no longer clears the connection; only a rejected grant does
* Returning from SouthPay now requires a signed-in shop manager
* Removed the manual API key, webhook registration and the test-mode check, none of which exist on the current platform
* SouthPay is hidden at checkout, including block checkout, until payments through a connection are available

= 2.1.7 =
* Fix "Test connection" failing with "Insufficient scope: payments:read required" on OAuth-connected stores; the check now uses an endpoint covered by the scopes the plugin actually requests

= 2.1.6 =
* Declare compatibility with WordPress 7.0

= 2.1.5 =
* Keep the store connected when a token refresh fails for a transient reason — only a definitive rejection from SouthPay now clears the connection and asks you to reconnect
* Fix concurrent refreshes on busy stores racing each other, which could invalidate the connection and require a manual reconnect
* Do not fail a request when a pre-emptive token refresh cannot run; the existing token is used instead
* Clear the "session expired — reconnect your account" notice as soon as the connection recovers, instead of leaving it up for a day above a store that is connected and working

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

= 3.1.0 =
Customers can pay at checkout again. If you connected with 3.0, click Connect again on the SouthPay settings page.

= 3.0.0 =
The previous SouthPay platform has been retired. After updating, connect again from the SouthPay settings. Checkout payments are not available in this version.
