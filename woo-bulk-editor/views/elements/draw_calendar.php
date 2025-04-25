<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
//echo date('d-m-Y H:i:s',$val);
//global $WOOBE;
 
$format = 'd/m/Y';
if($time){
    $format = 'd/m/Y H:i';
}
?>

<input type="text" onmouseover="woobe_init_calendar(this)" data-time="<?php echo ($time)?"true":"false" ?>" data-title="<?php echo esc_html(str_replace('"', '', strip_tags($product_title))) ?>" data-val-id="calendar_<?php echo esc_attr($field_key) ?>_<?php echo esc_attr($product_id) ?>" value="<?php if ($val) echo esc_html(date($format, $val)) ?>" class="woobe_calendar" placeholder="<?php echo esc_html($print_placeholder ? $product_title : '') ?>" />
<input type="hidden" data-key="<?php echo esc_attr($field_key) ?>" data-product-id="<?php echo esc_attr($product_id) ?>" id="calendar_<?php echo esc_attr($field_key) ?>_<?php echo esc_attr($product_id) ?>" value="<?php echo esc_html($val) ?>" name="<?php echo esc_html($name) ?>" />
<a href="javascript: void(0);" class="woobe_calendar_cell_clear"><?php echo esc_html__('clear', 'woo-bulk-editor') ?></a>
