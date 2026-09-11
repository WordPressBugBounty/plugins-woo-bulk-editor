<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Refunds - the shop wide total and, more usefully, which products come back.
 * Read only.
 *
 * WooCommerce analytics records a refund as its own row: negative quantities
 * and negative revenue in the product lookup, and a row in wc_order_stats whose
 * parent_id points at the original order. So refunds are found by looking for
 * negative quantities rather than by a status, which also catches partial
 * refunds on orders that are still marked completed.
 *
 * Reporting is at variation level where the shop sells variations, because
 * "sneakers" coming back tells the owner nothing and "sneakers, size 45,
 * orange" tells him what to do.
 */
final class WOOBE_MCP_TOOL_REFUNDS extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_refunds' => array(
				'name'        => 'woobe_refunds',
				'description' => 'What was refunded in a period, per product and per variation: how many units came back, how much money was returned, and the return rate against units sold in the same period. A high rate on one variation usually means a sizing or description problem rather than a bad product.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$this->period_schema(),
						array(
							'selection_id' => array(
								'type'        => 'string',
								'description' => 'Optional. Limit the report to these products.',
							),
							'ids'          => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'order_by'     => array(
								'type' => 'string',
								'enum' => array( 'units', 'amount', 'rate' ),
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

		if ( 'woobe_refunds' === $name ) {
			return $this->refunds( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	private function refunds( $args ) {

		global $wpdb;

		$stats  = $this->stats_table();
		$lookup = $this->lookup_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		// an optional product filter; without one the whole catalogue is in scope
		$scope = array();

		if ( ! empty( $args['selection_id'] ) || ! empty( $args['ids'] ) ) {

			$scope = $this->ids( $args );

			if ( is_wp_error( $scope ) ) {
				return $scope;
			}
		}

		$m            = $this->money_sql( 's' );
		$scope_sql    = '';
		$scope_params = array();

		// a variation sits in variation_id, its parent in product_id - a
		// selection of variations has to match the first or it finds nothing
		if ( ! empty( $scope ) ) {
			$scope_ph     = $this->placeholders( $scope, '%d' );
			$scope_sql    = ' AND ( l.product_id IN (' . $scope_ph . ') OR l.variation_id IN (' . $scope_ph . ') )';
			$scope_params = array_merge( array_map( 'intval', $scope ), array_map( 'intval', $scope ) );
		}

		// refunds: negative quantities, whatever the parent order's status is.
		// The date filter deliberately uses the refund's own date, so "refunds
		// in August" means refunds issued in August, not August orders refunded
		// later.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$refunded = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id,
						l.variation_id,
						SUM( l.product_qty )         AS units,
						SUM( l.product_net_revenue / {$m['rate']} ) AS amount,
						COUNT( DISTINCT l.order_id ) AS refunds
				   FROM {$lookup} AS l
				   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				   {$m['join']}
				  WHERE l.product_qty < 0
					AND s.date_created BETWEEN %s AND %s
					{$scope_sql}
				  GROUP BY l.product_id, l.variation_id",
				array_merge( array( $p['sql_from'], $p['sql_to'] ), $scope_params )
			),
			ARRAY_A
		);

		if ( empty( $refunded ) ) {
			return array(
				'period' => $p['label'],
				'rows'   => array(),
				'note'   => 'Nothing was refunded in this period.',
			);
		}

		// units sold in the same window, so a rate can be given rather than a
		// bare count - three returns out of four sold is a very different story
		// from three out of three hundred.
		// wc-refunded is added on purpose: an order refunded in full moves to
		// that status, and without it the sale vanished from the denominator
		// while its refund stayed in the numerator - 4 back out of 8 sold
		// reported as 4 out of 4, a 100% return rate that never happened.
		$sold_statuses = array_values( array_unique( array_merge( $p['statuses'], array( 'wc-refunded' ) ) ) );
		$status_ph     = $this->placeholders( $sold_statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sold_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, l.variation_id, SUM( l.product_qty ) AS units
				   FROM {$lookup} AS l
				   INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				  WHERE l.product_qty > 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY l.product_id, l.variation_id",
				array_merge( $sold_statuses, array( $p['sql_from'], $p['sql_to'] ) )
			),
			ARRAY_A
		);

		$sold = array();

		foreach ( (array) $sold_rows as $r ) {
			$sold[ intval( $r['product_id'] ) . ':' . intval( $r['variation_id'] ) ] = intval( $r['units'] );
		}

		$out          = array();
		$total_units  = 0;
		$total_amount = 0.0;

		foreach ( $refunded as $r ) {

			$product_id   = intval( $r['product_id'] );
			$variation_id = intval( $r['variation_id'] );
			$reported_id  = $variation_id ? $variation_id : $product_id;

			// stored negative; the owner thinks in positive numbers returned
			$units  = abs( intval( $r['units'] ) );
			$amount = abs( round( floatval( $r['amount'] ), $this->decimals() ) );
			$was    = isset( $sold[ $product_id . ':' . $variation_id ] ) ? $sold[ $product_id . ':' . $variation_id ] : 0;

			$total_units  += $units;
			$total_amount += $amount;

			$out[] = array(
				'id'         => $reported_id,
				'title'      => $this->product_label( $reported_id ),
				'sku'        => $this->products()->get_post_field( $reported_id, 'sku' ),
				'units'      => $units,
				'amount'     => $amount,
				'refunds'    => intval( $r['refunds'] ),
				'units_sold' => $was,
				'rate'       => $was ? round( $units * 100 / $was, 1 ) : null,
			);
		}

		$order_by = isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'units';

		usort(
			$out,
			function ( $a, $b ) use ( $order_by ) {

				if ( 'amount' === $order_by ) {
					return $b['amount'] <=> $a['amount'];
				}

				if ( 'rate' === $order_by ) {
					return floatval( $b['rate'] ) <=> floatval( $a['rate'] );
				}

				return $b['units'] <=> $a['units'];
			}
		);

		$limit = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;

		return array(
			'period'       => $p['label'],
			'currency'     => $this->currency_status( $p ),
			'total_units'  => $total_units,
			'total_amount' => round( $total_amount, $this->decimals() ),
			'rows'         => array_slice( $out, 0, $limit ),
			'note'         => 'rate is refunded units against units sold in the same period, so it is rough when a product sells slowly or was refunded long after it sold. It is null when nothing of that product sold in the window. Amounts exclude tax and shipping refunded separately.',
		);
	}
}