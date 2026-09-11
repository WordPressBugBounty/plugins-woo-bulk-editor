<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Base for an extended tool pack.
 *
 * Everything the MCP server can do beyond bulk editing lives in a file dropped
 * into ext/mcp/tools/. Nothing registers it: the loader scans the folder, and
 * the only convention is that the file name is the class name -
 * orders.php holds WOOBE_MCP_TOOL_ORDERS, coupons.php holds
 * WOOBE_MCP_TOOL_COUPONS. Same rule the plugin already uses for its extensions,
 * so there is one thing to remember rather than two.
 *
 * Almost every pack asks the same two questions first: which period, and which
 * order statuses count as a sale. Those live here rather than in whichever pack
 * happened to need them first - otherwise every new file would depend on
 * orders.php, and two packs answering about the same month could disagree.
 *
 * Packs may write - orders, coupons, variations, terms, images all do. What
 * they cannot rely on is BEAR history: it covers product fields changed
 * through the bulk editor's own tools, and nothing else. So every writing
 * pack shows a preview and asks for confirmed before it touches anything,
 * and says plainly in its answer how - or whether - the change can be undone:
 * the trash, the previous values, or not at all.
 */
abstract class WOOBE_MCP_TOOL {

	// order states worth counting as a sale by default; refunded and cancelled
	// are deliberately absent, and failed and pending were never money
	const DEFAULT_STATUSES = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );

	/**
	 * @var WOOBE_MCP
	 */
	protected $mcp;

	public function __construct( $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Tool definitions keyed by tool name, in the same shape the core catalogue
	 * uses: name, description, inputSchema, annotations.
	 *
	 * Write the description for a reader who has never seen this shop - it is
	 * the only thing an agent has to decide whether to call the tool at all.
	 *
	 * @return array
	 */
	abstract public function tools();

	/**
	 * Runs one of them. Return an array, or a WP_Error whose message is aimed at
	 * the person rather than at a developer.
	 *
	 * @param string $name
	 * @param array  $args
	 * @return array|WP_Error
	 */
	abstract public function call( $name, $args );

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// products

	protected function ids( $args ) {
		return $this->mcp->ids_from( $args );
	}

	protected function products() {
		return $this->mcp->products;
	}

	protected function settings() {
		return $this->mcp->settings;
	}

	protected function decimals() {
		return wc_get_price_decimals();
	}

	/**
	 * A product name a human recognises.
	 *
	 * A variation's own post title is empty or a slug, so reporting one by id
	 * tells the owner nothing. This returns the parent title with the chosen
	 * attributes appended - "Sneakers (Size: 45, Colour: Orange)" - which is how
	 * he thinks about it and how he will describe it back to you.
	 */
	protected function product_label( $product_id ) {

		$product = $this->products()->get_product( $product_id );

		if ( ! $product ) {
			return '#' . intval( $product_id );
		}

		if ( ! $product->is_type( 'variation' ) ) {
			return $product->get_name();
		}

		$parent = wc_get_product( $product->get_parent_id() );
		$name   = $parent ? $parent->get_name() : $product->get_name();
		$bits   = array();

		foreach ( $product->get_variation_attributes() as $key => $value ) {

			if ( '' === $value ) {
				continue;
			}

			$taxonomy = str_replace( 'attribute_', '', $key );
			$label    = wc_attribute_label( $taxonomy );
			$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : false;

			$bits[] = $label . ': ' . ( $term ? $term->name : $value );
		}

		return empty( $bits ) ? $name : $name . ' (' . implode( ', ', $bits ) . ')';
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// period and statuses

	/**
	 * Dates and statuses resolved once, the same way for every pack - so two
	 * answers about the same month can never disagree with each other.
	 */
	protected function period( $args ) {

		// Read in the shop's time zone. The reports compare against
		// wc_order_stats.date_created, which WooCommerce stores in shop time;
		// strtotime() and gmdate() read "today" in UTC instead. On a shop
		// east of Greenwich that turned "today" into yesterday for the first
		// hours after midnight, and an order placed at 01:20 was missing from
		// a report asked for today.
		$tz = wp_timezone();

		try {
			$from_dt = new DateTimeImmutable( isset( $args['date_from'] ) ? (string) $args['date_from'] : '-30 days', $tz );
			$to_dt   = new DateTimeImmutable( isset( $args['date_to'] ) ? (string) $args['date_to'] : 'now', $tz );
		} catch ( Exception $e ) {
			return new WP_Error( 'woobe_mcp_bad_date', 'Could not read those dates.' );
		}

		// a bare day means the whole day: from its first second, and the end
		// date is inclusive - "to the 31st" means through the 31st
		$from_dt = $from_dt->setTimezone( $tz );
		$to_dt   = $to_dt->setTimezone( $tz )->setTime( 23, 59, 59 );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( isset( $args['date_from'] ) ? (string) $args['date_from'] : '' ) )
			|| in_array( strtolower( trim( isset( $args['date_from'] ) ? (string) $args['date_from'] : '' ) ), array( 'today', 'yesterday' ), true ) ) {
			$from_dt = $from_dt->setTime( 0, 0, 0 );
		}

		$from = $from_dt->getTimestamp();
		$to   = $to_dt->getTimestamp();

		$statuses = isset( $args['statuses'] ) && is_array( $args['statuses'] ) && ! empty( $args['statuses'] )
			? array_map( 'sanitize_text_field', $args['statuses'] )
			: self::DEFAULT_STATUSES;

		foreach ( $statuses as $k => $s ) {
			$statuses[ $k ] = ( 0 === strpos( $s, 'wc-' ) ) ? $s : 'wc-' . $s;
		}

		return array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => $statuses,
			// shop time, the same clock as the column they are compared with
			'sql_from' => $from_dt->format( 'Y-m-d H:i:s' ),
			'sql_to'   => $to_dt->format( 'Y-m-d H:i:s' ),
			'label'    => array(
				'from' => $from_dt->format( 'Y-m-d' ),
				'to'   => $to_dt->format( 'Y-m-d' ),
			),
		);
	}

	/**
	 * The period arguments every pack shares, so the schemas stay identical and
	 * an agent does not have to relearn them per tool.
	 */
	protected function period_schema() {

		return array(
			'date_from' => array(
				'type'        => 'string',
				'description' => 'Any format strtotime understands, e.g. 2026-08-01. Defaults to 30 days ago.',
			),
			'date_to'   => array(
				'type'        => 'string',
				'description' => 'Defaults to now. The whole of that day is included.',
			),
			'statuses'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Order statuses to count, e.g. ["wc-completed"]. Defaults to completed, processing and on-hold.',
			),
		);
	}

	protected function placeholders( $values, $type = '%s' ) {
		return implode( ',', array_fill( 0, count( $values ), $type ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// tables

	/**
	 * True when a table really exists. Analytics tables, custom order tables and
	 * third party tables are all optional - a pack that assumes one is there
	 * fails with a SQL error instead of an explanation.
	 */
	protected function table_exists( $table ) {

		global $wpdb;

		static $seen = array();

		if ( isset( $seen[ $table ] ) ) {
			return $seen[ $table ];
		}

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$seen[ $table ] = ( $found === $table );

		return $seen[ $table ];
	}

	/**
	 * The analytics order table, or an explanation. Most packs start here.
	 */
	protected function stats_table() {

		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_stats';

		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error(
				'woobe_mcp_no_analytics',
				'This shop has no WooCommerce Analytics tables, so sales figures are not available. They appear once Analytics is enabled and has finished importing historical orders.'
			);
		}

		return $table;
	}

	/**
	 * Which products a whole-catalogue report is about, as a plain id list the
	 * report then treats exactly like a selection.
	 *
	 * Reports used to take the whole catalogue as a selection - every product
	 * and every variation - and refused past 500 ids, so on any real shop the
	 * cases built on them failed every time. The question those cases ask is
	 * never "all 20,000 items": it is "the ones that sold" or "the ones that
	 * did not". Both are answered by the database directly, sorted and cut
	 * there, and only the rows that can make it into the answer are loaded.
	 *
	 * Ids are reported the way a selection with include_variations all would
	 * report them: a variation under its own id, a simple product under its.
	 *
	 * @param string $mode     'sold' - sold in the period; 'unsold' - published,
	 *                         not a variable parent, and sold nothing.
	 * @param array  $p        the period, from period().
	 * @param string $order_by for 'sold': units, revenue, orders or last_sale.
	 * @param int    $cap      most ids to return.
	 * @param bool   $net_of_refunds for 'sold': count refund rows against the
	 *                         units, as woobe_product_sales does; otherwise only
	 *                         what was sold, as the stock reports need.
	 * @return array|WP_Error  ids => list of ids, found => how many qualified
	 *                         before the cap.
	 */
	protected function catalogue_ids( $mode, $p, $order_by = 'units', $cap = 500, $net_of_refunds = false ) {

		global $wpdb;

		$stats  = $this->stats_table();
		$lookup = $this->lookup_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$cap       = max( 1, intval( $cap ) );
		$status_ph = $this->placeholders( $p['statuses'] );
		$qty_sql   = $net_of_refunds ? '' : ' AND l.product_qty > 0';

		// The id a row is reported under is IF( l.variation_id > 0,
		// l.variation_id, l.product_id ): a variation under its own id, a simple
		// product under its. It is written out in each query rather than kept
		// in a variable - it is fixed SQL, and a variable in the query string is
		// something every reviewer has to trace back before trusting.

		if ( 'sold' === $mode ) {

			$m = $this->money_sql( 's' );

			$orders = array(
				'units'     => 'SUM( l.product_qty )',
				'revenue'   => "SUM( l.product_net_revenue / {$m['rate']} )",
				'orders'    => 'COUNT( DISTINCT l.order_id )',
				'last_sale' => 'MAX( s.date_created )',
			);

			// whitelisted, never interpolated from raw input
			$metric = isset( $orders[ $order_by ] ) ? $orders[ $order_by ] : $orders['units'];
			$params = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

			$found = intval(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names and placeholders only
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT( DISTINCT IF( l.variation_id > 0, l.variation_id, l.product_id ) )
						   FROM {$lookup} AS l
						   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
						  WHERE s.status IN ({$status_ph})
							AND s.date_created BETWEEN %s AND %s
							{$qty_sql}",
						$params
					)
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT IF( l.variation_id > 0, l.variation_id, l.product_id ) AS rid
					   FROM {$lookup} AS l
					   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
					   {$m['join']}
					  WHERE s.status IN ({$status_ph})
						AND s.date_created BETWEEN %s AND %s
						{$qty_sql}
					  GROUP BY IF( l.variation_id > 0, l.variation_id, l.product_id )
					  ORDER BY {$metric} DESC, rid ASC
					  LIMIT %d",
					array_merge( $params, array( $cap ) )
				)
			);

			return array(
				'ids'   => array_map( 'intval', (array) $ids ),
				'found' => $found,
			);
		}

		if ( 'unsold' === $mode ) {

			$meta = $wpdb->prefix . 'wc_product_meta_lookup';

			// most stock first: the dead stock worth acting on is the one
			// tying up the most goods. Without the meta lookup table the order
			// falls back to the newest items, which is still a stable answer.
			$has_meta   = $this->table_exists( $meta );
			$meta_join  = $has_meta ? "LEFT JOIN {$meta} AS ml ON ml.product_id = p.ID" : '';
			$meta_order = $has_meta ? 'COALESCE( ml.stock_quantity, 0 ) DESC,' : '';

			// one statement for both counting and fetching, so they cannot
			// disagree about what "unsold" means
			$where = "p.post_status = 'publish'
					AND (
						( p.post_type = 'product'
						  AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} AS c WHERE c.post_parent = p.ID AND c.post_type = 'product_variation' ) )
						OR
						( p.post_type = 'product_variation'
						  AND EXISTS ( SELECT 1 FROM {$wpdb->posts} AS pp WHERE pp.ID = p.post_parent AND pp.post_status = 'publish' ) )
					)
					AND NOT EXISTS (
						SELECT 1
						  FROM {$lookup} AS l
						  INNER JOIN {$stats} AS s ON s.order_id = l.order_id
						 WHERE l.product_id = p.ID
						   AND l.product_qty > 0
						   AND s.status IN ({$status_ph})
						   AND s.date_created BETWEEN %s AND %s
					)
					AND NOT EXISTS (
						SELECT 1
						  FROM {$lookup} AS l
						  INNER JOIN {$stats} AS s ON s.order_id = l.order_id
						 WHERE l.variation_id = p.ID
						   AND l.product_qty > 0
						   AND s.status IN ({$status_ph})
						   AND s.date_created BETWEEN %s AND %s
					)";

			// Two subqueries rather than one with OR: a simple product's sales
			// sit under product_id, a variation's under variation_id, and each
			// column has its own index - an OR across them uses neither, which
			// on a large catalogue turns this into a scan per product.
			$one    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );
			$params = array_merge( $one, $one );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$found = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} AS p WHERE {$where}", $params ) ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					   FROM {$wpdb->posts} AS p
					   {$meta_join}
					  WHERE {$where}
					  ORDER BY {$meta_order} p.ID DESC
					  LIMIT %d",
					array_merge( $params, array( $cap ) )
				)
			);

			return array(
				'ids'   => array_map( 'intval', (array) $ids ),
				'found' => $found,
			);
		}

		return new WP_Error( 'woobe_mcp_bad_catalogue_mode', 'Unknown catalogue mode ' . $mode . '.' );
	}

	/**
	 * The schema entry every report that can span the catalogue offers.
	 */
	protected function whole_catalogue_schema( $what ) {

		return array(
			'type'        => 'boolean',
			'description' => 'Report across the whole catalogue instead of a selection - no find step needed. ' . $what . ' Use it for shop-wide questions; for "the red jackets" find them first and pass selection_id.',
		);
	}

	protected function lookup_table() {

		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error( 'woobe_mcp_no_analytics', 'The product lookup table is missing, so per product figures are not available.' );
		}

		return $table;
	}

	/**
	 * Whether the shop stores orders in the custom tables (HPOS) or in posts.
	 * Only matters for the few fields analytics does not carry.
	 */
	protected function hpos() {

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// money
	//
	// Amounts in the analytics tables are stored in whatever currency the order
	// was placed in, so on a multi currency shop a plain SUM adds euros to yen.
	// The conversion itself lives in ext/mcp/currencies/ - one file per
	// switcher, loaded the same way tool packs are. Nothing here knows which
	// switcher is installed, and adding support for another one changes no file
	// in this folder.

	/**
	 * The switcher driving this shop, or null when none is recognised.
	 */
	protected function currency_driver() {

		// discovery belongs to the driver layer; see WOOBE_MCP_CURRENCY::active()
		require_once WOOBE_PATH . 'ext/mcp/currency.php';

		return WOOBE_MCP_CURRENCY::active();
	}

	protected function base_currency() {

		$driver = $this->currency_driver();

		return $driver ? $driver->base_currency() : get_woocommerce_currency();
	}

	/**
	 * Join clauses and a divisor expression that bring an amount back to base
	 * currency. Without a driver both are inert, so single currency shops pay
	 * nothing for this.
	 *
	 * @param string $alias alias of the table holding order_id, e.g. 's' or 'l'.
	 */
	protected function money_sql( $alias = 's', $raw = false ) {

		$driver = $this->currency_driver();

		if ( ! $driver ) {
			return array(
				'join' => '',
				'rate' => '1',
			);
		}

		global $wpdb;

		$hpos = $this->hpos() && $this->table_exists( $wpdb->prefix . 'wc_orders' );

		return $driver->money_sql( $alias, $hpos, $raw );
	}

	/**
	 * What every money answer has to say about itself.
	 *
	 * A total without this is a number the owner cannot check. When no switcher
	 * is recognised and the shop clearly sells in several currencies, this is
	 * where he finds out why the figures look strange and what to do about it.
	 */
	protected function currency_status( $p = null ) {

		$base   = $this->base_currency();
		$driver = $this->currency_driver();

		if ( $driver ) {
			return array(
				'base_currency' => $base,
				'converted'     => true,
				'engine'        => $driver->name(),
				'note'          => $driver->conversion_note( $base ),
			);
		}

		$seen = $this->currencies_in_use( $p );

		if ( count( $seen ) > 1 ) {
			return array(
				'base_currency' => $base,
				'converted'     => false,
				'engine'        => null,
				'currencies'    => $seen,
				'note'          => 'This shop has orders in several currencies (' . implode( ', ', $seen ) . ') and no currency switcher this server knows how to read, so the amounts below add different currencies together and are NOT reliable. Say that to the user plainly before quoting any total. Converting them needs the rate each order was placed at, which only the switcher that took the payment holds. FOX - WooCommerce Currency Switcher stores that rate on every order and is supported out of the box: https://currency-switcher.com/ . With a different switcher installed, the shop owner should ask its developer which order meta key holds the rate and pass that to BEAR support - adding it is one small file, not a rewrite.',
			);
		}

		return array(
			'base_currency' => $base,
			'converted'     => false,
			'engine'        => null,
			'note'          => 'This shop sells in one currency, so nothing needs converting.',
		);
	}

	/**
	 * Distinct order currencies actually used in the period. Only asked when
	 * there is no driver to convert with.
	 */
	protected function currencies_in_use( $p = null ) {

		global $wpdb;

		$stats = $wpdb->prefix . 'wc_order_stats';

		if ( ! $this->table_exists( $stats ) ) {
			return array();
		}

		$hpos   = $this->hpos() && $this->table_exists( $wpdb->prefix . 'wc_orders' );
		$where  = '';
		$params = array();

		if ( is_array( $p ) ) {
			$where    = ' WHERE s.date_created BETWEEN %s AND %s';
			$params[] = $p['sql_from'];
			$params[] = $p['sql_to'];
		}

		if ( $hpos ) {
			$sql = "SELECT DISTINCT woo_o.currency
					  FROM {$stats} AS s
					  LEFT JOIN {$wpdb->prefix}wc_orders AS woo_o ON woo_o.id = s.order_id{$where}";
		} else {
			$sql = "SELECT DISTINCT woo_o.meta_value
					  FROM {$stats} AS s
					  LEFT JOIN {$wpdb->postmeta} AS woo_o ON woo_o.post_id = s.order_id AND woo_o.meta_key = '_order_currency'{$where}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = empty( $params ) ? $wpdb->get_col( $sql ) : $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		$out = array();

		foreach ( (array) $rows as $c ) {
			$c = strtoupper( trim( (string) $c ) );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}

		return array_values( array_unique( $out ) );
	}
}