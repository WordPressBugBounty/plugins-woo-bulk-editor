<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

final class WOOBE_FILTERS extends WOOBE_EXT {

	protected $slug = 'filters'; // unique

	public function __construct() {
		add_action( 'woobe_ext_scripts', array( $this, 'woobe_ext_scripts' ), 1 );

		// ajax
		add_action( 'wp_ajax_woobe_filter_products', array( $this, 'woobe_filter_products' ), 1 );
		add_action( 'wp_ajax_woobe_reset_filter', array( $this, 'woobe_reset_filter' ), 1 );

		// hooks
		add_filter( 'woobe_print_plugin_options', array( $this, 'woobe_print_plugin_options' ), 1 );
		add_filter( 'woobe_apply_query_filter_data', array( $this, 'woobe_apply_query_filter_data' ) );

		// tabs
		$this->add_tab( $this->slug, 'top_panel', esc_html__( 'Filters', 'woo-bulk-editor' ), 'filter' );
		add_action( 'woobe_ext_top_panel_' . $this->slug, array( $this, 'woobe_ext_panel' ), 1 );

		add_action( 'woobe_tools_panel_buttons_end', array( $this, 'woobe_tools_panel_buttons_end' ), 20 );
	}

	public function woobe_ext_scripts() {
		wp_enqueue_script( 'woobe_ext_' . $this->slug, $this->get_ext_link() . 'assets/js/' . $this->slug . '.js', array(), WOOBE_VERSION, false );
		wp_enqueue_style( 'woobe_ext_' . $this->slug, $this->get_ext_link() . 'assets/css/' . $this->slug . '.css', array(), WOOBE_VERSION );
		?>
		<script>
			lang.<?php echo esc_attr( $this->slug ); ?> = {};
			lang.<?php echo esc_attr( $this->slug ); ?>.filtering = "<?php echo esc_html__( 'Filtering', 'woo-bulk-editor' ); ?> ...";
			lang.<?php echo esc_attr( $this->slug ); ?>.filtered = "<?php echo esc_html__( 'Filtered! Table redrawing ...', 'woo-bulk-editor' ); ?>";
		</script>
		<?php
	}

	public function woobe_ext_panel() {
		$data = array();
		WOOBE_HELPER::render_html_e( $this->get_ext_path() . 'views/panel.php', $data );
	}

