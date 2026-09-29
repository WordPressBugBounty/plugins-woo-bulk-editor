<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

final class WOOBE_HISTORY extends WOOBE_EXT {

	protected $slug     = 'history'; // unique
	private $table      = 'woobe_history'; // 1 field key operations
	private $table_bulk = 'woobe_history_bulk'; // bulk operations heads

	// Version of the two tables. 2 added via_mcp: whether a row was written
	// through an AI agent (1) or by hand (0).
	const SCHEMA_VERSION = 2;
	const SCHEMA_OPTION  = 'woobe_history_schema';

	// The shop-wide MCP key's author id as rows before the schema upgrade
	// carry it. Literal on purpose: the MCP extension is loaded after this
	// one, so its class does not exist yet when the upgrade runs.
	const SHOP_AGENT_ID = -777;

	public function __construct() {
		global $wpdb;
		$this->table      = $wpdb->prefix . $this->table;
		$this->table_bulk = $wpdb->prefix . $this->table_bulk;

		// before any hook below can write a row with the new column
		$this->maybe_upgrade_schema();

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
			// an administrator clears everybody's history, and is told so
			lang.<?php echo esc_attr( $this->slug ); ?>.clear_confirm = <?php echo wp_json_encode( $this->sees_everything() ? __( 'This clears the history of all users, including the AI agent rows. It cannot be undone. Continue?', 'woo-bulk-editor' ) : __( 'This clears your own history. It cannot be undone. Continue?', 'woo-bulk-editor' ) ); ?>;
		</script>
		<?php
	}

