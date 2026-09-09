<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
?>

<?php if ( ! empty( $history ) ) : ?>
	<ul class="woobe_fields" id="woobe_history_list">
		<?php foreach ( $history as $operation ) : ?>
			<?php $is_solo = isset( $operation['field_key'] ) ? true : false; ?>

			<li class="woobe_options_li <?php echo( $is_solo ? 'solo_li' : 'bulk_li' ); ?> woobe_history_item woobe_history_li_show" id="woobe_history_<?php echo esc_attr( ( $is_solo ? $operation['id'] : $operation['bulk_key'] ) ); ?>">
				<?php
				$filds = '';
				if ( $is_solo ) {
					$filds = $operation['field_key'];
				} elseif ( ! empty( $operation['set_of_keys'] ) ) {
						$keys = json_decode( $operation['set_of_keys'] );
					if ( $keys ) {
						$filds = implode( ',', $keys );
					}
				}

				?>
				<div class="woobe_history_data woobe_history_hidden" data-types="<?php echo esc_attr( $is_solo ? 1 : 2 ); ?>" data-author="<?php echo esc_attr( $operation['user_id'] ); ?>"  data-date="<?php echo esc_attr( ( $is_solo ) ? $operation['mod_date'] : $operation['started'] ); ?>" data-fields="<?php echo esc_attr( $filds ); ?>">
				</div>
				<?php
				// who did this - an owner looking at a month old change needs to
				// know whether it was him or the agent before he reverts it
				$woobe_author_id   = intval( $operation['user_id'] );
				$woobe_author_name = '';

				if ( class_exists( 'WOOBE_MCP' ) && WOOBE_MCP::user_id() === $woobe_author_id ) {
					$woobe_author_name = esc_html__( 'AI agent', 'woo-bulk-editor' );
				} else {
					$woobe_author      = get_userdata( $woobe_author_id );
					$woobe_author_name = ( $woobe_author instanceof WP_User ) ? $woobe_author->display_name : esc_html__( 'unknown', 'woo-bulk-editor' );
				}
				?> 
				<div class="col-lg-4">
					<?php if ( $is_solo ) : ?>
						<h5 style="margin: 0;"><?php echo esc_html( '#' . $operation['product_id'] . '. ' . get_the_title( $operation['product_id'] ) ); ?></h5>
						<h6 style="margin: 0;">[<?php echo esc_html( $operation['field_key'] ); ?>]</h6>
					<?php else : ?>
						<h5 style="margin: 0;"><?php esc_html_e( 'Bulk operation', 'woo-bulk-editor' ); ?></h5>
						[<span style="color: <?php echo esc_attr( $operation['state'] == 'completed' ? 'green' : 'red' ); ?>;"><small>
						<?php
						// translators: %s: operation state (completed or terminated).
						printf( esc_html__( 'state: %s', 'woo-bulk-editor' ), esc_html( $operation['state'] ) )
						?>
						</small></span>]
					<?php endif; ?>
						
					<div style="margin-top: 2px;">
						<small style="color: #888;"><?php echo esc_html( $woobe_author_name ); ?></small>
					</div>
				</div>
		
				<div class="col-lg-3">
					<small>
						<?php
						if ( $is_solo ) {
							echo esc_html( gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $operation['mod_date'] ) );
						} else {
							echo esc_html( gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $operation['started'] ) );
							if ( $operation['state'] !== 'terminated' ) {
								echo esc_html( ' - ' . gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $operation['finished'] ) );
							}
						}
						?>
					</small>
				</div>


				<div class="col-lg-3">
					<small>
						<?php
						if ( $is_solo ) {
							$show_val = isset( $settings_fields[ $operation['field_key'] ] ) && in_array( $settings_fields[ $operation['field_key'] ]['edit_view'], array( 'textinput' ) );
							$sanitize = '';
							if ( isset( $settings_fields[ $operation['field_key'] ]['sanitize'] ) ) {
								$sanitize = $settings_fields[ $operation['field_key'] ]['sanitize'];
							}
							if ( $show_val ) {
								// translators: %s: previous field value.
								printf( esc_html__( 'value been: %s', 'woo-bulk-editor' ), '<i>' . esc_html( substr( $products_obj->sanitize_answer_value( $operation['field_key'], $sanitize, $operation['prev_val'] ), 0, 120 ) ) . '</i>' );
							} else {
								echo '&nbsp;';
							}
						} else {
							// translators: %s: number of products that were bulk edited.
							printf( esc_html__( 'products bulked: %s', 'woo-bulk-editor' ), '<b>' . intval( $operation['products_count'] ) . '</b>' );

							if ( ! empty( $operation['set_of_keys'] ) ) {
								$set_of_keys = json_decode( $operation['set_of_keys'] );
								$names       = array();
								foreach ( $set_of_keys as $kk ) {
									$names[] = $settings_fields_full[ $kk ]['title'];
								}
								$names = implode( ', ', $names );
								echo '<br />';
								// translators: %s: list of column names.
								printf( esc_html__( 'columns: %s', 'woo-bulk-editor' ), '<b>' . esc_html( $names ) . '</b>' );
							}
						}
						?>
					</small>
				</div>


				<div class="col-lg-2 tar">
					<?php if ( $is_solo ) : ?>
						<a href="javascript: woobe_history_revert_solo(<?php echo esc_attr( $operation['id'] ); ?>, <?php echo esc_attr( $operation['product_id'] ); ?>);void(0);" class="button button-primary woobe_history_btn woobe_history_revert" title="<?php esc_html_e( 'revert', 'woo-bulk-editor' ); ?>"></a>
						<a href="javascript: woobe_history_delete_solo(<?php echo esc_attr( $operation['id'] ); ?>);void(0);" class="button button-primary woobe_history_btn woobe_history_delete" title="<?php esc_html_e( 'delete', 'woo-bulk-editor' ); ?>"></a><br />
					<?php else : ?>

						<a href="javascript: woobe_history_revert_bulk('<?php echo esc_attr( $operation['bulk_key'] ); ?>', <?php echo esc_attr( $operation['id'] ); ?>);void(0);" class="button button-primary woobe_history_btn woobe_history_revert" title="<?php esc_html_e( 'revert', 'woo-bulk-editor' ); ?>"></a>
						<a href="javascript: woobe_history_delete_bulk('<?php echo esc_attr( $operation['bulk_key'] ); ?>');void(0);" class="button button-primary woobe_history_btn woobe_history_delete" title="<?php esc_html_e( 'delete', 'woo-bulk-editor' ); ?>"></a><br />

						<div class="woobe_progress" style="display: none;">
							<div class="woobe_progress_in" id="woobe_bulk_progress_<?php echo esc_attr( $operation['id'] ); ?>">0%</div>
						</div>

					<?php endif; ?>
				</div>

				<div class="clear"></div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>

	<h5><?php esc_html_e( 'History is empty!', 'woo-bulk-editor' ); ?></h5>

<?php endif; ?>