	// ajax
	public function woobe_filter_products() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'mainform_nonce',
				'nonce_action' => 'woobe_mainform_nonce',
			)
		);

		$filter_data = array();
		parse_str( $_REQUEST['filter_data'], $filter_data );

		$this->apply_filter_data(
			$filter_data['woobe_filter'],
			sanitize_text_field( $_REQUEST['filter_current_key'] )
		);

		die( 'done' );
	}

	public function apply_filter_data( $filter_data, $filter_key ) {
		$this->storage->set_val( 'woobe_filter_' . $filter_key, $filter_data );
	}

	// ajax
	public function woobe_reset_filter() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'mainform_nonce',
				'nonce_action' => 'woobe_mainform_nonce',
			)
		);

		$this->reset_filter_storage_data( $_REQUEST['filter_current_key'] );
		die( 'done' );
	}

	public function get_product_visibility_query( $type ) {
		switch ( $type ) {
			case 'visible':
				return array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
					'operator' => 'NOT IN',
				);
				break;
			case 'shop_only':
				return array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'slug',
						'terms'    => array( 'exclude-from-search' ),
						'operator' => 'IN',
					),
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'slug',
						'terms'    => array( 'exclude-from-catalog' ),
						'operator' => 'NOT IN',
					),
				);
				break;
			case 'search_only':
				return array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'slug',
						'terms'    => array( 'exclude-from-search' ),
						'operator' => 'NOT IN',
					),
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'slug',
						'terms'    => array( 'exclude-from-catalog' ),
						'operator' => 'IN',
					),
				);
				break;
			case 'hidden':
				return array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
					'operator' => 'AND',
				);
				break;
			default:
				return array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
					'operator' => 'NOT IN',
				);
		}
	}

	// hook
	public function woobe_print_plugin_options( $args ) {
		// reset all filters
		// $this->reset_filter_storage_data();//system changed for using $filter_current_key and we not need it here
		return $args;
	}

	public function reset_filter_storage_data( $filter_current_key ) {
		// $this->storage->unset_val('woobe_filter_' . get_current_user_id());
		$this->storage->unset_val( 'woobe_filter_' . $filter_current_key );
	}

	public function posts_txt_where( $where = '' ) {

		$txt_where_sql = '';

		if ( ! empty( $_REQUEST['woobe_txt_search'] ) ) {
			foreach ( $_REQUEST['woobe_txt_search'] as $skey => $svalue ) {

				if ( empty( $svalue ) and $svalue !== '0' ) {
					continue;
				}

				$behavior   = sanitize_text_field( $_REQUEST['woobe_txt_search_behavior'][ $skey ] );
				$woobe_text = sanitize_text_field( wp_specialchars_decode( trim( urldecode( $svalue ) ) ) );

				// ***

				if ( empty( $woobe_text ) and $woobe_text !== '0' ) {
					return $where;
				}

				// ***

				$woobe_text = trim( WOOBE_HELPER::strtolower( $woobe_text ) );
				$woobe_text = preg_replace( '/\s+/', ' ', $woobe_text );
				// $woobe_text = preg_quote($woobe_text, '&');
				// $woobe_text = str_replace(' ', '?(.*)', $woobe_text);
				$woobe_text = str_replace( '\&#039;', "\'", $woobe_text );

				// http://dev.mysql.com/doc/refman/5.7/en/regexp.html
				// ***

				$search_by_full_word = false; // OPTION!!

				$woobe_text = str_replace( '(', '\\\(', $woobe_text );
				$woobe_text = str_replace( ')', '\\\)', $woobe_text );
				$woobe_text = str_replace( '[', '\\\[', $woobe_text );
				$woobe_text = str_replace( ']', '\\\]', $woobe_text );
				$woobe_text = str_replace( '}', '\\\}', $woobe_text );
				$woobe_text = str_replace( '{', '\\\{', $woobe_text );

				if ( $search_by_full_word ) {
					$woobe_text = '[[:<:]]' . $woobe_text . '[[:>:]]';
				}

				// ***

				switch ( $behavior ) {
					case 'exact':
						$text_where = "  LOWER({$skey}) = '__WOOBE_TEXT__'";
						break;

					case 'not':
						$text_where = "  LOWER({$skey}) NOT REGEXP '__WOOBE_TEXT__'";
						break;

					case 'begin':
						$text_where = "  LOWER({$skey}) REGEXP '^__WOOBE_TEXT__'";
						break;

					case 'end':
						$text_where = "  LOWER({$skey}) REGEXP '__WOOBE_TEXT__$'";
						break;
					case 'empty':
						$text_where = "  LOWER({$skey}) =''";
						break;

					default:
						// like
						$text_where = "  LOWER({$skey}) REGEXP '__WOOBE_TEXT__'";
						break;
				}

				// ***

				if ( substr_count( $woobe_text, '^' ) > 0 ) {
					$woobe_text = explode( '^', $woobe_text );
					$sql_tpl    = '(';

					// ***
					$not_been_not = 0; // for operation (!) at the end
					foreach ( $woobe_text as $st ) {
						$sql  = $text_where;
						$cond = 'OR';
						if ( substr( $st, 0, 1 ) == '~' ) {
							$st   = substr( $st, 1 );
							$cond = 'AND';
						}
						if ( $behavior == 'like' ) {
							if ( substr( $st, 0, 1 ) == '!' ) {
								$st  = substr( $st, 1 );
								$sql = str_replace( 'REGEXP', 'NOT REGEXP', $sql );

								if ( ! $not_been_not ) {
									$cond = ') AND (';
								} else {
									$cond = 'AND';
								}
								$not_been_not += 1;
							}
						}

						// ***

						$tmp      = $cond . ' ' . ( str_replace( '__WOOBE_TEXT__', $st, $sql ) ) . ' ';
						$sql_tpl .= $tmp;
					}

					// ***

					$sql_tpl = str_replace( '(OR', '', $sql_tpl );
					$sql_tpl = trim( $sql_tpl, ' OR' );
					$sql_tpl = trim( $sql_tpl, ' AND' );

					$text_where = $sql_tpl;
				} else {
					$sql        = $text_where;
					$st         = $woobe_text;
					$text_where = str_replace( '__WOOBE_TEXT__', $st, $sql );
				}

				// ***

				$txt_where_sql .= ( $text_where . ' AND ' );
			}

			// ***

			$txt_where_sql = trim( $txt_where_sql, ' AND' );

			if ( ! empty( $txt_where_sql ) ) {
				$where .= ' AND ( ' . $txt_where_sql . ' ) ';
			}
		}

		// ***
		// echo $where;
		return $where;
	}

	public function posts_sku_where( $where = '' ) {
		global $wpdb;
		$sku_where = '';

		$woobe_sku_request = explode( ',', $_REQUEST['woobe_sku_search'] );
		$woobe_sku_request = array_map( 'urldecode', $woobe_sku_request );
		$woobe_sku_request = array_map( 'trim', $woobe_sku_request );
		$woobe_sku_request = array_map( 'sanitize_text_field', $woobe_sku_request );

		// ***

		$sku_logic = sanitize_text_field( $_REQUEST['woobe_sku_search_behavior'] );

		if ( $sku_logic != 'empty' ) {
			$condtion_string = '';
			if ( ! empty( $woobe_sku_request ) or $sku_logic == 'empty' ) {
				foreach ( $woobe_sku_request as $k => $sku ) {
					switch ( $sku_logic ) {
						case 'exact':
							$condtion_string .= $wpdb->prepare( 'postmeta.meta_value = %s', $sku );
							$condtion_string .= ' OR ';
							break;
						case 'not':
							$condtion_string .= $wpdb->prepare( 'postmeta.meta_value NOT LIKE %s', '%' . $wpdb->esc_like( $sku ) . '%' );
							$condtion_string .= ' AND ';
							break;
						case 'begin':
							$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', $wpdb->esc_like( $sku ) . '%' );
							$condtion_string .= ' OR ';
							break;
						case 'end':
							$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', '%' . $wpdb->esc_like( $sku ) );
							$condtion_string .= ' OR ';
							break;
						default:
							$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', '%' . $wpdb->esc_like( $sku ) . '%' );
							$condtion_string .= ' OR ';
							break;
					}
				}
			}

			$condtion_string = trim( $condtion_string, 'OR ' );
			$condtion_string = trim( $condtion_string, 'AND ' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
                        SELECT posts.ID
                        FROM $wpdb->posts AS posts
                        LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
                        WHERE posts.post_type IN ('product','product_variation')
                        AND postmeta.meta_key = %s
                        AND ($condtion_string)",
					'_sku'
				),
				ARRAY_N
			);

			// +++
			$product_variations_ids = array();
		} else {
			$args = array(
				'post_type'      => array( 'product', 'product_variation' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_sku',
						'value'   => '',
						'compare' => '=',
					),
					array(
						'key'     => '_sku',
						'compare' => 'NOT EXISTS',
					),
				),
			);

			$product_variations_ids = get_posts( $args );
		}

		if ( ! empty( $product_variations ) or ! empty( $product_variations_ids ) ) {
			if ( empty( $product_variations_ids ) ) {
				foreach ( $product_variations as $v ) {
					$product_variations_ids[] = intval( $v[0] );
				}
			}

			// +++
			$product_variations_ids_string = implode( ',', array_map( 'intval', $product_variations_ids ) );
			$products                      = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT posts.post_parent
				FROM $wpdb->posts AS posts
				WHERE posts.ID IN ($product_variations_ids_string)
				AND posts.post_parent > 0",
				ARRAY_N
			);

			// +++
			$product_ids = array();
			if ( ! empty( $products ) ) {
				foreach ( $products as $v ) {
					$product_ids[] = intval( $v[0] );
				}
			}
			$product_ids = implode( ',', array_merge( $product_ids, $product_variations_ids ) );

			$sku_where .= " $wpdb->posts.ID IN($product_ids)";
			$where_sku  = " AND $wpdb->posts.ID IN($product_ids)";
		} else {
			$sku_where .= " $wpdb->posts.ID IN(-1)";
		}

		// ***

		if ( ! empty( $sku_where ) ) {
			$where .= ' AND ( ' . $sku_where . ' )';
		}

		// ***

		return $where;
	}

	public function posts_product_url_where( $where = '' ) {
		global $wpdb;
		$product_url_where = '';

		$woobe_product_url_request = sanitize_text_field( trim( urldecode( $_REQUEST['woobe_product_url_search'] ) ) );
		$product_url_logic         = sanitize_text_field( $_REQUEST['woobe_product_url_search_behavior'] );

		$condtion_string = '';
		if ( ! empty( $woobe_product_url_request ) ) {

			switch ( $product_url_logic ) {
				case 'exact':
					$condtion_string .= $wpdb->prepare( 'postmeta.meta_value = %s', $woobe_product_url_request );
					break;
				case 'not':
					$condtion_string .= $wpdb->prepare( 'postmeta.meta_value NOT LIKE %s', '%' . $wpdb->esc_like( $woobe_product_url_request ) . '%' );
					break;
				case 'begin':
					$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', $wpdb->esc_like( $woobe_product_url_request ) . '%' );
					break;
				case 'end':
					$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', '%' . $wpdb->esc_like( $woobe_product_url_request ) );
					break;
				default:
					$condtion_string .= $wpdb->prepare( 'postmeta.meta_value LIKE %s', '%' . $wpdb->esc_like( $woobe_product_url_request ) . '%' );
					break;
			}
		}
		// ***
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$product_variations = $wpdb->get_results(
			"
                    SELECT posts.ID
                    FROM $wpdb->posts AS posts
                    LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
                    WHERE posts.post_type IN ('product')
                    AND postmeta.meta_key = '_product_url'
                    AND ($condtion_string)",
			ARRAY_N
		);
		// +++

		$product_variations_ids = array();
		if ( ! empty( $product_variations ) ) {

			foreach ( $product_variations as $v ) {
				$product_variations_ids[] = $v[0];
			}
			$product_ids = implode( ',', array_map( 'intval', $product_variations_ids ) );
			$product_url_where .= " $wpdb->posts.ID IN($product_ids)";
			$where_product_url  = " AND $wpdb->posts.ID IN($product_ids)";
		}

		// ***
		if ( ! empty( $product_url_where ) ) {
			$where .= ' AND ( ' . $product_url_where . ' )';
		}

		// ***
		// echo $where;
		return $where;
	}

	public function stock_quantity_where( $where = '' ) {
		global $wpdb;

		if ( ( empty( $_REQUEST['stock_quantity_from'] ) && $_REQUEST['stock_quantity_from'] == null ) && ( empty( $_REQUEST['stock_quantity_to'] ) && $_REQUEST['stock_quantity_to'] == null ) ) {
			return $where;
		}

		$woobe_stock_quantity_from = intval( $_REQUEST['stock_quantity_from'] );
		$woobe_stock_quantity_to   = intval( $_REQUEST['stock_quantity_to'] );

		if ( $woobe_stock_quantity_from === $woobe_stock_quantity_to ) {
			
			$addtn_query = '';
			if ( $woobe_stock_quantity_from == 0 ) {
				$addtn_query = ' OR postmeta.meta_value = null';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
						SELECT posts.ID
						FROM $wpdb->posts AS posts
						LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
						WHERE posts.post_type IN ('product','product_variation')
						AND postmeta.meta_key = '_stock'
						AND postmeta.meta_value = %d",
					$woobe_stock_quantity_from
				) . $addtn_query,
				ARRAY_N
			);
		} else {

			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
						SELECT posts.ID
						FROM $wpdb->posts AS posts
						LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
						WHERE posts.post_type IN ('product','product_variation')
						AND postmeta.meta_key = '_stock'
						AND postmeta.meta_value >= %d 
						AND postmeta.meta_value < %d",
					$woobe_stock_quantity_from,
					$woobe_stock_quantity_to
				),
				ARRAY_N
			);
		}

		$product_variations_ids = array();
		if ( ! empty( $product_variations ) ) {
			foreach ( $product_variations as $v ) {
				$product_variations_ids[] = $v[0];
			}

			// +++
			$product_variations_ids_string = implode( ',', array_map( 'intval', $product_variations_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$products = $wpdb->get_results(
				"SELECT posts.post_parent
				FROM $wpdb->posts AS posts
				WHERE posts.ID IN ($product_variations_ids_string)
				AND posts.post_parent > 0",
				ARRAY_N
			);

			// +++
			$product_ids = array();
			if ( ! empty( $products ) ) {
				foreach ( $products as $v ) {
					$product_ids[] = $v[0];
				}
			}
			$stock_quantity_where = '';
			$product_ids          = implode( ',', array_merge( $product_ids, $product_variations_ids ) );
			if ( ! empty( $product_ids ) ) {
				$stock_quantity_where = " AND ( $wpdb->posts.ID IN($product_ids) )";
			}

			$where .= $stock_quantity_where;
		}
		// echo $where;
		return $where;
	}

	public function sale_price_where( $where = '' ) {

		if ( ( empty( $_REQUEST['sale_price_from'] ) && $_REQUEST['sale_price_from'] == null ) && ( empty( $_REQUEST['sale_price_to'] ) && $_REQUEST['sale_price_to'] == null ) ) {
			return $where;
		}

		global $wpdb;
		$woobe_sale_from = floatval( $_REQUEST['sale_price_from'] );
		$woobe_sale_to   = floatval( $_REQUEST['sale_price_to'] );

		if ( $woobe_sale_from > 0 && $woobe_sale_to === 0 ) {
			// only "from" set — find products >= from
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
						SELECT posts.ID
						FROM $wpdb->posts AS posts
						LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
						WHERE posts.post_type IN ('product','product_variation')
						AND postmeta.meta_key = '_sale_price'
						AND postmeta.meta_value != ''
						AND CAST(postmeta.meta_value AS DECIMAL(30,10)) >= %f",
					$woobe_sale_from
				),
				ARRAY_N
			);
		} elseif ( $woobe_sale_from === $woobe_sale_to ) {

			$addtn_query = '';
			if ( $woobe_sale_from === 0 ) {
				$addtn_query = " OR postmeta.meta_value = ''";
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $addtn_query is a hardcoded literal assigned above and never holds request data.
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT posts.ID
					FROM $wpdb->posts AS posts
					LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
					WHERE posts.post_type IN ('product','product_variation')
					AND postmeta.meta_key = '_sale_price'
					AND ( postmeta.meta_value = %f $addtn_query )",
					$woobe_sale_from
				),
				ARRAY_N
			);
		} else {
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT posts.ID
					FROM $wpdb->posts AS posts
					LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
					WHERE posts.post_type IN ('product','product_variation')
					AND postmeta.meta_key = '_sale_price'
					AND postmeta.meta_value != ''
					AND CAST(postmeta.meta_value AS DECIMAL(30,10)) >= %f
					AND CAST(postmeta.meta_value AS DECIMAL(30,10)) < %f",
					$woobe_sale_from,
					$woobe_sale_to
				),
				ARRAY_N
			);
		}

		// +++
		$product_variations_ids = array();
		if ( ! empty( $product_variations ) ) {
			foreach ( $product_variations as $v ) {
				$product_variations_ids[] = $v[0];
			}

			// +++
			$product_variations_ids_string = implode( ',', array_map( 'intval', $product_variations_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$products = $wpdb->get_results(
				"SELECT posts.post_parent
				FROM $wpdb->posts AS posts
				WHERE posts.ID IN ($product_variations_ids_string)
				AND posts.post_parent > 0",
				ARRAY_N
			);

			// +++
			$product_ids = array();
			if ( ! empty( $products ) ) {
				foreach ( $products as $v ) {
					$product_ids[] = $v[0];
				}
			}
			$sale_where  = '';
			$product_ids = implode( ',', array_merge( $product_ids, $product_variations_ids ) );
			if ( ! empty( $product_ids ) ) {
				$sale_where .= " AND ( $wpdb->posts.ID IN($product_ids) )";
			}

			$where .= $sale_where;
		}else {
			$where .= " AND {$wpdb->posts}.ID = -1";
		}

		return $where;
	}

	public function regular_price_where( $where = '' ) {
		global $wpdb;

		if (
			$_REQUEST['regular_price_from'] === '' &&
			$_REQUEST['regular_price_to'] === ''
		) {
			return $where;
		}

		$from = floatval($_REQUEST['regular_price_from']);
		$to   = floatval($_REQUEST['regular_price_to']);

		if ( $to <= 0 ) {
			$to = 999999999;
		}

		$product_variations = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT posts.ID
				FROM $wpdb->posts AS posts
				INNER JOIN $wpdb->postmeta AS postmeta
					ON posts.ID = postmeta.post_id
				WHERE posts.post_type IN ('product','product_variation')
				AND postmeta.meta_key = '_regular_price'
				AND postmeta.meta_value != ''
				AND CAST(postmeta.meta_value AS DECIMAL(30,10)) >= %f
				AND CAST(postmeta.meta_value AS DECIMAL(30,10)) < %f
				",
				$from,
				$to
			),
			ARRAY_N
		);

		$product_variation_ids = [];

		if ( ! empty( $product_variations ) ) {
			foreach ( $product_variations as $v ) {
				$product_variation_ids[] = (int) $v[0];
			}
		}

		$product_ids = [];

		if ( ! empty( $product_variation_ids ) ) {

			$ids_string = implode(',', array_map('intval', $product_variation_ids));

			$products = $wpdb->get_results(
				"
				SELECT posts.post_parent
				FROM $wpdb->posts AS posts
				WHERE posts.ID IN ($ids_string)
				AND posts.post_parent > 0
				",
				ARRAY_N
			);

			if ( ! empty( $products ) ) {
				foreach ( $products as $v ) {
					$product_ids[] = (int) $v[0];
				}
			}

			$product_ids = array_unique(
				array_merge( $product_ids, $product_variation_ids )
			);

			$product_ids = implode(',', array_map('intval', $product_ids));

			$where .= " AND {$wpdb->posts}.ID IN ($product_ids)";
		} else {
			$where .= " AND {$wpdb->posts}.ID = -1";
		}

		return $where;
	}

	public function posts_post_author_where( $where = '' ) {

		if ( isset( $_REQUEST['woobe_post_author_search'] ) and ! empty( $_REQUEST['woobe_post_author_search'] ) ) {
			$post_author = intval( $_REQUEST['woobe_post_author_search'] );
			if ( ! empty( $post_author ) ) {
				$where .= sprintf( 'AND ( post_author=%s )', $post_author );
			}
		}
		return $where;
	}

	// https://gist.github.com/marteinn/1069123
	public function woobe_post_date_from_to( $where = '' ) {
		global $wpdb;

		$woobe_post_date_from = sanitize_text_field( $_REQUEST['woobe_post_date_from'] );
		$woobe_post_date_to   = sanitize_text_field( $_REQUEST['woobe_post_date_to'] );

		if ( $_REQUEST['woobe_post_date_from'] ) {
			$where .= $wpdb->prepare( ' AND post_date >= %s', $woobe_post_date_from );
		}

		if ( $_REQUEST['woobe_post_date_to'] ) {
			$where .= $wpdb->prepare( ' AND post_date <= %s', $woobe_post_date_to );
		}

		return $where;
	}

	public function woobe_menu_order_to( $where = '' ) {
		global $wpdb;

		if ( $_REQUEST['woobe_menu_order_from'] ) {
			$woobe_menu_order_from = intval( $_REQUEST['woobe_menu_order_from'] );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$where                .= $wpdb->prepare( ' AND menu_order >= %d', $woobe_menu_order_from );
		}

		if ( $_REQUEST['woobe_menu_order_to'] ) {
			$woobe_menu_order_to = intval( $_REQUEST['woobe_menu_order_to'] );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$where              .= $wpdb->prepare( ' AND menu_order <= %d', $woobe_menu_order_to );
		}

		return $where;
	}

	public function dimensions_where( $key, $from, $to ) {
		// weight length width height
		global $wpdb;
		if ( ( empty( $from ) && $from == null ) && ( empty( $to ) && $to == null ) ) {
			return '';
		}

		if ( $from == $to ) {

			$addtn_query = '';
			if ( $from == 0 ) {
				$addtn_query = ' OR postmeta.meta_value = null';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $addtn_query is a hardcoded literal assigned above and never holds request data.
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT posts.ID
					FROM $wpdb->posts AS posts
					LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
					WHERE posts.post_type IN ('product','product_variation')
					AND postmeta.meta_key = %s
					AND postmeta.meta_value = %f",
					sanitize_key( $key ),
					$from
				) . $addtn_query,
				ARRAY_N
			);
		} else {
			$product_variations = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT posts.ID
					FROM $wpdb->posts AS posts
					LEFT JOIN $wpdb->postmeta AS postmeta ON ( posts.ID = postmeta.post_id )
					WHERE posts.post_type IN ('product','product_variation')
					AND postmeta.meta_key = %s
					AND postmeta.meta_value >= %f
					AND postmeta.meta_value < %f",
					sanitize_key( $key ),
					$from,
					$to
				),
				ARRAY_N
			);
		}

		$where                  = " AND ( $wpdb->posts.ID IN(-1) )";
		$product_variations_ids = array();
		if ( ! empty( $product_variations ) ) {
			foreach ( $product_variations as $v ) {
				$product_variations_ids[] = $v[0];
			}

			// +++
			$product_variations_ids_string = implode( ',', array_map( 'intval', $product_variations_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$products = $wpdb->get_results(
				"SELECT posts.post_parent
				FROM $wpdb->posts AS posts
				WHERE posts.ID IN ($product_variations_ids_string)
				AND posts.post_parent > 0",
				ARRAY_N
			);

			// +++

			$product_ids = array();
			if ( ! empty( $products ) ) {
				foreach ( $products as $v ) {
					$product_ids[] = $v[0];
				}
			}
			$stock_quantity_where = '';
			$product_ids          = implode( ',', array_merge( $product_ids, $product_variations_ids ) );

			if ( ! empty( $product_ids ) ) {
				$where = " AND ( $wpdb->posts.ID IN($product_ids) )";
			}
		}
		return $where;
	}

	// hook
	public function woobe_apply_query_filter_data( $args ) {

		$woobe_filter = array();

		if ( isset( $_REQUEST['filter_current_key'] ) and ! empty( $_REQUEST['filter_current_key'] ) ) {
			$woobe_filter = $this->storage->get_val( 'woobe_filter_' . sanitize_text_field( $_REQUEST['filter_current_key'] ) );
		}

		$fields = $this->settings->get_fields( false );

		$tax_query  = array();
		$meta_query = array();
		// var_dump($woobe_filter);
		if ( ! empty( $woobe_filter ) ) {

			if ( isset( $woobe_filter['taxonomies'] ) and ! empty( $woobe_filter['taxonomies'] ) ) {

				foreach ( $woobe_filter['taxonomies'] as $tax_key => $terms_ids ) {
					$operator = $woobe_filter['taxonomies_operators'][ $tax_key ];
					$children = apply_filters( 'woobe_filter_include_children', false, $tax_key );
					if ( $operator === 'AND' ) {
						// https://wordpress.stackexchange.com/questions/236902/wordpress-tax-query-and-operator-not-functioning-as-desired
						// when to set operatot to AND - no results
						foreach ( $terms_ids as $tid ) {
							$tax_query[] = array(
								'taxonomy'         => $tax_key,
								'field'            => 'term_id',
								'terms'            => $tid,
								'include_children' => $children,
							);
						}
					} else {
						$q = array(
							'taxonomy'         => $tax_key,
							'field'            => 'term_id', // term_id, slug
							'terms'            => $terms_ids,
							'include_children' => $children,
						);

						// if ($woobe_filter['taxonomies_operators'][$tax_key] != 'OR') {
						$q['operator'] = $woobe_filter['taxonomies_operators'][ $tax_key ]; // OR, NOT IN
						// }

						$tax_query[] = $q;
					}
				}
			}

			if ( isset( $woobe_filter['taxonomies_operators'] ) and is_array( $woobe_filter['taxonomies_operators'] ) ) {
				foreach ( $woobe_filter['taxonomies_operators'] as $key_tax => $operator ) {
					if ( 'NOT EXISTS' == $operator or 'EXISTS' == $operator ) {
						$tax_query[] = array(
							'taxonomy' => $key_tax,
							'operator' => $operator,
						);
					}
				}
			}

			// ***
			// meta keys by which allowed filtering
			$number_keys = array();
			$string_keys = array();

			foreach ( $fields as $k => $f ) {
				if ( isset( $woobe_filter[ $k ] ) and ! empty( $woobe_filter[ $k ] ) and isset( $f['meta_key'] ) ) {
					if ( in_array( $f['type'], array( 'number', 'timestamp', 'unix' ) ) ) {
						$number_keys[] = $k;
					} else {
						$string_keys[] = $k;
					}
				}
			}

			// ***

			if ( ! empty( $number_keys ) ) {
				foreach ( $number_keys as $key ) {

					if ( in_array( $key, array( 'regular_price', 'sale_price', 'stock_quantity' ) ) ) {
						continue;
					}

					if ( in_array( $key, array( 'weight', 'length', 'width', 'height' ) ) && isset( $woobe_filter[ $key ] ) && apply_filters( 'woobe_filter_consider_variation_dimensions', true ) ) {
						$m_key = $fields[ $key ]['meta_key'];

						$new_where = $this->dimensions_where( $m_key, floatval( str_replace( ',', '.', $woobe_filter[ $key ]['from'] ) ), floatval( str_replace( ',', '.', $woobe_filter[ $key ]['to'] ) ) );

						if ( ! $new_where ) {
							continue;
						}

						add_filter(
							'posts_where',
							function ( $where = '' ) use ( $new_where ) {

								$where .= $new_where;

								return $where;
							},
							531
						);

						continue;
					}
					if ( isset( $woobe_filter[ $key ] ) ) {

						$meta_key = $fields[ $key ]['meta_key'];

						if ( isset( $woobe_filter[ $key ] ) and ! empty( $woobe_filter[ $key ] ) and is_array( $woobe_filter[ $key ] ) ) {
							if ( ( empty( $woobe_filter[ $key ]['from'] ) && $woobe_filter[ $key ]['from'] == null ) && ( empty( $woobe_filter[ $key ]['to'] ) && $woobe_filter[ $key ]['from'] == null ) ) {
								continue;
							}
							if ( in_array( $fields[ $key ]['type'], array( 'number', 'unix' ) ) ) {
								$from = floatval( str_replace( ',', '.', $woobe_filter[ $key ]['from'] ) );
								$to   = floatval( str_replace( ',', '.', $woobe_filter[ $key ]['to'] ) );

								// ***

								if ( $from == 0 and $to == 0 ) {
									// continue; //nothing to select
								}

								// ***

								if ( $from < $to ) {
									// https://dev.mysql.com/doc/refman/5.7/en/precision-math-decimal-characteristics.html
									$meta_query[] = array(
										'key'     => $meta_key,
										'value'   => array( $from, $to ),
										// if to simply set DECIMAL without range - wrong range of data will be found, for example if to search from 100 to 150 will be found range 99.xx - 150!
										'type'    => 'DECIMAL(30,20)',
										'compare' => 'BETWEEN',
									);
								} elseif ( $from > $to ) {
									$meta_query[] = array(
										'key'     => $meta_key,
										'value'   => $from,
										'type'    => 'DECIMAL(30,20)',
										'compare' => '>=',
									);
								} else {
									// $from == $to
									if ( $from == 0 ) {
										$meta_query[] = array(
											'relation' => 'OR',
											array(
												'key'     => $meta_key,
												'value'   => 0,
												'compare' => '=',
											),
											array(
												'key'     => $meta_key,
												'compare' => 'NOT EXISTS',
											),
										);
									} else {
										$meta_query[] = array(
											'key'     => $meta_key,
											'value'   => $from,
											'type'    => 'DECIMAL(30,20)',
											'compare' => '=',
										);
									}
								}
							}
						}

						// +++

						if ( in_array( $fields[ $key ]['type'], array( 'timestamp' ) ) ) {
							// timestamp - Sale price from & Sale price to

							static $calendar_filter_inited = false; // flag

							if ( ! $calendar_filter_inited ) {

								if ( isset( $woobe_filter['date_on_sale_from'] ) ) {
									$date_on_sale_from = intval( strtotime( $woobe_filter['date_on_sale_from'] ) );
								} else {
									$date_on_sale_from = 0;
								}

								if ( isset( $woobe_filter['date_on_sale_to'] ) ) {
									$date_on_sale_to = intval( strtotime( $woobe_filter['date_on_sale_to'] ) );
								} else {
									$date_on_sale_to = 0;
								}

								// ***

								if ( intval( $date_on_sale_from ) === 0 and intval( $date_on_sale_to ) === 0 ) {
									continue; // nothing to select
								}

								if ( $date_on_sale_from > 0 and $date_on_sale_to === 0 ) {
									$meta_query[] = array(
										'key'     => $fields['date_on_sale_from']['meta_key'],
										'value'   => $date_on_sale_from,
										'type'    => 'NUMERIC',
										'compare' => '>=',
									);
								} elseif ( $date_on_sale_from === 0 and $date_on_sale_to > 0 ) {
									$meta_query[] = array(
										'key'     => $fields['date_on_sale_to']['meta_key'],
										'value'   => $date_on_sale_to, /* + 3600 * 24 */
										'type'    => 'NUMERIC',
										'compare' => '<=',
									);
									$meta_query[] = array(
										'key'     => $fields['date_on_sale_to']['meta_key'],
										'value'   => 0,
										'type'    => 'NUMERIC',
										'compare' => '>',
									);
								} else {
									// $date_on_sale_from > 0 AND $date_on_sale_to > 0

									$meta_query[] = array(
										'key'     => $fields['date_on_sale_from']['meta_key'],
										'value'   => array( $date_on_sale_from, $date_on_sale_to ),
										'type'    => 'NUMERIC',
										'compare' => 'BETWEEN',
									);

									$meta_query[] = array(
										'key'     => $fields['date_on_sale_to']['meta_key'],
										'value'   => array( $date_on_sale_from, $date_on_sale_to ),
										'type'    => 'NUMERIC',
										'compare' => 'BETWEEN',
									);
								}

								$calendar_filter_inited = true;
							}
						}
					}
				}
			}

			// ***
			// for string meta keys
			if ( ! empty( $string_keys ) ) {
				foreach ( $string_keys as $string_key ) {

					if ( $string_key === 'sku' || $string_key === '_tax_class' ) {
						// sku has its own filter-hook below
						continue;
					}

					// ***
					$is = true;
					if ( ! is_array( $woobe_filter[ $string_key ] ) ) {
						// $is = false;
					}
					if ( isset( $woobe_filter[ $string_key ]['value'] ) ) {
						if ( intval( $woobe_filter[ $string_key ]['value'] ) === -1 or strlen( $woobe_filter[ $string_key ]['value'] ) === 0 ) {
							$is = false;
						}
					} else {
						if ( is_string( $woobe_filter[ $string_key ] ) ) {
							$no = strlen( $woobe_filter[ $string_key ] ) === 0;
						} else {
							$no = empty( $woobe_filter[ $string_key ] );
						}

						if ( intval( $woobe_filter[ $string_key ] ) === -1 or $no ) {
							$is = false;
						}
					}

					if ( isset( $woobe_filter[ $string_key ]['behavior'] ) && ( $woobe_filter[ $string_key ]['behavior'] == 'empty' || $woobe_filter[ $string_key ]['behavior'] == 'not_empty' ) ) {
						$is = true;
					}
					/*
						if (is_numeric($woobe_filter[$string_key])) {
						if (intval($woobe_filter[$string_key]) > 0) {
						$is = true;
						}
						} else {
						if (!empty($woobe_filter[$string_key]['value'])) {
						$is = true;
						}
						}
					 */
					// +++

					if ( $is ) {
						if ( $woobe_filter[ $string_key ] === 'zero' ) {
							// fix for metafields of type switcher added in WOOBE extension
							$meta_query[] = array(
								'key'     => $fields[ $string_key ]['meta_key'],
								'value'   => 0,
								'type'    => 'DECIMAL',
								'compare' => isset( $woobe_filter[ $string_key ]['behavior'] ) ? $woobe_filter[ $string_key ]['behavior'] : '=',
							);
						} elseif ( isset( $woobe_filter[ $string_key ]['behavior'] ) and $woobe_filter[ $string_key ]['behavior'] == 'empty' ) {
							$meta_query[] = array(
								'relation' => 'OR',
								array(
									'key'     => $fields[ $string_key ]['meta_key'],
									'value'   => '',
									'compare' => '=',
								),
								array(
									'key'     => $fields[ $string_key ]['meta_key'],
									'compare' => 'NOT EXISTS',
								),
							);
						} elseif ( isset( $woobe_filter[ $string_key ]['behavior'] ) and $woobe_filter[ $string_key ]['behavior'] == 'not_empty' ) {
							$meta_query[] = array(
								'key'     => $fields[ $string_key ]['meta_key'],
								'value'   => '',
								'compare' => '!=',
							);
						} else {
							$meta_query[] = array(
								'key'     => $fields[ $string_key ]['meta_key'],
								'value'   => isset( $woobe_filter[ $string_key ]['value'] ) ? $woobe_filter[ $string_key ]['value'] : $woobe_filter[ $string_key ],
								'type'    => 'CHAR',
								'compare' => isset( $woobe_filter[ $string_key ]['behavior'] ) ? $woobe_filter[ $string_key ]['behavior'] : '=',
							);
						}

						// ***
						// fix to exclude products where stock_status not possible
						if ( $string_key === 'stock_status' ) {
							$tax_query[] = array(
								'taxonomy' => 'product_type',
								'field'    => 'slug', // id, slug
								'terms'    => $fields['stock_status']['allow_product_types'],
							);
						}
					}
				}
			}
		}

		// ***
		/*
			$order_by = $this->storage->get_val('woobe_products_order_by');

			if (!empty($order_by)) {
			$args['orderby'] = $order_by;
			}
		 */
		// ***
		/*
			$order = $this->storage->get_val('woobe_products_order');

			if (!empty($order)) {
			$args['order'] = $order;
			}
		 */
		// ***
		$txt_search                 = array();
		$txt_search['post_title']   = isset( $woobe_filter['post_title'] );
		$txt_search['post_content'] = isset( $woobe_filter['post_content'] );
		$txt_search['post_excerpt'] = isset( $woobe_filter['post_excerpt'] );
		$txt_search['post_name']    = isset( $woobe_filter['post_name'] );

		$_REQUEST['woobe_txt_search']          = array();
		$_REQUEST['woobe_txt_search_behavior'] = array();
		foreach ( $txt_search as $skey => $is ) {
			if ( isset( $woobe_filter[ $skey ] ) and ( ! empty( $woobe_filter[ $skey ]['value'] ) or $woobe_filter[ $skey ]['value'] === '0' or $woobe_filter[ $skey ]['behavior'] == 'empty' ) ) {
				$_REQUEST['woobe_txt_search'][ $skey ] = $woobe_filter[ $skey ]['value'];
				if ( $woobe_filter[ $skey ]['behavior'] == 'empty' ) {
					$_REQUEST['woobe_txt_search'][ $skey ] = 'empty';
				}
				$_REQUEST['woobe_txt_search_behavior'][ $skey ] = $woobe_filter[ $skey ]['behavior'];
			}
		}

		if ( ! empty( $_REQUEST['woobe_txt_search'] ) ) {
			add_filter( 'posts_where', array( $this, 'posts_txt_where' ), 101 );
		}

		// ***

		if ( isset( $woobe_filter['sku'] ) and ( ! empty( $woobe_filter['sku']['value'] ) or $woobe_filter['sku']['behavior'] == 'empty' ) ) {
			$_REQUEST['woobe_sku_search']          = $woobe_filter['sku']['value'];
			$_REQUEST['woobe_sku_search_behavior'] = $woobe_filter['sku']['behavior'];
			add_filter( 'posts_where', array( $this, 'posts_sku_where' ), 102 );
		}

		if ( isset( $woobe_filter['product_url'] ) and ! empty( $woobe_filter['product_url']['value'] ) ) {
			$_REQUEST['woobe_product_url_search']          = $woobe_filter['product_url']['value'];
			$_REQUEST['woobe_product_url_search_behavior'] = $woobe_filter['product_url']['behavior'];
			add_filter( 'posts_where', array( $this, 'posts_product_url_where' ), 102 );
		}
		// ***

		if ( isset( $woobe_filter['regular_price'] ) ) {
			$_REQUEST['regular_price_from'] = $woobe_filter['regular_price']['from'];
			$_REQUEST['regular_price_to']   = $woobe_filter['regular_price']['to'];

			add_filter( 'posts_where', array( $this, 'regular_price_where' ), 102 );
		}
		if ( isset( $woobe_filter['sale_price'] ) ) {
			$_REQUEST['sale_price_from'] = $woobe_filter['sale_price']['from'];
			$_REQUEST['sale_price_to']   = $woobe_filter['sale_price']['to'];
			add_filter( 'posts_where', array( $this, 'sale_price_where' ), 102 );
		}

		if ( isset( $woobe_filter['stock_quantity'] ) ) {
			$_REQUEST['stock_quantity_from'] = $woobe_filter['stock_quantity']['from'];
			$_REQUEST['stock_quantity_to']   = $woobe_filter['stock_quantity']['to'];

			add_filter( 'posts_where', array( $this, 'stock_quantity_where' ), 103 );
		}

		// ****

		if ( isset( $woobe_filter['post_author'] ) and ! empty( $woobe_filter['post_author'] ) and $woobe_filter['post_author'] != -1 ) {
			$_REQUEST['woobe_post_author_search'] = $woobe_filter['post_author'];
			add_filter( 'posts_where', array( $this, 'posts_post_author_where' ), 103 );
		}

		// ***
		// if ordering is by meta key
		if ( isset( $fields[ $args['orderby'] ]['meta_key'] ) and ! empty( $fields[ $args['orderby'] ]['meta_key'] ) ) {
			$args['meta_key'] = $fields[ $args['orderby'] ]['meta_key'];
			if ( in_array( $fields[ $args['orderby'] ]['type'], array( 'number', 'timestamp', 'unix' ) ) ) {
				$args['orderby'] = 'meta_value_num meta_value';
			} else {
				$args['orderby'] = 'meta_value';
			}
		}

		// ***

		if ( isset( $woobe_filter['post__in'] ) and ! empty( $woobe_filter['post__in']['value'] ) ) {

			$p_ids = array();
			$tmp   = explode( ',', $woobe_filter['post__in']['value'] );

			if ( ! empty( $tmp ) ) {
				foreach ( $tmp as $vv ) {
					if ( substr_count( $vv, '-' ) > 0 ) {
						$vv = explode( '-', trim( $vv ) );
						if ( ! empty( $vv[0] ) and ! empty( $vv[1] ) ) {
							if ( $vv[0] !== $vv[1] ) {
								$start  = $vv[0] < $vv[1] ? $vv[0] : $vv[1];
								$finish = $vv[1] > $vv[0] ? $vv[1] : $vv[0];
								while ( true ) {
									$p_ids[] = $start;
									++$start;
									if ( $start > $finish ) {
										break;
									}
								}
							}
						}
					} else {
						$p_ids[] = intval( $vv );
					}
				}
			}

			$args['post__in'] = $p_ids;
		}

		// ***
		// product_visibility
		if ( isset( $woobe_filter['product_visibility'] ) and intval( $woobe_filter['product_visibility'] ) !== -1 ) {
			$tax_query[] = $this->get_product_visibility_query( $woobe_filter['product_visibility'] );
		}

		// product_type
		if ( isset( $woobe_filter['product_type'] ) and intval( $woobe_filter['product_type'] ) !== -1 ) {
			$tax_query[] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug', // id, slug
				'terms'    => $woobe_filter['product_type'],
			);
		}

		// featured
		if ( isset( $woobe_filter['featured'] ) and intval( $woobe_filter['featured'] ) !== -1 ) {
			$q = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug', // id, slug
				'terms'    => 'featured', // 1
			);

			// only not featured
			if ( intval( $woobe_filter['featured'] ) === 2 ) {
				$q['operator'] = 'NOT IN';
			}

			$tax_query[] = $q;
		}

		// backorders
		if ( isset( $woobe_filter['backorders'] ) and intval( $woobe_filter['backorders'] ) !== -1 ) {
			$q = array(
				'key'     => '_backorders',
				'value'   => $woobe_filter['backorders'],
				'compare' => '=',
			);

			$meta_query[] = $q;
		}

		// post_date
		if ( ( isset( $woobe_filter['post_date_from'] ) and ! empty( $woobe_filter['post_date_from'] ) )
			or ( isset( $woobe_filter['post_date_to'] ) and ! empty( $woobe_filter['post_date_to'] ) ) ) {

			$_REQUEST['woobe_post_date_from'] = isset( $woobe_filter['post_date_from'] ) ? $woobe_filter['post_date_from'] : null;
			$_REQUEST['woobe_post_date_to']   = isset( $woobe_filter['post_date_to'] ) ? $woobe_filter['post_date_to'] : null;
			add_filter( 'posts_where', array( $this, 'woobe_post_date_from_to' ), 103 );
			unset( $woobe_filter['post_date_to'] );
			unset( $woobe_filter['post_date_from'] );
		}

		// meta date
		foreach ( $fields as $k => $f ) {
			if ( ( isset( $woobe_filter[ $k . '_from' ] ) and ! empty( $woobe_filter[ $k . '_from' ] ) )
				or ( isset( $woobe_filter[ $k . '_to' ] ) and ! empty( $woobe_filter[ $k . '_to' ] ) ) and isset( $f['meta_key'] ) ) {

				if ( isset( $woobe_filter[ $k . '_from' ] ) ) {
					$date_meta_from = intval( strtotime( $woobe_filter[ $k . '_from' ] ) );
				} else {
					$date_meta_from = 0;
				}

				if ( isset( $woobe_filter[ $k . '_to' ] ) ) {
					$date_meta_to = intval( strtotime( $woobe_filter[ $k . '_to' ] ) );
				} else {
					$date_meta_to = 0;
				}
				if ( $date_meta_to != 0 ) {
					$search_array = array( $date_meta_from, $date_meta_to );
					if ( $date_meta_from > $date_meta_to ) {
						$search_array = array( $date_meta_to, $date_meta_from );
					}
					$meta_query[] = array(
						'key'     => $k,
						'value'   => $search_array,
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					);
				} else {
					$meta_query[] = array(
						'key'     => $k,
						'value'   => $date_meta_from,
						'type'    => 'NUMERIC',
						'compare' => '>=',
					);
				}
			}
		}

		// thumbnail
		if ( isset( $woobe_filter['_thumbnail_id'] ) and intval( $woobe_filter['_thumbnail_id'] ) !== -1 ) {
			if ( $woobe_filter['_thumbnail_id'] == 'not_empty' ) {
				$meta_query[] = array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				);
			} else {
				$meta_query[] = array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				);
			}
		}

		// menu_order
		if ( ( isset( $woobe_filter['menu_order_from'] ) and ! empty( $woobe_filter['menu_order_from'] ) )
			or ( isset( $woobe_filter['menu_order_to'] ) and ! empty( $woobe_filter['menu_order_to'] ) ) ) {
			$_REQUEST['woobe_menu_order_from'] = isset( $woobe_filter['menu_order_from'] ) ? $woobe_filter['menu_order_from'] : null;
			$_REQUEST['woobe_menu_order_to']   = isset( $woobe_filter['menu_order_to'] ) ? $woobe_filter['menu_order_to'] : null;
			add_filter( 'posts_where', array( $this, 'woobe_menu_order_to' ), 104 );
		}
		// tax class
		if ( isset( $woobe_filter['_tax_class'] ) and intval( $woobe_filter['_tax_class'] ) !== -1 ) {
			$meta_query[] = array(
				'key'     => '_tax_class',
				'value'   => $woobe_filter['_tax_class'],
				'compare' => '=',
			);
		}

		// post_status
		if ( isset( $woobe_filter['post_status'] ) and intval( $woobe_filter['post_status'] ) !== -1 ) {
			$args['post_status'] = array( trim( $woobe_filter['post_status'] ) );
		}

		// ***

		if ( ! empty( $tax_query ) ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $meta_query ) ) {
			$meta_query['relation'] = 'AND';
		}

		// ***
		$args['tax_query']  = $tax_query;
		$args['meta_query'] = $meta_query;

		return $args;
	}

	public function woobe_tools_panel_buttons_end() {
		global $WOOBE;
		?>
		&nbsp;|&nbsp;<span>
			<?php
			$behavior_options = array(
				'like'  => esc_html__( 'LIKE', 'woo-bulk-editor' ),
				'exact' => esc_html__( 'EXACT', 'woo-bulk-editor' ),
				'not'   => esc_html__( 'NOT', 'woo-bulk-editor' ),
				'begin' => esc_html__( 'BEGIN', 'woo-bulk-editor' ),
				'end'   => esc_html__( 'END', 'woo-bulk-editor' ),
			);

			$search_options = apply_filters(
				'woobe_quick_search_options',
				array(
					'post_title' => esc_html__( 'Title', 'woo-bulk-editor' ),
					'sku'        => esc_html__( 'SKU', 'woo-bulk-editor' ),
					'post__in'   => esc_html__( 'ID', 'woo-bulk-editor' ),
				)
			);

			$fields = $this->settings->quick_search_fieds;
			if ( $fields ) {
				$fields         = WOOBE_HELPER::string_to_array( $fields );
				$search_options = array_merge( $search_options, $fields );
			}
			?>

			<div id="woobe_filter_form_tools_panel">
				<div class='tools_panel_filter-unit-wrap'>
					<div class="col-lg-6">
						<input type="text" placeholder="<?php esc_html_e( 'quick search by ...', 'woo-bulk-editor' ); ?>" name="woobe_filter_form_tools_value" style="width: 99% !important;" value="" />
					</div>
					<div class="col-lg-2">

						<select name="woobe_filter_tools_options" class="woobe_filter_tools_select">
							<?php foreach ( $search_options as $key => $title ) : ?>
								<option value="<?php echo esc_attr( trim( $key ) ); ?>"><?php echo esc_html( trim( $title ) ); ?></option>
							<?php endforeach; ?>
						</select>

					</div>                              
					<div class="col-lg-2">

						<select name="woobe_filter_tools_behavior">
							<?php foreach ( $behavior_options as $key => $title ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>

					</div>

					<div class="col-lg-2">
						<a href="#" class="button button-primary button-large" id="woobe_filter_btn_tools_panel"></a>
					</div>    
					<div class="clear"></div>

				</div>
			</div>

		</span>
		<?php
	}
}
