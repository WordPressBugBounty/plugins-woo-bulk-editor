<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Coupons: what was redeemed, and the coupons themselves.
 *
 * Usage comes from wc_order_coupon_lookup, the analytics table that already
 * holds one row per coupon per order with the discount that coupon actually
 * gave. Reading the order items instead would mean parsing coupon lines by
 * hand and would still miss the per coupon split on orders that used several.
 *
 * Writing goes through WC_Coupon and nothing else, so every rule WooCommerce
 * applies at checkout applies to what is created here.
 *
 * Two things WooCommerce does differently from how a shop owner talks, and
 * which this pack translates in both directions:
 *
 *   A start date does not exist on a coupon. "From Monday" is done the way
 *   WordPress schedules any post: status future with the post date on that
 *   day, and WP-Cron publishes it. Until then WooCommerce does not find the
 *   code at all, because it only looks up published coupons.
 *
 *   The expiry date is exclusive. wp-admin stores "30 September" as midnight
 *   at the start of the 30th, so the coupon is already dead on the day the
 *   owner named. Here the last day is inclusive: it is stored as 23:59:59 of
 *   that day, which wp-admin still displays as the same date.
 *
 * Coupons are not covered by BEAR history. Deletion goes to the trash and can
 * be undone; an edit answers with the values it replaced, so the agent can put
 * them back if asked.
 */
final class WOOBE_MCP_TOOL_COUPONS extends WOOBE_MCP_TOOL {

