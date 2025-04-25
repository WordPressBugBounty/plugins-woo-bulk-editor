<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

global $WOOBE;
?>

<input type="checkbox" <?php if (!intval($WOOBE->settings->load_switchers)): ?>onclick="woobe_click_checkbox(this, '<?php echo esc_attr($numcheck) ?>')"<?php endif; ?> data-numcheck='<?php echo esc_attr($numcheck) ?>' data-true='<?php echo esc_html($labels['true']) ?>' data-false='<?php echo esc_html($labels['false']) ?>' data-val-true='<?php echo esc_attr($vals['true']) ?>' data-val-false='<?php echo esc_attr($vals['false']) ?>' data-trigger-target='<?php echo esc_attr($trigger_target) ?>' data-class-true='label-inverse-success' data-class-false='label-inverse-default' class="js-switch js-check-change" <?php checked($is) ?> />
<input type="hidden" id="js_check_<?php echo esc_attr($numcheck) ?>" data-hidden-numcheck='<?php echo esc_attr($numcheck) ?>' value="<?php echo esc_attr($vals[($is ? 'true' : 'false')]) ?>" name="<?php echo esc_html($name) ?>" />
<label data-label-numcheck='<?php echo esc_attr($numcheck) ?>' class="label <?php echo esc_attr($css_classes) ?> <?php echo ($is ? 'label-inverse-success' : 'label-inverse-default') ?> check-change js-check-change-field"><?php echo esc_attr(($is ? $labels['true'] : $labels['false'])) ?></label>
