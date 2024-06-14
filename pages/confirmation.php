<?php
/**
 * Template: Confirmation
 * Version: 3.0
 *
 * See documentation for how to override the PMPro templates.
 * @link https://www.paidmembershipspro.com/documentation/templates/
 *
 * @version 3.0
 *
 * @author Paid Memberships Pro
 */
global $wpdb, $pmpro_invoice, $pmpro_msg, $pmpro_msgt;

// If this file is loaded, $pmpro_invoice should have been set by preheaders/confirmation.php. If not, show an error.
if ( empty( $pmpro_invoice ) ) {
	$pmpro_msg = __( 'There was an error retrieving your order. Please contact the site owner.', 'paid-memberships-pro' );
	$pmpro_msgt = 'pmpro_error';
}

// Output page contents.
?>
<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro' ) ); ?>">
	<section id="pmpro_confirmation-<?php echo esc_attr( intval( $pmpro_invoice->membership_id ) ); ?>" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_section' ) ); ?>">
		<?php
			// Show message if it was passed in.
			if ( $pmpro_msg ) {
				?>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_message ' . $pmpro_msgt, $pmpro_msgt ) ); ?>"><?php echo wp_kses_post( $pmpro_msg );?></div>
				<?php
			}

			// Check that we have an order.
			if ( ! empty( $pmpro_invoice ) ) {
				$pmpro_invoice->getUser();
				$pmpro_invoice->getMembershipLevel();
				$pmpro_invoice->user->membership_level = $pmpro_invoice->membership_level; // Backwards compatibility.

				// Start building the confirmation message.
				if ( 'success' != $pmpro_invoice->status ) {
					$confirmation_message = '<p>' . sprintf(__('Thank you for your membership to %1$s. Your %2$s membership will be activated once the payment has been completed.', 'paid-memberships-pro' ), get_bloginfo("name"), $pmpro_invoice->membership_level->name) . '</p>';
				} else {
					$confirmation_message = '<p>' . sprintf(__('Thank you for your membership to %s. Your %s membership is now active.', 'paid-memberships-pro' ), get_bloginfo("name"), $pmpro_invoice->membership_level->name) . '</p>';
				}

				// Add the level confirmation message if set.
				$level_message = $wpdb->get_var("SELECT confirmation FROM $wpdb->pmpro_membership_levels WHERE id = '" . intval( $pmpro_invoice->membership_id ) . "' LIMIT 1");
				if ( ! empty( $level_message ) ) {
					$confirmation_message .= wpautop( stripslashes( $level_message ) );
				}

				// Add some details to the confirmation message about the order.
				if ( ! pmpro_isLevelFree( $pmpro_invoice->membership_level ) ) {
					$confirmation_message .= '<p>' . sprintf( __( 'Below are details about your membership account and a receipt for your initial membership order. A welcome email with a copy of your initial membership order has been sent to %s.', 'paid-memberships-pro' ), $pmpro_invoice->user->user_email ) . '</p>';
				} else {
					$confirmation_message .= '<p>' . sprintf( __( 'Below are details about your membership account. A welcome email has been sent to %s.', 'paid-memberships-pro' ), $pmpro_invoice->user->user_email ) . '</p>';
				}

				/**
				 * Allow devs to filter the confirmation message.
				 * We also have a function in includes/filters.php that applies the the_content filters to this message.
				 * @param string $confirmation_message The confirmation message.
				 * @param object $pmpro_invoice The PMPro Order object.
				 */
				$confirmation_message = apply_filters( "pmpro_confirmation_message", $confirmation_message, $pmpro_invoice );
				echo wp_kses_post( $confirmation_message );

				?>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_spacer' ) ); ?>"></div>
				<?php

				// Show a message about account activation if the order is not yet successful.
				if ( 'success' != $pmpro_invoice->status ) {
					?>
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_message pmpro_alert' ) ); ?>">
						<?php
							/**
							 * Filter to change the message shown when the order is not successful.
							 *
							 * @since TBD
							 *
							 * @param string $message The message to show.
							 * @param object $pmpro_invoice The PMProOrder object.
							 *
							 * @return string $message The message to show.
							 */
							echo wp_kses_post( apply_filters( 'pmpro_confirmation_payment_incomplete_message', __( 'We are waiting for your payment to be completed.', 'paid-memberships-pro' ), $pmpro_invoice ) );
						?>
					</div> <!-- pmpro_message -->
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_spacer' ) ); ?>"></div>
					<?php
				}

				if (  ! pmpro_isLevelFree( $pmpro_invoice->membership_level ) ) {
					// If the order is not free, show the full order, but make sure we don't show $pmpro_msg again.
					$pmpro_msg = false;
					$pmpro_msgt = false;
					echo pmpro_loadTemplate( 'invoice' );	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					// The invoice is free, so we don't need to show a full order.
					// TODO
					?>
					<ul>
						<li><strong><?php esc_html_e('Account', 'paid-memberships-pro' );?>:</strong> <?php echo esc_html( $pmpro_invoice->user->display_name );?> (<?php echo esc_html( $pmpro_invoice->user->user_email );?>)</li>
						<li><strong><?php esc_html_e('Membership Level', 'paid-memberships-pro' );?>:</strong> <?php if(!empty($pmpro_invoice->membership_level)) echo esc_html( $pmpro_invoice->membership_level->name ); else esc_html_e("Pending", 'paid-memberships-pro' );?></li>
						<?php if( !empty( $pmpro_invoice->membership_level->expiration_period ) && $pmpro_invoice->membership_level->expiration_period == 'Hour' && apply_filters( 'pmpro_confirmation_display_hour_expiration', true, $pmpro_invoice->user ) ){ ?>
						<li><strong><?php esc_html_e('Expires In', 'paid-memberships-pro' );?>:</strong> <?php echo esc_html( $pmpro_invoice->membership_level->expiration_number . ' ' . pmpro_translate_billing_period( $pmpro_invoice->membership_level->expiration_period, $pmpro_invoice->membership_level->expiration_number ) ); ?></li>
						<?php }
						?>
					</ul>
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_actions_nav' ) ); ?>">
						<span class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_actions_nav-right' ) ); ?>"><a href="<?php echo esc_url( pmpro_url( 'account' ) ); ?>"><?php esc_html_e( 'View Your Membership Account &rarr;', 'paid-memberships-pro' ); ?></a></span>
					</div> <!-- end pmpro_actions_nav -->
					<?php
				}

			}
		?>
	</section> <!-- end pmpro_confirmation -->
</div> <!-- end pmpro -->