	// statuses a live or pending coupon can be in; trash is asked for explicitly
	const LIVE_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	public function tools() {

		$fields = array(
			'code'                   => array(
				'type'        => 'string',
				'description' => 'What the customer types at checkout. Codes are not case sensitive, and one already used by another coupon is refused rather than duplicated.',
			),
			'description'            => array(
				'type'        => 'string',
				'description' => 'A private note for the shop, never shown to customers - what the promo was for.',
			),
			'discount_type'          => array(
				'type'        => 'string',
				'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
				'description' => 'percent takes a percentage off the eligible items. fixed_cart takes a fixed sum off the whole basket once. fixed_product takes a fixed sum off each eligible item, per unit. Ask which one when the user says "10 off" and it is not obvious whether he means percent or money.',
			),
			'amount'                 => array(
				'type'        => 'string',
				'description' => 'The size of the discount: a percentage for percent, a sum in the shop currency otherwise. 0 is allowed only together with free_shipping.',
			),
			'valid_from'             => array(
				'type'        => 'string',
				'description' => 'First day the code works, e.g. 2026-10-01, in the shop\'s time zone. A future date schedules the coupon and WordPress publishes it that morning. Empty string removes a start date that was set.',
			),
			'valid_to'               => array(
				'type'        => 'string',
				'description' => 'Last day the code works, inclusive - "until the 30th" means the 30th still counts. Empty string removes the end date.',
			),
			'minimum_amount'         => array(
				'type'        => 'string',
				'description' => 'Smallest basket, before the discount, the code applies to. Empty string or 0 removes it.',
			),
			'maximum_amount'         => array(
				'type'        => 'string',
				'description' => 'Largest basket the code applies to. Empty string or 0 removes it.',
			),
			'usage_limit'            => array(
				'type'        => 'integer',
				'description' => 'How many times the code can be used in total, by everyone. 0 means no limit.',
			),
			'usage_limit_per_user'   => array(
				'type'        => 'integer',
				'description' => 'How many times one customer can use it, recognised by account or billing email. 0 means no limit.',
			),
			'limit_usage_to_x_items' => array(
				'type'        => 'integer',
				'description' => 'For percent and fixed_product: the most items in one basket the discount applies to. 0 means all of them.',
			),
			'individual_use'         => array(
				'type'        => 'boolean',
				'description' => 'The code cannot be combined with any other coupon.',
			),
			'free_shipping'          => array(
				'type'        => 'boolean',
				'description' => 'The code also grants free shipping. Only works where the shop has a free shipping method set to accept a coupon - say so, because otherwise this silently does nothing.',
			),
			'exclude_sale_items'     => array(
				'type'        => 'boolean',
				'description' => 'The code does not apply to items already on sale.',
			),
			'products'               => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'Product ids the code is limited to. Variation ids are accepted; a variable parent covers all its variations. Given on an edit, the list replaces the old one; an empty list removes the limit.',
			),
			'excluded_products'      => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'Product ids the code never applies to. Same replacing rule.',
			),
			'categories'             => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'product_cat term ids the code is limited to - woobe_list_terms finds them. Ids, not names, so a typo cannot quietly point at nothing.',
			),
			'excluded_categories'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'product_cat term ids the code never applies to.',
			),
			'allowed_emails'         => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Only customers with these billing emails can use it. *@example.com matches a whole domain. An empty list removes the restriction.',
			),
			'status'                 => array(
				'type'        => 'string',
				'enum'        => array( 'publish', 'draft' ),
				'description' => 'publish, the default, means the code works as soon as its dates allow. draft keeps it switched off until someone publishes it.',
			),
		);

		return array(

			'woobe_coupon_usage' => array(
				'name'        => 'woobe_coupon_usage',
				'description' => 'Which coupon codes were redeemed in a period: how many orders used each, how much discount it gave in total and on average, and what share of all coupon orders it accounts for. Answers "which promo is most popular" and "which promo is eating my margin".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$this->period_schema(),
						array(
							'order_by' => array(
								'type' => 'string',
								'enum' => array( 'orders', 'discount' ),
							),
							'limit'    => array( 'type' => 'integer' ),
						)
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_coupons' => array(
				'name'        => 'woobe_coupons',
				'description' => 'The coupons on this shop and what each one does: code, discount, the days it works, how often it has been used against its limit, and every restriction. Give id or code for one coupon in full; leave both out for a list. Read this before changing a coupon so the user hears what is there now, and before creating one so the code is not already taken.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'code'   => array( 'type' => 'string' ),
						'search' => array(
							'type'        => 'string',
							'description' => 'Part of a code.',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'scheduled', 'expired', 'used_up', 'draft', 'trash', 'all' ),
							'description' => 'Defaults to all except trash.',
						),
						'limit'  => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_save_coupon' => array(
				'name'        => 'woobe_save_coupon',
				'description' => 'Creates a coupon, or changes one when id is given. On a change only the fields passed are touched and everything else stays as it is. Call it once without confirmed: the answer describes the coupon as the customer will experience it - and for a change, what each field was and will be. Read that back, get a yes, then call again with confirmed true. Coupons are not in BEAR history: the answer to a change lists the old values, so keep them if the user may want it undone. Never invent a code, amount or date the user did not give - ask.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'id' => array(
								'type'        => 'integer',
								'description' => 'The coupon to change. Leave it out to create a new one.',
							),
						),
						$fields,
						array(
							'confirmed' => array( 'type' => 'boolean' ),
						)
					),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
				),
			),

			'woobe_delete_coupons' => array(
				'name'        => 'woobe_delete_coupons',
				'description' => 'Moves coupons to the trash. The codes stop working at once. They come back with woobe_restore_coupons, or from Marketing, Coupons, Trash in wp-admin. Past orders keep the discount they got and the usage report keeps counting them. Call once without confirmed, read the list back, then confirm.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'confirmed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'ids' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),

			'woobe_restore_coupons' => array(
				'name'        => 'woobe_restore_coupons',
				'description' => 'Brings coupons back from the trash with the status they had before. Use it the moment the user regrets a deletion; woobe_coupons with status trash lists what is there.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
					'required'   => array( 'ids' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_coupon_usage':
				return $this->coupon_usage( $args );
			case 'woobe_coupons':
				return $this->coupons( $args );
			case 'woobe_save_coupon':
				return $this->save_coupon( $args );
			case 'woobe_delete_coupons':
				return $this->delete_coupons( $args );
			case 'woobe_restore_coupons':
				return $this->restore_coupons( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}


	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// reading

	private function coupons( $args ) {

		// one coupon in full
		if ( ! empty( $args['id'] ) || ( isset( $args['code'] ) && '' !== trim( (string) $args['code'] ) ) ) {

			$coupon = $this->find_coupon( $args );

			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}

			return array(
				'coupon' => $this->describe( $coupon ),
				'note'   => 'last_day is the last day the code still works, inclusive. valid_from is set only on a scheduled coupon. usage_count counts orders that used the code, including cancelled ones WooCommerce has not given back yet.',
			);
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'all';
		$limit  = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;

		$query = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => ( 'trash' === $status ) ? 'trash' : self::LIVE_STATUSES,
			'posts_per_page' => 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( wp_unslash( $args['search'] ) );
		}

		$rows = array();

		foreach ( get_posts( $query ) as $coupon_id ) {

			$coupon = new WC_Coupon( $coupon_id );
			$state  = $this->state( $coupon );

			if ( 'all' !== $status && 'trash' !== $status && $state !== $status ) {
				continue;
			}

			$rows[] = $this->summary( $coupon, $state );

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return array(
			'count' => count( $rows ),
			'rows'  => $rows,
			'note'  => 'Render this as a table. state is worked out the way checkout decides it: scheduled has not started, expired is past its last day, used_up has hit its usage limit. woobe_coupons with an id gives one coupon in full, restrictions included.',
		);
	}

	/**
	 * A coupon by id or by code, any status except trash unless asked by id.
	 */
	private function find_coupon( $args ) {

		if ( ! empty( $args['id'] ) ) {

			$post = get_post( intval( $args['id'] ) );

			if ( ! $post || 'shop_coupon' !== $post->post_type ) {
				return new WP_Error( 'woobe_mcp_no_coupon', 'There is no coupon ' . intval( $args['id'] ) . ' on this shop.' );
			}

			return new WC_Coupon( $post->ID );
		}

		$code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $args['code'] ) ) );
		$ids  = $this->ids_by_code( $code );

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_coupon', 'There is no coupon with the code ' . $code . '. woobe_coupons with search finds it by part of the code.' );
		}

		return new WC_Coupon( $ids[0] );
	}

	/**
	 * Ids of coupons carrying this code, in any state but trash.
	 *
	 * wc_get_coupon_id_by_code() looks at published coupons only, which is
	 * right for checkout and wrong here: a scheduled or draft coupon with the
	 * same code would go unnoticed, and the day it publishes the shop would
	 * have two coupons answering to one code.
	 */
	private function ids_by_code( $code, $exclude = 0 ) {

		global $wpdb;

		// The statuses are written out rather than built from LIVE_STATUSES:
		// a fixed list needs no placeholders, so nothing variable reaches the
		// SQL. Keep the two in step if a status is ever added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					  WHERE LOWER( post_title ) = LOWER( %s )
						AND post_type = 'shop_coupon'
						AND post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
						AND ID <> %d
					  ORDER BY post_date DESC",
					$code,
					intval( $exclude )
				)
			)
		);
	}

	/**
	 * Where the coupon stands right now, the way checkout would see it.
	 */
	private function state( $coupon ) {

		$status = $coupon->get_status();

		if ( 'trash' === $status ) {
			return 'trash';
		}

		if ( 'future' === $status ) {
			return 'scheduled';
		}

		if ( 'publish' !== $status ) {
			return 'draft';
		}

		$expires = $coupon->get_date_expires();

		if ( $expires && time() > $expires->getTimestamp() ) {
			return 'expired';
		}

		$limit = intval( $coupon->get_usage_limit() );

		if ( $limit > 0 && intval( $coupon->get_usage_count() ) >= $limit ) {
			return 'used_up';
		}

		return 'active';
	}

	/**
	 * The last day the code works, inclusive, as a date in the shop's zone.
	 *
	 * A coupon saved here expires at 23:59:59, so its own date is the last
	 * day. One saved in wp-admin expires at midnight at the start of the day
	 * shown there, so its last working day is the one before.
	 */
	private function last_day( $coupon ) {

		$expires = $coupon->get_date_expires();

		if ( ! $expires ) {
			return null;
		}

		$local = ( new DateTimeImmutable( '@' . $expires->getTimestamp() ) )->setTimezone( wp_timezone() );

		if ( '00:00:00' === $local->format( 'H:i:s' ) ) {
			$local = $local->modify( '-1 day' );
		}

		return $local->format( 'Y-m-d' );
	}

	private function valid_from( $coupon ) {

		if ( 'future' !== $coupon->get_status() || ! $coupon->get_date_created() ) {
			return null;
		}

		return ( new DateTimeImmutable( '@' . $coupon->get_date_created()->getTimestamp() ) )
			->setTimezone( wp_timezone() )
			->format( 'Y-m-d' );
	}

	private function summary( $coupon, $state = null ) {

		return array(
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'state'         => $state ? $state : $this->state( $coupon ),
			'discount'      => $this->discount_text( $coupon ),
			'valid_from'    => $this->valid_from( $coupon ),
			'last_day'      => $this->last_day( $coupon ),
			'usage_count'   => intval( $coupon->get_usage_count() ),
			'usage_limit'   => intval( $coupon->get_usage_limit() ) ? intval( $coupon->get_usage_limit() ) : null,
			'restricted'    => $this->is_restricted( $coupon ),
		);
	}

	private function describe( $coupon ) {

		return array(
			'id'                     => $coupon->get_id(),
			'code'                   => $coupon->get_code(),
			'description'            => $coupon->get_description(),
			'state'                  => $this->state( $coupon ),
			'discount_type'          => $coupon->get_discount_type(),
			'amount'                 => $coupon->get_amount(),
			'discount'               => $this->discount_text( $coupon ),
			'valid_from'             => $this->valid_from( $coupon ),
			'last_day'               => $this->last_day( $coupon ),
			'minimum_amount'         => $coupon->get_minimum_amount() ? $coupon->get_minimum_amount() : null,
			'maximum_amount'         => $coupon->get_maximum_amount() ? $coupon->get_maximum_amount() : null,
			'usage_count'            => intval( $coupon->get_usage_count() ),
			'usage_limit'            => intval( $coupon->get_usage_limit() ) ? intval( $coupon->get_usage_limit() ) : null,
			'usage_limit_per_user'   => intval( $coupon->get_usage_limit_per_user() ) ? intval( $coupon->get_usage_limit_per_user() ) : null,
			'limit_usage_to_x_items' => intval( $coupon->get_limit_usage_to_x_items() ) ? intval( $coupon->get_limit_usage_to_x_items() ) : null,
			'individual_use'         => (bool) $coupon->get_individual_use(),
			'free_shipping'          => (bool) $coupon->get_free_shipping(),
			'exclude_sale_items'     => (bool) $coupon->get_exclude_sale_items(),
			'products'               => $this->name_products( $coupon->get_product_ids() ),
			'excluded_products'      => $this->name_products( $coupon->get_excluded_product_ids() ),
			'categories'             => $this->name_terms( $coupon->get_product_categories() ),
			'excluded_categories'    => $this->name_terms( $coupon->get_excluded_product_categories() ),
			'allowed_emails'         => array_values( (array) $coupon->get_email_restrictions() ),
			'edit_url'               => $coupon->get_id() ? admin_url( 'post.php?post=' . $coupon->get_id() . '&action=edit' ) : null,
		);
	}

	private function discount_text( $coupon ) {

		$amount = wc_format_decimal( $coupon->get_amount(), wc_get_price_decimals(), true );
		$money  = $amount . ' ' . get_woocommerce_currency();

		switch ( $coupon->get_discount_type() ) {
			case 'percent':
				$text = $amount . '% off the eligible items';
				break;
			case 'fixed_product':
				$text = $money . ' off each eligible item';
				break;
			default:
				$text = $money . ' off the basket';
		}

		if ( $coupon->get_free_shipping() ) {
			$text = ( floatval( $coupon->get_amount() ) > 0 ) ? $text . ', plus free shipping' : 'free shipping';
		}

		return $text;
	}

	private function is_restricted( $coupon ) {

		return (bool) (
			$coupon->get_product_ids()
			|| $coupon->get_excluded_product_ids()
			|| $coupon->get_product_categories()
			|| $coupon->get_excluded_product_categories()
			|| $coupon->get_email_restrictions()
			|| $coupon->get_minimum_amount()
			|| $coupon->get_maximum_amount()
			|| $coupon->get_exclude_sale_items()
			|| $coupon->get_individual_use()
		);
	}

	private function name_products( $ids ) {

		$out = array();

		foreach ( (array) $ids as $id ) {
			$out[] = array(
				'id'   => intval( $id ),
				'name' => $this->product_label( intval( $id ) ),
			);
		}

		return $out;
	}

	private function name_terms( $ids ) {

		$out = array();

		foreach ( (array) $ids as $id ) {
			$term  = get_term( intval( $id ), 'product_cat' );
			$out[] = array(
				'id'   => intval( $id ),
				'name' => ( $term && ! is_wp_error( $term ) ) ? $term->name : '(deleted category #' . intval( $id ) . ')',
			);
		}

		return $out;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// writing

	private function save_coupon( $args ) {

		$is_new = empty( $args['id'] );

		if ( $is_new ) {

			$coupon = new WC_Coupon();

			// the three things a coupon means nothing without; guessing any of
			// them hands a shop a promotion its owner never agreed to
			if ( ! isset( $args['code'] ) || '' === trim( (string) $args['code'] ) ) {
				return new WP_Error( 'woobe_mcp_coupon_code', 'A new coupon needs a code. Ask the user what customers should type - do not make one up.' );
			}

			if ( empty( $args['discount_type'] ) ) {
				return new WP_Error( 'woobe_mcp_coupon_type', 'Say which kind of discount: percent, fixed_cart (a sum off the basket) or fixed_product (a sum off each item). If the user said "10 off", ask whether he meant percent or money.' );
			}

			if ( ! isset( $args['amount'] ) && empty( $args['free_shipping'] ) ) {
				return new WP_Error( 'woobe_mcp_coupon_amount', 'A new coupon needs an amount, unless it only gives free shipping.' );
			}

			$before = null;

		} else {

			$coupon = $this->find_coupon( array( 'id' => $args['id'] ) );

			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}

			// A coupon in the trash may have its code changed and nothing else.
			// That is the way out of a restore clash when the owner wants the
			// old coupon back rather than the new one: rename it here, then
			// restore. Anything more is an edit to a coupon nobody can use, and
			// belongs after the restore, where the answer shows its real state.
			if ( 'trash' === $coupon->get_status() ) {

				$other = array_diff( array_keys( $args ), array( 'id', 'code', 'confirmed' ) );

				if ( ! empty( $other ) || ! isset( $args['code'] ) ) {
					return new WP_Error(
						'woobe_mcp_coupon_trashed',
						'Coupon ' . $coupon->get_code() . ' is in the trash. Only its code can be changed there - to free it from a clash before restoring. Restore it with woobe_restore_coupons first for anything else.'
					);
				}
			}

			$before = $this->describe( $coupon );
		}

		$warnings = $this->apply_fields( $coupon, $args, $is_new );

		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}

		$after = $this->describe( $coupon );

		if ( ! $is_new ) {

			$changes = $this->diff( $before, $after );

			if ( empty( $changes ) ) {
				return new WP_Error( 'woobe_mcp_coupon_unchanged', 'Nothing would change: coupon ' . $coupon->get_code() . ' already has those values.' );
			}
		}

		if ( empty( $args['confirmed'] ) ) {

			$text = $is_new
				? 'Nothing was created yet. This is the coupon as it would be: ' . wp_json_encode( $this->customer_view( $after ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: 'Nothing was changed yet. On coupon ' . $before['code'] . ' these would change, old value first: ' . wp_json_encode( $changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( ! empty( $warnings ) ) {
				$text .= ' Also say this: ' . implode( ' ', $warnings );
			}

			return new WP_Error( 'woobe_mcp_not_confirmed', $text . ' Read it back in plain words and get a clear yes, then call again with confirmed true.' );
		}

		try {
			$coupon->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'woobe_mcp_coupon_save', 'WooCommerce refused the coupon: ' . wp_strip_all_tags( $e->getMessage() ) );
		}

		$saved = new WC_Coupon( $coupon->get_id() );

		$out = array(
			'coupon'   => $this->describe( $saved ),
			'warnings' => $warnings,
			'note'     => $is_new
				? 'Created. Give the user the code, what it does and the days it works, and the edit link.'
				: 'Changed. The previous values are in previous - coupons are not in BEAR history, so these are what putting it back would take.',
		);

		if ( ! $is_new ) {
			$out['previous'] = $this->diff_old( $changes );
		}

		return $out;
	}

	/**
	 * Writes the given fields onto the coupon object, without saving it.
	 *
	 * @return array|WP_Error warnings for the user, or the reason nothing can
	 *                        be saved.
	 */
	private function apply_fields( $coupon, $args, $is_new ) {

		$warnings = array();
		$dp       = wc_get_price_decimals();

		try {

			if ( isset( $args['code'] ) ) {

				$code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $args['code'] ) ) );

				if ( '' === $code ) {
					return new WP_Error( 'woobe_mcp_coupon_code', 'That code is empty once cleaned up.' );
				}

				$taken = $this->ids_by_code( $code, $coupon->get_id() );

				if ( ! empty( $taken ) ) {
					$other = new WC_Coupon( $taken[0] );
					return new WP_Error(
						'woobe_mcp_coupon_code_taken',
						'The code ' . $code . ' already belongs to coupon ' . $taken[0] . ' (' . $this->state( $other ) . ', ' . $this->discount_text( $other ) . '). Codes are not case sensitive. Change that coupon instead, or pick another code.'
					);
				}

				$coupon->set_code( $code );
			}

			if ( isset( $args['description'] ) ) {
				$coupon->set_description( sanitize_textarea_field( wp_unslash( $args['description'] ) ) );
			}

			if ( isset( $args['discount_type'] ) ) {

				$type = sanitize_key( $args['discount_type'] );

				if ( ! array_key_exists( $type, wc_get_coupon_types() ) ) {
					return new WP_Error( 'woobe_mcp_coupon_type', 'Unknown discount type ' . $type . '. This shop has: ' . implode( ', ', array_keys( wc_get_coupon_types() ) ) . '.' );
				}

				$coupon->set_discount_type( $type );
			}

			if ( isset( $args['amount'] ) ) {

				if ( ! is_numeric( $args['amount'] ) || floatval( $args['amount'] ) < 0 ) {
					return new WP_Error( 'woobe_mcp_coupon_amount', 'The amount has to be a number, zero or more.' );
				}

				// checked here, before the setter: WC_Coupon::set_amount()
				// throws on its own for a percentage over 100, with a message
				// that tells the user nothing
				if ( 'percent' === $coupon->get_discount_type() && floatval( $args['amount'] ) > 100 ) {
					return new WP_Error( 'woobe_mcp_coupon_amount', 'A percentage discount cannot be more than 100.' );
				}

				$coupon->set_amount( wc_format_decimal( $args['amount'], $dp ) );
			}

			if ( 'percent' === $coupon->get_discount_type() && floatval( $coupon->get_amount() ) > 100 ) {
				return new WP_Error( 'woobe_mcp_coupon_amount', 'A percentage discount cannot be more than 100.' );
			}

			foreach ( array( 'individual_use', 'free_shipping', 'exclude_sale_items' ) as $flag ) {
				if ( isset( $args[ $flag ] ) ) {
					$coupon->{'set_' . $flag}( (bool) $args[ $flag ] );
				}
			}

			if ( floatval( $coupon->get_amount() ) <= 0 && ! $coupon->get_free_shipping() ) {
				return new WP_Error( 'woobe_mcp_coupon_amount', 'This coupon would give nothing: the amount is zero and it does not grant free shipping.' );
			}

			if ( ! empty( $args['free_shipping'] ) ) {
				$warnings[] = 'Free shipping only happens if a free shipping method on the shop is set to require a coupon - otherwise the code accepts and changes nothing about delivery.';
			}

			foreach ( array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ) as $limit ) {
				if ( isset( $args[ $limit ] ) ) {
					$coupon->{'set_' . $limit}( max( 0, intval( $args[ $limit ] ) ) );
				}
			}

			if ( intval( $coupon->get_limit_usage_to_x_items() ) > 0 && 'fixed_cart' === $coupon->get_discount_type() ) {
				$warnings[] = 'A limit on the number of items does nothing on a fixed_cart coupon - it only applies to percent and fixed_product.';
			}

			// both bounds as they will end up, checked before either setter:
			// WooCommerce refuses a maximum below the minimum itself, but only
			// with "Invalid maximum spend value"
			$min_next = isset( $args['minimum_amount'] ) ? floatval( $args['minimum_amount'] ) : floatval( $coupon->get_minimum_amount() );
			$max_next = isset( $args['maximum_amount'] ) ? floatval( $args['maximum_amount'] ) : floatval( $coupon->get_maximum_amount() );

			if ( $min_next > 0 && $max_next > 0 && $min_next > $max_next ) {
				return new WP_Error( 'woobe_mcp_coupon_bound', 'The minimum basket (' . $min_next . ') is above the maximum (' . $max_next . '), so no basket could ever use the code.' );
			}

			foreach ( array( 'minimum_amount', 'maximum_amount' ) as $bound ) {

				if ( ! isset( $args[ $bound ] ) ) {
					continue;
				}

				$value = trim( (string) $args[ $bound ] );

				if ( '' !== $value && ( ! is_numeric( $value ) || floatval( $value ) < 0 ) ) {
					return new WP_Error( 'woobe_mcp_coupon_bound', $bound . ' has to be a sum, or empty to remove it.' );
				}

				$coupon->{'set_' . $bound}( ( '' === $value || 0.0 === floatval( $value ) ) ? '' : wc_format_decimal( $value, $dp ) );
			}

			$min = floatval( $coupon->get_minimum_amount() );
			$max = floatval( $coupon->get_maximum_amount() );

			if ( $min > 0 && $max > 0 && $min > $max ) {
				return new WP_Error( 'woobe_mcp_coupon_bound', 'The minimum basket (' . $min . ') is above the maximum (' . $max . '), so no basket could ever use the code.' );
			}

			foreach ( array( 'products' => 'set_product_ids', 'excluded_products' => 'set_excluded_product_ids' ) as $key => $setter ) {

				if ( ! isset( $args[ $key ] ) ) {
					continue;
				}

				$ids = $this->check_products( $args[ $key ] );

				if ( is_wp_error( $ids ) ) {
					return $ids;
				}

				$coupon->$setter( $ids );
			}

			foreach ( array( 'categories' => 'set_product_categories', 'excluded_categories' => 'set_excluded_product_categories' ) as $key => $setter ) {

				if ( ! isset( $args[ $key ] ) ) {
					continue;
				}

				$ids = $this->check_categories( $args[ $key ] );

				if ( is_wp_error( $ids ) ) {
					return $ids;
				}

				$coupon->$setter( $ids );
			}

			$overlap = array_intersect( $coupon->get_product_ids(), $coupon->get_excluded_product_ids() );

			if ( ! empty( $overlap ) ) {
				return new WP_Error( 'woobe_mcp_coupon_overlap', 'Products ' . implode( ', ', $overlap ) . ' are both included and excluded. Exclusion wins at checkout, so they would never get the discount - take them out of one list.' );
			}

			$overlap = array_intersect( $coupon->get_product_categories(), $coupon->get_excluded_product_categories() );

			if ( ! empty( $overlap ) ) {
				return new WP_Error( 'woobe_mcp_coupon_overlap', 'Categories ' . implode( ', ', $overlap ) . ' are both included and excluded - take them out of one list.' );
			}

			if ( isset( $args['allowed_emails'] ) ) {

				$emails = array();

				foreach ( (array) $args['allowed_emails'] as $email ) {

					$email = strtolower( trim( sanitize_text_field( wp_unslash( $email ) ) ) );

					if ( '' === $email ) {
						continue;
					}

					// is_email() accepts the * WooCommerce uses as a wildcard
					if ( ! is_email( $email ) ) {
						return new WP_Error( 'woobe_mcp_coupon_email', $email . ' is not an email address. A whole domain is written *@example.com.' );
					}

					$emails[] = $email;
				}

				$coupon->set_email_restrictions( $emails );
			}

			// status before dates: a start date decides between publish and
			// future, and must not be overridden by a plain status afterwards
			if ( isset( $args['status'] ) ) {
				$coupon->set_status( 'draft' === $args['status'] ? 'draft' : 'publish' );
			} elseif ( $is_new ) {
				$coupon->set_status( 'publish' );
			}

			$zone = wp_timezone();
			$now  = time();

			if ( isset( $args['valid_to'] ) ) {

				if ( '' === trim( (string) $args['valid_to'] ) ) {
					$coupon->set_date_expires( null );
				} else {

					$day = $this->local_day( $args['valid_to'], $zone );

					if ( is_wp_error( $day ) ) {
						return $day;
					}

					// the whole of the last day counts, see the class comment
					$end = $day->setTime( 23, 59, 59 )->getTimestamp();

					if ( $end < $now ) {
						if ( $is_new ) {
							return new WP_Error( 'woobe_mcp_coupon_past', 'The last day, ' . $day->format( 'Y-m-d' ) . ', has already passed - the coupon would be dead on arrival.' );
						}
						$warnings[] = 'The last day is in the past, so the code stops working the moment this is saved.';
					}

					$coupon->set_date_expires( $end );
				}
			}

			if ( isset( $args['valid_from'] ) ) {

				if ( '' === trim( (string) $args['valid_from'] ) ) {

					// no start date: a scheduled coupon goes live now
					if ( 'future' === $coupon->get_status() ) {
						$coupon->set_status( 'publish' );
						$coupon->set_date_created( $now );
					}

				} else {

					$day = $this->local_day( $args['valid_from'], $zone );

					if ( is_wp_error( $day ) ) {
						return $day;
					}

					$start = $day->setTime( 0, 0, 0 )->getTimestamp();

					if ( $start > $now ) {

						// WordPress publishes a future post on its date, and
						// WooCommerce ignores the code until it does
						if ( 'draft' !== $coupon->get_status() ) {
							$coupon->set_status( 'future' );
							$warnings[] = 'The start relies on WP-Cron: on a shop where nobody visits the site around midnight, or where cron is switched off, the coupon goes live with the first visit after that instead.';
						} else {
							$warnings[] = 'The coupon is a draft, so it will NOT switch on by itself on the start date - someone has to publish it.';
						}

						$coupon->set_date_created( $start );

					} elseif ( 'future' === $coupon->get_status() ) {

						// a start date that has already come: live now
						$coupon->set_status( 'publish' );
						$coupon->set_date_created( $now );
					}
				}
			}

			// a published coupon must not carry a date in the future, or
			// WordPress quietly turns it back into a scheduled one on save
			if ( 'publish' === $coupon->get_status() && $coupon->get_date_created() && $coupon->get_date_created()->getTimestamp() > $now ) {
				$coupon->set_date_created( $now );
			}

			$expires = $coupon->get_date_expires();

			if ( $expires && 'future' === $coupon->get_status() && $coupon->get_date_created() && $expires->getTimestamp() < $coupon->get_date_created()->getTimestamp() ) {
				return new WP_Error( 'woobe_mcp_coupon_dates', 'The last day comes before the first day, so the code would never work.' );
			}

		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( 'woobe_mcp_coupon_invalid', wp_strip_all_tags( $e->getMessage() ) );
		}

		return $warnings;
	}

	private function local_day( $value, $zone ) {

		$value = trim( sanitize_text_field( (string) $value ) );
		$day   = date_create_immutable( $value, $zone );

		if ( ! $day ) {
			return new WP_Error( 'woobe_mcp_coupon_date', 'Could not read the date ' . $value . '. Use the form 2026-10-31.' );
		}

		return $day;
	}

	private function check_products( $raw ) {

		$ids     = array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ) ) ) );
		$missing = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'trash' === $product->get_status() ) {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error( 'woobe_mcp_coupon_products', 'These are not products on this shop: ' . implode( ', ', $missing ) . '. woobe_find_products gives the right ids.' );
		}

		return $ids;
	}

	private function check_categories( $raw ) {

		$ids     = array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ) ) ) );
		$missing = array();

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error( 'woobe_mcp_coupon_categories', 'These are not product categories: ' . implode( ', ', $missing ) . '. woobe_list_terms with product_cat gives the ids.' );
		}

		return $ids;
	}

	/**
	 * What the customer will experience, for reading a new coupon back.
	 * Empty restrictions are left out so the agent does not recite them.
	 */
	private function customer_view( $d ) {

		$out = array(
			'code'     => $d['code'],
			'discount' => $d['discount'],
			'status'   => $d['state'],
		);

		foreach ( array( 'valid_from', 'last_day', 'minimum_amount', 'maximum_amount', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ) as $key ) {
			if ( ! is_null( $d[ $key ] ) ) {
				$out[ $key ] = $d[ $key ];
			}
		}

		foreach ( array( 'individual_use', 'exclude_sale_items' ) as $key ) {
			if ( $d[ $key ] ) {
				$out[ $key ] = true;
			}
		}

		foreach ( array( 'products', 'excluded_products', 'categories', 'excluded_categories' ) as $key ) {
			if ( ! empty( $d[ $key ] ) ) {
				$out[ $key ] = wp_list_pluck( $d[ $key ], 'name' );
			}
		}

		if ( ! empty( $d['allowed_emails'] ) ) {
			$out['allowed_emails'] = $d['allowed_emails'];
		}

		if ( '' !== (string) $d['description'] ) {
			$out['description'] = $d['description'];
		}

		return $out;
	}

	/**
	 * Fields whose value differs, as field => [old, new].
	 */
	private function diff( $before, $after ) {

		$skip    = array( 'id', 'edit_url', 'usage_count' );
		$changes = array();

		foreach ( $after as $key => $value ) {

			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			if ( wp_json_encode( $value ) !== wp_json_encode( $before[ $key ] ) ) {
				$changes[ $key ] = array( $before[ $key ], $value );
			}
		}

		return $changes;
	}

	private function diff_old( $changes ) {

		$out = array();

		foreach ( $changes as $key => $pair ) {
			$out[ $key ] = $pair[0];
		}

		return $out;
	}

	private function delete_coupons( $args ) {

		$ids = ( isset( $args['ids'] ) && is_array( $args['ids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', $args['ids'] ) ) ) ) : array();

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_coupons', 'No coupons were given.' );
		}

		$found   = array();
		$missing = array();

		foreach ( $ids as $id ) {

			$post = get_post( $id );

			if ( ! $post || 'shop_coupon' !== $post->post_type || 'trash' === $post->post_status ) {
				$missing[] = $id;
				continue;
			}

			$found[ $id ] = new WC_Coupon( $id );
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error( 'woobe_mcp_no_coupon', 'Not a coupon on this shop, or already in the trash: ' . implode( ', ', $missing ) . '.' );
		}

		if ( empty( $args['confirmed'] ) ) {

			$rows = array();

			foreach ( $found as $coupon ) {
				$rows[] = $this->summary( $coupon );
			}

			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was deleted. Read this back and get a yes: ' . wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. '. The codes stop working at once; they can be brought back from the trash with woobe_restore_coupons.'
			);
		}

		$trashed = array();
		$failed  = array();

		foreach ( $found as $id => $coupon ) {

			if ( wp_trash_post( $id ) ) {
				$trashed[] = array(
					'id'   => $id,
					'code' => $coupon->get_code(),
				);
			} else {
				$failed[] = $id;
			}
		}

		return array(
			'trashed' => $trashed,
			'failed'  => $failed,
			'note'    => 'In the trash, not gone. Name the codes back, and say woobe_restore_coupons with these ids undoes it.',
		);
	}

	private function restore_coupons( $args ) {

		$ids = ( isset( $args['ids'] ) && is_array( $args['ids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', $args['ids'] ) ) ) ) : array();

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_coupons', 'No coupons were given.' );
		}

		$restored = array();
		$skipped  = array();
		$clashes  = array();

		foreach ( $ids as $id ) {

			$post = get_post( $id );

			if ( ! $post || 'shop_coupon' !== $post->post_type || 'trash' !== $post->post_status ) {
				$skipped[] = $id;
				continue;
			}

			// A trashed code is free to be used again, and may have been. Two
			// live coupons answering to one code is worse than a refusal:
			// checkout silently picks the newer one.
			$code  = wc_format_coupon_code( $post->post_title );
			$taken = $this->ids_by_code( $code, $id );

			if ( ! empty( $taken ) ) {
				$clashes[] = array(
					'id'       => $id,
					'code'     => $code,
					'taken_by' => $taken[0],
				);
				continue;
			}

			// wp_untrash_post() brings a post back as a draft since WordPress
			// 5.6 unless told otherwise; a coupon that was live should be
			// live, and core ships the helper that restores the old status
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
			$ok = wp_untrash_post( $id );
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );

			if ( $ok ) {
				$coupon     = new WC_Coupon( $id );
				$restored[] = $this->summary( $coupon );
			} else {
				$skipped[] = $id;
			}
		}

		return array(
			'restored' => $restored,
			'skipped'  => $skipped,
			'clashes'  => $clashes,
			'note'     => 'skipped are ids that were not coupons in the trash. clashes stayed in the trash because another coupon now uses the same code - say which, and ask which one should get a new code: either can be renamed with woobe_save_coupon, the trashed one included, and then restored. A restored coupon is back with the status it had; check state - one whose last day passed while it was in the trash comes back expired.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// usage

	private function coupon_usage( $args ) {

		global $wpdb;

		$stats = $this->stats_table();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		$coupons = $wpdb->prefix . 'wc_order_coupon_lookup';

		if ( ! $this->table_exists( $coupons ) ) {
			return new WP_Error( 'woobe_mcp_no_coupon_lookup', 'The coupon lookup table is missing, so coupon figures are not available.' );
		}

		$p = $this->period( $args );

		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$m         = $this->money_sql( 's' );
		$status_ph = $this->placeholders( $p['statuses'] );
		$params    = array_merge( $p['statuses'], array( $p['sql_from'], $p['sql_to'] ) );

		// the coupon post may have been deleted since; the lookup keeps the id,
		// so the code is joined in rather than assumed to exist
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.coupon_id,
						COUNT( DISTINCT c.order_id )  AS orders,
						SUM( c.discount_amount / {$m['rate']} ) AS discount
				   FROM {$coupons} AS c
				   INNER JOIN {$stats} AS s ON s.order_id = c.order_id
				   {$m['join']}
				  WHERE s.parent_id = 0
					AND s.status IN ({$status_ph})
					AND s.date_created BETWEEN %s AND %s
				  GROUP BY c.coupon_id",
				$params
			),
			ARRAY_A
		);

		$total_orders   = 0;
		$total_discount = 0.0;

		foreach ( (array) $rows as $r ) {
			$total_orders   += intval( $r['orders'] );
			$total_discount += floatval( $r['discount'] );
		}

		$out = array();

		foreach ( (array) $rows as $r ) {

			$coupon_id = intval( $r['coupon_id'] );
			$orders    = intval( $r['orders'] );
			$discount  = round( floatval( $r['discount'] ), $this->decimals() );
			$post      = get_post( $coupon_id );

			$out[] = array(
				'coupon_id'        => $coupon_id,
				'code'             => $post ? $post->post_title : '(deleted coupon #' . $coupon_id . ')',
				'orders'           => $orders,
				'share'            => $total_orders ? round( $orders * 100 / $total_orders, 1 ) : 0,
				'discount'         => $discount,
				'average_discount' => $orders ? round( $discount / $orders, $this->decimals() ) : 0,
			);
		}

		$order_by = isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'orders';

		usort(
			$out,
			function ( $a, $b ) use ( $order_by ) {
				return 'discount' === $order_by
					? $b['discount'] <=> $a['discount']
					: $b['orders'] <=> $a['orders'];
			}
		);

		$limit = isset( $args['limit'] ) ? min( 100, max( 1, intval( $args['limit'] ) ) ) : 50;

		return array(
			'period'         => $p['label'],
			'statuses'       => $p['statuses'],
			'currency'       => $this->currency_status( $p ),
			'coupons_used'   => count( $out ),
			'coupon_orders'  => $total_orders,
			'total_discount' => round( $total_discount, $this->decimals() ),
			'rows'           => array_slice( $out, 0, $limit ),
			'note'           => 'share is the percentage of coupon orders, not of all orders - an order using two codes counts once for each. Orders placed without any coupon do not appear here at all.',
		);
	}
}