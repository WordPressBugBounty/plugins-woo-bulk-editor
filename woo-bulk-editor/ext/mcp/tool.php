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
 * The hard rule for anything outside bulk editing: READ ONLY. Orders, coupons,
 * refunds, payments - a pack may look and must never write. Writing stays in
 * the bulk editor's own tools, where every change is recorded in the history
 * table and can be rolled back.
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

		$from = isset( $args['date_from'] ) ? strtotime( (string) $args['date_from'] ) : strtotime( '-30 days' );
		$to   = isset( $args['date_to'] ) ? strtotime( (string) $args['date_to'] ) : time();

		if ( ! $from || ! $to ) {
			return new WP_Error( 'woobe_mcp_bad_date', 'Could not read those dates.' );
		}

		// the end date is inclusive: "to the 31st" means through the 31st
		$to = strtotime( gmdate( 'Y-m-d 23:59:59', $to ) );

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
			'sql_from' => gmdate( 'Y-m-d H:i:s', $from ),
			'sql_to'   => gmdate( 'Y-m-d H:i:s', $to ),
			'label'    => array(
				'from' => gmdate( 'Y-m-d', $from ),
				'to'   => gmdate( 'Y-m-d', $to ),
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

		static $driver = false; // false means "not looked yet", null means "none"

		if ( false !== $driver ) {
			return $driver;
		}

		$driver = null;

		require_once WOOBE_PATH . 'ext/mcp/currency.php';

		$dirs = apply_filters(
			'woobe_mcp_currencies_dirs',
			array(
				WOOBE_PATH . 'ext' . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . 'currencies' . DIRECTORY_SEPARATOR,
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'woobe_mcp_currencies' . DIRECTORY_SEPARATOR,
			)
		);

		foreach ( $dirs as $dir ) {

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( (array) glob( $dir . '*.php' ) as $file ) {

				include_once $file;

				$class = 'WOOBE_MCP_CURRENCY_' . strtoupper( basename( $file, '.php' ) );

				if ( ! class_exists( $class ) || ! is_subclass_of( $class, 'WOOBE_MCP_CURRENCY' ) ) {
					continue;
				}

				$candidate = new $class();

				// first one that recognises the shop wins; two switchers at once
				// is a broken shop, not a case worth designing for
				if ( $candidate->is_active() ) {
					$driver = $candidate;
					return $driver;
				}
			}
		}

		return $driver;
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
	protected function money_sql( $alias = 's' ) {

		$driver = $this->currency_driver();

		if ( ! $driver ) {
			return array(
				'join' => '',
				'rate' => '1',
			);
		}

		global $wpdb;

		$hpos = $this->hpos() && $this->table_exists( $wpdb->prefix . 'wc_orders' );

		return $driver->money_sql( $alias, $hpos );
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
