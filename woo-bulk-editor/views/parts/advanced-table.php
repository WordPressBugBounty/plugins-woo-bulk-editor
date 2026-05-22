<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

	$allowedpost          = wp_kses_allowed_html( 'post' );
	$allowedpost['input'] = array(
		'type'    => array(),
		'class'   => array(),
		'name'    => array(),
		'value'   => array(),
		'checked' => array(),
	);
	?>

<div style="padding: 0 0.2em;">
	<div id="woobe_tools_panel">

		<div style="position: relative">

			<a href="#" class="button button-secondary woobe_tools_panel_full_width_btn icon-resize-horizontal-1" title="<?php esc_html_e( 'Set full width', 'woo-bulk-editor' ); ?>"></a>
			<a href="#" class="button button-secondary woobe_tools_panel_profile_btn" title="<?php esc_html_e( 'Columns profiles', 'woo-bulk-editor' ); ?>"></a>


			<?php do_action( 'woobe_tools_panel_buttons' ); ?>


			<a href="#" class="button button-secondary woobe_tools_panel_newprod_btn" title="<?php esc_html_e( 'New Product', 'woo-bulk-editor' ); ?>"></a>



			<a href="#" class="button button-primary woobe_tools_panel_duplicate_btn" title="<?php esc_html_e( 'Duplicate selected product(s). ATTENTION: duplication of variations of the products is locked!', 'woo-bulk-editor' ); ?>" style="display: none;"></a>
			<a href="#" class="button button-primary woobe_tools_panel_delete_btn" title="<?php esc_html_e( 'Delete selected product(s)', 'woo-bulk-editor' ); ?>" style="display: none;"></a>

			<a href="#" class="button button-primary woobe_tools_panel_uncheck_all" title="<?php esc_html_e( 'Uncheck all selected products', 'woo-bulk-editor' ); ?>" style="display: none;"></a>
			<a href="#" class="button button-secondary woobe_filter_reset_btn2" title="<?php esc_html_e( 'Reset filters', 'woo-bulk-editor' ); ?>" style="display: none;"></a>




			&nbsp;<span>
				<?php
				WOOBE_HELPER::draw_advanced_switcher_e(
					0,
					'woobe_show_variations',
					'',
					array(
						'true'  => esc_html__( 'variations', 'woo-bulk-editor' ),
						'false' => esc_html__( 'variations', 'woo-bulk-editor' ),
					),
					array(
						'true'  => 1,
						'false' => 0,
					),
					'js_check_woobe_show_variations',
					'woobe_show_variations'
				);
				?>
				<?php WOOBE_HELPER::draw_tooltip( esc_html__( 'Bulk editing of the parent products will be ignored! Enabling this mode hide not relevant bulk edit operations for the products [variations]! Before activation of this mode, for more convenient editing of [variations] recommend make filtering for all variable products. Binded operation also will be applied only to the products variations!', 'woo-bulk-editor' ) ); ?>
			</span>&nbsp;

			<span><a href="#" id="woobe_select_all_vars" class="button" style="display: none;"><?php esc_html_e( 'select all variations', 'woo-bulk-editor' ); ?></a></span>

			<input type="hidden" id="woobe_tools_panel_nonce" value="<?php echo esc_attr( wp_create_nonce( 'woobe_tools_panel_nonce' ) ); ?>">
			<?php do_action( 'woobe_tools_panel_buttons_end' ); ?>

			<div style="display: none;">
				<a href="#" id="woobe_scroll_right" class="button" title="<?php esc_html_e( 'Scroll right', 'woo-bulk-editor' ); ?>" style="display: none;"></a>
				<a href="#" id="woobe_scroll_left" class="button" title="<?php esc_html_e( 'Scroll left', 'woo-bulk-editor' ); ?>" style="display: none;"></a>
			</div>

		</div>
	</div>
</div>
<table id="advanced-table" data-editable="<?php echo esc_attr( $table_data['editable'] ); ?>" data-default-sort-by="<?php echo esc_attr( $table_data['default-sort-by'] ); ?>" data-sort="<?php echo esc_attr( $table_data['sort'] ); ?>" data-no-order="<?php echo esc_attr( $table_data['no-order'] ); ?>" data-additional='' data-start-page="<?php echo esc_attr( $table_data['start-page'] ); ?>"  data-extend-per-page="<?php echo esc_attr( $table_data['extend_per_page'] ); ?>" data-per-page="<?php echo esc_attr( $table_data['per-page'] ); ?>" data-fields="<?php echo esc_attr( $table_data['fields'] ); ?>" data-edit-views="<?php echo esc_attr( $table_data['edit_views'] ); ?>" data-edit-sanitize="<?php echo esc_attr( $table_data['edit_sanitize'] ); ?>" class="display table dt-responsive table-striped table-bordered nowrap">
	<thead>
		<tr>
			<?php foreach ( $table_labels as $c => $label ) : ?>
				<th id="woobe_col_<?php echo esc_attr( $c ); ?>"><?php echo wp_kses( trim( $label['title'] ), $allowedpost ); ?><?php ( ! empty( $label['desc'] ) and $c > 0 ? WOOBE_HELPER::draw_tooltip( $label['desc'] ) : '' ); ?></th>
				<?php endforeach; ?>
		</tr>
	</thead>
	<tfoot>
		<tr>
			<?php foreach ( $table_labels as $label ) : ?>
				<th><?php echo wp_kses( trim( $label['title'] ), $allowedpost ); ?></th>
			<?php endforeach; ?>
		</tr>
	</tfoot>
	<tbody></tbody>
</table>


