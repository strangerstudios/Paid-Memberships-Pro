<?php
/**
 * @see https://github.com/WordPress/gutenberg/blob/trunk/docs/reference-guides/block-api/block-metadata.md#render
 */
$output = 'Level Name';
$output = do_blocks( $content );

?>
<p <?php echo get_block_wrapper_attributes(); ?>>
	<?php echo $output; ?>
</p>
