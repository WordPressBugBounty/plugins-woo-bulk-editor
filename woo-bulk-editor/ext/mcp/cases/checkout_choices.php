<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * How people pay and get it.
 *
 * The cheapest improvement in most shops: this almost always shows one or two
 * options nobody has picked in months, and removing them shortens checkout for
 * everyone else.
 *
 * Shipping and payment are two steps rather than one because they come from
 * different places - the method from the order items, the gateway from the
 * order itself - and a shop can easily have data for one and not the other.
 */
final class WOOBE_MCP_CASE_CHECKOUT_CHOICES extends WOOBE_MCP_CASE {

	public function title() {
		return 'How people pay and get it';
	}

	public function question() {
		return 'Which delivery and payment options do customers actually choose?';
	}

	public function answers() {
		return 'Share of orders per shipping method and per gateway. Usually shows an option nobody uses, which can be removed to shorten checkout.';
	}

	public function tags() {
		return array( 'checkout', 'shipping', 'payment' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function steps() {

		return array(
			array(
				'key'       => 'shipping',
				'tool'      => 'woobe_shipping_breakdown',
				'arguments' => array( 'date_from' => '-6 months' ),
			),
			array(
				'key'       => 'payment',
				'tool'      => 'woobe_payment_breakdown',
				'arguments' => array( 'date_from' => '-6 months' ),
			),
		);
	}
}
