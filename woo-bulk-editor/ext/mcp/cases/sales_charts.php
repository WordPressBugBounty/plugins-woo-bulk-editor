<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Sales over a period, gathered to be drawn rather than read.
 *
 * Every other case answers a question. This one answers "show me" - the owner
 * wants to look at his shop, not to be told about it, and will decide what he
 * is looking for while the picture is on screen. So it collects the same period
 * from three angles that a chart can switch between without another round trip:
 *
 *   over time    - the monthly series, for a line or a column chart
 *   by units     - what leaves the shelf most often
 *   by revenue   - what actually pays, which is rarely the same list
 *
 * The two rankings are the point. A shop whose best seller by units is near the
 * bottom by revenue is selling cheap volume, and the owner usually finds that
 * out here rather than from his accountant.
 *
 * Variations count separately, because a size that outsells its siblings four
 * to one is invisible once the parent swallows it.
 */
final class WOOBE_MCP_CASE_SALES_CHARTS extends WOOBE_MCP_CASE {

	public function title() {
		return 'Show me the numbers';
	}

	public function question() {
		return 'Show me how sales went over this period, in charts.';
	}

	public function answers() {
		return 'The monthly series plus two rankings of the same period - by units and by revenue - ready to be drawn as columns, a line or shares, and switched between without asking again.';
	}

	public function tags() {
		return array( 'sales', 'charts', 'revenue' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function steps() {

		return array(
			array(
				'key'       => 'over_time',
				'tool'      => 'woobe_sales_summary',
				'arguments' => array(
					'date_from' => '-6 months',
					'group_by'  => 'month',
				),
			),
			$this->catalogue_step(),
			array(
				'key'       => 'by_units',
				'tool'      => 'woobe_product_sales',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-6 months',
					'order_by'     => 'units',
					'limit'        => 15,
				),
			),
			array(
				'key'       => 'by_revenue',
				'tool'      => 'woobe_product_sales',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-6 months',
					'order_by'     => 'revenue',
					'limit'        => 15,
				),
			),
		);
	}
}
