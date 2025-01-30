<?php

class PMPro_Email_Template_Checkout_Check extends PMPro_Email_Template {

	/**
	 * The user object of the user to send the email to.
	 *
	 * @var WP_User
	 */
	protected $user;

	/**
	 * The {@link MemberOrder} object of the order that was updated.
	 *
	 * @var MemberOrder
	 */
	protected $order;

	/**
	 * Constructor.
	 *
	 * @since TBD
	 *
	 * @param WP_User $user The user object of the user to send the email to.
	 * @param MemberOrder $order The order object that is associated to the member.
	 */
	public function __construct( WP_User $user,  MemberOrder $order ) {
		$this->user = $user;
		$this->order = $order;
	}

	/**
	 * Get the email template slug.
	 *
	 * @since TBD
	 *
	 * @return string The email template slug.
	 */
	public static function get_template_slug() {
		return 'checkout_check';
	}

	/**
	 * Get the "nice name" of the email template.
	 *
	 * @since TBD
	 *
	 * @return string The "nice name" of the email template.
	 */
	public static function get_template_name() {
		return esc_html__( 'Checkout - Pay by Check', 'paid-memberships-pro' );
	}

	/**
	 * Get "help text" to display to the admin when editing the email template.
	 *
	 * @since TBD
	 *
	 * @return string The help text.
	 */
	public static function get_template_description() {
		return esc_html__( 'This is a membership confirmation welcome email sent to a new member or to existing members that change their level using the "Pay by Check" gateway.', 'paid-memberships-pro' );
	}

	/**
	 * Get the default subject for the email.
	 *
	 * @since TBD
	 *
	 * @return string The default subject for the email.
	 */
	public static function get_default_subject() {
		return esc_html__( 'Your membership confirmation for !!sitename!!', 'paid-memberships-pro' );
	}

	/**
	 * Get the default body for the email.
	 *
	 * @since TBD
	 *
	 * @return string The default body for the email.
	 */
	public static function get_default_body() {
		return wp_kses_post('<p>Thank you for your membership to !!sitename!!. Your membership account is now active.</p>

		!!membership_level_confirmation_message!!

		!!instructions!!

		<p>Below are details about your membership account and a receipt for your initial membership order.</p>

		<p>Account: !!display_name!! (!!user_email!!)</p>
		<p>Membership Level: !!membership_level_name!!</p>
		<p>Membership Fee: !!membership_cost!!</p>
		!!membership_expiration!! !!discount_code!!

		<p>
			Order #!!order_id!! on !!order_date!!<br />
			Total Billed: !!order_total!!
		</p>

		<p>Log in to your membership account here: !!login_url!!</p>', 'paid-memberships-pro' );
	}

	/**
	 * Get the email address to send the email to.
	 *
	 * @since TBD
	 *
	 * @return string The email address to send the email to.
	 */
	public function get_recipient_email() {
		return $this->user->user_email;
	}

	/**
	 * Get the name of the email recipient.
	 *
	 * @since TBD
	 *
	 * @return string The name of the email recipient.
	 */
	public function get_recipient_name() {
		return $this->user->display_name;
	}

	/**
	 * Get the email template variables for the email paired with a description of the variable.
	 *
	 * @since TBD
	 *
	 * @return array The email template variables for the email (key => value pairs).
	 */
	public static function get_email_template_variables_with_description() {

		return array(
			'!!subject!!' => esc_html__( 'The subject of the email.', 'paid-memberships-pro' ),
			'!!name!!' => esc_html__( 'The name of the email recipient.', 'paid-memberships-pro' ),
			'!!display_name!!' => esc_html__( 'The name of the email recipient.', 'paid-memberships-pro' ),
			'!!user_login!!' => esc_html__( 'The login name of the email recipient.', 'paid-memberships-pro' ),
			'!!membership_id!!' => esc_html__( 'The ID of the membership level.', 'paid-memberships-pro' ),
			'!!membership_level_name!!' => esc_html__( 'The name of the membership level.', 'paid-memberships-pro' ),
			'!!confirmation_message!!' => esc_html__( 'The confirmation message for the membership level.', 'paid-memberships-pro' ),
			'!!membership_cost!!' => esc_html__( 'The cost of the membership level.', 'paid-memberships-pro' ),
			'!!user_email!!' => esc_html__( 'The email address of the email recipient.', 'paid-memberships-pro' ),
			'!!membership_expiration!!' => esc_html__( 'The expiration date of the membership level.', 'paid-memberships-pro' ),
			'!!discount_code!!' => esc_html__( 'The discount code used for the membership level.', 'paid-memberships-pro' ),
			'!!order_id!!' => esc_html__( 'The ID of the order.', 'paid-memberships-pro' ),
			'!!order_date!!' => esc_html__( 'The date of the order.', 'paid-memberships-pro' ),
			'!!order_total!!' => esc_html__( 'The total cost of the order.', 'paid-memberships-pro' ),
		);
	}

	/**
	 * Get the email template variables for the email.
	 *
	 * @since TBD
	 *
	 * @return array The email template variables for the email (key => value pairs).
	 */
	public function get_email_template_variables() {
		$order = $this->order;
		$user = $this->user;
		$membership_level = pmpro_getLevel( $order->membership_id );

		$confirmation_in_email = get_pmpro_membership_level_meta( $membership_level->id, 'confirmation_in_email', true );
			if ( ! empty( $confirmation_in_email ) ) {
				$confirmation_message = $membership_level->confirmation;
			} else {
				$confirmation_message = '';
			}

		$email_template_variables = array(
			'subject' => $this->get_default_subject(),
			'name' => $this->get_recipient_name(),
			'display_name' => $this->get_recipient_name(),
			'user_login' => $user->user_login,
			'membership_id' => $membership_level->id,
			'membership_level_name' => $membership_level->name,
			'confirmation_message' => $confirmation_message,
			'membership_cost' => pmpro_getLevelCost($membership_level),
			'user_email' => $user->user_email,
			'order_id' => $order->code,
			'order_total' => $order->get_formatted_total(),
			'order_date' => date_i18n( get_option( 'date_format' ), $order->getTimestamp() ),
		);

		return $email_template_variables;
	}

}

/**
 * Register the email template.
 *
 * @since TBD
 *
 * @param array $email_templates The email templates (template slug => email template class name)
 * @return array The modified email templates array.
 */
function pmpro_email_templates_checkout_check( $email_templates ) {
	$email_templates['checkout_check'] = 'PMPro_Email_Template_Checkout_Check';

	return $email_templates;
}
add_filter( 'pmpro_email_templates', 'pmpro_email_templates_checkout_check' );