<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What comes back.
 *
 * Reported per variation, not per product, because that is where the answer
 * usually is: one size runs small, one colour looks different on the photo.
 * A product level return rate averages that away and tells the owner his
 * shirts are fine.
 *
 * Sorted by rate rather than by count: three returns out of four sold is a
 * problem, three out of three hundred is noise.
 */
final class WOOBE_MCP_CASE_RETURNS_PROBLEM extends WOOBE_MCP_CASE {

	public function title() {
		return 'What comes back';
	}

	public function question() {
		return 'What are people returning, and how often?';
	}

	public function answers() {
		return 'Refunds per product and per variation with the return rate against units sold. A high rate on one variation usually means a size or a photo is wrong, not the product.';
	}

	public function tags() {
		return array( 'refunds', 'quality', 'products' );
	}

	public function steps() {

		return array(
			array(
				'key'       => 'refunds',
				'tool'      => 'woobe_refunds',
				'arguments' => array(
					'date_from' => '-6 months',
					'order_by'  => 'rate',
					'limit'     => 30,
				),
			),
		);
	}
}
