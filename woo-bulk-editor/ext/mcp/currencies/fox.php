<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * FOX - WooCommerce Currency Switcher (plugin slug woocommerce-currency-switcher,
 * class WOOCS, by PluginUs.NET).
 *
 * Compatible with FOX 2.x. Everything this file knows about that plugin:
 *
 *   class WOOCS + global $WOOCS   the switcher is running
 *   $WOOCS->default_currency      the shop's base currency
 *   $WOOCS->get_currencies()      code => array with a 'rate' key
 *   option 'woocs'                where that same array is stored
 *   meta _woocs_order_rate        rate at the moment the order was placed
 *   meta _woocs_order_base_currency  base currency at that moment
 *   displayed = base * rate       so converting back divides
 *
 * The per order rate is the valuable part: FOX writes it at checkout, so a
 * report can value a March order at March rates instead of rewriting history
 * with today's. FOX's own reports do exactly this, and this driver deliberately
 * follows them rather than inventing a second answer for the same shop.
 */
final class WOOBE_MCP_CURRENCY_FOX extends WOOBE_MCP_CURRENCY {

	public function name() {
		return 'FOX - WooCommerce Currency Switcher';
	}

	public function is_active() {

		global $WOOCS;

		return ( class_exists( 'WOOCS' ) && is_object( $WOOCS ) );
	}

	public function base_currency() {

		global $WOOCS;

		if ( is_object( $WOOCS ) && ! empty( $WOOCS->default_currency ) ) {
			return strtoupper( $WOOCS->default_currency );
		}

		return get_woocommerce_currency();
	}

	public function rates() {

		global $WOOCS;

		$out = array();

		if ( ! $this->is_active() ) {
			return $out;
		}

		// the accessor applies the plugin's own filters, so a site that adjusts
		// its rates programmatically is reported with the rates it really uses;
		// the raw option is only a fallback for a half loaded plugin
		$currencies = method_exists( $WOOCS, 'get_currencies' ) ? $WOOCS->get_currencies() : get_option( 'woocs', array() );

		foreach ( (array) $currencies as $code => $data ) {
			if ( isset( $data['rate'] ) && floatval( $data['rate'] ) > 0 ) {
				$out[ strtoupper( $code ) ] = floatval( $data['rate'] );
			}
		}

		return $out;
	}

	public function order_rate_meta_key() {
		return '_woocs_order_rate';
	}

	public function operation() {
		return 'multiply';
	}

	public function link() {
		return 'https://currency-switcher.com/';
	}

	/**
	 * The generic block plus what only FOX knows: the symbol, where it goes,
	 * how many decimals each currency is shown with, and the label the owner
	 * gave it. Read through the same accessor rates() uses, so the two cannot
	 * disagree about which currencies exist.
	 */
	public function describe() {

		global $WOOCS;

		$out  = parent::describe();
		$data = method_exists( $WOOCS, 'get_currencies' ) ? (array) $WOOCS->get_currencies() : (array) get_option( 'woocs', array() );

		foreach ( $out['currencies'] as $i => $row ) {

			$code = $row['code'];

			if ( ! isset( $data[ $code ] ) || ! is_array( $data[ $code ] ) ) {
				continue;
			}

			$c = $data[ $code ];

			if ( isset( $c['symbol'] ) ) {
				$out['currencies'][ $i ]['symbol'] = html_entity_decode( (string) $c['symbol'], ENT_QUOTES, 'UTF-8' );
			}

			if ( isset( $c['position'] ) ) {
				$out['currencies'][ $i ]['symbol_position'] = (string) $c['position'];
			}

			if ( isset( $c['decimals'] ) ) {
				$out['currencies'][ $i ]['decimals'] = intval( $c['decimals'] );
			}

			if ( ! empty( $c['description'] ) ) {
				$out['currencies'][ $i ]['label'] = wp_strip_all_tags( (string) $c['description'] );
			}
		}

		return $out;
	}
	
	
	/**
	 * FOX converts WooCommerce's analytics itself: dashboard_stat.php divides
	 * the wc_order_stats row by the order's rate on every sync, and
	 * analytics.php does the same to the product, coupon and tax lookups.
	 * The tables are therefore already in base currency.
	 */
	public function analytics_in_base() {
		return true;
	}

