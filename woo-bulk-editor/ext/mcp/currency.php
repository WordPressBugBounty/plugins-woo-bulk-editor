<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Base for a currency switcher driver.
 *
 * WooCommerce analytics stores every amount in the currency the order was
 * placed in and keeps no currency column of its own. On a single currency shop
 * that is invisible; on a multi currency shop it means the totals are a sum of
 * different things, and 100 EUR plus 100 JPY is not money. So every amount has
 * to be divided back to the base currency before it is summed.
 *
 * The rate to divide by is not WooCommerce's to give - it belongs to whichever
 * switcher took the payment, and every switcher keeps it somewhere else. Rather
 * than spread those differences through the reporting code, each switcher gets
 * one file in ext/mcp/currencies/. Nothing registers it: the loader scans the
 * folder and the file name is the class name, so fox.php holds
 * WOOBE_MCP_CURRENCY_FOX. Supporting another switcher is a new file and nothing
 * else - no reporting pack ever learns that switchers exist.
 *
 * A driver answers four questions:
 *
 *   is_active()            - is this switcher running on this shop
 *   base_currency()        - what the shop's own money is
 *   rates()                - today's rate per currency code
 *   order_rate_meta_key()  - where the rate at the time of purchase is stored
 *
 * The last one matters more than it looks. Converting an old order at today's
 * rate silently rewrites history: a March order becomes worth whatever March
 * money is worth in September. A switcher that records the rate on the order
 * lets the report be right; one that does not leaves the answer approximate,
 * and the report says so.
 */
abstract class WOOBE_MCP_CURRENCY {

	/**
	 * Human name of the switcher, for the answer the user reads.
	 */
	abstract public function name();

	/**
	 * Is this switcher installed and running right now.
	 */
	abstract public function is_active();

	/**
	 * The shop's own currency - what every reported figure is expressed in.
	 */
	public function base_currency() {
		return get_woocommerce_currency();
	}

	/**
	 * Current rate per uppercase currency code, base currency excluded or not,
	 * it does not matter. Used only for orders that carry no rate of their own.
	 *
	 * @return array code => float
	 */
	public function rates() {
		return array();
	}

	/**
	 * Order meta key holding the exchange rate at the time of purchase, or an
	 * empty string when the switcher does not record one.
	 */
	public function order_rate_meta_key() {
		return '';
	}

	/**
	 * How the switcher applies its rate to a base price.
	 *
	 * 'multiply' means displayed = base * rate, which is what almost every
	 * switcher does, and converting back therefore divides. 'divide' means the
	 * opposite convention; the builder inverts the expression for it. Get this
	 * wrong and the numbers are wrong by the square of the rate, so it is worth
	 * asking the switcher's developer rather than guessing.
	 */
	public function operation() {
		return 'multiply';
	}

	/**
	 * Where to send a shop owner who has no supported switcher.
	 */
	public function link() {
		return '';
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * The join clauses and the divisor expression that convert an amount.
	 *
	 * Generic: built from what the driver declared, so a new switcher needs no
	 * SQL of its own. The result is always a DIVISOR, whatever convention the
	 * switcher uses, because every reporting query writes amount / rate.
	 *
	 * @param string $alias alias of the table holding order_id, e.g. 's' or 'l'.
	 * @param bool   $hpos  whether orders live in the custom tables.
	 */
	public function money_sql( $alias, $hpos ) {

		global $wpdb;

		$meta_key = $this->order_rate_meta_key();

		// where the order's own currency code lives, so the fallback can find a
		// rate for orders placed before this switcher existed
		if ( $hpos ) {

			$join     = " LEFT JOIN {$wpdb->prefix}wc_orders AS woo_o ON woo_o.id = {$alias}.order_id";
			$currency = 'woo_o.currency';

			if ( '' !== $meta_key ) {
				$join .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->prefix}wc_orders_meta AS woo_r ON woo_r.order_id = {$alias}.order_id AND woo_r.meta_key = %s",
					$meta_key
				);
			}
		} else {

			$join     = " LEFT JOIN {$wpdb->postmeta} AS woo_o ON woo_o.post_id = {$alias}.order_id AND woo_o.meta_key = '_order_currency'";
			$currency = 'woo_o.meta_value';

			if ( '' !== $meta_key ) {
				$join .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS woo_r ON woo_r.post_id = {$alias}.order_id AND woo_r.meta_key = %s",
					$meta_key
				);
			}
		}

		$base  = $this->base_currency();
		$cases = '';

		foreach ( $this->rates() as $code => $rate ) {

			$code = strtoupper( $code );
			$rate = floatval( $rate );

			if ( $code === $base || $rate <= 0 ) {
				continue;
			}

			$cases .= $wpdb->prepare( ' WHEN %s THEN %f', $code, $rate );
		}

		$fallback = ( '' === $cases ) ? '1' : "CASE {$currency}{$cases} ELSE 1 END";

		if ( '' === $meta_key ) {
			$expr = $fallback;
		} else {
			// NULLIF guards a stored zero, which would divide by nothing
			$expr = "COALESCE( NULLIF( CAST( woo_r.meta_value AS DECIMAL(20,10) ), 0 ), {$fallback} )";
		}

		// a divide-convention switcher stores rates the other way round, so the
		// divisor the reports need is the reciprocal
		if ( 'divide' === $this->operation() ) {
			$expr = "( 1 / NULLIF( {$expr}, 0 ) )";
		}

		return array(
			'join' => $join,
			'rate' => $expr,
		);
	}

	/**
	 * One sentence for the user about how the money was arrived at.
	 */
	public function conversion_note( $base ) {

		if ( '' !== $this->order_rate_meta_key() ) {
			return 'Amounts are converted to ' . $base . ' by ' . $this->name()
				. ', using the exchange rate stored on each order at the time of purchase and falling back to the current rate where an order has none.';
		}

		return 'Amounts are converted to ' . $base . ' by ' . $this->name()
			. ' using current exchange rates. This switcher does not record the rate an order was placed at, so older orders are approximate.';
	}
}
