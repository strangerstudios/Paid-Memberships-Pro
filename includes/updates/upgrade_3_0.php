<?php
/**
 * Upgrade to 3.0
 *
 * We added the subscription and subscriptionmeta tables. In order to
 * populate these tables for existing sites, we are going to:
 * 1. Create a subscription for each unique subscription_transaction_id in the orders table.
 * 2. Mark all created subscriptions as needing to be synced with gateway.
 * 3. Change all `cancelled` orders to `success` so that we can remove `cancelled` status.
 *
 * @since TBD
 */
function pmpro_upgrade_3_0() {
	global $wpdb;

	// Get ID for most recent subscription so that the metadata step can start at the right place.
	$last_subscription_id = $wpdb->get_var( "SELECT id FROM {$wpdb->pmpro_subscriptions} ORDER BY id DESC LIMIT 1" );
	if ( empty( $last_subscription_id ) ) {
		$last_subscription_id = 0;
	}

	// Create a subscription for each unique subscription_transaction_id in the orders table.
	$sqlQuery = "
		INSERT IGNORE INTO {$wpdb->pmpro_subscriptions} ( user_id, membership_level_id, gateway,  gateway_environment, subscription_transaction_id, status )
		SELECT DISTINCT user_id, membership_id, gateway, gateway_environment, subscription_transaction_id, IF(STRCMP(status,'success'), 'cancelled', 'active')
		FROM {$wpdb->pmpro_membership_orders}
		WHERE subscription_transaction_id <> ''
		AND gateway <> ''
		AND gateway_environment <> ''
		AND status in ('success','cancelled')
		";
	$wpdb->query( $sqlQuery );

	// Mark all created subscriptions as needing to be synced with gateway.
	$sqlQuery = "
		INSERT INTO {$wpdb->pmpro_subscriptionmeta} ( pmpro_subscription_id, meta_key, meta_value )
		SELECT DISTINCT id, 'has_default_migration_data', '1'
		FROM {$wpdb->pmpro_subscriptions}
		WHERE id > {$last_subscription_id}
		";
	$wpdb->query( $sqlQuery );

	// Change all `cancelled` orders to `success` so that we can remove `cancelled` status.
	$sqlQuery = "
		UPDATE {$wpdb->pmpro_membership_orders}
		SET status = 'success'
		WHERE status = 'cancelled'
		";
	//$wpdb->query( $sqlQuery ); // Disabled for now to not interfere with development sites.

	return 3.0;
}
