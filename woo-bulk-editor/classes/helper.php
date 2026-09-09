<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

final class WOOBE_HELPER {

	static $users = array();

	public static function draw_link( $data ) {
		$link = "<a href='{$data['href']}'";

		if ( isset( $data['class'] ) ) {
			$link .= " class='{$data['class']}'";
		}

		if ( isset( $data['style'] ) ) {
			$link .= " style='{$data['style']}'";
		}

		if ( isset( $data['id'] ) ) {
			$link .= " id='{$data['id']}'";
		}

		if ( isset( $data['target'] ) ) {
			$link .= " target='{$data['target']}'";
		}

		if ( isset( $data['title_attr'] ) ) {
			$link .= " title='{$data['title_attr']}'";
		}

		if ( isset( $data['more'] ) ) {
			$link .= " {$data['more']} ";
		}

		$link .= '>' . $data['title'] . '</a>';
		return $link;
	}

	public static function draw_link_e( $data ) {
		?>
		<a href='<?php echo esc_attr( $data['href'] ); ?>'
		<?php if ( isset( $data['class'] ) ) { ?>
				class='<?php echo esc_attr( $data['class'] ); ?>'
			<?php } ?>
			<?php if ( isset( $data['style'] ) ) { ?>
				style='<?php echo esc_attr( $data['style'] ); ?>'
			<?php } ?>
			<?php if ( isset( $data['id'] ) ) { ?>
				id='<?php echo esc_attr( $data['id'] ); ?>'
			<?php } ?>			   
			<?php if ( isset( $data['target'] ) ) { ?>
				target='<?php echo esc_attr( $data['target'] ); ?>'
			<?php } ?>
			<?php if ( isset( $data['title_attr'] ) ) { ?>
				title='<?php echo esc_attr( $data['title_attr'] ); ?>'
			<?php } ?>
			<?php if ( isset( $data['more'] ) ) { ?>
				<?php echo esc_attr( $data['more'] ); ?>
			<?php } ?>
			>
				<?php echo wp_kses_post( $data['title'] ); ?>

		</a>

		<?php
	}

	public static function get_users() {

		if ( empty( self::$users ) ) {

			$roles__in = array();
			foreach ( wp_roles()->roles as $role_slug => $role ) {
				if ( ! empty( $role['capabilities']['publish_posts'] ) ) {
					$roles__in[] = $role_slug;
				}
			}

			$users_arg = apply_filters(
				'woobe_users_args',
				array(
					'fields'   => array( 'ID', 'display_name' ),
					// 'who' => 'authors',
					'role__in' => $roles__in,
				)
			);

			$users = get_users( $users_arg );

			foreach ( $users as $user ) {
				self::$users[ $user->ID ] = $user->display_name;
			}
		}

		return self::$users;
	}

	public static function draw_select( $data, $is_multi = false ) {
		$multiple = '';
		if ( $is_multi ) {
			$multiple = 'multiple size=2';
		}

		// for filters
		$name = '';
		if ( isset( $data['name'] ) ) {
			$name = "name='{$data['name']}'";
		}

		$disabled = '';
		if ( isset( $data['disabled'] ) and $data['disabled'] ) {
			$disabled = "disabled=''";
		}

		// ***

		$onchange = '';
		if ( isset( $data['onchange'] ) ) {
			$onchange = "onchange='{$data['onchange']};'";
		}

		$onmouseover = '';
		if ( isset( $data['onmouseover'] ) ) {
			$onmouseover = "onmouseover='{$data['onmouseover']};'";
		}

		// ***
		$selected = '';
		if ( isset( $data['selected'] ) ) {
			if ( is_array( $data['selected'] ) ) {
				$selected = implode( ',', $data['selected'] );
			} else {
				$selected = $data['selected'];
			}
		}

		$select = "<div class='select-wrap'><select {$multiple} {$name} {$disabled} {$onchange} {$onmouseover} id='mselect_{$data['field']}_{$data['product_id']}' data-field='{$data['field']}' data-product-id='{$data['product_id']}' data-placeholder=' ' data-selected='{$selected}' class='{$data['class']}'>";

		// ***

		if ( isset( $data['options'] ) ) {
			$in_selected = array();

			// ***

			if ( isset( $data['selected'] ) ) {
				if ( is_array( $data['selected'] ) ) {
					$in_selected = $data['selected'];
				} else {
					$in_selected[] = $data['selected'];
				}
			}

			// ***

			foreach ( $data['options'] as $key => $title ) {

				$selected = false;
				if ( in_array( $key, $in_selected ) ) {
					$selected = true;
				}
				$select .= '<option ' . selected( $selected, true, false ) . " value='{$key}'>" . $title . '</option>';
			}
		}

		$select .= '</select></div>';
		return $select;
	}

