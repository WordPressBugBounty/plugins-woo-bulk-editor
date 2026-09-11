<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Where sales meet the catalogue: how fast things sell against what is left on
 * the shelf, and what the margin is when the shop bothers to record cost.
 * Read only - the answers feed straight into a bulk edit.
 *
 * Both tools deliberately return the product ids they report on, so the agent
 * can hand them to woobe_find_products and go on to change prices or stock in
 * the same conversation. That round trip is the whole point of putting sales
 * data inside a bulk editor rather than in yet another analytics screen.
 */
final class WOOBE_MCP_TOOL_STOCK extends WOOBE_MCP_TOOL {

	// where WooCommerce keeps cost of goods sold when the feature is in use
	const COGS_META = '_cogs_total_value';

	// Most items a whole-catalogue run looks at. Each one is loaded as a
	// product object, and past this the answer gets slow without getting
	// better: the rows that matter are the busiest ones, and those come first.
	const CATALOGUE_CAP = 1000;

	public function tools() {

		$period = $this->period_schema();

		return array(

			'woobe_stock_velocity' => array(
				'name'        => 'woobe_stock_velocity',
				'description' => 'How fast products sell against what is left in stock: units sold in the period, units per day, stock on hand, and an estimate of how many days of stock remain at that rate. Use it for "what am I about to run out of" and, with slow_only, for "what has been sitting there for months".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$period,
						array(
							'selection_id' => array( 'type' => 'string' ),
							'ids'          => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'whole_catalogue' => $this->whole_catalogue_schema( 'Looks at every product and variation that sold in the period - the busiest ' . self::CATALOGUE_CAP . ' at most - or, with slow_only, at every published one that sold nothing, most stock first.' ),
							'managed_only' => array(
								'type'        => 'boolean',
								'description' => 'Only products with stock management enabled. Defaults to true - days of stock is meaningless without a stock number.',
							),
							'slow_only'    => array(
								'type'        => 'boolean',
								'description' => 'Only products that sold nothing in the period. This is the dead stock list.',
							),
							'moving_only'  => array(
								'type'        => 'boolean',
								'description' => 'Only products that sold at least one unit in the period. Use it when the question is "what am I about to run out of": a product with no sales has no depletion rate, so it can never run out and only crowds the answer. The opposite of slow_only.',
							),
							'keep_parents' => array(
								'type'        => 'boolean',
								'description' => 'Include variable parents. Off by default and rarely worth turning on: a parent holds no stock and sells nothing itself, so it reports as dead stock even when its variations are the best sellers in the shop.',
							),
							'limit'        => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_margin' => array(
				'name'        => 'woobe_margin',
				'description' => 'Gross margin per product for a period, from revenue minus cost of goods. Only works where the shop records a cost on the product - the answer always says how many of the products asked about actually had one, and you must repeat that to the user. WooCommerce does not store what the shop paid its suppliers anywhere else, so where cost is missing no margin can be computed and none is guessed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$period,
						array(
							'selection_id' => array( 'type' => 'string' ),
							'ids'          => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'whole_catalogue' => $this->whole_catalogue_schema( 'Looks at every product and variation that sold in the period - the top ' . self::CATALOGUE_CAP . ' by revenue at most.' ),
							'order_by'     => array(
								'type' => 'string',
								'enum' => array( 'margin', 'percent', 'revenue' ),
							),
							'limit'        => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_stock_velocity':
				return $this->stock_velocity( $args );
			case 'woobe_margin':
				return $this->margin( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * Units sold per product id over the period, as a flat map.
	 * Shared by both tools here so they cannot disagree about the same window.
	 */
	private function units_sold( $ids, $p ) {

		global $wpdb;

		$stats  = $this->stats_table();
		$lookup = $this->lookup_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$m         = $this->money_sql( 's' );
		$id_ph     = $this->placeholders( $ids, '%d' );
		$status_ph = $this->placeholders( $p['statuses'] );

		// A variation is filed under its parent's id in product_id and its own
		// in variation_id; match both and report under whichever was asked for,
		// or every variation shows zero sales and "never runs out".
		// Placeholders in SQL order: select, where x2, statuses, dates, group.
		// The expression is repeated in GROUP BY on purpose: MySQL resolves a
		// GROUP BY name against the table columns before the select aliases,
		// so GROUP BY product_id would group by l.product_id again.
		$int_ids = array_map( 'intval', $ids );

		$params = array_merge(
			$int_ids,
			$int_ids,
			$int_ids,
			$p['statuses'],
			array( $p['sql_from'], $p['sql_to'] ),
			$int_ids
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT IF( l.variation_id IN ({$id_ph}), l.variation_id, l.product_id ) AS product_id,
						SUM( l.product_qty )         AS units,
						SUM( l.product_net_revenue / {$m['rate']} ) AS net
				   FROM {$lookup} AS l
				   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				   {$m['join']}
				  WHERE ( l.product_id IN ({$id_ph}) OR l.variation_id IN ({$id_ph}) )
					AND l.product_qty > 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY IF( l.variation_id IN ({$id_ph}), l.variation_id, l.product_id )",
				$params
			),
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $rows as $r ) {
			$map[ intval( $r['product_id'] ) ] = array(
				'units' => intval( $r['units'] ),
				'net'   => floatval( $r['net'] ),
			);
		}

		return $map;
	}

	/**
	 * The products a report covers, and how they were chosen.
	 *
	 * With whole_catalogue the database picks them - see catalogue_ids() in
	 * tool.php - and the report treats them exactly like a selection.
	 *
	 * @return array|WP_Error ids, whole (bool), found (how many qualified)
	 */
	private function pick( $args, $p, $mode, $order_by ) {

		if ( empty( $args['whole_catalogue'] ) ) {

			$ids = $this->scope( $args );

			return is_wp_error( $ids ) ? $ids : array(
				'ids'   => $ids,
				'whole' => false,
				'found' => count( $ids ),
			);
		}

		$picked = $this->catalogue_ids( $mode, $p, $order_by, self::CATALOGUE_CAP, false );

		if ( is_wp_error( $picked ) ) {
			return $picked;
		}

		return array(
			'ids'   => $picked['ids'],
			'whole' => true,
			'found' => $picked['found'],
		);
	}

	/**
	 * The scope line of an answer, so the agent can say what was looked at.
	 */
	private function scope_text( $pick, $mode ) {

		if ( ! $pick['whole'] ) {
			return 'selection';
		}

		$what = ( 'unsold' === $mode ) ? 'published items that sold nothing in the period' : 'items that sold in the period';

		return ( $pick['found'] > count( $pick['ids'] ) )
			? 'whole catalogue: ' . $pick['found'] . ' ' . $what . ', the first ' . count( $pick['ids'] ) . ' looked at - say that this is not all of them'
			: 'whole catalogue: all ' . $pick['found'] . ' ' . $what;
	}

	private function scope( $args ) {

		$ids = $this->ids( $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_products', 'No products to report on.' );
		}

		if ( count( $ids ) > 500 ) {
			return new WP_Error(
				'woobe_mcp_too_many_products',
				'That selection holds ' . count( $ids ) . ' products. Ask about at most 500 at a time - narrow the filter first, or pass whole_catalogue for a shop-wide answer.'
			);
		}

		return $ids;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function stock_velocity( $args ) {

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		// dead stock is the products that did NOT sell; everything else here
		// is about the ones that did
		$mode = ! empty( $args['slow_only'] ) ? 'unsold' : 'sold';
		$pick = $this->pick( $args, $p, $mode, 'units' );

		if ( is_wp_error( $pick ) ) {
			return $pick;
		}

		$ids = $pick['ids'];

		if ( empty( $ids ) ) {
			return array(
				'period' => $p['label'],
				'scope'  => $this->scope_text( $pick, $mode ),
				'rows'   => array(),
				'note'   => 'unsold' === $mode ? 'Every published item sold something in this period.' : 'Nothing sold in this period.',
			);
		}

		$sold = $this->units_sold( $ids, $p );

		if ( is_wp_error( $sold ) ) {
			return $sold;
		}

		$days = max( 1, ceil( ( $p['to'] - $p['from'] ) / DAY_IN_SECONDS ) );

		$managed_only = isset( $args['managed_only'] ) ? (bool) $args['managed_only'] : true;
		$slow_only    = ! empty( $args['slow_only'] );
		$moving_only  = ! empty( $args['moving_only'] );
		$keep_parents = ! empty( $args['keep_parents'] );

		$out = array();

		foreach ( $ids as $product_id ) {

			$product_id = intval( $product_id );
			$product    = $this->products()->get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			// A variable parent is a container: the stock and the sales belong
			// to its variations, which are separate rows in this same report.
			// Left in, it reports zero sales forever and lands at the top of
			// every dead stock list - telling the owner to discount the product
			// whose variations are his best sellers.
			if ( ! $keep_parents && $product->is_type( 'variable' ) ) {
				continue;
			}

			// WooCommerce answers 'parent' for a variation that inherits its
			// parent's stock, not false. The number is real but shared across
			// every variation of that product, so it is worth naming rather
			// than folding into a boolean and reading as "13 left of this size".
			$managed_raw = $product->managing_stock();
			$managed     = ( false !== $managed_raw && '' !== $managed_raw );

			if ( $managed_only && ! $managed ) {
				continue;
			}

			$units = isset( $sold[ $product_id ] ) ? $sold[ $product_id ]['units'] : 0;

			if ( $slow_only && $units > 0 ) {
				continue;
			}

			// nothing sold means no rate, and no rate means the product cannot
			// run out - it belongs in the dead stock answer, not in a list of
			// what to reorder
			if ( $moving_only && $units < 1 ) {
				continue;
			}

			$per_day = $units / $days;

			// null here means "not tracked", not "none left" - the two read the
			// same in a table and mean opposite things, so the row says which
			$stock   = $managed ? intval( $product->get_stock_quantity() ) : null;

			$out[] = array(
				'id'             => $product_id,
				'title'          => $this->product_label( $product_id ),
				'sku'            => $product->get_sku(),
				'units_sold'     => $units,
				'units_per_day'   => round( $per_day, 3 ),
				// the same rate at the scales people actually think in: nobody
				// plans a reorder around 0.072 units a day, but two a month is
				// a sentence a shop owner can act on
				'units_per_week'  => round( $per_day * 7, 2 ),
				'units_per_month' => round( $per_day * 30, 2 ),
				'stock'           => $stock,
				'stock_tracked'   => ( 'parent' === $managed_raw ) ? 'shared with the other variations of this product' : $managed,
				'days_of_stock'   => ( $per_day > 0 && ! is_null( $stock ) ) ? round( $stock / $per_day ) : null,
				'stock_status'    => $product->get_stock_status(),
			);
		}

		// soonest to run out first; products that will never run out at this
		// rate sink to the bottom rather than sorting as zero
		usort(
			$out,
			function ( $a, $b ) {

				$x = is_null( $a['days_of_stock'] ) ? PHP_INT_MAX : $a['days_of_stock'];
				$y = is_null( $b['days_of_stock'] ) ? PHP_INT_MAX : $b['days_of_stock'];

				return $x <=> $y;
			}
		);

		$limit = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;

		return array(
			'period'         => $p['label'],
			'days_in_period' => $days,
			'scope'          => $this->scope_text( $pick, $mode ),
			'products_asked' => count( $ids ),
			'rows'           => array_slice( $out, 0, $limit ),
			'ids'            => wp_list_pluck( array_slice( $out, 0, $limit ), 'id' ),
			'note'           => 'Variable parents are excluded: they hold no stock and sell nothing themselves, their variations do and appear here as their own rows. stock_tracked says whether the number is this product\'s own, shared with its sibling variations, or not tracked at all - null stock means untracked, which is not the same as none left. units_per_week and units_per_month are the same rate rescaled, not separate measurements. days_of_stock is stock divided by the average daily rate over this period, so it assumes demand stays flat - it is a warning sign, not a forecast. It is null when the product sold nothing or does not manage stock. Pass the ids to woobe_find_products if the user wants to act on this list.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function margin( $args ) {

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		// margin only exists for what sold; the biggest earners first, so a
		// capped run still covers the money that matters
		$pick = $this->pick( $args, $p, 'sold', 'revenue' );

		if ( is_wp_error( $pick ) ) {
			return $pick;
		}

		$ids = $pick['ids'];

		if ( empty( $ids ) ) {
			return array(
				'period' => $p['label'],
				'scope'  => $this->scope_text( $pick, 'sold' ),
				'rows'   => array(),
				'note'   => 'Nothing sold in this period, so there is no margin to report.',
			);
		}

		$sold = $this->units_sold( $ids, $p );

		if ( is_wp_error( $sold ) ) {
			return $sold;
		}

		$out           = array();
		$with_cost     = 0;
		$total_net     = 0.0;
		$total_cost    = 0.0;
		$skipped_sold  = 0;

		foreach ( $ids as $product_id ) {

			$product_id = intval( $product_id );

			if ( ! isset( $sold[ $product_id ] ) ) {
				continue;
			}

			$units = $sold[ $product_id ]['units'];
			$net   = round( $sold[ $product_id ]['net'], $this->decimals() );
			$cost  = $this->unit_cost( $product_id );

			if ( is_null( $cost ) ) {
				++$skipped_sold;
				continue;
			}

			++$with_cost;

			$cost_total = round( $cost * $units, $this->decimals() );
			$margin     = round( $net - $cost_total, $this->decimals() );

			$total_net  += $net;
			$total_cost += $cost_total;

			$out[] = array(
				'id'         => $product_id,
				'title'      => $this->product_label( $product_id ),
				'sku'        => $this->products()->get_post_field( $product_id, 'sku' ),
				'units'      => $units,
				'net'        => $net,
				'unit_cost'  => round( $cost, $this->decimals() ),
				'cost_total' => $cost_total,
				'margin'     => $margin,
				'percent'    => $net > 0 ? round( $margin * 100 / $net, 1 ) : null,
			);
		}

		$order_by = isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'margin';

		usort(
			$out,
			function ( $a, $b ) use ( $order_by ) {

				if ( 'percent' === $order_by ) {
					return floatval( $a['percent'] ) <=> floatval( $b['percent'] );
				}

				if ( 'revenue' === $order_by ) {
					return $b['net'] <=> $a['net'];
				}

				return $a['margin'] <=> $b['margin'];
			}
		);

		$limit  = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;
		$margin = round( $total_net - $total_cost, $this->decimals() );

		return array(
			'period'            => $p['label'],
			'currency'          => $this->currency_status( $p ),
			'scope'             => $this->scope_text( $pick, 'sold' ),
			'products_asked'    => count( $ids ),
			'products_sold'     => count( $sold ),
			'products_costed'   => $with_cost,
			'sold_without_cost' => $skipped_sold,
			'total_net'         => round( $total_net, $this->decimals() ),
			'total_cost'        => round( $total_cost, $this->decimals() ),
			'total_margin'      => $margin,
			'total_percent'     => $total_net > 0 ? round( $margin * 100 / $total_net, 1 ) : null,
			'rows'              => array_slice( $out, 0, $limit ),
			'note'              => 'Sorted worst margin first by default, because that is the list worth acting on. These totals cover only the products that have a cost recorded: sold_without_cost is how many sold with no cost on file and are absent from every figure here. Say that number out loud - a margin computed over part of the catalogue is not the shop\'s margin.',
		);
	}

	/**
	 * Cost per unit, or null when the shop does not record one.
	 *
	 * Prefers WooCommerce's own accessor where the build has cost of goods sold,
	 * and falls back to the meta key for older versions. Null is a real answer
	 * here and must never become zero - a zero cost would silently report the
	 * entire revenue as profit.
	 */
	private function unit_cost( $product_id ) {

		$product = $this->products()->get_product( $product_id );

		if ( ! $product ) {
			return null;
		}

		if ( method_exists( $product, 'get_cogs_value' ) ) {

			$value = $product->get_cogs_value();

			if ( '' !== $value && ! is_null( $value ) ) {
				return floatval( $value );
			}
		}

		$meta = get_post_meta( $product_id, self::COGS_META, true );

		if ( '' === $meta || is_null( $meta ) ) {
			return null;
		}

		return floatval( $meta );
	}
}