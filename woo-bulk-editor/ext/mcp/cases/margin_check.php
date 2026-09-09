<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Where the money is.
 *
 * Hidden unless the shop records a cost of goods on at least one product:
 * without cost there is no margin, and answering with revenue dressed up as
 * profit would be worse than not answering. is_available() checks for that
 * rather than letting the case return a table of nulls.
 *
 * Sorted worst margin first, because that end of the list is the one worth
 * acting on.
 */
final class WOOBE_MCP_CASE_MARGIN_CHECK extends WOOBE_MCP_CASE {

	public function title() {
		return 'Where the money is';
	}

	public function question() {
		return 'Which products actually make me money?';
	}

	public function answers() {
		return 'Revenue minus cost of goods per product, worst margin first. Covers only products that have a cost recorded, and says how many do not.';
	}

	public function is_available() {

		global $wpdb;

		// one product with a cost is enough for the case to be worth offering
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1",
				'_cogs_total_value'
			)
		);

		return ! empty( $found );
	}

	public function tags() {
		return array( 'margin', 'pricing', 'profit' );
	}

	public function steps() {

		return array(
			$this->catalogue_step(),
			array(
				'key'       => 'margin',
				'tool'      => 'woobe_margin',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-3 months',
					'order_by'     => 'percent',
					'limit'        => 40,
				),
			),
		);
	}
}
