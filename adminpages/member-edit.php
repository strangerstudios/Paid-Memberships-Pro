<?php

/**
 * Get the panels to display on the member edit page.
 *
 * @since TBD
 *
 * @return array
 */
function pmpro_member_edit_get_panels() {
	static $panels_cache = array();
	if ( ! empty( $panels_cache ) ) {
		// Use cached value.
		return $panels_cache;
	}

	// Add default panels.
	$panels = array();
	$panels[] = new PMPro_Member_Edit_Panel_User_Info();
	$panels[] = new PMPro_Member_Edit_Panel_Memberships();
	$panels[] = new PMPro_Member_Edit_Panel_Subscriptions();
	$panels[] = new PMPro_Member_Edit_Panel_Orders();
	$panels[] = new PMPro_Member_Edit_Panel_Other();

	// Add user fields panels.
	$user_id = PMPro_Member_Edit_Panel::get_user()->ID;
	if ( $user_id ) {
		$profile_user_fields = pmpro_get_user_fields_for_profile( $user_id, true );
		if ( ! empty( $profile_user_fields ) ) {
			foreach ( $profile_user_fields as $group_name => $user_fields ) {
				$panels[] = new PMPro_Member_Edit_Panel_User_Fields( $group_name );
			}
		}
	}

	/**
	 * Filter to add/edit panels on the member edit page.
	 *
	 * @since TBD
	 *
	 * @param array $panels The panels to display on the member edit page.
	 */
	$panels = apply_filters( 'pmpro_member_edit_panels', $panels );

	// Add panels to cache with slug as key.
	foreach ( $panels as $panel ) {
		$panels_cache[ $panel->get_slug() ] = $panel;
	}

	// Return panels.
	return $panels_cache;
}

/**
 * Display the member edit page.
 *
 * @since TBD
 */
function pmpro_member_edit_display() {
	global $current_user;

	// Get the user that we are editing.
	$user = PMPro_Member_Edit_Panel::get_user();

	// Define a constant if user is editing their own membership.
	if ( ! defined( 'IS_PROFILE_PAGE' ) ) {
		define( 'IS_PROFILE_PAGE', ( $user->ID === $current_user->ID ) );
	}

	$panels = pmpro_member_edit_get_panels();

	// Get the panel to default to.
	$default_panel_slug = 'user_info';
	if ( ! empty( $user->ID ) && ! empty( $_REQUEST['pmpro_member_edit_panel'] ) && ! empty( $panels[ $_REQUEST['pmpro_member_edit_panel'] ] ) ) {
		$default_panel_slug = sanitize_text_field( $_REQUEST['pmpro_member_edit_panel'] );
	}

	/**
	 * Load the Paid Memberships Pro dashboard-area header
	 */
	require_once( PMPRO_DIR . '/adminpages/admin_header.php' ); ?>

	<hr class="wp-header-end">
	<h1 class="wp-heading-inline">
		<?php		
		if ( ! empty( $user->ID ) ) {
			echo get_avatar( $user->ID, 96 );
			echo wp_kses_post( sprintf( __( 'Edit Member: %s', 'paid-memberships-pro' ), '<strong>' . $user->display_name . '</strong>' ) );
		} else {
			echo esc_html_e( 'Add Member', 'paid-memberships-pro' );
		}
		?>
	</h1>

	<?php pmpro_showMessage(); ?>

	<div id="pmpro-edit-user-div">
		<nav id="pmpro-edit-user-nav" role="tablist" aria-labelledby="pmpro-edit-user-menu">
			<h2 id="pmpro-edit-user-menu" class="screen-reader-text"><?php esc_html_e( 'Edit Member Area Menu', 'paid-memberships-pro' ); ?></h2>
			<?php
				foreach ( $panels as $panel_slug => $panel ) {
					$panel->display_tab( $panel_slug === $default_panel_slug );
				}
			?>
		</nav>
		<div class="pmpro_section">
			<?php
			foreach ( $panels as $panel_slug => $panel ) {
				// When creating a new user, we only want to show the user_info panel.
				if ( empty( $user->ID ) && $panel_slug !== 'user_info' ) {
					continue;
				}

				// If we are showing the orders panel, there is additional code that we need to run to allow emailing invoices.
				// Ideally this would be in the "orders" panel class, but this code needs to be its own separate <form>.
				// Hopefully we will have a solution for this down the road, but for now, adding this code here.
				if ( $panel_slug === 'orders' && function_exists( 'pmpro_add_email_order_modal' ) ) {
					// Load the email order modal.
					pmpro_add_email_order_modal();
				}

				// Display the panel.
				$panel->display_panel( $panel_slug === $default_panel_slug );
			}
			?>
		</div>
	</div>

	<?php
	require_once( PMPRO_DIR . '/adminpages/admin_footer.php' );	
}

/**
 * Save the member edit page.
 *
 * @since TBD
 */
function pmpro_member_edit_save() {
	global $current_user;

	// Check if we are on the pmpro-member page.
	if ( empty( $_REQUEST['page'] ) || 'pmpro-member' !== $_REQUEST['page'] ) {
		return;
	}

	// Check that data was posted.
	if ( empty( $_POST ) ) {
		return;
	}

	// Make sure the current user can edit this user.
	// Alterred from wp-admin/user-edit.php.
	$user = PMPro_Member_Edit_Panel::get_user();
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		wp_die( __( 'Sorry, you are not allowed to edit this user.', 'paid-memberships-pro' ) );
	}

	// Get the panel slug that was submitted.
	$panel_slug = empty( $_REQUEST['pmpro_member_edit_panel'] ) ? '' : sanitize_text_field( $_REQUEST['pmpro_member_edit_panel'] );
	if ( empty( $panel_slug ) ) {
		return;
	}

	// Check the nonce.
	if ( empty( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) || ! wp_verify_nonce( $_REQUEST['pmpro_member_edit_saved_panel_nonce'], 'pmpro_member_edit_saved_panel_' . $panel_slug ) ) {
		return;
	}

	// Define a constant if user is editing their own membership.
	if ( ! defined( 'IS_PROFILE_PAGE' ) ) {
		define( 'IS_PROFILE_PAGE', ( $user->ID === $current_user->ID ) );
	}

	// Save the panel.
	$panels = pmpro_member_edit_get_panels();
	if ( ! empty( $panels[ $panel_slug ] ) ) {
		$panels[ $panel_slug ]->save();
	}
}
add_action( 'admin_init', 'pmpro_member_edit_save' );