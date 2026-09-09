<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What is left in stock, and how fast it is going.
 *
 * Opens with the size of the catalogue, because a reorder list of eight
 * products means one thing in a shop of twenty and another in a shop of six
 * thousand - and the owner is the only one who knows which he has.
 *
 * Then the same depletion rate at three scales. Per day is the honest unit and
 * the one the arithmetic uses, but nobody plans a purchase order around 0.072
 * units a day; per week and per month are the same number rescaled so it can be
 * read out loud and acted on.
 *
 * Two kinds of product are deliberately absent from the list:
 *
 *   - products that do not manage stock. WooCommerce treats them as always
 *     available - digital goods, made to order, drop shipped - so there is no
 *     number to run down and nothing to reorder.
 *   - products that sold nothing in the period. With no rate they can never run
 *     out, so they would sit here forever with an empty days column and push
 *     the products that need attention off the screen. dead_stock is theirs.
 *
 * What remains is sorted by days of cover, shortest first. The top of that list
 * is the order to place this week.
 */
final class WOOBE_MCP_CASE_STOCK_HEALTH extends WOOBE_MCP_CASE {

	public function title() {
		return 'What is left in stock';
	}

	public function question() {
		return 'What do I have on the shelf, how fast is it going, and what runs out first?';
	}

	public function answers() {
		return 'How big the catalogue is, then stock on hand next to the selling rate per day, per week and per month, sorted by days of stock left. Only products that track stock and are actually selling - the top of that list is what to reorder.';
	}

	public function tags() {
		return array( 'stock', 'reorder', 'warehouse' );
	}

	public function steps() {

		return array(
			array(
				'key'       => 'catalogue_size',
				'tool'      => 'woobe_describe_shop',
				'arguments' => array(),
			),
			$this->catalogue_step(),
			array(
				'key'       => 'running_out',
				'tool'      => 'woobe_stock_velocity',
				'arguments' => array(
					'selection_id' => '@catalogue.selection_id',
					'date_from'    => '-3 months',
					'managed_only' => true,
					'moving_only'  => true,
					'limit'        => 50,
				),
			),
		);
	}
}
