<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Which payment methods customers used. Read only.
 *
 * This is the one figure the analytics tables do not carry, so it has to come
 * from the orders themselves - and that is the one place where HPOS matters.
 * Under HPOS the gateway is a column on wc_orders; on the legacy storage it is
 * a postmeta row. Both are joined back to wc_order_stats for the date and
 * status filter, so the period means exactly the same thing here as in every
 * other tool.
 */
final class WOOBE_MCP_TOOL_PAYMENTS extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_payment_breakdown' => array(
				'name'        => 'woobe_payment_breakdown',
				'description' => 'Which payment methods customers used in a period: gateway name, how many orders, what share of orders, and how much was paid through each. Answers "is anyone still using bank transfer" and "how much goes through the card gateway".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => $this->period_schema(),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		if ( 'woobe_payment_breakdown' === $name ) {
			return $this->payment_breakdown( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	private function payment_breakdown( $args ) {

		global $wpdb;

		$stats = $this->stats_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$status_ph = $this->placeholders( $p['statuses'] );
		$params    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

		$orders_table = $wpdb->prefix . 'wc_orders';
		$use_hpos     = $this->hpos() && $this->table_exists( $orders_table );

		if ( $use_hpos ) {

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE( MAX( CASE WHEN o.payment_method_title = '' OR BINARY o.payment_method_title = BINARY o.payment_method THEN NULL ELSE o.payment_method_title END ), NULLIF( o.payment_method, '' ), '(none)' ) AS method,
							o.payment_method     AS method_id,
							COUNT( * )           AS orders,
							SUM( s.total_sales / {$m['rate']} ) AS paid
					   FROM {$orders_table} AS o
					   INNER JOIN {$stats} AS s ON s.order_id = o.id
					   {$m['join']}
					  WHERE s.parent_id = 0
						AND s.status IN ({$status_ph})
						AND s.date_created BETWEEN %s AND %s
					  GROUP BY o.payment_method
					  ORDER BY orders DESC",
					$params
				),
				ARRAY_A
			);

		} else {

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE( MAX( CASE WHEN pmt.meta_value = '' OR BINARY pmt.meta_value = BINARY pm.meta_value THEN NULL ELSE pmt.meta_value END ), NULLIF( pm.meta_value, '' ), '(none)' ) AS method,
							pm.meta_value        AS method_id,
							COUNT( * )           AS orders,
							SUM( s.total_sales / {$m['rate']} ) AS paid
					   FROM {$stats} AS s
					   {$m['join']}
					   LEFT JOIN {$wpdb->postmeta} AS pm  ON pm.post_id  = s.order_id AND pm.meta_key  = '_payment_method'
					   LEFT JOIN {$wpdb->postmeta} AS pmt ON pmt.post_id = s.order_id AND pmt.meta_key = '_payment_method_title'
					  WHERE s.parent_id = 0
						AND s.status IN ({$status_ph})
						AND s.date_created BETWEEN %s AND %s
					  GROUP BY pm.meta_value
					  ORDER BY orders DESC",
					$params
				),
				ARRAY_A
			);
		}

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
				'paid'      => round( floatval( $r['paid'] ), $this->decimals() ),
			);
		}

		return array(
			'period'   => $p['label'],
			'statuses' => $p['statuses'],
			'currency' => $this->currency_status( $p ),
			'storage'  => $use_hpos ? 'hpos' : 'legacy',
			'orders'   => $total_orders,
			'rows'     => $out,
			'note'     => 'paid is the gross order total, tax and shipping included, because that is the amount that actually went through the gateway. Gateway fees are not stored by WooCommerce and are not included anywhere here.',
		);
	}
}