	public static function draw_select_e( $data, $is_multi = false ) {
		$multiple = '';
		if ( $is_multi ) {
			$multiple = 'multiple size=2';
		}

		$disabled = '';
		if ( isset( $data['disabled'] ) and $data['disabled'] ) {
			$disabled = "disabled=''";
		}

		$onmouseover = '';
		if ( isset( $data['onmouseover'] ) ) {
			$onmouseover = "onmouseover='{$data['onmouseover']};'";
		}

		// ***
		$selected = '';
		if ( isset( $data['selected'] ) ) {
			if ( is_array( $data['selected'] ) ) {
				$selected = implode( ',', $data['selected'] );
			} else {
				$selected = $data['selected'];
			}
		}
		?>
		<div class='select-wrap'>
			<select <?php echo esc_attr( $multiple ); ?>
				<?php if ( isset( $data['name'] ) ) { ?>
					name='<?php echo esc_attr( $data['name'] ); ?>'
				<?php } ?>
				<?php if ( isset( $data['onchange'] ) ) { ?>
					onchange='<?php echo esc_attr( $data['onchange'] ); ?>'
				<?php } ?>
				<?php if ( isset( $data['onmouseover'] ) ) { ?>
					onmouseover='<?php echo esc_attr( $data['onmouseover'] ); ?>'
				<?php } ?>
		<?php echo esc_attr( $disabled ); ?>
				id = 'mselect_<?php echo esc_attr( $data['field'] ); ?>_<?php echo esc_attr( $data['product_id'] ); ?>'
				data-field='<?php echo esc_attr( $data['field'] ); ?>'
				data-product-id='<?php echo esc_attr( $data['product_id'] ); ?>'
				data-placeholder=' ' 
				data-selected='<?php echo esc_attr( $selected ); ?>' 
				class='<?php echo esc_attr( $data['class'] ); ?>'
				>		
					<?php
					// ***
					if ( isset( $data['options'] ) ) {
						$in_selected = array();

						// ***

						if ( isset( $data['selected'] ) ) {
							if ( is_array( $data['selected'] ) ) {
								$in_selected = $data['selected'];
							} else {
								$in_selected[] = $data['selected'];
							}
						}

						// ***

						foreach ( $data['options'] as $key => $title ) {

							$selected = false;
							if ( in_array( $key, $in_selected ) ) {
								$selected = true;
							}
							?>
						<option <?php echo selected( $selected, true, false ); ?> value='<?php echo esc_attr( $key ); ?>'><?php echo esc_html( $title ); ?></option>
							<?php
						}
					}
					?>
			</select>
		</div>
		<?php
	}

