<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * What the discounts cost.
 *
 * Two different questions in one table: which code people reach for, and which
 * code gives away the most money. They are rarely the same code, and a shop
 * that only looks at usage keeps running the expensive one.
 *
 * Hidden on a shop that has never issued a coupon.
 */
final class WOOBE_MCP_CASE_PROMO_COST extends WOOBE_MCP_CASE {

	public function title() {
		return 'What the discounts cost';
	}

	public function question() {
		return 'Which promo codes are being used, and how much are they costing me?';
	}

	public function answers() {
		return 'Each coupon with how many orders used it and the discount it gave away, so a popular code and an expensive one can be told apart.';
	}

	public function tags() {
		return array( 'promo', 'coupons', 'margin' );
	}

	public function render() {
		return 'chart_bar';
	}

	public function is_available() {

		$coupons = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $coupons );
	}

	public function steps() {

		return array(
			array(
				'key'       => 'coupons',
				'tool'      => 'woobe_coupon_usage',
				'arguments' => array(
					'date_from' => '-6 months',
					'order_by'  => 'discount',
				),
			),
		);
	}
}
