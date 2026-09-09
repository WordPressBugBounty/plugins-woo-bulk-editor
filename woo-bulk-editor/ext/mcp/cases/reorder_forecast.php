<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * When to buy more.
 *
 * The question underneath "how fast does it sell" is almost always "when do I
 * have to order it again", and answering the first without the second leaves
 * the owner to divide in his head. So this reports the selling rate and the
 * remaining cover side by side, for everything that is actually moving.
 *
 * managed_only is deliberately off. A product with no stock tracking still has
 * a rate, and seeing it here is how the owner notices that his best seller has
 * no stock control at all - which is the finding, not a gap in the report. The
 * days column is simply empty for those, and the note says why.
 *
 * The rate is a flat average over the period, so it assumes demand stays put.
 * That makes it a warning sign rather than a forecast, and the answer should be
 * read out that way: a product with three weeks of cover needs attention now,
 * not in three weeks.
 */
final class WOOBE_MCP_CASE_REORDER_FORECAST extends WOOBE_MCP_CASE {

	public function title() {
		return 'When to buy more';
	}

	public function question() {
		return 'How fast is my stock selling, and when does it run out?';
	}

	public function answers() {
		return 'Selling rate per day, week and month for everything that moves, with the days of cover left beside it. Sorted so whatever runs out first is at the top.';
	}

	public function tags() {
		return array( 'stock', 'reorder', 'demand', 'purchasing' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function steps() {

		return array(
			$this->catalogue_step(),
			array(
				'key'       => 'forecast',
				'tool'      => 'woobe_stock_velocity',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-6 months',
					'managed_only' => false,
					'moving_only'  => true,
					'limit'        => 50,
				),
			),
		);
	}
}