	public function woobe_ext_panel() {
		// the tables first: the list of authors reads them
		$this->install_tables();
		$data = array(
			'history_admin'   => $this->sees_everything(),
			'history_authors' => $this->sees_everything() ? $this->authors_in_history() : array(),
		);
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
  `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?',
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
  `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?',
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

		// created with the current definition: nothing left to upgrade
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// schema upgrade
	//
	// CREATE TABLE IF NOT EXISTS never touches a table that is already there,
	// so a site that installed an older version keeps its old columns. The
	// upgrade runs once per schema version: one autoloaded option read on
	// every later load, nothing else. Each ALTER is guarded by a look at the
	// columns, so a table created with the new definition, or a second request
	// racing the first, is left alone.

	private function maybe_upgrade_schema() {

		if ( intval( get_option( self::SCHEMA_OPTION, 1 ) ) >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;

		$tables = array();

		foreach ( array( $this->table, $this->table_bulk ) as $table ) {

			// not created yet: install_tables() creates it with the column
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			$tables[] = $table;

			if ( $this->column_exists( $table, 'via_mcp' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name only, a one-time upgrade
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?'" ) ) {
				// not marked done: the next load tries again
				return;
			}
		}

		// rows from before the column: everything was by hand, except what the
		// shop-wide key's agent wrote under its own id
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET via_mcp = 1 WHERE user_id = %d AND via_mcp = 0", self::SHOP_AGENT_ID ) );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private function column_exists( $table, $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// ownership of the history rows
	//
	// Every row carries its author (user_id) and whether it was written
	// through an AI agent (via_mcp). By hand, the author is the current user.
	// Through MCP it is the user behind a personal key, or the shop-wide
	// key's own negative id - never colliding with a real user.
	//
	// Who sees what is decided in one place, scope(): an administrator sees
	// every row of every author - the shop-wide key is his, so it sees
	// everything too - and anybody else only his own rows, by hand or through
	// his own agent. Every read, rollback, delete and clear below goes through
	// it, so no query can drift from the rule.

	// author id for the rows this request writes
	private function uid() {

		if ( class_exists( 'WOOBE_MCP' ) && WOOBE_MCP::is_request() ) {
			return WOOBE_MCP::user_id();
		}

		return get_current_user_id();
	}

	// 1 when this request writes through an AI agent
	private function via_mcp() {
		return ( class_exists( 'WOOBE_MCP' ) && WOOBE_MCP::is_request() ) ? 1 : 0;
	}

	// the shop-wide key's author id, whoever is acting now
	private function mcp_uid() {
		return class_exists( 'WOOBE_MCP' ) ? WOOBE_MCP::shop_user_id() : self::SHOP_AGENT_ID;
	}

	/**
	 * Whether whoever is acting now sees the history of everyone: an
	 * administrator by hand or through his own personal key, or the
	 * shop-wide key, which belongs to the administrator.
	 */
	public function sees_everything() {

		if ( class_exists( 'WOOBE_MCP' ) && WOOBE_MCP::is_request() && ! WOOBE_MCP_BOOT::personal_user_id() ) {
			return true;
		}

		return WOOBE_MCP_BOOT::is_administrator();
	}

	/**
	 * The one rule of who sees which rows, as a SQL condition and its
	 * arguments for $wpdb->prepare(). Works on both tables.
	 *
	 * @return array sql, args
	 */
	private function scope() {

		if ( $this->sees_everything() ) {
			return array(
				'sql'  => '1=1',
				'args' => array(),
			);
		}

		return array(
			'sql'  => 'user_id = %d',
			'args' => array( $this->uid() ),
		);
	}

	/**
	 * $wpdb->prepare() when there is something to prepare. The administrator
	 * scope has no placeholder, and prepare() refuses a query without one.
	 */
	private function prepare( $sql, $args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, filled here
		return empty( $args ) ? $sql : $wpdb->prepare( $sql, $args );
	}

	// Every query from here on carries the condition from scope(): fixed SQL
	// with %d placeholders only, filled by $wpdb->prepare() together with the
	// query's own arguments. Table and column names are the plugin's own.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	/**
	 * The list shown in the History tab, newest first, each row with its
	 * author label.
	 *
	 * @param array $filters administrators only: who (author id) and via
	 *                       (0 by hand, 1 through an AI agent).
	 */
	public function get_history( $filters = array() ) {
		global $wpdb, $WOOBE;

		$scope = $this->scope();
		$sql   = $scope['sql'];
		$args  = $scope['args'];

		// who made the change and how: narrowing the administrator's view,
		// never widening anybody's - the scope stays in the condition
		if ( $this->sees_everything() ) {

			if ( isset( $filters['who'] ) && '' !== (string) $filters['who'] ) {
				$sql   .= ' AND user_id = %d';
				$args[] = intval( $filters['who'] );
			}

			if ( isset( $filters['via'] ) && in_array( (string) $filters['via'], array( '0', '1' ), true ) ) {
				$sql   .= ' AND via_mcp = %d';
				$args[] = intval( $filters['via'] );
			}
		}

		if ( $WOOBE->show_notes ) {
			$this->prune_free_build();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		$solo = $wpdb->get_results( $this->prepare( "SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND {$sql} ORDER BY mod_date DESC, id DESC", $args ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		$bulk = $wpdb->get_results( $this->prepare( "SELECT * FROM {$this->table_bulk} WHERE {$sql} ORDER BY started DESC, id DESC", $args ), ARRAY_A );

		// one list, newest first. Sorted rather than keyed by time: two rows
		// written in the same second - easy once an administrator sees every
		// author - must both stay in the list
		$history = array_merge( (array) $solo, (array) $bulk );

		usort(
			$history,
			function ( $a, $b ) {
				$ta = intval( isset( $a['field_key'] ) ? $a['mod_date'] : $a['started'] );
				$tb = intval( isset( $b['field_key'] ) ? $b['mod_date'] : $b['started'] );

				if ( $ta !== $tb ) {
					return $tb - $ta;
				}

				return intval( $b['id'] ) - intval( $a['id'] );
			}
		);

		// the free version rolls back the last two operations - of each author
		if ( $WOOBE->show_notes ) {

			$per_author = array();

			foreach ( $history as $key => $row ) {

				$author = intval( $row['user_id'] );

				$per_author[ $author ] = isset( $per_author[ $author ] ) ? $per_author[ $author ] + 1 : 1;

				if ( $per_author[ $author ] > 2 ) {
					unset( $history[ $key ] );
				}
			}

			$history = array_values( $history );
		}

		return $this->with_author_labels( $history );
	}

	/**
	 * The free version keeps the last 2 solo and the last 2 bulk operations -
	 * per author, and only of the authors the current user owns: himself,
	 * and for an administrator also the shop-wide key, which is his. Opening
	 * the tab never deletes another user's rows.
	 */
	private function prune_free_build() {

		$authors = array( $this->uid() );

		if ( $this->sees_everything() ) {
			$authors[] = $this->mcp_uid();
		}

		foreach ( array_unique( array_map( 'intval', $authors ) ) as $author ) {
			$this->prune_author( $author );
		}
	}

	private function prune_after_request() {
		global $WOOBE;

		if ( empty( $WOOBE->show_notes ) ) {
			return;
		}

		if ( ! has_action( 'shutdown', array( $this, 'prune_on_shutdown' ) ) ) {
			add_action( 'shutdown', array( $this, 'prune_on_shutdown' ) );
		}
	}

	public function prune_on_shutdown() {
		$this->prune_author( $this->uid() );
	}

	private function prune_author( $author ) {
		global $wpdb;

		$author = intval( $author );

		// solo: the two newest rows and the rows written together with them
		$newest = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND user_id = %d ORDER BY id DESC LIMIT 2", $author ), ARRAY_A );

		if ( ! empty( $newest ) ) {
			$keep = array();

			foreach ( $newest as $row ) {
				foreach ( $this->stock_change_rows( $row ) as $other ) {
					$keep[] = intval( $other['id'] );
				}
			}

			$keep = implode( ',', array_unique( $keep ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list built above
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE user_id = %d AND bulk_key IS NULL AND id NOT IN ($keep)", $author ) );
		}

		// bulk: the two newest operations, heads and rows
		$heads = $wpdb->get_col( $wpdb->prepare( "SELECT bulk_key FROM {$this->table_bulk} WHERE user_id = %d ORDER BY id DESC LIMIT 2", $author ) );

		if ( ! empty( $heads ) ) {
			$in   = implode( ',', array_fill( 0, count( $heads ), '%s' ) );
			$args = array_merge( array( $author ), $heads );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_bulk} WHERE user_id = %d AND bulk_key NOT IN ($in)", $args ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE user_id = %d AND bulk_key IS NOT NULL AND bulk_key NOT IN ($in)", $args ) );
		}
	}

	/**
	 * Adds 'author' to every row: the display name by hand, "Name (AI agent)"
	 * through a personal key, "AI agent (shop key)" for the shop-wide key,
	 * "user #ID" for a user who no longer exists. Names are read in one
	 * query for the whole list, never per row.
	 */
	private function with_author_labels( $rows ) {

		$ids = array();

		foreach ( $rows as $row ) {
			$id = intval( $row['user_id'] );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$names = array();

		if ( ! empty( $ids ) ) {
			foreach ( get_users(
				array(
					'include' => array_values( $ids ),
					'fields'  => array( 'ID', 'display_name' ),
				)
			) as $user ) {
				$names[ intval( $user->ID ) ] = $user->display_name;
			}
		}

		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['author'] = $this->author_label( intval( $row['user_id'] ), ! empty( $row['via_mcp'] ), $names );
		}

		return $rows;
	}

	private function author_label( $user_id, $via_mcp, $names ) {

		if ( $user_id === $this->mcp_uid() ) {
			return __( 'AI agent (shop key)', 'woo-bulk-editor' );
		}

		/* translators: %d: id of a user who no longer exists */
		$name = isset( $names[ $user_id ] ) ? $names[ $user_id ] : sprintf( __( 'user #%d', 'woo-bulk-editor' ), $user_id );

		/* translators: %s: name of the user whose AI agent made the change */
		return $via_mcp ? sprintf( __( '%s (AI agent)', 'woo-bulk-editor' ), $name ) : $name;
	}

	/**
	 * Everybody who appears in the history, for the administrator's "who made
	 * the change" filter: author id => label. The shop-wide key is listed
	 * under its own label.
	 */
	public function authors_in_history() {
		global $wpdb;

		if ( ! $this->table_exists( $this->table ) ) {
			return array();
		}

		$scope = $this->scope();
		$ids   = array_map(
			'intval',
			(array) $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
				$this->prepare( "SELECT DISTINCT user_id FROM {$this->table} WHERE {$scope['sql']} UNION SELECT DISTINCT user_id FROM {$this->table_bulk} WHERE {$scope['sql']}", array_merge( $scope['args'], $scope['args'] ) )
			)
		);

		$rows = array();

		foreach ( array_unique( $ids ) as $id ) {
			$rows[] = array(
				'user_id' => $id,
				'via_mcp' => 0,
			);
		}

		$out = array();

		foreach ( $this->with_author_labels( $rows ) as $row ) {
			$out[ $row['user_id'] ] = $row['author'];
		}

		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
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
				'via_mcp'     => $this->via_mcp(),
			)
		);
		
		$this->prune_after_request();
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

				// A date (the sale schedule) comes as a WC_DateTime object, and
				// the database layer stores an object as an empty string: the
				// revert then deleted the schedule instead of putting the old
				// date back. Kept as an ISO date with its offset, which the
				// setter reads back exactly and the list shows as a date.
				if ( $prev_val instanceof WC_DateTime ) {
					$prev_val = $prev_val->format( DATE_ATOM );
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
					'via_mcp'    => $this->via_mcp(),
				)
			);
		} catch ( Exception $e ) {
			// +++
		}
		
		$this->prune_after_request();

		// return $wpdb->insert_id;
	}

	/**
	 * Whether a solo row exists within what the current user may see.
	 */
	private function solo_in_scope( $id ) {
		global $wpdb;

		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE id = %d AND {$scope['sql']}", array_merge( array( intval( $id ) ), $scope['args'] ) ) );
	}

	/**
	 * Whether a bulk operation - its rows or its head - exists within what
	 * the current user may see.
	 */
	private function bulk_in_scope( $bulk_key ) {
		global $wpdb;

		$scope = $this->scope();
		$args  = array_merge( array( $bulk_key ), $scope['args'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_bulk} WHERE bulk_key = %s AND {$scope['sql']}", $args ) ) ) {
			return true;
		}

		return $this->count_bulk_rows( $bulk_key ) > 0;
	}

	/**
	 * Server side refusal of a row or operation outside the user's scope.
	 * The buttons are only drawn for rows he can see; this is what stops a
	 * request built by hand.
	 */
	private function refuse() {
		wp_send_json_error(
			array( 'message' => esc_html__( 'This history entry is not yours. Only an administrator can roll back or delete the changes of other users.', 'woo-bulk-editor' ) ),
			403
		);
	}

	// removing 1 row of data from the history
	private function delete( $table, $id, $field = 'id' ) {
		global $wpdb;

		// $field comes from internal calls only, but it is interpolated into the
		// statement, so it stays whitelisted rather than trusted
		$field = in_array( $field, array( 'id', 'bulk_key' ), true ) ? $field : 'id';
		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$field} = %s AND {$scope['sql']}",
				array_merge( array( $id ), $scope['args'] )
			)
		);
	}

	private function revert( $id ) {
		global $wpdb;

		remove_all_actions( 'woobe_before_update_page_field' );

		$scope = $this->scope();
		$solo  = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND {$scope['sql']}",
				array_merge( array( intval( $id ) ), $scope['args'] )
			),
			ARRAY_A
		);

		if ( ! empty( $solo ) ) {

			// The stock status recorded with a stock change belongs to it
			// (see stock_status_pair()): whichever of the two rows is
			// reverted, both are, the stock change first - while a product
			// tracks its stock, WooCommerce derives the status and would
			// overwrite the old one.
			$pair = $this->stock_status_pair( $solo );

			if ( $pair && 'stock_status' === $solo['field_key'] ) {
				$this->revert( $pair['id'] );
				return;
			}

			// The stock quantity recorded when management was switched off
			// belongs to that switch too: reverting it alone would write a
			// number WooCommerce drops again, so the whole switch is reverted.
			if ( 'stock_quantity' === $solo['field_key'] && ! ( is_null( $solo['prev_val'] ) || '' === $solo['prev_val'] ) ) {
				$owner = $this->stock_row_at( $solo, -2, 'manage_stock' );
				if ( $owner && $this->stock_status_pair( $owner ) ) {
					$this->revert( $owner['id'] );
					return;
				}
			}

			// the quantity WooCommerce dropped when management went off
			$dropped = ( $pair && 'manage_stock' === $solo['field_key'] ) ? $this->stock_row_at( $solo, 2, 'stock_quantity' ) : null;

			// a field this user can no longer see is not in his list; the
			// write below refuses it as before, without a notice on the way
			$fields     = $this->settings->get_fields();
			$field_type = isset( $fields[ $solo['field_key'] ]['field_type'] ) ? $fields[ $solo['field_key'] ]['field_type'] : '';

			switch ( $field_type ) {
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

			// An empty stock quantity is how WooCommerce keeps "this product
			// does not track stock" - most often a variable product whose
			// variations hold the stock. Written back as 0 it went through the
			// rule that a stock of 0 or less switches stock management on, and
			// the rollback left the product managed, at 0 and out of stock where
			// it had been on sale. So the revert switches management off again,
			// which is what the empty value recorded: WooCommerce then keeps no
			// quantity, and a variable product takes its stock status from its
			// variations as before.
			if ( 'stock_quantity' === $solo['field_key'] && ( is_null( $solo['prev_val'] ) || '' === $solo['prev_val'] ) ) {
				$this->untrack_stock( intval( $solo['product_id'] ) );
			} else {

				// fix when reverting to the empty value, for example set null to calendar field as date_on_sale_from
				if ( is_null( $solo['prev_val'] ) ) {
					$solo['prev_val'] = 0;
				}

				$this->products->update_page_field( $solo['product_id'], $solo['field_key'], $solo['prev_val'] );
			}

			// the quantity back before the status: with management on again
			// WooCommerce derives the status from it
			if ( $dropped ) {
				$this->products->update_page_field( $dropped['product_id'], 'stock_quantity', $dropped['prev_val'] );
				$this->delete( $this->table, $dropped['id'] );
			}

			// then the stock status the change had recorded, which now sticks
			if ( $pair ) {
				$this->products->update_page_field( $pair['product_id'], 'stock_status', is_null( $pair['prev_val'] ) ? 0 : $pair['prev_val'] );
				$this->delete( $this->table, $pair['id'] );
			}
			/*
				if (!empty($solo['bulk_key'])) {
				$this->count_bulked_products($solo['bulk_key'], 1, '-');
				}
			 *
			 */
		}

		$this->delete( $this->table, $id );
	}

	/**
	 * The two rows of one stock change. update_page_field() records the stock
	 * status as a second row when the change makes WooCommerce derive it
	 * again: a switch of stock management, or a stock of 0 or less on a
	 * product that did not track its stock, which switches management on. The
	 * two are written one after the other, so the status row is the next id -
	 * same product, same author, same way in, same bulk run, written at the
	 * same moment. A row that differs in any of these is not part of the pair.
	 *
	 * @param array $row a row of the history table
	 * @return array|null the other row of the pair, or null
	 */
	private function stock_status_pair( $row ) {
		global $wpdb;

		if ( 'stock_status' === $row['field_key'] ) {
			$other_id = intval( $row['id'] ) - 1;
			$keys     = array( 'manage_stock', 'stock_quantity' );
		} elseif ( 'manage_stock' === $row['field_key'] || ( 'stock_quantity' === $row['field_key'] && ( is_null( $row['prev_val'] ) || '' === $row['prev_val'] ) ) ) {
			// a stock quantity pairs only when it was empty before: only then
			// did the product not track its stock
			$other_id = intval( $row['id'] ) + 1;
			$keys     = array( 'stock_status' );
		} else {
			return null;
		}

		$scope = $this->scope();
		$other = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND {$scope['sql']}",
				array_merge( array( $other_id ), $scope['args'] )
			),
			ARRAY_A
		);

		if ( empty( $other ) || ! in_array( $other['field_key'], $keys, true ) ) {
			return null;
		}

		// the stock quantity side of a pair is one that was empty before
		if ( 'stock_quantity' === $other['field_key'] && ! ( is_null( $other['prev_val'] ) || '' === $other['prev_val'] ) ) {
			return null;
		}

		// mod_date is the time of writing plus up to 30 random seconds, and
		// the two rows of a pair are written in the same call
		if ( intval( $other['product_id'] ) !== intval( $row['product_id'] )
			|| intval( $other['user_id'] ) !== intval( $row['user_id'] )
			|| intval( $other['via_mcp'] ) !== intval( $row['via_mcp'] )
			|| (string) $other['bulk_key'] !== (string) $row['bulk_key']
			|| abs( intval( $other['mod_date'] ) - intval( $row['mod_date'] ) ) > 31 ) {
			return null;
		}

		return $other;
	}
	
	/**
	 * A row of the same stock change at a fixed distance from $row. A switch
	 * of stock management off writes three rows one after the other:
	 * manage_stock, stock_status, stock_quantity - so the quantity is the
	 * switch's id + 2. Same checks as stock_status_pair(): same product,
	 * author, way in, bulk run and moment.
	 *
	 * @param array  $row    a row of the history table
	 * @param int    $offset distance in ids (+2 or -2)
	 * @param string $key    the field key expected there
	 * @return array|null
	 */
	private function stock_row_at( $row, $offset, $key ) {
		global $wpdb;

		$scope = $this->scope();
		$other = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND {$scope['sql']}",
				array_merge( array( intval( $row['id'] ) + intval( $offset ) ), $scope['args'] )
			),
			ARRAY_A
		);

		if ( empty( $other ) || $key !== $other['field_key'] ) {
			return null;
		}

		if ( intval( $other['product_id'] ) !== intval( $row['product_id'] )
			|| intval( $other['user_id'] ) !== intval( $row['user_id'] )
			|| intval( $other['via_mcp'] ) !== intval( $row['via_mcp'] )
			|| (string) $other['bulk_key'] !== (string) $row['bulk_key']
			|| abs( intval( $other['mod_date'] ) - intval( $row['mod_date'] ) ) > 31 ) {
			return null;
		}

		return $other;
	}
	
	/**
	 * Every row of the change $row belongs to: the row itself, and for a
	 * stock change all of its rows - a switch of stock management writes
	 * manage_stock, stock_status and, when switched off, stock_quantity; a
	 * stock of 0 on an untracked product writes stock_quantity and
	 * stock_status. Found from whichever of them $row is.
	 *
	 * @param array $row a row of the history table
	 * @return array rows
	 */
	private function stock_change_rows( $row ) {

		$rows  = array( $row );
		$owner = null;

		if ( 'manage_stock' === $row['field_key'] ) {
			$owner = $row;
		} elseif ( 'stock_status' === $row['field_key'] ) {
			$pair = $this->stock_status_pair( $row );
			if ( $pair ) {
				$rows[] = $pair;
				if ( 'manage_stock' === $pair['field_key'] ) {
					$owner = $pair;
				}
			}
		} elseif ( 'stock_quantity' === $row['field_key'] ) {
			$pair = $this->stock_status_pair( $row );
			if ( $pair ) {
				$rows[] = $pair;
			} else {
				$owner = $this->stock_row_at( $row, -2, 'manage_stock' );
			}
		}

		if ( $owner ) {
			$rows[] = $owner;
			$rows[] = $this->stock_status_pair( $owner );
			$rows[] = $this->stock_row_at( $owner, 2, 'stock_quantity' );
		}

		return array_filter( $rows );
	}

	/**
	 * Puts a product back to not tracking its stock, the state an empty stock
	 * quantity in the history stands for. Allowed to whoever may edit the
	 * stock quantity, as any other revert of that field.
	 */
	private function untrack_stock( $product_id ) {

		if ( ! $this->products->is_current_user_can_edit_field( 'stock_quantity' ) ) {
			return;
		}

		$product = $this->products->get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$product->set_manage_stock( false );
		$product->save();

		do_action( 'woobe_after_update_page_field', $product_id, $product, 'stock_quantity', '', 'prop' );
	}

	private function wipe_history() {
		global $wpdb;

		// exactly what this user can see: everything for an administrator,
		// his own rows for anybody else
		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- scope placeholders only
		$wpdb->query( $this->prepare( "DELETE FROM {$this->table} WHERE {$scope['sql']}", $scope['args'] ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- scope placeholders only
		$wpdb->query( $this->prepare( "DELETE FROM {$this->table_bulk} WHERE {$scope['sql']}", $scope['args'] ) );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

		if ( ! $this->solo_in_scope( $id ) ) {
			$this->refuse();
		}

		$this->revert( $id );

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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$bulk_key = WOOBE_HELPER::sanitize_bulk_key( isset( $_REQUEST['bulk_key'] ) ? $_REQUEST['bulk_key'] : '' );

		if ( ! $this->bulk_in_scope( $bulk_key ) ) {
			$this->refuse();
		}

		die( esc_html( $this->count_bulk_rows( $bulk_key ) ) );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$bulk_key = WOOBE_HELPER::sanitize_bulk_key( isset( $_REQUEST['bulk_key'] ) ? $_REQUEST['bulk_key'] : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$limit = isset( $_REQUEST['limit'] ) ? intval( $_REQUEST['limit'] ) : 10;

		if ( ! $this->bulk_in_scope( $bulk_key ) ) {
			$this->refuse();
		}

		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} LIMIT %d",
				array_merge( array( $bulk_key ), $scope['args'], array( $limit ) )
			),
			ARRAY_A
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$this->revert( $r['id'] );
			}
		}

		// ***

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$removed_count = ( isset( $_REQUEST['removed_count'] ) ? intval( $_REQUEST['removed_count'] ) : 0 ) + $limit;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$total_count = isset( $_REQUEST['total_count'] ) ? intval( $_REQUEST['total_count'] ) : 0;

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

		// who made the change and how - read for administrators only, and
		// even then only narrowing, see get_history()
		$filters = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
			'who' => isset( $_REQUEST['who'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['who'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
			'via' => isset( $_REQUEST['via'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['via'] ) ) : '',
		);

		$data                         = array();
		$data['history']              = $this->get_history( $filters );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

		if ( ! $this->solo_in_scope( $id ) ) {
			$this->refuse();
		}

		$this->delete( $this->table, $id );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- nonce checked by WOOBE_HELPER::check_ajax_access() above, values cast or sanitized here
		$bulk_key = WOOBE_HELPER::sanitize_bulk_key( isset( $_REQUEST['bulk_key'] ) ? $_REQUEST['bulk_key'] : '' );

		if ( ! $this->bulk_in_scope( $bulk_key ) ) {
			$this->refuse();
		}

		$this->delete( $this->table, $bulk_key, 'bulk_key' );
		$this->delete( $this->table_bulk, $bulk_key, 'bulk_key' );
		exit;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// public entry points for the MCP extension: the ajax handlers above
	// cannot be reused because they verify a nonce that a REST request never
	// has. The same scope applies - to the acting identity of the request.

	/**
	 * The latest bulk operations the acting identity may see, each with its
	 * author label.
	 */
	public function bulk_operations( $limit = 20 ) {
		global $wpdb;

		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_bulk} WHERE {$scope['sql']} ORDER BY started DESC, id DESC LIMIT %d",
				array_merge( $scope['args'], array( max( 1, intval( $limit ) ) ) )
			),
			ARRAY_A
		);

		return $this->with_author_labels( (array) $rows );
	}

	/**
	 * How many revertible rows of a bulk operation the acting identity may
	 * roll back.
	 */
	public function count_bulk_rows( $bulk_key ) {
		global $wpdb;

		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		return intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']}", array_merge( array( $bulk_key ), $scope['args'] ) ) ) );
	}

	/**
	 * The products a bulk operation touched, within the same scope.
	 *
	 * @return int[]
	 */
	public function bulk_product_ids( $bulk_key ) {
		global $wpdb;

		$scope = $this->scope();

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
				$wpdb->prepare( "SELECT DISTINCT product_id FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']}", array_merge( array( $bulk_key ), $scope['args'] ) )
			)
		);
	}

	/**
	 * Reverts up to $limit rows of a bulk operation within the scope, and
	 * removes its head once nothing is left to revert - as the History tab
	 * does, so the operation does not linger in the list as an empty entry.
	 *
	 * @return int rows reverted
	 */
	public function revert_bulk_portion( $bulk_key, $limit = 200 ) {
		global $wpdb;

		$scope = $this->scope();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope placeholders only
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} LIMIT %d",
				array_merge( array( $bulk_key ), $scope['args'], array( intval( $limit ) ) )
			),
			ARRAY_A
		);

		$before = $this->count_bulk_rows( $bulk_key );

		foreach ( (array) $rows as $r ) {
			$this->revert( $r['id'] );
		}

		// the rows gone, not the ids read: the two rows of a stock change are
		// reverted together (stock_status_pair()), so a portion can take one
		// row more than its limit
		$n = $before - $this->count_bulk_rows( $bulk_key );

		if ( $n > 0 && 0 === $this->count_bulk_rows( $bulk_key ) ) {
			$this->delete( $this->table_bulk, $bulk_key, 'bulk_key' );
		}

		return $n;
	}
	// phpcs:enable
}
