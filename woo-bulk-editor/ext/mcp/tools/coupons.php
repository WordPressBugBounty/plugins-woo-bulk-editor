<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Coupon usage. Read only.
 *
 * Uses wc_order_coupon_lookup, the analytics table that already holds one row
 * per coupon per order with the discount that coupon actually gave. Reading the
 * order items instead would mean parsing coupon lines by hand and would still
 * miss the per coupon split on orders that used several.
 */
final class WOOBE_MCP_TOOL_COUPONS extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_coupon_usage' => array(
				'name'        => 'woobe_coupon_usage',
				'description' => 'Which coupon codes were redeemed in a period: how many orders used each, how much discount it gave in total and on average, and what share of all coupon orders it accounts for. Answers "which promo is most popular" and "which promo is eating my margin".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$this->period_schema(),
						array(
							'order_by' => array(
								'type' => 'string',
								'enum' => array( 'orders', 'discount' ),
							),
							'limit'    => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		if ( 'woobe_coupon_usage' === $name ) {
			return $this->coupon_usage( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	private function coupon_usage( $args ) {

		global $wpdb;

		$stats = $this->stats_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$coupons = $wpdb->prefix . 'wc_order_coupon_lookup';

		if ( ! $this->table_exists( $coupons ) ) {
			return new WP_Error( 'woobe_mcp_no_coupon_lookup', 'The coupon lookup table is missing, so coupon figures are not available.' );
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$status_ph = $this->placeholders( $p['statuses'] );
		$params    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

		// the coupon post may have been deleted since; the lookup keeps the id,
		// so the code is joined in rather than assumed to exist
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.coupon_id,
						COUNT( DISTINCT c.order_id )  AS orders,
						SUM( c.discount_amount / {$m['rate']} ) AS discount
				   FROM {$coupons} AS c
				   INNER JOIN {$stats} AS s ON s.order_id = c.order_id
				   {$m['join']}
				  WHERE s.parent_id = 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY c.coupon_id",
				$params
			),
			ARRAY_A
		);

		$total_orders   = 0;
		$total_discount = 0.0;

		foreach ( (array) $rows as $r ) {
			$total_orders   += intval( $r['orders'] );
			$total_discount += floatval( $r['discount'] );
		}

		$out = array();

		foreach ( (array) $rows as $r ) {

			$coupon_id = intval( $r['coupon_id'] );
			$orders    = intval( $r['orders'] );
			$discount  = round( floatval( $r['discount'] ), $this->decimals() );
			$post      = get_post( $coupon_id );

			$out[] = array(
				'coupon_id'        => $coupon_id,
				'code'             => $post ? $post->post_title : '(deleted coupon #' . $coupon_id . ')',
				'orders'           => $orders,
				'share'            => $total_orders ? round( $orders * 100 / $total_orders, 1 ) : 0,
				'discount'         => $discount,
				'average_discount' => $orders ? round( $discount / $orders, $this->decimals() ) : 0,
			);
		}

		$order_by = isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'orders';

		usort(
			$out,
			function ( $a, $b ) use ( $order_by ) {
				return 'discount' === $order_by
					? $b['discount'] <=> $a['discount']
					: $b['orders'] <=> $a['orders'];
			}
		);

		$limit = isset( $args['limit'] ) ? min( 100, max( 1, intval( $args['limit'] ) ) ) : 50;

		return array(
			'period'         => $p['label'],
			'statuses'       => $p['statuses'],
			'currency'       => $this->currency_status( $p ),
			'coupons_used'   => count( $out ),
			'coupon_orders'  => $total_orders,
			'total_discount' => round( $total_discount, $this->decimals() ),
			'rows'           => array_slice( $out, 0, $limit ),
			'note'           => 'share is the percentage of coupon orders, not of all orders - an order using two codes counts once for each. Orders placed without any coupon do not appear here at all.',
		);
	}
}
