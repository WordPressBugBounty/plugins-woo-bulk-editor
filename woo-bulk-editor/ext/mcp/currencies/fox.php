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
}
