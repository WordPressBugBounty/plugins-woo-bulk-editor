<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// global $WOOBE;
if ( empty( $src ) ) {
	return '';
}
?>

<img src="<?php echo esc_attr( $src ); ?>" 
<?php
if ( ! empty( $class ) ) :
	?>
	class="<?php echo esc_attr( $class ); ?>"<?php endif; ?>
	<?php
	if ( ! empty( $width ) ) :
		?>
	width="<?php echo esc_attr( $width ); ?>"<?php endif; ?> alt="<?php echo esc_html( $alt ); ?>" />