	/**
	 * Moves an order into another currency the way FOX does at checkout.
	 *
	 * FOX stores an order's totals in the currency the customer saw, and writes
	 * the rate alongside so a report can get back to base by dividing. Creating
	 * an order in GBP therefore means multiplying every line by the rate and
	 * recording it - not setting a currency code and hoping, which produces an
	 * order that says GBP over euro figures and quietly skews every report the
	 * shop runs afterwards.
	 *
	 * Called on an order whose totals are still in base currency, before it is
	 * saved.
	 */
	public function apply_to_order( $order, $currency ) {

		$currency = strtoupper( sanitize_text_field( $currency ) );
		$base     = $this->base_currency();

		if ( $currency === $base ) {
			return true;
		}

		$rates = $this->rates();

		if ( ! isset( $rates[ $currency ] ) ) {
			return new WP_Error(
				'woobe_mcp_no_rate',
				$currency . ' is not one of the currencies this shop sells in. FOX has: ' . implode( ', ', array_keys( $rates ) ) . '.'
			);
		}

		$rate = floatval( $rates[ $currency ] );

		if ( $rate <= 0 ) {
			return new WP_Error( 'woobe_mcp_bad_rate', 'FOX has no usable rate for ' . $currency . '.' );
		}

		$dp = wc_get_price_decimals();

		// Every figure read while the order is still in base currency, and every
		// figure written before the currency changes.

		$totals = array(
			'shipping'     => floatval( $order->get_shipping_total() ) * $rate,
			'shipping_tax' => floatval( $order->get_shipping_tax() ) * $rate,
			'discount'     => floatval( $order->get_total_discount( false ) ) * $rate,
			'cart_tax'     => floatval( $order->get_cart_tax() ) * $rate,
			'total'        => floatval( $order->get_total() ) * $rate,
		);

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {

			// Subtotal BEFORE total, and both read before either is written.
			// WC_Order_Item_Product::set_total() raises the subtotal to the new
			// total whenever the subtotal is lower ("subtotal cannot be less
			// than total"). Setting the converted total first therefore lifted
			// the base-currency subtotal to the converted figure, and the line
			// below multiplied it by the rate a second time - total right,
			// subtotal the rate squared, and a discount nobody had given.
			// is_callable, not method_exists: a shipping line has set_subtotal
			// too but keeps it protected, and method_exists cannot tell the
			// difference - which is how the tax setter below fataled
			$line_total    = floatval( $item->get_total() );
			$line_subtotal = is_callable( array( $item, 'get_subtotal' ) ) ? floatval( $item->get_subtotal() ) : null;

			if ( ! is_null( $line_subtotal ) && is_callable( array( $item, 'set_subtotal' ) ) ) {
				$item->set_subtotal( round( $line_subtotal * $rate, $dp ) );
			}

			$item->set_total( round( $line_total * $rate, $dp ) );

			// set_taxes rather than set_total_tax: the latter is protected on a
			// shipping line, and method_exists says yes to protected methods,
			// so the check passed and the call fataled. set_taxes is public on
			// every item type and is what WooCommerce uses itself.
			if ( is_callable( array( $item, 'set_taxes' ) ) ) {

				$taxes = $item->get_taxes();

				if ( is_array( $taxes ) ) {

					foreach ( array( 'total', 'subtotal' ) as $bucket ) {

						if ( empty( $taxes[ $bucket ] ) || ! is_array( $taxes[ $bucket ] ) ) {
							continue;
						}

						foreach ( $taxes[ $bucket ] as $tax_id => $amount ) {
							$taxes[ $bucket ][ $tax_id ] = round( floatval( $amount ) * $rate, $dp );
						}
					}

					$item->set_taxes( $taxes );
				}
			}

			$item->save();
		}

		// Coupon lines carry the discount they gave as a figure of their own,
		// apart from the line totals above. Left in base currency they said 19.40
		// on an order in CAD: wp-admin showed the coupon at that amount, and
		// analytics copied it into wc_order_coupon_lookup as CAD, which FOX then
		// divided by the rate a second time - so the coupon report undervalued
		// every coupon used on a foreign order by the rate.
		// A loop of its own: a coupon line has no get_total() or set_total(), so
		// adding 'coupon' to the loop above would fatal on it.
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$coupon_item->set_discount( round( floatval( $coupon_item->get_discount() ) * $rate, $dp ) );
			$coupon_item->set_discount_tax( round( floatval( $coupon_item->get_discount_tax() ) * $rate, $dp ) );
			$coupon_item->save();
		}

		// written rather than recalculated: calculate_totals() would redo taxes
		// and coupons on the converted lines and could drift from these figures
		$order->set_shipping_total( round( $totals['shipping'], $dp ) );
		$order->set_shipping_tax( round( $totals['shipping_tax'], $dp ) );
		$order->set_discount_total( round( $totals['discount'], $dp ) );
		$order->set_cart_tax( round( $totals['cart_tax'], $dp ) );
		$order->set_total( round( $totals['total'], $dp ) );

		// currency last, once every figure above is in place
		//
		// set_currency() rather than writing _order_currency: that key is
		// WooCommerce's own, the setter is what maintains it, and touching it
		// directly earns a doing_it_wrong notice in the log on every order.
		$order->set_currency( $currency );

		// these two are FOX's, not WooCommerce's, so meta is the right place
		$order->update_meta_data( $this->order_rate_meta_key(), $rate );
		$order->update_meta_data( '_woocs_order_base_currency', $base );

		return true;
	}
}