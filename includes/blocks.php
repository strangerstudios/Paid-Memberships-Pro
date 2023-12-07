<?php
/**
 * Add new block category for Paid Memberships Pro blocks.
 *
 * @since 1.0
 *
 * @param array $categories Array of block categories.
 * @return array Array of block categories.
 */
function pmpro_block_categories( $categories ) {
	return array_merge(
		$categories,
		array(
			array(
				'slug' => 'pmpro',
				'title' => esc_html__( 'Paid Memberships Pro', 'paid-memberships-pro' ),
			),
			array(
				'slug' => 'pmpro-pages',
				'title' => esc_html__( 'Paid Memberships Pro Pages', 'paid-memberships-pro' ),
			),
		)
	);
}
add_filter( 'block_categories_all', 'pmpro_block_categories' );

/**
 * Register block types for the block editor.
 */
function pmpro_register_block_types() {
	register_block_type( PMPRO_DIR . '/blocks/build/account-invoices-section' );
	register_block_type( PMPRO_DIR . '/blocks/build/account-profile-section' );	
	register_block_type( PMPRO_DIR . '/blocks/build/account-links-section' );
 	register_block_type( PMPRO_DIR . '/blocks/build/account-membership-section' );
	register_block_type( PMPRO_DIR . '/blocks/build/account-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/billing-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/cancel-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/checkout-button' );
	register_block_type( PMPRO_DIR . '/blocks/build/checkout-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/confirmation-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/invoice-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/levels-page' );
	register_block_type( PMPRO_DIR . '/blocks/build/login' );
	register_block_type( PMPRO_DIR . '/blocks/build/member-profile-edit' );
	register_block_type( PMPRO_DIR . '/blocks/build/membership' );
	register_block_type( PMPRO_DIR . '/blocks/build/single-level' );
	register_block_type( PMPRO_DIR . '/blocks/build/single-level-name' );
	register_block_type( PMPRO_DIR . '/blocks/build/single-level-expiration' );
	register_block_type( PMPRO_DIR . '/blocks/build/single-level-description' );
	register_block_type( PMPRO_DIR . '/blocks/build/single-level-price' );
}
add_action( 'init', 'pmpro_register_block_types' );
/**
 * Enqueue block editor only CSS.
 */
function pmpro_block_editor_assets() {
	// Enqueue the CSS file css/blocks.editor.css.
	wp_enqueue_style(
		'pmpro-block-editor-css',
		PMPRO_URL . '/css/blocks.editor.css',
		array( 'wp-edit-blocks' )
	);

	// If we're editing a post that can be restricted, enqueue the sidebar block editor script.
	if ( in_array( get_post_type(), apply_filters( 'pmpro_restrictable_post_types', array( 'page', 'post' ) ) ) ) {
		wp_register_script(
			'pmpro-sidebar-editor-script',
			PMPRO_URL . '/blocks/build/sidebar/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-editor', 'wp-api-request', 'wp-plugins', 'wp-edit-post' )
		);
		wp_localize_script(
			'pmpro-sidebar-editor-script',
			'pmpro_block_editor_sidebar',
			array(
				'post_id' => get_the_ID(),
			)
		);
		wp_enqueue_script( 'pmpro-sidebar-editor-script' );
	}

}
add_action( 'enqueue_block_editor_assets', 'pmpro_block_editor_assets' );

/**
 * Register post meta needed for our blocks.
 *
 * @since TBD
 */
function pmpro_register_post_meta() {
	// Register pmpro_default_level for the checkout block.
	register_post_meta(
		'',
		'pmpro_default_level',
		array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'string',
		)
	);
}
add_action( 'init', 'pmpro_register_post_meta' );
