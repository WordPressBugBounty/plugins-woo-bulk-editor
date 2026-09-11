<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * How the shop is doing.
 *
 * The first question anyone asks, and the one where a single number is useless:
 * revenue alone cannot tell a shop that found more buyers from a shop whose
 * regulars started buying more. So the month by month figures come with the
 * order count and the average order, and the products underneath them.
 */
final class WOOBE_MCP_CASE_SALES_OVERVIEW extends WOOBE_MCP_CASE {

	public function title() {
		return 'How the shop is doing';
	}

	public function question() {
		return 'How are sales going, and what is selling?';
	}

	public function answers() {
		return 'Revenue by month with the order count and the average order, plus the products carrying it. Shows whether growth came from more buyers or bigger baskets.';
	}

	public function tags() {
		return array( 'sales', 'revenue', 'products' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function steps() {

		return array(
			array(
				'key'       => 'by_month',
				'tool'      => 'woobe_sales_summary',
				'arguments' => array(
					'date_from' => '-3 months',
					'group_by'  => 'month',
				),
			),
			array(
				'key'       => 'top_products',
				'tool'      => 'woobe_product_sales',
				'arguments' => array(
					'whole_catalogue' => true,
					'date_from'    => '-3 months',
					'order_by'     => 'units',
					'limit'        => 10,
				),
			),
		);
	}
}
