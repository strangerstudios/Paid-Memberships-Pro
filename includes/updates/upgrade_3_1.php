<?php
/**
 * Upgrade to version 3.1
 *
 */
function pmpro_upgrade_3_1() {
    // Check if we have a setting for pmpro_nonmembertext and compare it to the default.
    $pmpro_nonmembertext = get_option( 'pmpro_nonmembertext' );
    if ( $pmpro_nonmembertext !== false ) {
		// We have text set, let's compare it to the old default.
		$old_default_nonmembertext = __( 'This content is for !!levels!! members only.<br /><a href="!!levels_page_url!!">Join Now</a>', 'paid-memberships-pro' );
		if ( $pmpro_nonmembertext == $old_default_nonmembertext ) {
			// This is the old default. Set it to the new default.
			$new_default_nonmembertext = sprintf( __( '<h2 class="pmpro_font-large">Membership Required</h2><p>You must be a !!levels!! member to access this content.</p><p><a class="pmpro_btn" href="%s">Join Now</a></p>', 'paid-memberships-pro' ), "!!levels_page_url!!" );	
			update_option( 'pmpro_nonmembertext', $new_default_nonmembertext );

			// Set the new option for pmpro_nonmembertext_type to "pmpro".
			update_option( 'pmpro_nonmembertext_type', 'pmpro' );
		} else {
			// Set the new option for pmpro_nonmembertext_type to "custom".
			update_option( 'pmpro_nonmembertext_type', 'custom' );
		}
    }

	// Update the version number
	update_option( 'pmpro_db_version', '3.1' );
}
