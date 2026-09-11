<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What is not moving.
 *
 * The mirror of fast_movers, and the more actionable of the two: money already
 * spent and sitting still. The list ends in a decision - discount it, bundle
 * it, or stop reordering it - which is why the ids come back with it.
 *
 * Six months rather than three: a seasonal product that sells twice a year is
 * not dead, and a shorter window would call it that.
 */
final class WOOBE_MCP_CASE_DEAD_STOCK extends WOOBE_MCP_CASE {

	public function title() {
		return 'What is not moving';
	}

	public function question() {
		return 'What has been sitting there without selling?';
	}

	public function answers() {
		return 'Products that sold nothing in the period, with what is still on the shelf. This is the list to discount, bundle or stop reordering.';
	}

	public function tags() {
		return array( 'stock', 'warehouse', 'pricing' );
	}

	public function steps() {

		return array(
			array(
				'key'       => 'idle',
				'tool'      => 'woobe_stock_velocity',
				'arguments' => array(
					'whole_catalogue' => true,
					'date_from'    => '-6 months',
					'managed_only' => false,
					'slow_only'    => true,
					'limit'        => 50,
				),
			),
		);
	}
}
