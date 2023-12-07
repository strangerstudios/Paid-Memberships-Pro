<?php

class PMPro_Member_Edit_Panel_Other extends PMPro_Member_Edit_Panel {
	/**
	 * Set up the panel.
	 */
	public function __construct() {
		$this->slug = 'other';
		$this->title = __( 'Additional Info', 'paid-memberships-pro' );
	}

	/**
	 * Display the panel contents.
	 */
	protected function display_panel_contents() {
		// Show TOS Consent History if available.
		$tospage_id = pmpro_getOption( 'tospage' );
		$consent_log = pmpro_get_consent_log( self::get_user()->ID, true );
		if ( ! empty( $tospage_id ) || ! empty( $consent_log ) ) { ?>
			<h3><?php esc_html_e("TOS Consent History", 'paid-memberships-pro' ); ?></h3>
			<div id="tos_consent_history">
				<?php
					if ( ! empty( $consent_log ) ) {
						// Build the selectors for the invoices history list based on history count.
						$consent_log_classes = array();
						$consent_log_classes[] = "pmpro_consent_log";
						if ( count( $consent_log ) > 5 ) {
							$consent_log_classes[] = "pmpro_scrollable";
						}
						$consent_log_class = implode( ' ', array_unique( $consent_log_classes ) );
						echo '<ul class="' . esc_attr( $consent_log_class ) . '">';
						foreach( $consent_log as $entry ) {
							echo '<li>' . pmpro_consent_to_text( $entry ) . '</li>';
						}
						echo '</ul> <!-- end pmpro_consent_log -->';
					} else {
						echo __( 'N/A', 'paid-memberships-pro' );
					}
				?>
			</div>
			<?php
		}

		do_action( 'pmpro_after_membership_level_profile_fields', self::get_user() );
	}
}