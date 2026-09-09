<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
global $WOOBE;
$btn_text = esc_html__( 'Content[empty]', 'woo-bulk-editor' );
if ( $val ) {
	$btn_text = esc_html__( 'Content', 'woo-bulk-editor' );
	if ( $WOOBE->settings->show_text_editor && is_string( $val ) ) {
		$btn_text = wp_trim_words( $val, 15 );
		if ( ! $btn_text ) {
			$btn_text = esc_html__( 'Content', 'woo-bulk-editor' );
		}
	}
}
?>

<div class="woobe-button text-editor-standart" data-text-title="<?php echo esc_attr( $WOOBE->settings->show_text_editor ); ?>" onclick="woobe_act_popupeditor(this, <?php echo intval( $post['post_parent'] ); ?>)" data-product_id="<?php echo esc_attr( $post['ID'] ); ?>" id="popup_val_<?php echo esc_attr( $field_key ); ?>_<?php echo esc_attr( $post['ID'] ); ?>" data-key="<?php echo esc_attr( $field_key ); ?>" data-terms_ids="" data-name="
<?php
// translators: %s: product title.
printf( esc_html__( 'Product: %s', 'woo-bulk-editor' ), esc_html( $post['post_title'] ) )
?>
"><?php echo wp_kses_post( $btn_text ); ?></div>
