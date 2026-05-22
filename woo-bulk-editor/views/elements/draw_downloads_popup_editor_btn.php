<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

global $WOOBE;

// ***

$title     = '';
$downloads = array();
if ( $product_id > 0 ) {
	$product = $WOOBE->products->get_product( $product_id, false );
	if ( ! is_object( $product ) ) {
		return;
	}
	$downloadable_files = $product->get_downloads();
	$files_count        = count( $downloadable_files );
	$title              = $product->get_title();
}

if ( ! empty( $downloadable_files ) ) {
	foreach ( $downloadable_files as $file ) {
		$file['file'] = esc_attr( $file['file'] );
		$downloads[]  = (array) $file['data'];
	}
}
?>

<div class="woobe-button" onclick="woobe_act_downloads_editor(this)" data-downloads='<?php echo json_encode( $downloads, JSON_HEX_APOS ); ?>' data-count="<?php echo esc_attr( $files_count ); ?>" data-product_id="<?php echo esc_attr( $product_id ); ?>" id="popup_val_<?php echo esc_attr( $field_key ); ?>_<?php echo esc_attr( $product_id ); ?>" data-key="<?php echo esc_attr( $field_key ); ?>" data-terms_ids="" data-name="<?php echo esc_html__( 'Product: ', 'woo-bulk-editor' ) . esc_attr( $title ); ?>">
	<?php echo esc_html__( 'Files ', 'woo-bulk-editor' ) . '(' . esc_attr( $files_count ) . ')'; ?>
</div>


