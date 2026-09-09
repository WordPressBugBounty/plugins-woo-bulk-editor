<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

final class WOOBE_HISTORY extends WOOBE_EXT {

	protected $slug     = 'history'; // unique
	private $table      = 'woobe_history'; // 1 field key operations
	private $table_bulk = 'woobe_history_bulk'; // bulk operations heads

	public function __construct() {
		global $wpdb;
		$this->table      = $wpdb->prefix . $this->table;
		$this->table_bulk = $wpdb->prefix . $this->table_bulk;

		add_action( 'woobe_ext_scripts', array( $this, 'woobe_ext_scripts' ), 1 );

		// ajax
		add_action( 'wp_ajax_woobe_history_revert_product', array( $this, 'woobe_history_revert_product' ), 1 );
		add_action( 'wp_ajax_woobe_history_get_bulk_count', array( $this, 'woobe_history_get_bulk_count' ), 1 );
		add_action( 'wp_ajax_woobe_history_revert_bulk_portion', array( $this, 'woobe_history_revert_bulk_portion' ), 1 );
		add_action( 'wp_ajax_woobe_get_history_list', array( $this, 'woobe_get_history_list' ), 1 );
		add_action( 'wp_ajax_woobe_history_clear', array( $this, 'woobe_history_clear' ), 1 );
		add_action( 'wp_ajax_woobe_history_delete_solo', array( $this, 'woobe_history_delete_solo' ), 1 );
		add_action( 'wp_ajax_woobe_history_delete_bulk', array( $this, 'woobe_history_delete_bulk' ), 1 );

		// tabs
		$this->add_tab( $this->slug, 'panel', esc_html__( 'History', 'woo-bulk-editor' ), 'undo' );
		add_action( 'woobe_ext_panel_' . $this->slug, array( $this, 'woobe_ext_panel' ), 1 );

		// hooks
		add_action( 'woobe_bulk_started', array( $this, 'start_bulk' ), 10, 1 );
		add_action( 'woobe_bulk_going', array( $this, 'count_bulked_products' ), 10, 2 );
		add_action( 'woobe_bulk_finished', array( $this, 'finish_bulk' ), 10, 1 );
		add_action( 'woobe_before_update_page_field', array( $this, 'write' ), 10, 3 );
	}

	public function woobe_ext_scripts() {
		wp_enqueue_script( 'woobe_ext_' . $this->slug, $this->get_ext_link() . 'assets/js/' . $this->slug . '.js', array(), WOOBE_VERSION, false );
		wp_enqueue_style( 'woobe_ext_' . $this->slug, $this->get_ext_link() . 'assets/css/' . $this->slug . '.css', array(), WOOBE_VERSION );
		?>
		<script>
			lang.<?php echo esc_attr( $this->slug ); ?> = {};
			lang.<?php echo esc_attr( $this->slug ); ?>.reverting = '<?php esc_html_e( 'Reverting', 'woo-bulk-editor' ); ?> ...';
			lang.<?php echo esc_attr( $this->slug ); ?>.reverted = '<?php esc_html_e( 'Reverted!', 'woo-bulk-editor' ); ?>';
			lang.<?php echo esc_attr( $this->slug ); ?>.wait_until_finish = '<?php esc_html_e( 'Wait please while data reverting is going!', 'woo-bulk-editor' ); ?>';
			lang.<?php echo esc_attr( $this->slug ); ?>.clearing = '<?php esc_html_e( 'History clearing ...', 'woo-bulk-editor' ); ?>';
			lang.<?php echo esc_attr( $this->slug ); ?>.cleared = '<?php esc_html_e( 'History is cleared!', 'woo-bulk-editor' ); ?>';
			lang.<?php echo esc_attr( $this->slug ); ?>.history_is_going = "<?php echo esc_html__( 'ATTENTION: History operation is going!', 'woo-bulk-editor' ); ?>";
		</script>
		<?php
	}

	public function woobe_ext_panel() {
		$data = array();
		$this->install_tables();
		WOOBE_HELPER::render_html_e( $this->get_ext_path() . 'views/panel.php', $data );
	}

