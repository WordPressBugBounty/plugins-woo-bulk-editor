<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Sales the shop already knows about: per product, shop wide, and by shipping
 * method. Read only.
 *
 * Built on WooCommerce's analytics lookup tables and on the order items table
 * rather than on order objects: those are flat and indexed, they behave the
 * same under HPOS and the legacy post storage, and they hold no personal data.
 * Nothing here ever returns a name, an address or an email.
 */
final class WOOBE_MCP_TOOL_ORDERS extends WOOBE_MCP_TOOL {

	public function tools() {

		$period = $this->period_schema();

		return array(

			'woobe_product_sales' => array(
				'name'        => 'woobe_product_sales',
				'description' => 'Sales figures for specific products over a date range: how many orders included the product, how many units, how much revenue, and when it last sold. Takes a selection_id from woobe_find_products or an explicit id list, so you can ask about "the red jackets" by finding them first.',
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
							'include_zero' => array(
								'type'        => 'boolean',
								'description' => 'Include products that sold nothing in the period. Use this to find dead stock.',
							),
							'order_by'     => array(
								'type' => 'string',
								'enum' => array( 'revenue', 'units', 'orders', 'last_sale' ),
							),
							'limit'        => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_sales_summary' => array(
				'name'        => 'woobe_sales_summary',
				'description' => 'Money totals for a period: net revenue on goods, shipping collected, tax collected, gross paid, order count, units, average order value, and how many orders came from returning customers. Optionally broken down by day, week or month for a trend.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$period,
						array(
							'group_by' => array(
								'type'        => 'string',
								'enum'        => array( 'none', 'day', 'week', 'month' ),
								'description' => 'Defaults to none, which returns a single total.',
							),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_shipping_breakdown' => array(
				'name'        => 'woobe_shipping_breakdown',
				'description' => 'Which shipping methods customers actually chose in a period: name, how many orders, what share of orders, and how much was collected for each. The amounts are what customers paid, not what the carrier charged the shop - WooCommerce does not store the second number.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => $period,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_product_sales':
				return $this->product_sales( $args );
			case 'woobe_sales_summary':
				return $this->sales_summary( $args );
			case 'woobe_shipping_breakdown':
				return $this->shipping_breakdown( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function product_sales( $args ) {

		global $wpdb;

		$stats  = $this->stats_table();
		$lookup = $this->lookup_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$ids = $this->ids( $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_products', 'No products to report on.' );
		}

		// a report over tens of thousands of products is not something anybody
		// reads; narrow the selection instead
		if ( count( $ids ) > 500 ) {
			return new WP_Error(
				'woobe_mcp_too_many_products',
				'That selection holds ' . count( $ids ) . ' products. Ask for sales on at most 500 at a time - narrow the filter first.'
			);
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$id_ph     = $this->placeholders( $ids, '%d' );
		$status_ph = $this->placeholders( $p['statuses'] );

		$params = array_merge(
			array_map( 'intval', $ids ),
			$p['statuses'],
			array( $p['sql_from'], $p['sql_to'] )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id,
						COUNT( DISTINCT l.order_id ) AS orders,
						SUM( l.product_qty )         AS units,
						SUM( l.product_net_revenue / {$m['rate']} ) AS net,
						MAX( s.date_created )        AS last_sale
				   FROM {$lookup} AS l
				   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				   {$m['join']}
				  WHERE l.product_id IN ({$id_ph})
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY l.product_id",
				$params
			),
			ARRAY_A
		);

		$by_id = array();

		foreach ( (array) $rows as $r ) {
			$by_id[ intval( $r['product_id'] ) ] = $r;
		}

		$include_zero = ! empty( $args['include_zero'] );
		$out          = array();
		$total_units  = 0;
		$total_net    = 0.0;

		foreach ( $ids as $product_id ) {

			$product_id = intval( $product_id );
			$has        = isset( $by_id[ $product_id ] );

			if ( ! $has && ! $include_zero ) {
				continue;
			}

			$units = $has ? intval( $by_id[ $product_id ]['units'] ) : 0;
			$net   = $has ? round( floatval( $by_id[ $product_id ]['net'] ), $this->decimals() ) : 0;

			$total_units += $units;
			$total_net   += $net;

			$out[] = array(
				'id'        => $product_id,
				'title'     => $this->product_label( $product_id ),
				'sku'       => $this->products()->get_post_field( $product_id, 'sku' ),
				'orders'    => $has ? intval( $by_id[ $product_id ]['orders'] ) : 0,
				'units'     => $units,
				'net'       => $net,
				'last_sale' => $has ? $by_id[ $product_id ]['last_sale'] : null,
			);
		}

		$order_by = isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'revenue';

		usort(
			$out,
			function ( $a, $b ) use ( $order_by ) {

				if ( 'units' === $order_by ) {
					return $b['units'] <=> $a['units'];
				}

				if ( 'orders' === $order_by ) {
					return $b['orders'] <=> $a['orders'];
				}

				if ( 'last_sale' === $order_by ) {
					return strcmp( (string) $b['last_sale'], (string) $a['last_sale'] );
				}

				return $b['net'] <=> $a['net'];
			}
		);

		$limit = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;

		return array(
			'period'         => $p['label'],
			'statuses'       => $p['statuses'],
			'currency'       => $this->currency_status( $p ),
			'products_asked' => count( $ids ),
			'products_sold'  => count( $by_id ),
			'total_units'    => $total_units,
			'total_net'      => round( $total_net, $this->decimals() ),
			'rows'           => array_slice( $out, 0, $limit ),
			'note'           => 'Net revenue excludes tax and shipping and is what WooCommerce Analytics itself reports. Products missing from the rows sold nothing in this period unless include_zero was set.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function sales_summary( $args ) {

		global $wpdb;

		$stats = $this->stats_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$group_by = isset( $args['group_by'] ) ? sanitize_key( $args['group_by'] ) : 'none';

		// whitelisted, never interpolated from raw input
		$buckets = array(
			'day'   => "DATE_FORMAT( s.date_created, '%%Y-%%m-%%d' )",
			'week'  => "DATE_FORMAT( s.date_created, '%%x-W%%v' )",
			'month' => "DATE_FORMAT( s.date_created, '%%Y-%%m' )",
		);

		$select_bucket = isset( $buckets[ $group_by ] ) ? $buckets[ $group_by ] . ' AS bucket,' : "'all' AS bucket,";
		$group_clause  = isset( $buckets[ $group_by ] ) ? 'GROUP BY bucket ORDER BY bucket ASC' : '';

		$m         = $this->money_sql( 's' );
		$status_ph = $this->placeholders( $p['statuses'] );
		$params    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

		// parent_id = 0 keeps refund rows out: they are separate negative orders
		// pointing at their parent, and counting them here would both understate
		// revenue and invent orders that never happened
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select_bucket}
						COUNT( * )                  AS orders,
						SUM( s.net_total / {$m['rate']} )      AS net,
						SUM( s.total_sales / {$m['rate']} )    AS gross,
						SUM( s.shipping_total / {$m['rate']} ) AS shipping,
						SUM( s.tax_total / {$m['rate']} )      AS tax,
						SUM( s.num_items_sold )     AS items,
						SUM( s.returning_customer ) AS returning_orders
				   FROM {$stats} AS s
				   {$m['join']}
				  WHERE s.parent_id = 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  {$group_clause}",
				$params
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $r ) {

			$orders = intval( $r['orders'] );

			$out[] = array(
				'bucket'           => $r['bucket'],
				'orders'           => $orders,
				'items'            => intval( $r['items'] ),
				'net'              => round( floatval( $r['net'] ), $this->decimals() ),
				'shipping'         => round( floatval( $r['shipping'] ), $this->decimals() ),
				'tax'              => round( floatval( $r['tax'] ), $this->decimals() ),
				'gross'            => round( floatval( $r['gross'] ), $this->decimals() ),
				'average_order'    => $orders ? round( floatval( $r['gross'] ) / $orders, $this->decimals() ) : 0,
				'returning_orders' => intval( $r['returning_orders'] ),
			);
		}

		return array(
			'period'   => $p['label'],
			'statuses' => $p['statuses'],
			'currency' => $this->currency_status( $p ),
			'group_by' => $group_by,
			'rows'     => $out,
			'note'     => 'net is revenue on goods after discounts, without shipping or tax. gross is what customers actually paid. shipping is what customers were charged for delivery - what the shop paid its carrier is not stored anywhere in WooCommerce, so profit on shipping cannot be derived from this.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function shipping_breakdown( $args ) {

		global $wpdb;

		$stats = $this->stats_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// core WooCommerce and identical under HPOS and the legacy storage,
		// which is exactly why the method is read from here and not from meta
		if ( ! $this->table_exists( $items ) || ! $this->table_exists( $itemmeta ) ) {
			return new WP_Error( 'woobe_mcp_no_order_items', 'The order items tables are missing on this shop.' );
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$status_ph = $this->placeholders( $p['statuses'] );
		$params    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.order_item_name            AS method,
						MAX( mid.meta_value )         AS method_id,
						COUNT( DISTINCT oi.order_id ) AS orders,
						SUM( CAST( COALESCE( mc.meta_value, 0 ) AS DECIMAL(18,6) ) / {$m['rate']} ) AS collected
				   FROM {$items} AS oi
				   INNER JOIN {$stats} AS s ON s.order_id = oi.order_id
				   {$m['join']}
				   LEFT JOIN {$itemmeta} AS mc  ON mc.order_item_id  = oi.order_item_id AND mc.meta_key  = 'cost'
				   LEFT JOIN {$itemmeta} AS mid ON mid.order_item_id = oi.order_item_id AND mid.meta_key = 'method_id'
				  WHERE oi.order_item_type = 'shipping'
					AND s.parent_id = 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY oi.order_item_name
				  ORDER BY orders DESC",
				$params
			),
			ARRAY_A
		);

		$total_orders = 0;

		foreach ( (array) $rows as $r ) {
			$total_orders += intval( $r['orders'] );
		}

		$out = array();

		foreach ( (array) $rows as $r ) {

			$orders = intval( $r['orders'] );

			$out[] = array(
				'method'    => $r['method'],
				'method_id' => $r['method_id'],
				'orders'    => $orders,
				'share'     => $total_orders ? round( $orders * 100 / $total_orders, 1 ) : 0,
				'collected' => round( floatval( $r['collected'] ), $this->decimals() ),
			);
		}

		return array(
			'period'         => $p['label'],
			'statuses'       => $p['statuses'],
			'currency'       => $this->currency_status( $p ),
			'orders_shipped' => $total_orders,
			'rows'           => $out,
			'note'           => 'share is the percentage of shipped orders using that method. Orders with no shipping line at all - virtual goods, or pickup configured without a shipping line - do not appear, so orders_shipped can be lower than the order count for the period.',
		);
	}
}
