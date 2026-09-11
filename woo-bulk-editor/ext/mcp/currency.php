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
	 * The switcher driving this shop, or null when none is recognised.
	 *
	 * Discovery lives here, next to the drivers, so every part of the MCP
	 * extension asks the same question the same way - the reporting packs,
	 * the order builder and the shop overview alike - and none of them knows
	 * which switchers exist. The folder is scanned the way tool packs are:
	 * the file name is the class name, fox.php holds WOOBE_MCP_CURRENCY_FOX.
	 */
	public static function active() {

		static $driver = false; // false means "not looked yet", null means "none"

		if ( false !== $driver ) {
			return $driver;
		}

		$driver = null;

		$dirs = apply_filters(
			'woobe_mcp_currencies_dirs',
			array(
				WOOBE_PATH . 'ext' . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . 'currencies' . DIRECTORY_SEPARATOR,
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'woobe_mcp_currencies' . DIRECTORY_SEPARATOR,
			)
		);

		foreach ( $dirs as $dir ) {

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( (array) glob( $dir . '*.php' ) as $file ) {

				include_once $file;

				$class = 'WOOBE_MCP_CURRENCY_' . strtoupper( basename( $file, '.php' ) );

				if ( ! class_exists( $class ) || ! is_subclass_of( $class, 'WOOBE_MCP_CURRENCY' ) ) {
					continue;
				}

				$candidate = new $class();

				// first one that recognises the shop wins; two switchers at once
				// is a broken shop, not a case worth designing for
				if ( $candidate->is_active() ) {
					$driver = $candidate;
					return $driver;
				}
			}
		}

		return $driver;
	}

	/**
	 * The currency block of the shop overview, whatever runs the shop.
	 *
	 * With a driver it is the driver's own describe(). Without one the shop
	 * sells in its WooCommerce currency only, and says so - an agent about to
	 * build an order in pounds finds out here rather than from a refusal.
	 */
	public static function shop_block() {

		$driver = self::active();

		if ( $driver ) {
			return $driver->describe();
		}

		$base = get_woocommerce_currency();

		return array(
			'engine'           => null,
			'base_currency'    => $base,
			'currencies'       => array(
				array(
					'code'    => $base,
					'rate'    => 1,
					'is_base' => true,
				),
			),
			'can_create_orders_in_other_currencies' => false,
			'note'             => 'No currency switcher this server can read is running, so the shop sells in ' . $base . ' only and every order is created in it.',
		);
	}

	/**
	 * What this switcher offers, for the shop overview.
	 *
	 * Built from the four questions every driver already answers, so a new
	 * driver gets a correct block without writing anything. A driver that
	 * knows more about its currencies - symbols, decimals - overrides this,
	 * calls the parent and adds to each row.
	 */
	public function describe() {

		$base  = $this->base_currency();
		$rates = $this->rates();
		$rows  = array(
			array(
				'code'    => $base,
				'rate'    => 1,
				'is_base' => true,
			),
		);

		foreach ( $rates as $code => $rate ) {

			if ( strtoupper( $code ) === $base ) {
				continue;
			}

			$rows[] = array(
				'code'    => strtoupper( $code ),
				'rate'    => floatval( $rate ),
				'is_base' => false,
			);
		}

		return array(
			'engine'           => $this->name(),
			'link'             => $this->link(),
			'base_currency'    => $base,
			'currencies'       => $rows,
			'rate_means'       => 'divide' === $this->operation()
				? '1 unit of the currency is worth rate units of ' . $base
				: '1 ' . $base . ' is worth rate units of the currency',
			'order_rate_recorded' => '' !== $this->order_rate_meta_key(),
			'can_create_orders_in_other_currencies' => $this->writes_orders(),
			'note'             => 'currencies is what woobe_create_order accepts in its currency argument. Rates are today\'s; reports value each order at the rate stored on it'
				. ( '' !== $this->order_rate_meta_key() ? '.' : ' - this switcher does not store one, so older orders are valued at today\'s rate.' ),
		);
	}

	/**
	 * Whether this driver can put an order into another currency - true when
	 * it overrides apply_to_order(), whose base version only refuses.
	 */
	public function writes_orders() {

		$method = new ReflectionMethod( $this, 'apply_to_order' );

		return __CLASS__ !== $method->getDeclaringClass()->getName();
	}

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
	
	
	/**
	 * Whether this switcher already rewrites WooCommerce's analytics tables
	 * into the base currency.
	 *
	 * Some do: they hook the analytics sync and divide every stored amount by
	 * the order's rate. On such a shop the tables are already in base, and
	 * dividing them again here gives figures wrong by the rate - an order worth
	 * 156 EUR reports as 97. Return true and the reports read the tables as
	 * they are.
	 */
	public function analytics_in_base() {
		return false;
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
	public function money_sql( $alias, $hpos, $raw = false ) {
		
		
		// raw order data (item meta, order columns) is never touched by the
		// switcher and stays in the order's currency, so it is always divided
		if ( ! $raw && $this->analytics_in_base() ) {
			return array(
				'join' => '',
				'rate' => '1',
			);
		}

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
	 * Puts an order into a currency other than the shop's own.
	 *
	 * Reading a shop's money and writing it are different problems. Reading
	 * only needs the rate an order was placed at; writing has to decide what
	 * the line totals mean, tag the order so every later report values it
	 * correctly, and do both the way the switcher itself would - otherwise a
	 * hand made order is the one row that disagrees with all the others.
	 *
	 * A driver that cannot do this returns a WP_Error explaining so, and the
	 * caller falls back to the base currency rather than writing something
	 * plausible and wrong.
	 *
	 * @param WC_Order $order    an order whose totals are still in base currency.
	 * @param string   $currency the code to move it to.
	 * @return true|WP_Error
	 */
	public function apply_to_order( $order, $currency ) {

		return new WP_Error(
			'woobe_mcp_currency_write_unsupported',
			$this->name() . ' can be read but not written to from here, so orders are created in ' . $this->base_currency() . '.'
		);
	}

	/**
	 * One sentence for the user about how the money was arrived at.
	 */
	public function conversion_note( $base ) {
		
		if ( $this->analytics_in_base() ) {
			return 'Amounts are in ' . $base . ': ' . $this->name()
				. ' converts every order into the base currency itself, at the rate stored on the order, when WooCommerce records it for reporting.';
		}

		if ( '' !== $this->order_rate_meta_key() ) {
			return 'Amounts are converted to ' . $base . ' by ' . $this->name()
				. ', using the exchange rate stored on each order at the time of purchase and falling back to the current rate where an order has none.';
		}

		return 'Amounts are converted to ' . $base . ' by ' . $this->name()
			. ' using current exchange rates. This switcher does not record the rate an order was placed at, so older orders are approximate.';
	}
}