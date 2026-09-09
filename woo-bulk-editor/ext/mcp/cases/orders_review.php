<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What went out and what came back.
 *
 * Sales and refunds in one answer on purpose. Apart they are two harmless
 * tables; together they are a decision. A product near the top of both is not a
 * bestseller, it is a bestseller with a problem - and the return rate is the
 * only number that tells the two apart, because three returns out of four sold
 * and three out of three hundred look identical in a count.
 *
 * Both sides count variations separately. Returns almost always concentrate in
 * one size or one colour, and a product level rate averages exactly that away -
 * the owner reads "shirts return 8% of the time", shrugs, and never finds the
 * size that returns at sixty.
 *
 * Six months rather than three, because a refund arrives weeks after the sale
 * and a short window catches the return without the purchase that caused it.
 */
final class WOOBE_MCP_CASE_ORDERS_REVIEW extends WOOBE_MCP_CASE {

	public function title() {
		return 'What sold and what came back';
	}

	public function question() {
		return 'What sells best, and what do people send back most?';
	}

	public function answers() {
		return 'Top sellers by units next to refunds ranked by return rate, per product and per variation. A product high on both lists has a problem worth fixing rather than a demand worth celebrating.';
	}

	public function tags() {
		return array( 'orders', 'sales', 'refunds' );
	}

	public function steps() {

		return array(
			$this->catalogue_step(),
			array(
				'key'       => 'best_sellers',
				'tool'      => 'woobe_product_sales',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-6 months',
					'order_by'     => 'units',
					'limit'        => 20,
				),
			),
			array(
				'key'       => 'most_returned',
				'tool'      => 'woobe_refunds',
				'arguments' => array(
					'date_from' => '-6 months',
					'order_by'  => 'units',
					'limit'     => 20,
				),
			),
			array(
				'key'       => 'worst_return_rate',
				'tool'      => 'woobe_refunds',
				'arguments' => array(
					'date_from' => '-6 months',
					'order_by'  => 'rate',
					'limit'     => 20,
				),
			),
		);
	}
}
