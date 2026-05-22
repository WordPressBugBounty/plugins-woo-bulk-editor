<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// global $WOOBE;
if ( empty( $text ) ) {
	$text = esc_html__( 'Not allowed!', 'woo-bulk-editor' );
}
?>

<a class="info_helper info_restricked" data-balloon-length="medium" data-balloon-pos="<?php echo esc_attr( $direction ); ?>" data-balloon="<?php echo esc_html( $text ); ?>"><img src="<?php echo esc_attr( WOOBE_ASSETS_LINK ) . 'images/restricted.png'; ?>" width="25" alt="" /></a>