	// install history tables
	private function install_tables() {

		global $wpdb;

		$checktable = $wpdb->query( "SHOW TABLES LIKE '{$this->table}'" );

		if ( $checktable ) {
			return;
		}

		// ***

		$charset_collate = '';

		if ( method_exists( $wpdb, 'has_cap' ) and $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}

		// ***

		$sql1 = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
  `id` int(12) NOT NULL AUTO_INCREMENT,
  `field_key` varchar(32) NOT NULL,
  `product_id` int(11) NOT NULL,
  `prev_val` text,
  `mod_date` int(11) NOT NULL COMMENT 'modification time',
  `bulk_key` varchar(16) DEFAULT NULL COMMENT 'is changed in the bulk flow?',
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (id),
  INDEX `product_id` (`product_id`),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $wpdb->query( $sql1 ) === false ) {
			?>
			<div class="error notice">
				<p class="description"><?php esc_html_e( 'BEAR cannot create the database table! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel phpmyadmin!', 'woo-bulk-editor' ); ?></p>
				<code><?php echo esc_sql( $sql1 ); ?></code>
				<?php
				echo esc_html( $wpdb->last_error );
				?>
			</div>
			<?php
		}

		// ***

		$sql2 = "CREATE TABLE IF NOT EXISTS `{$this->table_bulk}` (
  `id` int(12) NOT NULL AUTO_INCREMENT,
  `bulk_key` varchar(16) NOT NULL,
  `state` enum('completed','terminated') NOT NULL DEFAULT 'terminated',
  `started` int(11) DEFAULT NULL,
  `finished` int(11) DEFAULT NULL,
  `products_count` int(11) DEFAULT '0',
  `set_of_keys` text,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (id),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $wpdb->query( $sql2 ) === false ) {
			?>
			<div class="error notice">
				<p class="description"><?php esc_html_e( 'BEAR cannot create the database table! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel phpmyadmin!', 'woo-bulk-editor' ); ?></p>
				<code><?php echo esc_sql( $sql2 ); ?></code>
				<?php
				echo esc_html( $wpdb->last_error );
				?>
			</div>
			<?php
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// ownership of the history rows
	//
	// History has always been per user: every query here carries a user_id, so
	// one shop manager never sees or reverts another one's operations. An agent
	// working over MCP has no WordPress user of its own, so its rows would land
	// under id 0 and stay invisible to everybody - including the shop owner, who
	// is the one person who must be able to see and undo them.
	//
	// So the agent gets a fixed id of its own (WOOBE_MCP::user_id(), negative,
	// therefore never colliding with a real user), and every read here matches
	// "mine or the agent's". Writes stay single-author: uid() returns the agent
	// id during an MCP request and the current user everywhere else.

	// author id for the rows this request writes
	private function uid() {

		if ( class_exists( 'WOOBE_MCP' ) && WOOBE_MCP::is_request() ) {
			return WOOBE_MCP::user_id();
		}

		return get_current_user_id();
	}

	// the agent's id, so its rows stay visible to everyone
	private function mcp_uid() {
		return class_exists( 'WOOBE_MCP' ) ? WOOBE_MCP::user_id() : $this->uid();
	}

	public function get_history() {
		$history = array();
		global $wpdb, $WOOBE;
		$user_id = $this->uid();
		$mcp_id  = $this->mcp_uid();

		if ( $WOOBE->show_notes ) {
			$solo = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND user_id IN (%d, %d) ORDER BY mod_date DESC LIMIT 2",
					$user_id,
					$mcp_id
				),
				ARRAY_A
			);

			$bulk = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_bulk} WHERE user_id IN (%d, %d) ORDER BY started DESC LIMIT 2",
					$user_id,
					$mcp_id
				),
				ARRAY_A
			);

			$bulk_ids = array();
			if ( ! empty( $bulk ) ) {
				foreach ( $bulk as $v ) {
					$bulk_ids[] = $v['id'];
				}
				$bulk_ids_clean = implode( ',', array_map( 'intval', $bulk_ids ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_bulk} WHERE user_id IN (%d, %d) AND id NOT IN ($bulk_ids_clean)", $user_id, $mcp_id ) );
			}

			$solo_ids = array();
			if ( ! empty( $solo ) ) {
				foreach ( $solo as $v ) {
					$solo_ids[] = $v['id'];
				}
				$solo_ids_string = implode( ',', array_map( 'intval', $solo_ids ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$this->table} WHERE user_id IN (%d, %d) AND bulk_key IS NULL AND id NOT IN ($solo_ids_string)",
						$user_id,
						$mcp_id
					)
				);
			}
		} else {
			$solo = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND user_id IN (%d, %d) ORDER BY mod_date DESC",
					$user_id,
					$mcp_id
				),
				ARRAY_A
			);

			$bulk = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_bulk} WHERE user_id IN (%d, %d) ORDER BY started DESC",
					$user_id,
					$mcp_id
				),
				ARRAY_A
			);
		}

		// ***

		$time_keys = array();
		if ( ! empty( $solo ) ) {
			foreach ( $solo as $key => $value ) {
				$time_keys[]                = $value['mod_date'];
				$solo[ $value['mod_date'] ] = $value;
				unset( $solo[ $key ] );
			}
		}

		if ( ! empty( $bulk ) ) {
			foreach ( $bulk as $key => $value ) {
				$time_keys[]               = $value['started'];
				$bulk[ $value['started'] ] = $value;
				unset( $bulk[ $key ] );
			}
		}

		// ***

		if ( ! empty( $time_keys ) ) {
			foreach ( $time_keys as $t ) {
				if ( isset( $solo[ $t ] ) ) {
					$history[ $t ] = $solo[ $t ];
				} else {
					$history[ $t ] = $bulk[ $t ];
				}
			}

			ksort( $history, SORT_NUMERIC );
			$history = array_reverse( $history );

			if ( $WOOBE->show_notes ) {
				if ( count( $history ) > 2 ) {
					$history = array_slice( $history, 0, 2 );
				}
			}
		}

		return $history;
	}

	public function start_bulk( $bulk_key ) {
		global $wpdb;
		$woobe_bulk = $this->storage->get_val( 'woobe_bulk_' . strtolower( $bulk_key ) );

		$wpdb->insert(
			$this->table_bulk,
			array(
				'bulk_key'    => $bulk_key,
				'started'     => current_time( 'timestamp', false ),
				'set_of_keys' => ! empty( $woobe_bulk['is'] ) ? json_encode( array_keys( $woobe_bulk['is'] ) ) : '',
				'user_id'     => $this->uid(),
			)
		);
	}

	public function count_bulked_products( $bulk_key, $products_count, $sign = '+' ) {
		global $wpdb;
		$user_id = $this->uid();
		$sign    = in_array( $sign, array( '+', '-' ), true ) ? $sign : '+';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table_bulk} SET products_count = products_count {$sign} %d WHERE bulk_key = %s AND user_id = %d", intval( $products_count ), $bulk_key, $user_id ) );
	}

	public function finish_bulk( $bulk_key ) {
		global $wpdb;
		$wpdb->update(
			$this->table_bulk,
			array(
				'state'    => 'completed',
				'finished' => current_time( 'timestamp', false ),
			),
			array(
				'bulk_key' => $bulk_key,
				'user_id'  => $this->uid(),
			)
		);
	}

	// for hook woobe_before_update_page_field
	public function write( $field_key, $product_id, $post_parent = 0 ) {
		global $wpdb;

		if ( empty( $field_key ) or empty( $product_id ) ) {
			return;
		}

		// fix for description of one variation
		$field_type = $this->settings->get_fields()[ $field_key ]['field_type'];

		$prev_val = $this->products->get_post_field( $product_id, $field_key, $post_parent );

		switch ( $field_type ) {
			case 'taxonomy':
				$tmp = array();
				if ( ! empty( $prev_val ) ) {
					foreach ( $prev_val as $t ) {
						$tmp[] = $t->term_id;
					}
				} else {
					$prev_val = '';
				}
				$prev_val = json_encode( $tmp );
				break;

			case 'attribute':
				if ( ! empty( $prev_val ) ) {
					$prev_val = json_encode( $prev_val );
				} else {
					$prev_val = '';
				}

				break;

			case 'prop':
				if ( $field_key == 'stock_status' ) {
					$prev_val = ( 'instock' == $prev_val ? 1 : 0 );
				}

				break;

			case 'gallery':
			case 'upsells':
			case 'cross_sells':
			case 'grouped':
				if ( ! empty( $prev_val ) ) {
					$prev_val = json_encode( $prev_val );
				} else {
					$prev_val = '';
				}
				break;

			case 'downloads':
				$tmp = array();

				if ( ! empty( $prev_val ) ) {
					foreach ( $prev_val as $hash => $file ) {
						$tmp[] = array(
							'name' => $file['name'],
							'file' => $file['file'],
							'hash' => $hash,
						);
					}
					$prev_val = json_encode( $tmp );
				} else {
					$prev_val = '';
				}

				break;
		}

		// for all another cases
		if ( is_array( $prev_val ) ) {
			if ( $this->settings->get_fields()[ $field_key ]['edit_view'] == 'meta_popup_editor' ) {
				$prev_val = json_encode( $prev_val, JSON_HEX_QUOT | JSON_HEX_TAG );
			} else {
				$prev_val = json_encode( $prev_val );
			}
		}

		// ***

		try {
			$wpdb->insert(
				$this->table,
				array(
					'field_key'  => $field_key,
					'product_id' => $product_id,
					'prev_val'   => $prev_val,
					'mod_date'   => current_time( 'timestamp', false ) + wp_rand( 0, 30 ), // rand - to avoid the same unix time for different DB table rows
					'bulk_key'   => isset( $_REQUEST['woobe_bulk_key'] ) ? WOOBE_HELPER::sanitize_bulk_key( $_REQUEST['woobe_bulk_key'] ) : null,
					'user_id'    => $this->uid(),
				)
			);
		} catch ( Exception $e ) {
			// +++
		}

		// return $wpdb->insert_id;
	}

	// removing 1 row of data from the history
	private function delete( $table, $id, $field = 'id' ) {
		global $wpdb;

		// $field comes from internal calls only, but it is interpolated into the
		// statement, so it stays whitelisted rather than trusted
		$field = in_array( $field, array( 'id', 'bulk_key' ), true ) ? $field : 'id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$field} = %s AND user_id IN (%d, %d)",
				$id,
				$this->uid(),
				$this->mcp_uid()
			)
		);
	}

	private function revert( $id ) {
		global $wpdb;

		remove_all_actions( 'woobe_before_update_page_field' );

		$solo = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND user_id IN (%d, %d)",
				$id,
				$this->uid(),
				$this->mcp_uid()
			),
			ARRAY_A
		);

		if ( ! empty( $solo ) ) {

			switch ( $this->settings->get_fields()[ $solo['field_key'] ]['field_type'] ) {
				case 'taxonomy':
				case 'attribute':
				case 'gallery':
				case 'upsells':
				case 'cross_sells':
				case 'grouped':
					if ( ! empty( $solo['prev_val'] ) ) {
						$solo['prev_val'] = json_decode( $solo['prev_val'] );
					} else {
						$solo['prev_val'] = null;
					}
					break;

				case 'downloads':
					$prev_val         = json_decode( $solo['prev_val'], true );
					$solo['prev_val'] = array();

					if ( ! empty( $prev_val ) ) {
						$solo['prev_val']['_wc_file_names']  = array();
						$solo['prev_val']['_wc_file_hashes'] = array();
						$solo['prev_val']['_wc_file_urls']   = array();
						foreach ( $prev_val as $f ) {
							$solo['prev_val']['_wc_file_names'][]  = $f['name'];
							$solo['prev_val']['_wc_file_hashes'][] = $f['hash'];
							$solo['prev_val']['_wc_file_urls'][]   = $f['file'];
						}
					}

					break;

				case 'meta':
					// for serialized arrays in meta fields
					// if (maybe_serialize($solo['prev_val'])) {
					if ( is_string( $solo['prev_val'] ) && is_array( json_decode( $solo['prev_val'], true ) ) && ( json_last_error() == JSON_ERROR_NONE ) ) {
						$solo['prev_val'] = json_decode( $solo['prev_val'], true );
					}
					break;
			}

			// fix when reverting to the empty value, for example set null to calendar field as date_on_sale_from
			if ( is_null( $solo['prev_val'] ) ) {
				$solo['prev_val'] = 0;
			}

			$this->products->update_page_field( $solo['product_id'], $solo['field_key'], $solo['prev_val'] );
			/*
				if (!empty($solo['bulk_key'])) {
				$this->count_bulked_products($solo['bulk_key'], 1, '-');
				}
			 *
			 */
		}

		$this->delete( $this->table, $id );
	}

	private function wipe_history() {
		global $wpdb;

		$user_id = $this->uid();
		$mcp_id  = $this->mcp_uid();

		// the agent's rows go with them: they are shown in this same list, so
		// leaving them behind would make "clear the history" look broken
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE user_id IN (%d, %d)", $user_id, $mcp_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_bulk} WHERE user_id IN (%d, %d)", $user_id, $mcp_id ) );
	}

	// ajax
	public function woobe_history_revert_product() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			die( '0' );
		}
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		// ***

		$this->revert( intval( $_REQUEST['id'] ) );

		exit;
	}

	// ajax
	public function woobe_history_get_bulk_count() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		global $wpdb;
		$bulk_key = WOOBE_HELPER::sanitize_bulk_key( $_REQUEST['bulk_key'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		die(
			esc_html(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND user_id IN (%d, %d)",
						$bulk_key,
						$this->uid(),
						$this->mcp_uid()
					)
				)
			)
		);
	}

	// ajax
	public function woobe_history_revert_bulk_portion() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			die( '0' );
		}
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		global $wpdb;

		// ***

		$bulk_key = WOOBE_HELPER::sanitize_bulk_key( $_REQUEST['bulk_key'] );
		$limit    = intval( $_REQUEST['limit'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key = %s AND user_id IN (%d, %d) LIMIT %d",
				$bulk_key,
				$this->uid(),
				$this->mcp_uid(),
				$limit
			),
			ARRAY_A
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$this->revert( $r['id'] );
			}
		}

		// ***

		$removed_count = intval( $_REQUEST['removed_count'] ) + $limit;
		$total_count   = intval( $_REQUEST['total_count'] );

		if ( ( $total_count - $removed_count ) <= 0 ) {
			$this->delete( $this->table_bulk, $bulk_key, 'bulk_key' );
		}

		exit;
	}

	// ajax
	public function woobe_get_history_list() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		$data                         = array();
		$data['history']              = $this->get_history();
		$data['settings_fields']      = $this->settings->get_fields();
		$data['settings_fields_full'] = (array) $this->settings->get_fields( false );
		$data['products_obj']         = $this->products;
		WOOBE_HELPER::render_html_e( $this->get_ext_path() . 'views/list.php', $data );
		exit;
	}

	// ajax
	public function woobe_history_clear() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		$this->wipe_history();
		exit;
	}

	// ajax
	public function woobe_history_delete_solo() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		$this->delete( $this->table, intval( $_REQUEST['id'] ) );
		exit;
	}

	// ajax
	public function woobe_history_delete_bulk() {
		WOOBE_HELPER::check_ajax_access(
			array(
				'nonce_field'  => 'history_nonce',
				'nonce_action' => 'woobe_history_panel_nonce',
			)
		);
		$this->delete( $this->table, WOOBE_HELPER::sanitize_bulk_key( $_REQUEST['bulk_key'] ), 'bulk_key' );
		$this->delete( $this->table_bulk, WOOBE_HELPER::sanitize_bulk_key( $_REQUEST['bulk_key'] ), 'bulk_key' );
		exit;
	}

	// public entry point for the MCP extension: the ajax handler above cannot be
	// reused because it verifies a nonce that a REST request never has
	public function revert_bulk_portion( $bulk_key, $limit = 200 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key = %s AND user_id IN (%d, %d) LIMIT %d",
				$bulk_key,
				$this->uid(),
				$this->mcp_uid(),
				intval( $limit )
			),
			ARRAY_A
		);

		$n = 0;

		foreach ( (array) $rows as $r ) {
			$this->revert( $r['id'] );
			++$n;
		}

		return $n;
	}
}