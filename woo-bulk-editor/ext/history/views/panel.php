<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

global $WOOBE;
?>
<h4 class="woobe-documentation"><a href="https://bulk-editor.com/document/history/" target="_blank" class="button button-primary"><span class="icon-book"></span></a>&nbsp;<?php esc_html_e( 'History', 'woo-bulk-editor' ); ?></h4>
<div class="woobe_alert"><?php esc_html_e( 'Works for edit-operations and not work with delete-operations! Also does not work with all operations which are presented in "Variations Advanced Bulk Operations"', 'woo-bulk-editor' ); ?></div>

<?php if ( $WOOBE->show_notes ) : ?>
	<div class="woobe_set_attention woobe_alert"><?php esc_html_e( 'In FREE version of the plugin it is possible to roll back 2 last operations.', 'woo-bulk-editor' ); ?></div><br />
<?php endif; ?>


<div class="col-lg-6">
	<label for="woobe_history_pagination_number"><?php esc_html_e( 'Per page:', 'woo-bulk-editor' ); ?></label>
	<select style="width: 50px;" id="woobe_history_pagination_number">
		<option value="10">10</option>
		<option value="20">20</option>
		<option value="50">50</option>
		<option value="-1"><?php esc_html_e( 'ALL', 'woo-bulk-editor' ); ?></option>
	</select>
</div>
<div class="col-lg-6 tar">
	<a href="javascript: woobe_history_clear();void(0);" class="button button-primary"><?php esc_html_e( 'Clear the History', 'woo-bulk-editor' ); ?></a>
