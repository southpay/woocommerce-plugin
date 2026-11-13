<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_soutpaygw_gateway_settings' );
delete_option( 'soutpaygw_connection' );
delete_option( 'soutpaygw_refresh_lock' );
delete_transient( 'soutpaygw_pkce_verifier' );
delete_transient( 'soutpaygw_oauth_state' );
delete_transient( 'soutpaygw_reconnect_required' );
wp_clear_scheduled_hook( 'soutpaygw_sync_orders' );
