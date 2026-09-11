<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Orders: the figures, and the orders themselves.
 *
 * The reporting half is built on WooCommerce's analytics lookup tables and on
 * the order items table rather than on order objects: those are flat, indexed,
 * behave the same under HPOS and the legacy post storage, and hold no personal
 * data at all.
 *
 * The order half cannot avoid personal data and does not pretend to. A shop
 * owner asking which parcels to send needs the name and the address, and an
 * assistant that answers "I can see six orders but not who they are for" is
 * useless to him. So names, addresses and payment methods are returned when an
 * order is asked about - and only then. The listing gives a town and a country;
 * the full address comes with the single order, when somebody is actually
 * packing it.
 *
 * Emails and phone numbers are never returned. They are what a leaked
 * transcript turns into a mailing list, and nothing in day to day order work
 * needs them: contacting a customer happens through the order page, where the
 * shop already logs who did it.
 */
final class WOOBE_MCP_TOOL_ORDERS extends WOOBE_MCP_TOOL {

	public function tools() {

		$period = $this->period_schema();

		return array(

			'woobe_product_sales' => array(
				'name'        => 'woobe_product_sales',
				'description' => 'Sales figures for products over a date range: how many orders included the product, how many units, how much revenue, and when it last sold. Takes a selection_id from woobe_find_products or an explicit id list, so you can ask about "the red jackets" by finding them first - or whole_catalogue for "what sells best" across the shop.',
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
								'description' => 'Include products that sold nothing in the period. Use this to find dead stock within a selection; across the whole shop, woobe_stock_velocity with whole_catalogue and slow_only is the tool for it.',
							),
							'whole_catalogue' => $this->whole_catalogue_schema( 'Returns the best sellers of the period by order_by, each variation as its own row, up to limit.' ),
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

			'woobe_orders' => array(
				'name'        => 'woobe_orders',
				'description' => 'The orders themselves, as a list: number, date, status, customer name, town, what was ordered, how it is being shipped and what it came to. This is the working view - "what came in today", "what is still waiting to be sent", "anything stuck on hold". Render it as a table; that is what the user is picturing when he asks.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'date_from' => array(
							'type'        => 'string',
							'description' => 'Anything strtotime reads - "today", "-7 days", "2026-09-01". Defaults to 30 days ago.',
						),
						'date_to'   => array( 'type' => 'string' ),
						'statuses'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Order statuses, with or without the wc- prefix: ["processing","on-hold"]. Leave it out for every status except trashed; ["trash"] lists the orders in the trash.',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Matches a customer name, an order number or an address.',
						),
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'Only orders containing this product.',
						),
						'customer_id' => array( 'type' => 'integer' ),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'Defaults to 25. Keep it small: every row has to be written out into the reply.',
						),
						'page'      => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_order' => array(
				'name'        => 'woobe_order',
				'description' => 'One order in full: every line with its quantity and price, the totals, both addresses, how it was paid and shipped, the notes and when the status last changed. Use it when the user is dealing with a particular order rather than scanning the day.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array(
							'type'        => 'integer',
							'description' => 'The order id, which is normally the number the customer sees.',
						),
					),
					'required'   => array( 'order_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_top_customers' => array(
				'name'        => 'woobe_top_customers',
				'description' => 'Who spent the most in a period: name, town, how many orders, how much, and when they last bought. Answers "who are my best customers" and "has anyone stopped ordering". No contact details - the order page has those.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$period,
						array(
							'limit' => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_set_order_status' => array(
				'name'        => 'woobe_set_order_status',
				'description' => 'Moves orders to another status - marking a batch as completed after a post office run, putting something on hold while a query is sorted out. Say which orders and which status before doing it, and name them back afterwards. A status change is reversible by changing it again, but it can send the customer an email on the way, so it is not silent.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'pending, processing, on-hold, completed, cancelled, refunded or failed. The wc- prefix is optional.',
						),
						'note'      => array(
							'type'        => 'string',
							'description' => 'Optional note recorded with the change, for whoever reads the order later.',
						),
					),
					'required'   => array( 'order_ids', 'status' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_order_note' => array(
				'name'        => 'woobe_order_note',
				'description' => 'Adds a note to an order. A private note is for the shop; a customer note is emailed to the buyer, so read it back before sending and never write one on your own initiative.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer' ),
						'note'     => array( 'type' => 'string' ),
						'to_customer' => array(
							'type'        => 'boolean',
							'description' => 'False by default. True emails the note to the buyer - only ever with the user\'s explicit say so, in his words.',
						),
					),
					'required'   => array( 'order_id', 'note' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_create_order' => array(
				'name'        => 'woobe_create_order',
				'description' => 'Creates an order by hand - a phone order, a wholesale one, a replacement for something that arrived broken. Call it once without confirmed to see the lines, the totals and what it will do to stock, read that back, and only then confirm. Built with WooCommerce\'s own order object, so tax, coupons and currency behave exactly as they do at a real checkout.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'items'       => array(
							'type'        => 'array',
							'description' => 'What is being bought. Each entry is an object with product_id and optionally quantity (1 by default) and price - pass price only to override what the shop charges, for a discount agreed by phone.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'product_id' => array( 'type' => 'integer' ),
									'quantity'   => array( 'type' => 'integer' ),
									'price'      => array( 'type' => 'string' ),
								),
							),
						),
						'customer_id' => array(
							'type'        => 'integer',
							'description' => 'An existing customer account. Leave it out for a guest order and give the address instead.',
						),
						'billing'     => array(
							'type'        => 'object',
							'description' => 'Address fields: first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone. Country is a two letter code. Give what the user gave and do not invent the rest.',
						),
						'shipping'    => array(
							'type'        => 'object',
							'description' => 'Where it goes, same fields without email and phone. Left out, the billing address is used.',
						),
						'status'      => array(
							'type'        => 'string',
							'description' => 'pending by default, which is what a real checkout starts at. processing means it is paid and being packed; completed means it is done. Choosing a paid status can send the customer an email.',
						),
						'payment_method' => array(
							'type'        => 'string',
							'description' => 'Gateway id - bacs, cod, cheque. Recorded on the order; nothing is charged, this is not a checkout.',
						),
						'shipping_method' => array(
							'type'        => 'object',
							'description' => 'A shipping line: {"title":"Flat rate","cost":"5.00"}. Optional.',
						),
						'coupon'      => array(
							'type'        => 'string',
							'description' => 'A coupon code to apply. It is validated the way it would be at checkout, so an expired one is refused.',
						),
						'currency'    => array(
							'type'        => 'string',
							'description' => 'Currency code for the order. Defaults to the shop\'s own. On a shop with a currency switcher, ask which one before building the order rather than assuming - a wholesale customer abroad is usually invoiced in his money, and changing it afterwards means rebuilding the order. woobe_describe_shop lists what the shop sells in.',
						),
						'note'        => array( 'type' => 'string' ),
						'reduce_stock' => array(
							'type'        => 'boolean',
							'description' => 'Take the items out of stock. Off by default - a manual order is often for goods already set aside, and taking them twice is worse than not taking them at all.',
						),
						'confirmed'   => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'items' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_refund_order' => array(
				'name'        => 'woobe_refund_order',
				'description' => 'Refunds an order, in full or in part. The heaviest thing on this connection: a refund cannot be undone, it restocks items if asked, and with the gateway option it moves real money. Always call it once without confirmed to see the figures, read them out, and only then confirm. Never round, never estimate, never guess an amount the user did not say.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id'  => array( 'type' => 'integer' ),
						'amount'    => array(
							'type'        => 'string',
							'description' => 'How much to give back. Leave it out to refund the whole order.',
						),
						'reason'    => array( 'type' => 'string' ),
						'restock'   => array(
							'type'        => 'boolean',
							'description' => 'Put the returned goods back into stock. Only meaningful when the goods actually came back, and only for items whose stock was taken when the order was placed - nothing is added for stock that was never removed. A full refund restocks every line; a partial one needs items, because an amount alone does not say what came back.',
						),
						'items'     => array(
							'type'        => 'array',
							'description' => 'Which goods came back, for a partial refund: each entry {product_id, quantity}. A variation can be named by its own id or its parent\'s. Given without amount, the refund is the value of those items including their tax; given with amount, amount is what is paid back and the items only say what returned.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'product_id' => array( 'type' => 'integer' ),
									'quantity'   => array( 'type' => 'integer' ),
								),
							),
						),
						'via_gateway' => array(
							'type'        => 'boolean',
							'description' => 'Send the refund through the payment gateway rather than only recording it. This moves money and cannot be reversed from here. False by default, and it needs the user to have said so plainly.',
						),
						'confirmed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'order_id' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
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
			case 'woobe_orders':
				return $this->orders( $args );
			case 'woobe_order':
				return $this->order( $args );
			case 'woobe_top_customers':
				return $this->top_customers( $args );
			case 'woobe_set_order_status':
				return $this->set_order_status( $args );
			case 'woobe_order_note':
				return $this->order_note( $args );
			case 'woobe_create_order':
				return $this->create_order( $args );
			case 'woobe_refund_order':
				return $this->refund_order( $args );
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

		$whole = ! empty( $args['whole_catalogue'] );
		$found = null;

		if ( $whole ) {

			// the database picks the best sellers itself, sorted and cut the
			// way this report sorts and cuts, and the rest of the function
			// treats them as if they had been a selection
			$p = $this->period( $args );

			if ( is_wp_error( $p ) ) {
				return $p;
			}

			$pick = $this->catalogue_ids(
				'sold',
				$p,
				isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'revenue',
				isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50,
				true
			);

			if ( is_wp_error( $pick ) ) {
				return $pick;
			}

			$ids   = $pick['ids'];
			$found = $pick['found'];

			if ( empty( $ids ) ) {
				return array(
					'period' => $p['label'],
					'scope'  => 'whole catalogue',
					'rows'   => array(),
					'note'   => 'Nothing sold in this period.',
				);
			}

			// meaningless here: every row sold something by construction
			$args['include_zero'] = false;

		} else {

			$ids = $this->ids( $args );

			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			if ( empty( $ids ) ) {
				return new WP_Error( 'woobe_mcp_no_products', 'No products to report on.' );
			}

			// a report over tens of thousands of products is not something
			// anybody reads; narrow the selection, or use whole_catalogue
			if ( count( $ids ) > 500 ) {
				return new WP_Error(
					'woobe_mcp_too_many_products',
					'That selection holds ' . count( $ids ) . ' products. Ask for sales on at most 500 at a time - narrow the filter first, or pass whole_catalogue for the best sellers across the whole shop.'
				);
			}
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$id_ph     = $this->placeholders( $ids, '%d' );
		$status_ph = $this->placeholders( $p['statuses'] );

		// The lookup table files a variation under its parent's id in
		// product_id and keeps its own id in variation_id. Matching product_id
		// alone meant a variation asked for by its own id never matched a
		// single sale. Each row is reported under whichever id was asked for:
		// the variation's own when it is in the list, the parent's otherwise.
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
						COUNT( DISTINCT l.order_id ) AS orders,
						SUM( l.product_qty )         AS units,
						SUM( l.product_net_revenue / {$m['rate']} ) AS net,
						MAX( s.date_created )        AS last_sale
				   FROM {$lookup} AS l
				   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				   {$m['join']}
				  WHERE ( l.product_id IN ({$id_ph}) OR l.variation_id IN ({$id_ph}) )
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY IF( l.variation_id IN ({$id_ph}), l.variation_id, l.product_id )",
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
			'scope'          => $whole ? 'whole catalogue: ' . $found . ' items sold in the period, the top ' . count( $ids ) . ' by ' . ( isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'revenue' ) . ' are below' : 'selection',
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

		// Refunds, as figures of their own. WooCommerce records each refund as
		// a separate negative row in wc_order_stats pointing at its order, and
		// the query above leaves those out by design. Here they are counted by
		// the date the money went back, and only for orders whose status is in
		// the list - so an order refunded in full, which moves to refunded and
		// has already dropped out of the sales above, is not taken off twice.
		// Partial refunds on completed orders are exactly what this catches.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$refund_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select_bucket}
						COUNT( * )                             AS refunds,
						SUM( s.total_sales / {$m['rate']} )    AS refunded,
						SUM( s.net_total / {$m['rate']} )      AS refunded_net
				   FROM {$stats} AS s
				   INNER JOIN {$stats} AS po ON po.order_id = s.parent_id
				   {$m['join']}
				  WHERE s.parent_id > 0
					AND po.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  {$group_clause}",
				$params
			),
			ARRAY_A
		);

		$refunds_by_bucket = array();

		foreach ( (array) $refund_rows as $r ) {
			if ( intval( $r['refunds'] ) > 0 ) {
				$refunds_by_bucket[ $r['bucket'] ] = $r;
			}
		}

		$out = array();

		foreach ( (array) $rows as $r ) {

			$orders = intval( $r['orders'] );
			$ref    = isset( $refunds_by_bucket[ $r['bucket'] ] ) ? $refunds_by_bucket[ $r['bucket'] ] : null;

			unset( $refunds_by_bucket[ $r['bucket'] ] );

			$out[] = $this->summary_row( $r['bucket'], $orders, $r, $ref );
		}

		// a day or week with refunds and no sales still has to show up, or the
		// money that went back that day disappears from a grouped report
		foreach ( $refunds_by_bucket as $bucket => $ref ) {
			$out[] = $this->summary_row( $bucket, 0, null, $ref );
		}

		if ( isset( $buckets[ $group_by ] ) ) {
			usort(
				$out,
				function ( $a, $b ) {
					return strcmp( (string) $a['bucket'], (string) $b['bucket'] );
				}
			);
		}

		return array(
			'period'   => $p['label'],
			'statuses' => $p['statuses'],
			'currency' => $this->currency_status( $p ),
			'group_by' => $group_by,
			'rows'     => $out,
			'note'     => 'net is revenue on goods after discounts, without shipping or tax. gross is what customers actually paid. shipping is what customers were charged for delivery - what the shop paid its carrier is not stored anywhere in WooCommerce, so profit on shipping cannot be derived from this. refunded is money given back in the period, tax and shipping included, counted on the day of the refund; net_after_refunds is net minus the goods part of those refunds. An order refunded in full is in neither figure - it leaves the sales when its status becomes refunded. Whenever refunded is not zero, quote it alongside gross; a total without it overstates what the shop kept.',
		);
	}

	/**
	 * One row of the sales summary, sales and refunds for the same bucket.
	 */
	private function summary_row( $bucket, $orders, $sales, $refund ) {

		$dp  = $this->decimals();
		$get = function ( $row, $key ) {
			return ( is_array( $row ) && isset( $row[ $key ] ) ) ? floatval( $row[ $key ] ) : 0.0;
		};

		$net          = $get( $sales, 'net' );
		$gross        = $get( $sales, 'gross' );
		$refunded_net = $get( $refund, 'refunded_net' );

		return array(
			'bucket'            => $bucket,
			'orders'            => $orders,
			'items'             => intval( $get( $sales, 'items' ) ),
			'net'               => round( $net, $dp ),
			'shipping'          => round( $get( $sales, 'shipping' ), $dp ),
			'tax'               => round( $get( $sales, 'tax' ), $dp ),
			'gross'             => round( $gross, $dp ),
			'average_order'     => $orders ? round( $gross / $orders, $dp ) : 0,
			'returning_orders'  => intval( $get( $sales, 'returning_orders' ) ),
			// stored negative; reported the way an owner says it
			'refunds'           => intval( $get( $refund, 'refunds' ) ),
			'refunded'          => round( abs( $get( $refund, 'refunded' ) ), $dp ),
			'net_after_refunds' => round( $net + $refunded_net, $dp ),
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

		// shipping cost comes from order item meta, not from analytics, so it
		// is in the order's own currency whatever the switcher does to reports
		$m         = $this->money_sql( 's', true );
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

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the orders themselves
	//
	// Everything below goes through wc_get_orders() and the order object rather
	// than through SQL. The reporting half above reads lookup tables because it
	// deals in millions of rows; this half deals in dozens, and an order object
	// is the only thing that behaves identically under HPOS and the old post
	// storage without a branch for each.

	private function orders( $args ) {

		$query = array(
			// refunds are orders too as far as wc_get_orders is concerned, and
			// without this they are counted in the total while being filtered
			// out of the rows - the list then says eleven and shows seven
			'type'    => 'shop_order',
			'limit'   => isset( $args['limit'] ) ? min( 100, max( 1, intval( $args['limit'] ) ) ) : 25,
			'page'    => isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'paginate' => true,
		);

		// days in the shop's time zone, as the reports read them - in UTC,
		// "today" shortly after midnight in Spain was still yesterday
		$tz   = wp_timezone();
		$from = '';
		$to   = '';

		try {
			$from = ( new DateTimeImmutable( isset( $args['date_from'] ) ? (string) $args['date_from'] : '-30 days', $tz ) )->setTimezone( $tz )->format( 'Y-m-d' );
			$to   = isset( $args['date_to'] ) ? ( new DateTimeImmutable( (string) $args['date_to'], $tz ) )->setTimezone( $tz )->format( 'Y-m-d' ) : '';
		} catch ( Exception $e ) {
			return new WP_Error( 'woobe_mcp_bad_date', 'Could not read those dates.' );
		}

		if ( $from && $to ) {
			$query['date_created'] = $from . '...' . $to;
		} elseif ( $from ) {
			$query['date_created'] = '>=' . $from;
		}

		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {

			$statuses = array();

			foreach ( $args['statuses'] as $status ) {

				$status = sanitize_key( $status );

				// The trash is a WordPress status, not a WooCommerce one: it is
				// plain "trash", never "wc-trash". Prefixed like the others it
				// matched nothing, so asking for the trash always came back
				// empty even with orders sitting in it.
				if ( 'trash' === $status || 'wc-trash' === $status ) {
					$statuses[] = 'trash';
					continue;
				}

				$statuses[] = ( 0 === strpos( $status, 'wc-' ) ) ? $status : 'wc-' . $status;
			}

			$query['status'] = $statuses;
		}

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}

		if ( ! empty( $args['customer_id'] ) ) {
			$query['customer_id'] = intval( $args['customer_id'] );
		}

		// wc_get_orders has no product filter, so the ids come from the order
		// items table first and go in as an explicit list
		if ( ! empty( $args['product_id'] ) ) {

			$ids = $this->order_ids_with_product( intval( $args['product_id'] ) );

			if ( empty( $ids ) ) {
				return array(
					'count' => 0,
					'rows'  => array(),
					'note'  => 'No order has ever contained that product.',
				);
			}

			$query['post__in'] = $ids;
			$query['include']  = $ids;
		}

		$result = wc_get_orders( $query );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : (array) $result;
		$total  = is_object( $result ) && isset( $result->total ) ? intval( $result->total ) : count( $orders );

		$rows = array();

		foreach ( $orders as $order ) {

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$rows[] = array(
				'id'        => $order->get_id(),
				'number'    => $order->get_order_number(),
				'date'      => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
				'status'    => $order->get_status(),
				'customer'  => trim( $order->get_formatted_billing_full_name() ),
				'town'      => $this->where_to( $order ),
				'items'     => $this->item_summary( $order ),
				'shipping'  => $order->get_shipping_method(),
				'payment'   => $order->get_payment_method_title(),
				// the number and the code, not WooCommerce's formatted markup:
				// that returns two prices with a strikethrough once part of an
				// order has been refunded, which reads as nonsense in a table
				'total'     => $order->get_total(),
				'currency'  => $order->get_currency(),
				// what the shop counts it as: a list mixing three currencies
				// invites somebody to add the column up, and the answer would
				// be wrong in a way nothing on screen would reveal
				'total_base' => $this->to_base_currency( $order ),
				'refunded'  => $order->get_total_refunded() ? $order->get_total_refunded() : 0,
			);
		}

		return array(
			'count'    => count( $rows ),
			'total'    => $total,
			'page'     => $query['page'],
			'rows'     => $rows,
			'base_currency' => get_woocommerce_currency(),
			'note'     => 'Render this as a table - it is what the user is picturing. town is enough for scanning; the full address is in woobe_order when he is actually packing something. total is what the customer paid, in the currency he paid it in; total_base is the same money counted in the shop\'s own currency. Add up total_base and never total, or a shop selling in three currencies gets a number that means nothing. total_base is null when the order is in another currency and there is no rate to convert it with - either no switcher is running, or the switcher no longer sells that currency and the order carries no rate of its own. Leave those out of any sum and say how many were left out.',
		);
	}

	/**
	 * Town and country rather than the whole address.
	 *
	 * Enough to tell two orders apart at a glance, and short enough for a table
	 * - a full street address per row turns a list of twenty into a wall.
	 */
	private function where_to( $order ) {

		$city    = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();

		return trim( $city . ( $country ? ', ' . $country : '' ), ' ,' );
	}

	/**
	 * What was ordered, in a line.
	 *
	 * One product is named; several are counted, because a row that lists nine
	 * items is unreadable and the detail belongs in woobe_order anyway.
	 */
	private function item_summary( $order ) {

		$items = $order->get_items();

		if ( empty( $items ) ) {
			return '';
		}

		$first = reset( $items );
		$name  = $first ? $first->get_name() : '';
		$more  = count( $items ) - 1;

		if ( $more > 0 ) {
			/* translators: 1: first item name, 2: how many other items */
			return sprintf( '%1$s + %2$d more', $name, $more );
		}

		$qty = $first ? intval( $first->get_quantity() ) : 0;

		return $qty > 1 ? $name . ' x' . $qty : $name;
	}

	private function order_ids_with_product( $product_id ) {

		global $wpdb;

		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

		if ( ! $this->table_exists( $items ) || ! $this->table_exists( $itemmeta ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT i.order_id
				   FROM {$items} i
				   JOIN {$itemmeta} m ON m.order_item_id = i.order_item_id
				  WHERE i.order_item_type = 'line_item'
					AND m.meta_key IN ( '_product_id', '_variation_id' )
					AND m.meta_value = %d
				  ORDER BY i.order_id DESC
				  LIMIT 500",
				$product_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function order( $args ) {

		$order = $this->get_order( isset( $args['order_id'] ) ? $args['order_id'] : 0 );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$lines = array();

		foreach ( $order->get_items() as $item ) {

			$product = $item->get_product();

			$lines[] = array(
				'product_id' => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => intval( $item->get_quantity() ),
				'total'      => $item->get_total(),
			);
		}

		$notes = array();

		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[] = array(
				'date'        => $note->date_created ? $note->date_created->date( 'Y-m-d H:i' ) : '',
				'author'      => $note->added_by,
				'to_customer' => (bool) $note->customer_note,
				'text'        => $note->content,
			);
		}

		$refunded = $order->get_total_refunded();

		return array(
			'id'         => $order->get_id(),
			'number'     => $order->get_order_number(),
			'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'status'     => $order->get_status(),
			'customer'   => array(
				'name'        => trim( $order->get_formatted_billing_full_name() ),
				'customer_id' => $order->get_customer_id(),
				'company'     => $order->get_billing_company(),
			),
			'ship_to'    => wp_strip_all_tags( str_replace( '<br/>', ', ', $order->get_formatted_shipping_address() ) ),
			'bill_to'    => wp_strip_all_tags( str_replace( '<br/>', ', ', $order->get_formatted_billing_address() ) ),
			'shipping'   => $order->get_shipping_method(),
			'payment'    => $order->get_payment_method_title(),
			'items'      => $lines,
			'totals'     => array(
				'items'    => $order->get_subtotal(),
				'shipping' => $order->get_shipping_total(),
				'tax'      => $order->get_total_tax(),
				'discount' => $order->get_total_discount(),
				'total'    => $order->get_total(),
				'refunded' => $refunded ? $refunded : 0,
				'currency' => $order->get_currency(),
			),
			'coupons'    => $order->get_coupon_codes(),
			'notes'      => $notes,
			'edit_url'   => $order->get_edit_order_url(),
			'note'       => 'This holds the customer\'s name and address. Use them for what the user asked and do not repeat them further than needed - a transcript outlives the conversation. No email or phone is returned; those are on the order page, where the shop logs who looked.',
		);
	}

	private function top_customers( $args ) {

		$p     = $this->period( $args );
		$limit = isset( $args['limit'] ) ? min( 50, max( 1, intval( $args['limit'] ) ) ) : 10;

		// orders in another currency that nothing could convert
		$unconverted = 0;

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => 2000,
				'status'       => $p['statuses'],
				// period() hands back timestamps; strtotime on a number returns
				// false and the range then silently matches nothing at all
				// shop-time days, as every other report reads them
				'date_created' => $p['label']['from'] . '...' . $p['label']['to'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$by_customer = array();

		foreach ( $orders as $order ) {

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			// A guest checkout has no customer id, so orders are grouped by the
			// billing email's hash instead - the email itself never leaves here.
			// An order with neither is nobody: grouping those together produces
			// one nameless row that looks like the shop's biggest customer,
			// which is how imported and seeded orders quietly poison this
			// report. They are counted separately and named as unattributed.
			$email = strtolower( trim( (string) $order->get_billing_email() ) );

			if ( $order->get_customer_id() ) {
				$key = 'u' . $order->get_customer_id();
			} elseif ( '' !== $email ) {
				$key = 'g' . md5( $email );
			} else {
				$key = 'x' . $order->get_id();
			}

			if ( ! isset( $by_customer[ $key ] ) ) {
				$name = trim( $order->get_formatted_billing_full_name() );

				$by_customer[ $key ] = array(
					'customer_id' => $order->get_customer_id(),
					'name'        => ( '' !== $name ) ? $name : 'unattributed order #' . $order->get_id(),
					'town'        => $this->where_to( $order ),
					'orders'      => 0,
					'spent'       => 0.0,
					'last_order'  => '',
					'guest'       => ! $order->get_customer_id(),
				);
			}

			++$by_customer[ $key ]['orders'];

			// converted the way the money reports do it: a shop selling in
			// several currencies would otherwise add pounds to euros and
			// present the result as a total. An order that cannot be converted
			// stays out of spent and is counted instead, so the gap is visible.
			$in_base = $this->to_base_currency( $order );

			if ( is_null( $in_base ) ) {
				++$unconverted;
				$by_customer[ $key ]['unconverted_orders'] = ( isset( $by_customer[ $key ]['unconverted_orders'] ) ? $by_customer[ $key ]['unconverted_orders'] : 0 ) + 1;
			} else {
				$by_customer[ $key ]['spent'] += $in_base;
			}

			$date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';

			if ( $date > $by_customer[ $key ]['last_order'] ) {
				$by_customer[ $key ]['last_order'] = $date;
			}
		}

		usort(
			$by_customer,
			function ( $a, $b ) {
				return $b['spent'] <=> $a['spent'];
			}
		);

		$rows = array_slice( $by_customer, 0, $limit );

		foreach ( $rows as $k => $row ) {
			$rows[ $k ]['spent'] = round( $row['spent'], 2 );
		}

		return array(
			'period'    => array(
				'from' => $p['label']['from'],
				'to'   => $p['label']['to'],
			),
			'currency'           => $this->currency_status( $p ),
			'unconverted_orders' => $unconverted,
			'customers'          => count( $by_customer ),
			'rows'               => $rows,
			'note'               => 'guest marks a checkout with no account - those are grouped by their billing email, which is not returned. spent is what they paid in total, refunds not deducted. Names and towns only; contact details are on the order page. unconverted_orders counts orders in another currency that could not be valued in the shop\'s money; they are left out of spent, and when it is not zero say how many before quoting anyone\'s total.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// writing

	private function set_order_status( $args ) {

		$status = isset( $args['status'] ) ? sanitize_key( str_replace( 'wc-', '', $args['status'] ) ) : '';
		$known  = array_keys( wc_get_order_statuses() );
		$known  = array_map(
			function ( $s ) {
				return str_replace( 'wc-', '', $s );
			},
			$known
		);

		if ( ! in_array( $status, $known, true ) ) {
			return new WP_Error(
				'woobe_mcp_bad_status',
				'Unknown order status: ' . $status . '. This shop has: ' . implode( ', ', $known ) . '.'
			);
		}

		$ids  = ( isset( $args['order_ids'] ) && is_array( $args['order_ids'] ) ) ? array_map( 'intval', $args['order_ids'] ) : array();
		$note = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_orders', 'No orders were given.' );
		}

		if ( count( $ids ) > 100 ) {
			return new WP_Error(
				'woobe_mcp_too_many_orders',
				'That is ' . count( $ids ) . ' orders. A hundred at a time is the limit here - a status change can send an email per order, and a thousand emails leaving at once is a different kind of problem.'
			);
		}

		$changed = array();
		$failed  = array();

		foreach ( $ids as $order_id ) {

			$order = $this->get_order( $order_id );

			if ( is_wp_error( $order ) ) {
				$failed[] = array(
					'id'     => $order_id,
					'reason' => $order->get_error_message(),
				);
				continue;
			}

			$before = $order->get_status();

			if ( $before === $status ) {
				continue;
			}

			// WooCommerce glues a note given to update_status() onto its own
			// "Order status changed from ..." line, so the two read as one
			// sentence. A note of its own stays a note of its own.
			if ( '' !== trim( (string) $note ) ) {
				$order->add_order_note( $note, 0, true );
			}

			$order->update_status( $status, '', true );

			$changed[] = array(
				'id'     => $order_id,
				'number' => $order->get_order_number(),
				'from'   => $before,
				'to'     => $status,
			);
		}

		return array(
			'changed' => $changed,
			'failed'  => $failed,
			'note'    => 'Name the orders back with their old and new status. Orders already in that status were left alone rather than touched again - repeating a status change can resend the email that goes with it.',
		);
	}

	private function order_note( $args ) {

		$order = $this->get_order( isset( $args['order_id'] ) ? $args['order_id'] : 0 );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$note = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';

		if ( '' === trim( $note ) ) {
			return new WP_Error( 'woobe_mcp_empty_note', 'The note is empty.' );
		}

		$to_customer = ! empty( $args['to_customer'] );

		$note_id = $order->add_order_note( $note, $to_customer ? 1 : 0, false );

		return array(
			'order_id'    => $order->get_id(),
			'note_id'     => $note_id,
			'to_customer' => $to_customer,
			'note'        => $to_customer
				? 'Added and emailed to the customer. Say so plainly - the user should know a message left the shop.'
				: 'Added as a private note. The customer sees nothing.',
		);
	}

	private function refund_order( $args ) {

		$order = $this->get_order( isset( $args['order_id'] ) ? $args['order_id'] : 0 );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$dp  = wc_get_price_decimals();
		$max = floatval( $order->get_total() ) - floatval( $order->get_total_refunded() );

		if ( $max <= 0 ) {
			return new WP_Error(
				'woobe_mcp_nothing_to_refund',
				'Order ' . $order->get_order_number() . ' has already been refunded in full.'
			);
		}

		// which goods are coming back, if the user said
		$wanted = null;

		if ( ! empty( $args['items'] ) && is_array( $args['items'] ) ) {

			$wanted = array();

			foreach ( $args['items'] as $raw ) {

				$pid = isset( $raw['product_id'] ) ? intval( $raw['product_id'] ) : 0;
				$qty = isset( $raw['quantity'] ) ? max( 1, intval( $raw['quantity'] ) ) : 1;

				if ( $pid ) {
					$wanted[ $pid ] = ( isset( $wanted[ $pid ] ) ? $wanted[ $pid ] : 0 ) + $qty;
				}
			}
		}

		$amount_given = isset( $args['amount'] ) && '' !== (string) $args['amount'];
		$full         = ! $amount_given && is_null( $wanted );

		// Refund lines, not just an amount. WooCommerce restocks only the lines
		// a refund lists (wc_restock_refunded_items walks line_items), so a
		// refund made of an amount alone ignored restock entirely - and it
		// also left the per product refund report blind to it.
		$lines = array();

		if ( ! is_null( $wanted ) ) {
			$lines = $this->refund_lines( $order, $wanted );
		} elseif ( $full ) {
			$lines = $this->refund_lines( $order, null );
		}

		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		if ( ! empty( $args['restock'] ) && empty( $lines ) ) {
			return new WP_Error(
				'woobe_mcp_restock_needs_items',
				'A partial refund given as an amount does not say which goods came back, so nothing can be put back into stock. Ask the user which products and how many were returned, and pass them in items.'
			);
		}

		if ( $amount_given ) {
			$amount = round( floatval( $args['amount'] ), $dp );
		} elseif ( ! is_null( $wanted ) ) {
			$amount = 0;
			foreach ( $lines as $line ) {
				$amount += $line['refund_total'] + array_sum( $line['refund_tax'] );
			}
			$amount = round( $amount, $dp );
		} else {
			$amount = round( $max, $dp );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'woobe_mcp_bad_amount', 'A refund has to be more than zero.' );
		}

		if ( $amount > round( $max, $dp ) ) {
			return new WP_Error(
				'woobe_mcp_over_refund',
				'That is more than is left on the order. ' . round( $max, 2 ) . ' ' . $order->get_currency()
				. ' can still be refunded on order ' . $order->get_order_number() . ', not ' . $amount . '.'
			);
		}

		// Lines worth more than the money actually given back - an amount
		// smaller than the goods named, or an earlier partial refund - keep
		// their quantities for the stock but scale their money down, so the
		// lines never claim more was refunded than was.
		$lines_sum = 0;

		foreach ( $lines as $line ) {
			$lines_sum += $line['refund_total'] + array_sum( $line['refund_tax'] );
		}

		if ( $lines_sum > $amount && $lines_sum > 0 ) {

			$k = $amount / $lines_sum;

			foreach ( $lines as $item_id => $line ) {

				$lines[ $item_id ]['refund_total'] = round( $line['refund_total'] * $k, $dp );

				foreach ( $line['refund_tax'] as $tax_id => $tax ) {
					$lines[ $item_id ]['refund_tax'][ $tax_id ] = round( $tax * $k, $dp );
				}
			}
		}

		$via_gateway = ! empty( $args['via_gateway'] );

		$goods = array();

		foreach ( $lines as $line ) {
			$goods[] = $line['name'] . ' x' . $line['qty'];
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was refunded. Read this back and get a clear yes first: order ' . $order->get_order_number()
				. ' for ' . $order->get_total() . ' ' . $order->get_currency() . ', refunding '
				. $amount . ' ' . $order->get_currency()
				. ( $via_gateway ? ' through the payment gateway, which moves the money for real' : ' as a record in the shop, without touching the gateway' )
				. ( ! empty( $goods ) ? ', for ' . implode( ', ', $goods ) : '' )
				. ( ! empty( $args['restock'] ) && '' !== $this->stock_promise( $order, array_keys( (array) $lines ), 'put back' ) ? '.' . rtrim( $this->stock_promise( $order, array_keys( (array) $lines ), 'put back' ), '.' ) : '' )
				. '. A refund cannot be undone from here or from anywhere else in WooCommerce.'
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => isset( $args['reason'] ) ? sanitize_textarea_field( $args['reason'] ) : '',
				'line_items'     => $lines,
				'refund_payment' => $via_gateway,
				'restock_items'  => ! empty( $args['restock'] ),
			)
		);

		if ( is_wp_error( $refund ) ) {
			return new WP_Error( 'woobe_mcp_refund_failed', $refund->get_error_message() );
		}

		$order = $this->get_order( $order->get_id() );

		return array(
			'order_id'       => $order->get_id(),
			'number'         => $order->get_order_number(),
			'refunded_now'   => $amount,
			'refunded_total' => $order->get_total_refunded(),
			'order_total'    => $order->get_total(),
			'status'         => $order->get_status(),
			'via_gateway'    => $via_gateway,
			'goods'          => $goods,
			'restocked'      => ! empty( $args['restock'] ) && ! empty( $this->stock_split( $order, array_keys( (array) $lines ) )['tracked'] ),
			'stock_untracked' => $this->stock_split( $order, array_keys( (array) $lines ) )['untracked'],
			'note'           => 'Done and recorded on the order. stock_untracked names goods that do not track stock - nothing was put back for those, whatever restock said.'
				. ' Say the amount and whether the money actually moved - "refunded" means two different things to a shop owner depending on that, and he needs to know which one happened. If restocked, stock only went back for goods the order had taken out; an order placed without reducing stock gets nothing added.',
		);
	}

	/**
	 * Refund lines for wc_create_refund(): what is coming back, per order line.
	 *
	 * $wanted is product id => quantity, or null for everything still left on
	 * the order. A variation matches by its own id or its parent's. Money per
	 * line is the line's share of what was actually charged, tax included,
	 * so a discounted line refunds its discounted price.
	 *
	 * @return array|WP_Error item_id => qty, refund_total, refund_tax, name
	 */
	/**
	 * Which goods of an order track stock, by name. Used so an answer says
	 * what happened to stock rather than repeating what was asked for:
	 * "restocked" about goods with no stock count reads as a movement that
	 * never took place.
	 *
	 * @param WC_Order   $order
	 * @param array|null $only order item ids to look at; null for every line
	 * @return array tracked and untracked names
	 */
	/**
	 * The preview's sentence about stock, naming goods that have none to
	 * move. It used to promise "Stock WILL be reduced for every line" about a
	 * variation with no stock count, and only the answer to the write told
	 * the truth.
	 */
	private function stock_promise( $order, $only, $verb ) {

		$split = $this->stock_split( $order, $only );

		if ( empty( $split['tracked'] ) && empty( $split['untracked'] ) ) {
			return '';
		}

		if ( empty( $split['tracked'] ) ) {
			return ' No stock will be ' . $verb . ': none of these goods track stock (' . implode( ', ', $split['untracked'] ) . ').';
		}

		return ' Stock will be ' . $verb . ' for ' . implode( ', ', $split['tracked'] ) . ( $split['untracked'] ? '; not for ' . implode( ', ', $split['untracked'] ) . ', which do not track stock' : '' ) . '.';
	}

	private function stock_split( $order, $only = null ) {

		$out = array(
			'tracked'   => array(),
			'untracked' => array(),
		);

		foreach ( $order->get_items() as $item_id => $item ) {

			if ( is_array( $only ) && ! in_array( $item_id, $only, true ) && ! in_array( (string) $item_id, array_map( 'strval', $only ), true ) ) {
				continue;
			}

			$product = $item->get_product();

			// managing_stock() also answers yes for a variation whose parent
			// keeps the count, which is where WooCommerce then takes it from
			if ( $product && $product->managing_stock() ) {
				$out['tracked'][] = $item->get_name();
			} else {
				$out['untracked'][] = $item->get_name();
			}
		}

		return $out;
	}

	private function refund_lines( $order, $wanted ) {

		$dp    = wc_get_price_decimals();
		$lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {

			// get_qty_refunded_for_item() is negative, hence the plus
			$qty_left = intval( $item->get_quantity() ) + intval( $order->get_qty_refunded_for_item( $item_id ) );

			if ( $qty_left <= 0 ) {
				continue;
			}

			if ( is_null( $wanted ) ) {

				$qty = $qty_left;

			} else {

				$own    = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$parent = $item->get_product_id();
				$key    = ! empty( $wanted[ $own ] ) ? $own : ( ! empty( $wanted[ $parent ] ) ? $parent : 0 );

				if ( ! $key ) {
					continue;
				}

				$qty             = min( $wanted[ $key ], $qty_left );
				$wanted[ $key ] -= $qty;
			}

			$share      = $qty / max( 1, intval( $item->get_quantity() ) );
			$taxes      = $item->get_taxes();
			$refund_tax = array();

			if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $tax_id => $tax ) {
					$refund_tax[ $tax_id ] = round( floatval( $tax ) * $share, $dp );
				}
			}

			$lines[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => round( floatval( $item->get_total() ) * $share, $dp ),
				'refund_tax'   => $refund_tax,
				'name'         => $item->get_name(),
			);
		}

		if ( ! is_null( $wanted ) ) {

			$left_over = array();

			foreach ( $wanted as $pid => $qty ) {
				if ( $qty > 0 ) {
					$left_over[] = $pid . ' (' . $qty . ' more than the order has left)';
				}
			}

			if ( ! empty( $left_over ) ) {
				return new WP_Error(
					'woobe_mcp_refund_items',
					'Order ' . $order->get_order_number() . ' does not have these to refund: ' . implode( ', ', $left_over )
					. '. Either the product is not on the order or that many have already been refunded. woobe_order shows what is on it.'
				);
			}
		}

		return $lines;
	}

	/**
	 * An order, or a readable reason there is not one.
	 */
	private function get_order( $order_id ) {

		$order_id = intval( $order_id );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $order instanceof WC_Order ) {
			return new WP_Error( 'woobe_mcp_no_order', 'There is no order ' . $order_id . ' on this shop.' );
		}

		return $order;
	}

	/**
	 * An order's total in the shop's base currency.
	 *
	 * Uses the rate stored on the order at the time of purchase, the same one
	 * the money reports use. Without it a customer who paid in pounds and one
	 * who paid in euros are added together and the answer means nothing.
	 */
	private function to_base_currency( $order ) {

		$total = floatval( $order->get_total() );

		// already the shop's own money
		if ( strtoupper( $order->get_currency() ) === strtoupper( get_woocommerce_currency() ) ) {
			return $total;
		}

		// Another currency and nothing to convert it with. This used to hand
		// back the total untouched, so 6901 SEK came out as 6901 EUR and every
		// sum built on it was wrong without a word. null means "unknown", and
		// callers leave it out of sums and say so.
		$driver = $this->currency_driver();

		if ( ! $driver || ! $driver->is_active() ) {
			return null;
		}

		$key  = $driver->order_rate_meta_key();
		$rate = ( '' !== $key ) ? floatval( $order->get_meta( $key ) ) : 0;

		// an order with no rate of its own is valued at today's, the same
		// fallback the SQL reports use
		if ( $rate <= 0 ) {
			$rates = $driver->rates();
			$code  = strtoupper( $order->get_currency() );
			$rate  = isset( $rates[ $code ] ) ? floatval( $rates[ $code ] ) : 0;
		}

		if ( $rate <= 0 ) {
			return null;
		}

		return ( 'divide' === $driver->operation() )
			? round( $total * $rate, 2 )
			: round( $total / $rate, 2 );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// creating an order by hand

	/**
	 * Builds an order the way a checkout would.
	 *
	 * Everything goes through WC_Order and calculate_totals() rather than
	 * through inserts: tax rates, coupon rules, currency and the price a
	 * customer would actually have been charged all live in that object, and a
	 * hand built row gets each of them subtly wrong in a way nobody notices
	 * until the quarterly figures disagree.
	 *
	 * The dry run costs a real order object, created and thrown away. That is
	 * the only honest way to show the totals: working them out separately would
	 * be a second implementation of WooCommerce's tax engine, and it would
	 * drift.
	 */
	private function create_order( $args ) {

		if ( empty( $args['items'] ) || ! is_array( $args['items'] ) ) {
			return new WP_Error( 'woobe_mcp_no_items', 'An order needs at least one product.' );
		}

		$lines = array();

		foreach ( $args['items'] as $raw ) {

			$product_id = isset( $raw['product_id'] ) ? intval( $raw['product_id'] ) : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : false;

			if ( ! $product ) {
				return new WP_Error( 'woobe_mcp_no_product', 'There is no product ' . $product_id . ' on this shop.' );
			}

			if ( $product->is_type( 'variable' ) ) {
				return new WP_Error(
					'woobe_mcp_variable_parent',
					$product->get_name() . ' is a variable product, so it cannot be ordered as it stands - a customer picks a variation. Pass the id of the variation the user meant; woobe_find_products with include_variations all lists them.'
				);
			}

			$lines[] = array(
				'product'  => $product,
				'quantity' => isset( $raw['quantity'] ) ? max( 1, intval( $raw['quantity'] ) ) : 1,
				'price'    => isset( $raw['price'] ) ? floatval( $raw['price'] ) : null,
			);
		}

		$order = wc_create_order(
			array(
				'customer_id' => isset( $args['customer_id'] ) ? intval( $args['customer_id'] ) : 0,
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'woobe_mcp_create_failed', $order->get_error_message() );
		}

		foreach ( $lines as $line ) {

			$item_args = array();

			// an agreed price overrides the shop's, which is the point of a
			// phone order; without it WooCommerce charges what the page says
			if ( ! is_null( $line['price'] ) ) {
				$item_args['subtotal'] = $line['price'] * $line['quantity'];
				$item_args['total']    = $line['price'] * $line['quantity'];
			}

			$order->add_product( $line['product'], $line['quantity'], $item_args );
		}

		$customer_id = isset( $args['customer_id'] ) ? intval( $args['customer_id'] ) : 0;

		// An order for a known customer with no address given should go where
		// his last one went. Without this "create an order for customer 5"
		// produces an order with a name attached and nowhere to send it, which
		// looks like the tool half worked.
		if ( $customer_id && empty( $args['billing'] ) ) {

			$customer = new WC_Customer( $customer_id );

			if ( $customer->get_id() ) {

				$order->set_address( $customer->get_billing(), 'billing' );

				$stored_shipping = $customer->get_shipping();

				if ( ! empty( $stored_shipping['address_1'] ) ) {
					$order->set_address( $stored_shipping, 'shipping' );
				}
			}
		}

		if ( ! empty( $args['billing'] ) && is_array( $args['billing'] ) ) {
			$order->set_address( $this->clean_address( $args['billing'] ), 'billing' );
		}

		// no shipping address given means it goes where the bill goes, which is
		// what a shop assistant would assume
		$shipping = ( ! empty( $args['shipping'] ) && is_array( $args['shipping'] ) )
			? $args['shipping']
			: ( ! empty( $args['billing'] ) && is_array( $args['billing'] ) ? $args['billing'] : array() );

		if ( ! empty( $shipping ) ) {
			$order->set_address( $this->clean_address( $shipping ), 'shipping' );
		}

		if ( ! empty( $args['payment_method'] ) ) {

			$gateway_id = sanitize_key( $args['payment_method'] );
			$gateways   = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

			$order->set_payment_method( $gateway_id );

			// A gateway that is not installed or not loaded on this shop still
			// gets a title: the id itself. Without it the order list and every
			// payment report show an empty method, which reads as "unpaid"
			// rather than "paid by a gateway this shop no longer runs".
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$order->set_payment_method_title( $gateways[ $gateway_id ]->get_title() );
			} else {
				$order->set_payment_method_title( $gateway_id );
			}
		}

		if ( ! empty( $args['shipping_method'] ) && is_array( $args['shipping_method'] ) ) {

			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( isset( $args['shipping_method']['title'] ) ? sanitize_text_field( $args['shipping_method']['title'] ) : 'Shipping' );
			$shipping_item->set_total( isset( $args['shipping_method']['cost'] ) ? floatval( $args['shipping_method']['cost'] ) : 0 );

			$order->add_item( $shipping_item );
		}

		$coupon_problem = '';

		if ( ! empty( $args['coupon'] ) ) {

			$applied = $order->apply_coupon( sanitize_text_field( $args['coupon'] ) );

			if ( is_wp_error( $applied ) ) {
				// WooCommerce writes its coupon errors for a browser and puts
				// the code in &quot; entities; read aloud that is gibberish
				$coupon_problem = html_entity_decode( wp_strip_all_tags( $applied->get_error_message() ), ENT_QUOTES, 'UTF-8' );
			}
		}

		$order->calculate_totals( true );

		// Currency last, once the totals exist in base: the driver converts the
		// lines and tags the order the way the switcher does at checkout, so
		// every later report values this order like any other. Refused rather
		// than guessed when the shop cannot sell in that currency - an order
		// labelled GBP over euro figures is worse than no order.
		$currency_note = '';

		if ( ! empty( $args['currency'] ) ) {

			$driver = $this->currency_driver();

			if ( ! $driver || ! $driver->is_active() ) {

				if ( strtoupper( $args['currency'] ) !== get_woocommerce_currency() ) {
					$this->discard_order( $order );
					return new WP_Error(
						'woobe_mcp_no_switcher',
						'This shop sells in ' . get_woocommerce_currency() . ' only - there is no currency switcher running, so an order cannot be placed in ' . strtoupper( sanitize_text_field( $args['currency'] ) ) . '.'
					);
				}
			} else {

				$applied = $driver->apply_to_order( $order, $args['currency'] );

				if ( is_wp_error( $applied ) ) {
					$this->discard_order( $order );
					return $applied;
				}

				// deliberately no calculate_totals() here: the driver has
				// already written every figure, and recalculating would redo
				// taxes and coupons on converted lines and could drift from them
				$currency_note = 'Converted to ' . $order->get_currency() . ' by ' . $driver->name() . ' at the shop\'s current rate, and tagged so the reports value it correctly.';
			}
		}

		// the dry run ends here: the object was needed to get honest totals out
		// of WooCommerce, and now it goes away again
		if ( empty( $args['confirmed'] ) ) {

			$preview = $this->describe_new_order( $order, $args, $coupon_problem, $currency_note );

			$this->discard_order( $order );

			return new WP_Error( 'woobe_mcp_not_confirmed', $preview );
		}

		$status = ! empty( $args['status'] ) ? sanitize_key( str_replace( 'wc-', '', $args['status'] ) ) : 'pending';

		if ( ! empty( $args['note'] ) ) {
			$order->add_order_note( sanitize_textarea_field( $args['note'] ), 0, false );
		}

		$order->add_order_note( 'Order created through the MCP connection.', 0, false );

		if ( ! empty( $args['reduce_stock'] ) ) {
			wc_reduce_stock_levels( $order->get_id() );
		}

		// Mark stock as handled either way. WooCommerce reduces stock on its
		// own when an order enters processing, completed or on-hold, unless
		// this flag is set - so without it reduce_stock false was a promise
		// the status change broke. Safe for a later cancellation: restoring
		// only touches items carrying _reduced_stock, which none do here.
		// The setter on the object, not the data store: set_stock_reduced()
		// saves a fresh copy, and the save() below would overwrite it.
		$order->set_order_stock_reduced( true );

		$order->set_status( $status );
		$order->save();

		return array(
			'order_id' => $order->get_id(),
			'number'   => $order->get_order_number(),
			'status'   => $order->get_status(),
			'total'    => $order->get_total(),
			'currency' => $order->get_currency(),
			'items'    => count( $order->get_items() ),
			// what happened to stock, not what was asked: an item that does
			// not track stock has nothing to reduce, and saying "reduced"
			// about it reads as a movement that never took place
			'stock_reduced' => ! empty( $args['reduce_stock'] ) && ! empty( $this->stock_split( $order )['tracked'] ),
			'stock_untracked' => $this->stock_split( $order )['untracked'],
			'total_base' => $this->to_base_currency( $order ),
			'base_currency' => get_woocommerce_currency(),
			'currency_note' => $currency_note,
			'coupon_problem' => $coupon_problem,
			'edit_url' => $order->get_edit_order_url(),
			'note'     => 'Created. Give the user the number and the edit link, and say whether stock was taken - that is the part he will want to check. Choosing a paid status may have sent the customer an email, so mention it if you set one.',
		);
	}

	/**
	 * Throws away an order that was only built to be looked at, or that
	 * could not be finished.
	 *
	 * apply_coupon() records the coupon's usage the moment it runs - a pending
	 * order counts - and delete() does not give it back. Without this, every
	 * preview of an order with a coupon spent one use of it: three previews
	 * shown to the owner, three uses gone from a limit of a hundred, and a
	 * single-use code dead before anyone ordered anything.
	 */
	private function discard_order( $order ) {

		if ( $order->get_data_store()->get_recorded_coupon_usage_counts( $order ) ) {

			// the same key apply_coupon() recorded the use under
			$used_by = $order->get_user_id() ? $order->get_user_id() : $order->get_billing_email();

			foreach ( $order->get_coupon_codes() as $code ) {

				$coupon = new WC_Coupon( $code );

				if ( $coupon->get_id() ) {
					$coupon->decrease_usage_count( $used_by );
				}
			}
		}

		$order->delete( true );
	}

	/**
	 * The dry run, written for somebody to read out loud.
	 */
	private function describe_new_order( $order, $args, $coupon_problem, $currency_note = '' ) {

		$lines = array();

		foreach ( $order->get_items() as $item ) {
			$lines[] = $item->get_name() . ' x' . intval( $item->get_quantity() ) . ' = ' . $item->get_total();
		}

		$who = trim( $order->get_formatted_billing_full_name() );
		$to  = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();

		$text = 'Nothing was created yet. This is what it would be'
			. ( $who ? ' for ' . $who : '' )
			. ( $to ? ' in ' . $to : '' )
			. ': ' . implode( '; ', $lines )
			. '. Subtotal ' . $order->get_subtotal()
			. ', shipping ' . $order->get_shipping_total()
			. ', tax ' . $order->get_total_tax()
			. ', discount ' . $order->get_total_discount()
			. ', total ' . $order->get_total() . ' ' . $order->get_currency() . '.';

		$text .= ' Status will be ' . ( ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : 'pending' ) . '.';

		$text .= empty( $args['reduce_stock'] )
			? ' Stock will NOT be reduced - say so, because a manual order for goods already set aside is the usual case and taking them twice is the usual mistake.'
			: $this->stock_promise( $order, null, 'reduced' );

		if ( $currency_note ) {
			$text .= ' ' . $currency_note;
		}

		if ( $coupon_problem ) {
			$text .= ' The coupon was refused: ' . rtrim( $coupon_problem, '. ' ) . '. Everything else stands.';
		}

		if ( '' === $who ) {
			$text .= ' No customer name or address is attached - fine for a counter sale, wrong for anything that has to be posted. Check that is what the user meant before confirming.';
		}

		$text .= ' Read the lines and the total back, get a clear yes, then call again with confirmed true.';

		return $text;
	}

	/**
	 * Address fields WooCommerce recognises, and nothing else.
	 *
	 * A stray key here becomes order meta that no screen displays and nobody
	 * ever finds again.
	 */
	private function clean_address( $raw ) {

		$allowed = array(
			'first_name',
			'last_name',
			'company',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
			'email',
			'phone',
		);

		$out = array();

		foreach ( $allowed as $field ) {

			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $raw[ $field ] ) );

			if ( 'country' === $field || 'state' === $field ) {
				$value = strtoupper( $value );
			}

			if ( 'email' === $field ) {
				$value = sanitize_email( $value );
			}

			$out[ $field ] = $value;
		}

		return $out;
	}
}