</div>
<div class="clear"></div>
<div class="col-lg-12 woobe_history_pagination_cont">

	<div class="col-lg-12 woobe_history_filters">
		<div class="col-lg-2">
			<select id="woobe_history_show_types">
				<option value="0"><?php esc_html_e( 'all', 'woo-bulk-editor' ); ?></option>
				<option value="1"><?php esc_html_e( 'solo operations', 'woo-bulk-editor' ); ?></option>
				<option value="2"><?php esc_html_e( 'bulk operations', 'woo-bulk-editor' ); ?></option>
			</select>
		</div>
		<?php // Hidden: duplicated by the server-side 'Who made the change' filter. Remove display:none to bring it back. ?>
		<div class="col-lg-2" id="woobe_history_author_filter_wrap" style="display:none">
			<?php
			$opt_auth     = array();
			$opt_auth[-1] = esc_html__( 'by Author', 'woo-bulk-editor' );
			$opt_auth     = $opt_auth + WOOBE_HELPER::get_users();

			// the AI agent has no WordPress account, so it is not in the user
			// list - but its operations are in this history and the owner has to
			// be able to tell them from his own
			if ( class_exists( 'WOOBE_MCP' ) ) {
				$opt_auth[ WOOBE_MCP::user_id() ] = esc_html__( 'AI agent', 'woo-bulk-editor' );
			}
			?>
			<?php
			WOOBE_HELPER::draw_select_e(
				array(
					'options'    => $opt_auth,
					'product_id' => 'author',
					'class'      => 'woobe_history_filter_author chosen-select',
					'name'       => '',
					'field'      => 'woobe_history_filter',
				)
			);
			?>
		</div>
		<div class="col-lg-2" >
			<input type="text" onmouseover="woobe_init_calendar(this)" data-title="<?php esc_html_e( 'by date from', 'woo-bulk-editor' ); ?>" data-val-id="woobe_history_filter_date_from" value="" class="woobe_calendar" placeholder="<?php esc_html_e( 'by date from', 'woo-bulk-editor' ); ?>" />
			<input type="hidden" data-key="from" data-product-id="" id="woobe_history_filter_date_from" value=""  />            
			<a href="#" class="woobe_calendar_clear" data-val-id="woobe_history_filter_date_from" ><?php echo esc_html__( 'clear', 'woo-bulk-editor' ); ?></a>
		</div>
		<div class="col-lg-2" >
			<input type="text" onmouseover="woobe_init_calendar(this)" data-title="<?php esc_html_e( 'by date to', 'woo-bulk-editor' ); ?>" data-val-id="woobe_history_filter_date_to" value="" class="woobe_calendar" placeholder="<?php esc_html_e( 'by date to', 'woo-bulk-editor' ); ?>" />
			<input type="hidden" data-key="from" data-product-id="" id="woobe_history_filter_date_to" value=""  />
			<a href="#" class="woobe_calendar_clear" data-val-id="woobe_history_filter_date_to"><?php echo esc_html__( 'clear', 'woo-bulk-editor' ); ?></a>
		</div>
		<div class="col-lg-2" >
			<input type="text" id="woobe_history_filter_field" placeholder="<?php esc_html_e( 'by fields, use comma', 'woo-bulk-editor' ); ?>" >
		</div>     
		<div class="col-lg-2" >
			<input type="button" id="woobe_history_filter_submit" class="button button-primary" value="<?php esc_html_e( 'Filter', 'woo-bulk-editor' ); ?>">&nbsp;<input type="button" class="button button-primary" id="woobe_history_filter_reset" value="<?php esc_html_e( 'Reset', 'woo-bulk-editor' ); ?>">
		</div>


	</div>

	<?php if ( ! empty( $history_admin ) ) : ?>
		<?php
		// who made the change and how - administrators only, filtered on the
		// server: the list reloads with only the matching rows
		$woobe_history_who = is_array( $history_authors ) ? $history_authors : array();

		if ( class_exists( 'WOOBE_MCP' ) && ! isset( $woobe_history_who[ WOOBE_MCP::shop_user_id() ] ) ) {
			$woobe_history_who[ WOOBE_MCP::shop_user_id() ] = __( 'AI agent (shop key)', 'woo-bulk-editor' );
		}
		?>
		<div class="col-lg-12 woobe_history_filters woobe_history_filters_who">
			<div class="col-lg-3">
				<select id="woobe_history_filter_who" title="<?php esc_attr_e( 'Who made the change', 'woo-bulk-editor' ); ?>">
					<option value=""><?php esc_html_e( 'Who made the change: everyone', 'woo-bulk-editor' ); ?></option>
					<?php foreach ( $woobe_history_who as $woobe_history_author_id => $woobe_history_author_label ) : ?>
						<option value="<?php echo esc_attr( $woobe_history_author_id ); ?>"><?php echo esc_html( $woobe_history_author_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-lg-3">
				<select id="woobe_history_filter_via" title="<?php esc_attr_e( 'By hand or through the AI agent', 'woo-bulk-editor' ); ?>">
					<option value=""><?php esc_html_e( 'all', 'woo-bulk-editor' ); ?></option>
					<option value="0"><?php esc_html_e( 'by hand', 'woo-bulk-editor' ); ?></option>
					<option value="1"><?php esc_html_e( 'through AI agent', 'woo-bulk-editor' ); ?></option>
				</select>
			</div>
		</div>
	<?php endif; ?>
	<input type="hidden" id="woobe_history_panel_nonce" value="<?php echo esc_attr( wp_create_nonce( 'woobe_history_panel_nonce' ) ); ?>">
</div>    
<div class="clear"></div>

<div id="woobe_history_list_container"></div>

<div id="woobe_history_pagination_container">
	<div>
		<a href="#"class="woobe_history_pagination_prev button button-primary"><span class="icon-left"></span><?php esc_html_e( 'Prev', 'woo-bulk-editor' ); ?></a>
	</div>
	<div>
		<span class="woobe_history_pagination_current_count"></span><?php esc_html_e( 'of', 'woo-bulk-editor' ); ?><span class="woobe_history_pagination_count"></span>
	</div>
	<div>
		<a href="#" class="woobe_history_pagination_next button button-primary"><?php esc_html_e( 'Next', 'woo-bulk-editor' ); ?><span class="icon-right"></span></a>
	</div>
</div>

