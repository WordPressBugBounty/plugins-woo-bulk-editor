<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What sells fastest.
 *
 * Deliberately measured in units per day rather than in money: a cheap thing
 * that leaves every day matters more to a warehouse than one expensive sale,
 * and a revenue ranking hides it completely.
 *
 * Unlike stock_health this does not require stock management, because the
 * question is about demand, not cover.
 */
final class WOOBE_MCP_CASE_FAST_MOVERS extends WOOBE_MCP_CASE {

	public function title() {
		return 'What sells fastest';
	}

	public function question() {
		return 'Which products go out the door quickest?';
	}

	public function answers() {
		return 'Units per day per product, so a cheap thing that sells constantly is not hidden behind one expensive sale.';
	}

	public function tags() {
		return array( 'sales', 'stock', 'demand' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function steps() {

		return array(
			array(
				'key'       => 'velocity',
				'tool'      => 'woobe_stock_velocity',
				'arguments' => array(
					'whole_catalogue' => true,
					'date_from'    => '-3 months',
					'managed_only' => false,
					'limit'        => 50,
				),
			),
		);
	}
}