	public static function draw_advanced_switcher( $is, $numcheck, $name, $labels, $vals, $trigger_target = '', $css_classes = '' ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_advanced_switcher.php',
			array(
				'is'             => $is,
				'numcheck'       => $numcheck,
				'name'           => $name,
				'labels'         => $labels,
				'vals'           => $vals,
				'trigger_target' => $trigger_target,
				'css_classes'    => $css_classes,
			)
		);
	}

	public static function draw_advanced_switcher_e( $is, $numcheck, $name, $labels, $vals, $trigger_target = '', $css_classes = '' ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_advanced_switcher.php',
			array(
				'is'             => $is,
				'numcheck'       => $numcheck,
				'name'           => $name,
				'labels'         => $labels,
				'vals'           => $vals,
				'trigger_target' => $trigger_target,
				'css_classes'    => $css_classes,
			)
		);
	}

	public static function draw_calendar( $product_id, $product_title, $field_key, $val, $name = '', $print_placeholder = false, $time = false ) {

		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_calendar.php',
			array(
				'product_id'        => $product_id,
				'product_title'     => $product_title,
				'field_key'         => $field_key,
				'val'               => $val,
				'name'              => $name,
				'print_placeholder' => $print_placeholder,
				'time'              => $time,
			)
		);
	}

	public static function draw_calendar_e( $product_id, $product_title, $field_key, $val, $name = '', $print_placeholder = false, $time = false ) {

		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_calendar.php',
			array(
				'product_id'        => $product_id,
				'product_title'     => $product_title,
				'field_key'         => $field_key,
				'val'               => $val,
				'name'              => $name,
				'print_placeholder' => $print_placeholder,
				'time'              => $time,
			)
		);
	}

	public static function draw_taxonomy_popup_btn( $data, $tax_key, $post ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_taxonomy_popup_btn.php',
			array(
				'data'    => $data,
				'tax_key' => $tax_key,
				'post'    => $post,
			)
		);
	}

	public static function draw_attribute_list_btn( $terms, $selected_terms_ids, $tax_key, $post ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_attribute_list_btn.php',
			array(
				'terms'              => $terms,
				'selected_terms_ids' => $selected_terms_ids,
				'tax_key'            => $tax_key,
				'post'               => $post,
			)
		);
	}

	public static function draw_attribute_list_btn_e( $terms, $selected_terms_ids, $tax_key, $post ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_attribute_list_btn.php',
			array(
				'terms'              => $terms,
				'selected_terms_ids' => $selected_terms_ids,
				'tax_key'            => $tax_key,
				'post'               => $post,
			)
		);
	}

	public static function draw_popup_editor_btn( $val, $field_key, $post ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_popup_editor_btn.php',
			array(
				'val'       => $val,
				'field_key' => $field_key,
				'post'      => $post,
			)
		);
	}

	public static function draw_downloads_popup_editor_btn( $field_key, $product_id, $files_count = 0 ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_downloads_popup_editor_btn.php',
			array(
				'field_key'   => $field_key,
				'product_id'  => $product_id,
				'files_count' => $files_count,
			)
		);
	}

	public static function draw_downloads_popup_editor_btn_e( $field_key, $product_id, $files_count = 0 ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_downloads_popup_editor_btn.php',
			array(
				'field_key'   => $field_key,
				'product_id'  => $product_id,
				'files_count' => $files_count,
			)
		);
	}

	public static function draw_gallery_popup_editor_btn( $field_key, $product_id, $images = array() ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_gallery_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'images'     => $images,
			)
		);
	}

	public static function draw_gallery_popup_editor_btn_e( $field_key, $product_id, $images = array() ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_gallery_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'images'     => $images,
			)
		);
	}

	public static function draw_upsells_popup_editor_btn( $field_key, $product_id, $ids = array() ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_upsells_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_upsells_popup_editor_btn_e( $field_key, $product_id, $ids = array() ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_upsells_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_cross_sells_popup_editor_btn( $field_key, $product_id, $ids = array() ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_cross_sells_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_cross_sells_popup_editor_btn_e( $field_key, $product_id, $ids = array() ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_cross_sells_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_meta_popup_editor_btn( $field_key, $product_id, $btn_title = '' ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_meta_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'btn_title'  => $btn_title,
			)
		);
	}

	public static function draw_meta_popup_editor_btn_e( $field_key, $product_id, $btn_title = '' ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_meta_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'btn_title'  => $btn_title,
			)
		);
	}

	public static function draw_grouped_popup_editor_btn( $field_key, $product_id, $ids = array() ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_grouped_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_grouped_popup_editor_btn_e( $field_key, $product_id, $ids = array() ) {
		self::render_html_e(
			WOOBE_PATH . 'views/elements/draw_grouped_popup_editor_btn.php',
			array(
				'field_key'  => $field_key,
				'product_id' => $product_id,
				'ids'        => $ids,
			)
		);
	}

	public static function draw_tooltip( $text, $direction = 'down' ) {
		?>
		<a class="info_helper zebra_tips1" title="<?php echo esc_html( $text ); ?>"><span class="icon-info"></span></a>
		<?php
	}

	public static function draw_restricked( $text = '', $direction = 'right' ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_restricked.php',
			array(
				'text'      => $text,
				'direction' => $direction,
			)
		);
	}

	public static function draw_image( $src, $alt = '', $class = '', $width = '' ) {
		return self::render_html(
			WOOBE_PATH . 'views/elements/draw_image.php',
			array(
				'src'   => $src,
				'alt'   => $alt,
				'class' => $class,
				'width' => $width,
			)
		);
	}

	public static function draw_checkbox( $attributes = array(), $is_checked = false ) {
		$ch = '<input type="checkbox" ';
		if ( ! empty( $attributes ) ) {
			foreach ( $attributes as $key => $value ) {
				$ch .= $key . '=' . '"' . $value . '" ';
			}
		}

		if ( $is_checked ) {
			$ch .= 'checked ';
		}

		$ch .= '/>';
		return $ch;
	}

	public static function strtolower( $string ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			$string = mb_strtolower( $string, 'UTF-8' );
		} else {
			$string = strtolower( $string );
		}

		return $string;
	}

	public static function array_to_string( $array ) {
		$string = '';
		foreach ( $array as $key => $value ) {
			$string .= $key . ':' . $value . ',';
		}
		return trim( $string, ',' );
	}

	public static function string_to_array( $string ) {
		$res = array();
		$tmp = explode( ',', $string );
		if ( substr_count( $string, ':' ) > 0 ) {
			// if indexes of array matter: 3:1,4:2,5:1 - index:value
			if ( ! empty( $tmp ) ) {
				$vv = array();
				foreach ( $tmp as $v ) {
					$v           = explode( ':', $v );
					$vv[ $v[0] ] = $v[1];
				}
				$res = $vv;
			}
		} else {
			// 1,2,5,7,12
			$res = $tmp;
		}

		return $res;
	}

	public static function get_taxonomies_terms_hierarchy( $taxonomy ) {

		$res = array();

		$object_terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$data = array();

		if ( ! empty( $object_terms ) ) {
			foreach ( $object_terms as $term ) {
				if ( is_object( $term ) ) {
					$data[ $term->parent ][] = array(
						'term_id'     => $term->term_id,
						'name'        => $term->name,
						'slug'        => $term->slug,
						'parent'      => $term->parent,
						'description' => $term->description,
						'childs'      => array(),
					);
				}
			}

			// ***

			$res = self::sort_taxonomies_by_parents( $data );
		}

		return $res;
	}

	private static function sort_taxonomies_by_parents( $data, $parent_id = 0 ) {
		if ( isset( $data[ $parent_id ] ) ) {
			if ( ! empty( $data[ $parent_id ] ) ) {
				foreach ( $data[ $parent_id ] as $key => $o ) {
					if ( isset( $data[ $o['term_id'] ] ) ) {
						$data[ $parent_id ][ $key ]['childs'] = self::sort_taxonomies_by_parents( $data, $o['term_id'] );
					}
				}

				return $data[ $parent_id ];
			}
		}

		return array();
	}

	public static function prepare_meta_keys( $key ) {
		// return sanitize_title(trim($key));
		return trim( $key );
	}

	public static function draw_rounding_drop_down() {
		?>
		<select class="woobe_num_rounding">
			<option value="0"><?php esc_html_e( 'no rounding', 'woo-bulk-editor' ); ?></option>
			<option value="100">00</option>
			<option value="5">5</option>
			<option value="10">10</option>
			<option value="9">9</option>
			<option value="19">19</option>
			<option value="29">29</option>
			<option value="39">39</option>
			<option value="49">49</option>
			<option value="59">59</option>
			<option value="69">69</option>
			<option value="79">79</option>
			<option value="89">89</option>
			<option value="99">99</option>
		</select>  
		<?php
	}

	public static function render_html( $pagepath, $data = array() ) {

		if ( is_array( $data ) and ! empty( $data ) ) {
			if ( isset( $data['pagepath'] ) ) {
				unset( $data['pagepath'] );
			}
			extract( $data );
		}

		// ***

		ob_start();
		include str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $pagepath );
		return ob_get_clean();
	}

	public static function render_html_e( $pagepath, $data = array() ) {

		if ( is_array( $data ) and ! empty( $data ) ) {
			if ( isset( $data['pagepath'] ) ) {
				unset( $data['pagepath'] );
			}
			extract( $data );
		}

		// ***
		include str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $pagepath );
	}

	public static function sanitize_bulk_key( $bulk_key ) {
		return strtolower( sanitize_text_field( $bulk_key ) );
	}

	public static function over_switcher_swicher_to_val( $val, $key ) {
		global $WOOBE;
		$switcher_values = $WOOBE->settings->override_switcher_fieds;
		// $switcher_values = 'dist:yes,stest:true';
		$sw_array = explode( ',', $switcher_values );
		foreach ( $sw_array as $rule ) {
			$rule_array = explode( ':', $rule );
			if ( count( $rule_array ) > 1 && $rule_array[0] == $key ) {
				$values_array = explode( '^', $rule_array[1] );
				if ( $val == 1 ) {
					return $values_array[0];
				} elseif ( count( $values_array ) > 1 && ! $val ) {
					return $values_array[1];
				}
			}
		}
		return $val;
	}

	public static function over_switcher_val_to_swicher( $val, $key ) {
		global $WOOBE;
		$switcher_values = $WOOBE->settings->override_switcher_fieds;
		// $switcher_values = 'dist:yes,stest:true';
		$sw_array = explode( ',', $switcher_values );
		foreach ( $sw_array as $rule ) {
			$rule_array = explode( ':', $rule );
			if ( count( $rule_array ) > 1 && $rule_array[0] == $key ) {
				$values_array = explode( '^', $rule_array[1] );
				if ( $values_array[0] == $val ) {
					return 1;
				} else {
					return '';
				}
			}
		}
		return $val;
	}

	public static function get_product_id_by_sku( $sku ) {
		global $wpdb;

		$query = "
			SELECT p.ID 
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sku'
			AND pm.meta_value = %s
			AND p.post_type IN ('product', 'product_variation')
			LIMIT 1
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $wpdb->prepare( $query, $sku ) );
	}

	/**
	 * Single authorization gate for the plugin ajax handlers.
	 *
	 * Checks, in this order: the nonce the calling form already provides, the
	 * capability, and - when product ids are given - current_user_can( 'edit_post', $id )
	 * for every one of them, which resolves to the edit_post meta capability and so
	 * honours post ownership for roles without edit_others_products.
	 *
	 * On failure it stops the request the same way the calling handler already did.
	 *
	 * @param array $args nonce_field, nonce_action, cap, product_ids, on_fail.
	 * @return void
	 */
	public static function check_ajax_access( $args = array() ) {

		$args = array_merge(
			array(
				'nonce_field'  => '',
				'nonce_action' => '',
				'cap'          => 'manage_woocommerce',
				'product_ids'  => null,
				'on_fail'      => 'die0',
			),
			$args
		);

		$granted = true;

		if ( ! empty( $args['nonce_field'] ) ) {
			if ( ! isset( $_REQUEST[ $args['nonce_field'] ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $args['nonce_field'] ] ) ), $args['nonce_action'] ) ) {
				$granted = false;
			}
		}

		if ( $granted && ! empty( $args['cap'] ) && ! current_user_can( $args['cap'] ) ) {
			$granted = false;
		}

		if ( $granted && $args['product_ids'] !== null ) {
			foreach ( (array) $args['product_ids'] as $product_id ) {
				$product_id = intval( $product_id );

				if ( $product_id <= 0 ) {
					continue;
				}

				// WooCommerce maps edit_post on a variation to the plain edit_products
				// capability, which ignores ownership, and a variation carries
				// post_author 0 anyway - so authorize against the parent product.
				if ( get_post_type( $product_id ) === 'product_variation' ) {
					$parent_id = wp_get_post_parent_id( $product_id );
					if ( $parent_id ) {
						$product_id = $parent_id;
					}
				}

				if ( ! current_user_can( 'edit_post', $product_id ) ) {
					$granted = false;
					break;
				}
			}
		}

		if ( $granted ) {
			return;
		}

		self::deny_ajax_access( $args['on_fail'] );
	}

	/**
	 * Reads a request value that the handler is about to walk as an array.
	 *
	 * A missing key yields an empty array, because jQuery drops a parameter whose
	 * value is an empty array and the handlers already treat that as "nothing to
	 * do". Anything present but of the wrong shape is rejected rather than cast,
	 * so a scalar can no longer reach a foreach() or an offset read.
	 *
	 * @param string $key             request key.
	 * @param bool   $rows_are_arrays every element must be an array as well.
	 * @param string $on_fail         die0, json or exit.
	 * @return array
	 */
	public static function get_request_array( $key, $rows_are_arrays = false, $on_fail = 'die0' ) {

		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return array();
		}

		if ( ! is_array( $_REQUEST[ $key ] ) ) {
			self::deny_ajax_access( $on_fail );
		}

		if ( $rows_are_arrays ) {
			foreach ( $_REQUEST[ $key ] as $row ) {
				if ( ! is_array( $row ) ) {
					self::deny_ajax_access( $on_fail );
				}
			}
		}

		return $_REQUEST[ $key ];
	}

	/**
	 * Stops a rejected ajax request, keeping the failure style of the calling handler.
	 *
	 * @param string $on_fail die0, json or exit.
	 * @return void
	 */
	private static function deny_ajax_access( $on_fail ) {

		switch ( $on_fail ) {
			case 'json':
				wp_send_json_error( 'Security check failed' );
				break;
			case 'exit':
				exit;
			default:
				die( '0' );
		}
	}
	
	// service
	public static function draw_child_filter_terms( $term_id, $terms_by_parents, $level ) {
		?>
		<?php if ( isset( $terms_by_parents[ $term_id ] ) and ! empty( $terms_by_parents[ $term_id ] ) ) : ?>
			<?php
			foreach ( $terms_by_parents[ $term_id ] as $tt ) :
				?>
				<option  value="<?php echo esc_attr( $tt->term_id ); ?>"><?php echo esc_html( $level ) . ' '; ?><?php echo esc_html( $tt->name ); ?></option>
				<?php WOOBE_HELPER::draw_child_filter_terms( $tt->term_id, $terms_by_parents, $level . '-' ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
}
