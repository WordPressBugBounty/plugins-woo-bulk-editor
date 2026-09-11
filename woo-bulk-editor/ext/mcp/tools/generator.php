<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Filling a development shop with products.
 *
 * Not a tool for a live catalogue. It exists because testing anything - a
 * filter, an import, a theme, a bulk operation - needs a shop with more than
 * six products in it, and building one by hand is an afternoon nobody enjoys.
 *
 * Two ways to produce the items, and the choice is about cost rather than
 * quality of code.
 *
 * Written out by the assistant: it composes each product itself and passes them
 * in. Names read like a real catalogue and no two are alike. Fine for fifty,
 * absurd for a thousand - a thousand product descriptions is more text than the
 * conversation around them.
 *
 * Built from a vocabulary: the assistant supplies a few dozen words and some
 * sentence patterns, and the server combines them. Forty words make thousands
 * of unlikely-to-repeat names for the price of forty words. Less characterful,
 * and the only sane way to reach four figures.
 *
 * The whole batch is worked out and stored when the plan is made, not while it
 * is being written. So the preview is the truth rather than a guess, names are
 * checked for collisions once, and a run that stops halfway can be picked up
 * where it left off.
 */
final class WOOBE_MCP_TOOL_GENERATOR extends WOOBE_MCP_TOOL {

	const PLAN_PREFIX = 'woobe_mcp_genplan_';
	const PLAN_TTL    = 7200;
	const MAX_ITEMS   = 5000;
	const PORTION     = 50;

	public function tools() {

		return array(

			'woobe_generate_plan' => array(
				'name'        => 'woobe_generate_plan',
				'description' => 'Works out a batch of products to create and returns a plan id, without writing anything. For a development shop that needs filling - never for a live catalogue.

ASK FIRST, in one message rather than six. Six things decide the shape of the batch and none of them is guessable:

1. What the shop sells - a few words. Everything else follows from it: the names, the descriptions, which categories make sense.
2. How many products.
3. Price range. If he has no preference say you are using 10 to 1000 and move on.
4. Which product types, or what mix - all simple, mostly simple with some variable, whatever he needs to test.
5. Stock - mixed (most tracked, a fifth not, like a real shop), managed (a number on everything), or none. And the quantity range if it matters.
6. Whether SKUs need a prefix. Without one they are built from the product name, which is usually fine.

Offer sensible defaults alongside the questions so he can answer "just do it" and get something reasonable. Do not interrogate him.

Then choose how to produce them. Up to about a hundred, write them yourself and pass them in "items": the names read properly and each one is considered. Beyond that use "vocabulary" - a few dozen words the server combines - because writing a thousand descriptions costs more than everything else in the conversation put together. Say which you are doing and why, and offer the expensive one anyway if the user wants it: it is his budget, and the result is better.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'count'      => array(
							'type'        => 'integer',
							'description' => 'How many products to create.',
						),
						'theme'      => array(
							'type'        => 'string',
							'description' => 'What the shop sells, in a few words - "outdoor clothing", "coffee equipment". Recorded with the plan so the batch can be recognised later.',
						),
						'items'      => array(
							'type'        => 'array',
							'description' => 'Products written out one by one. Each is an object with name, and optionally type, description, short_description, price, sku, stock. Anything left out is filled in from the settings below. Use this for small batches.',
							'items'       => array( 'type' => 'object' ),
						),
						'vocabulary' => array(
							'type'        => 'object',
							'description' => 'Words to build names and descriptions from, for large batches. adjectives, materials and nouns are combined into names; sentences are description patterns where {name}, {material} and {adjective} are replaced. Twelve of each is plenty - the combinations multiply.',
							'properties'  => array(
								'adjectives' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'materials'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'nouns'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'sentences'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
						'price_min'  => array(
							'type'        => 'number',
							'description' => 'Ask for a range. If the user has no preference, say you are using 10 to 1000 and move on rather than pressing him for a number he does not care about.',
						),
						'price_max'  => array( 'type' => 'number' ),
						'sale_depth' => array(
							'type'        => 'object',
							'description' => 'How deep the discounts go, as percentages - {"min":20,"max":50}. Defaults to 20 to 50, varied per product rather than the same everywhere.',
							'properties'  => array(
								'min' => array( 'type' => 'integer' ),
								'max' => array( 'type' => 'integer' ),
							),
						),
						'type_mix'   => array(
							'type'        => 'object',
							'description' => 'Percentages by product type - {"simple":70,"variable":25,"external":5}. Defaults to mostly simple with a quarter variable. grouped is built from products created earlier in the same batch.',
							'properties'  => array(
								'simple'   => array( 'type' => 'integer' ),
								'variable' => array( 'type' => 'integer' ),
								'external' => array( 'type' => 'integer' ),
								'grouped'  => array( 'type' => 'integer' ),
							),
						),
						'categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Category ids to spread the products across. Leave it out to use whatever categories the shop already has.',
						),
						'taxonomies' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Other taxonomies to assign at random - product_tag, product_brand. Existing terms only; nothing new is created.',
						),
						'attributes' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Attribute taxonomies for the variable products - pa_color, pa_size. Existing terms are used and nothing new is created. Ask the user which ones he wants and tell him three is the ceiling: a fourth axis turns eight variations per product into eighty, and the shop is then slow for the wrong reason. Leave it out and the shop\'s own attributes are used, up to three.',
						),
						'stock'      => array(
							'type'        => 'string',
							'enum'        => array( 'mixed', 'managed', 'none' ),
							'description' => 'How stock is handled. mixed is the default and looks like a real shop: most products tracked, a fifth not. managed puts a number on everything. none leaves stock management off entirely. Ask which he wants - a shop for testing stock reports needs managed, one for testing a theme does not care.',
						),
						'stock_min'  => array(
							'type'        => 'integer',
							'description' => 'Lower bound for quantities. Defaults to 0, so some products come out sold out - worth having in a test catalogue.',
						),
						'stock_max'  => array(
							'type'        => 'integer',
							'description' => 'Upper bound for quantities. Defaults to 100.',
						),
						'sku_prefix' => array(
							'type'        => 'string',
							'description' => 'Prefix for generated SKUs - "DEV" gives DEV-0001. Leave it out and they are built from the product name. Every product and every variation gets one either way.',
						),
						'sale_chance' => array(
							'type'        => 'integer',
							'description' => 'Percentage of products that get a sale price. Defaults to 20.',
						),
						'status'     => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'description' => 'Defaults to publish - a shop being filled for testing wants visible products.',
						),
					),
					'required'   => array( 'count' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_generate_run' => array(
				'name'        => 'woobe_generate_run',
				'description' => 'Creates the next portion of a planned batch. Call it repeatedly with the plan id until done is true - fifty at a time, because a thousand products in one request times out on any shop. Tell the user the running total between calls rather than waiting silently; a batch of a thousand takes a few minutes and a quiet minute looks like a hang.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plan_id' => array( 'type' => 'string' ),
						'limit'   => array(
							'type'        => 'integer',
							'description' => 'How many to create in this call. Defaults to fifty; lower it on a slow shop.',
						),
					),
					'required'   => array( 'plan_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_generate_plan':
				return $this->plan( $args );
			case 'woobe_generate_run':
				return $this->run( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function plan( $args ) {

		$count = isset( $args['count'] ) ? intval( $args['count'] ) : 0;

		if ( $count < 1 ) {
			return new WP_Error( 'woobe_mcp_no_count', 'How many products should be created?' );
		}

		if ( $count > self::MAX_ITEMS ) {
			return new WP_Error(
				'woobe_mcp_too_many',
				'That is ' . $count . ' products. The cap is ' . self::MAX_ITEMS . ' in one batch - beyond that the shop is slow to open long before the generator finishes, and a second batch costs nothing.'
			);
		}

		$settings = array(
			'theme'       => isset( $args['theme'] ) ? sanitize_text_field( $args['theme'] ) : '',
			'price_min'   => isset( $args['price_min'] ) ? floatval( $args['price_min'] ) : 10,
			'price_max'   => isset( $args['price_max'] ) ? floatval( $args['price_max'] ) : 1000,
			'sale_min'    => isset( $args['sale_depth']['min'] ) ? max( 1, min( 90, intval( $args['sale_depth']['min'] ) ) ) : 20,
			'sale_max'    => isset( $args['sale_depth']['max'] ) ? max( 1, min( 90, intval( $args['sale_depth']['max'] ) ) ) : 50,
			'stock_mode'  => ( isset( $args['stock'] ) && in_array( $args['stock'], array( 'mixed', 'managed', 'none' ), true ) ) ? $args['stock'] : 'mixed',
			'stock_min'   => isset( $args['stock_min'] ) ? max( 0, intval( $args['stock_min'] ) ) : 0,
			'stock_max'   => isset( $args['stock_max'] ) ? intval( $args['stock_max'] ) : 100,
			'sku_prefix'  => isset( $args['sku_prefix'] ) ? strtoupper( sanitize_key( $args['sku_prefix'] ) ) : '',
			'sale_chance' => isset( $args['sale_chance'] ) ? intval( $args['sale_chance'] ) : 20,
			'status'      => ( isset( $args['status'] ) && 'draft' === $args['status'] ) ? 'draft' : 'publish',
		);

		if ( $settings['price_max'] < $settings['price_min'] ) {
			$settings['price_max'] = $settings['price_min'] + 50;
		}

		$categories = $this->pick_categories( $args );
		$taxonomies = $this->pick_taxonomies( $args );
		$attributes = $this->pick_attributes( $args );
		$mix        = $this->pick_mix( $args, $attributes );

		// worked out now rather than during the run: the preview is then the
		// truth, and a run that dies halfway resumes without repeating itself
		$items = ! empty( $args['items'] ) && is_array( $args['items'] )
			? $this->items_from_list( $args['items'], $count, $settings, $mix )
			: $this->items_from_vocabulary( $args, $count, $settings, $mix );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$plan_id = 'gen_' . bin2hex( random_bytes( 6 ) );

		set_transient(
			self::PLAN_PREFIX . $plan_id,
			array(
				'items'      => $items,
				'settings'   => $settings,
				'categories' => $categories,
				'taxonomies' => $taxonomies,
				'attributes' => $attributes,
				'created'    => array(),
				'offset'     => 0,
			),
			self::PLAN_TTL
		);

		$by_type = array();

		foreach ( $items as $item ) {
			$by_type[ $item['type'] ] = isset( $by_type[ $item['type'] ] ) ? $by_type[ $item['type'] ] + 1 : 1;
		}

		return array(
			'plan_id'    => $plan_id,
			'count'      => count( $items ),
			'by_type'    => $by_type,
			'price_range' => $settings['price_min'] . ' - ' . $settings['price_max'],
			'status'     => $settings['status'],
			'categories' => count( $categories ),
			'taxonomies' => array_keys( $taxonomies ),
			'attributes' => array_keys( $attributes ),
			'sample'     => array_slice(
				array_map(
					function ( $item ) {
						return array(
							'name'  => $item['name'],
							'type'  => $item['type'],
							'price' => $item['price'],
						);
					},
					$items
				),
				0,
				8
			),
			'note'       => 'Nothing was created. Show the user the counts and a few of the sample names so he can see the shape of it, then call woobe_generate_run with this plan_id, repeatedly, until done comes back true. The plan lasts two hours.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function run( $args ) {

		$plan_id = isset( $args['plan_id'] ) ? sanitize_text_field( $args['plan_id'] ) : '';
		$plan    = $plan_id ? get_transient( self::PLAN_PREFIX . $plan_id ) : false;

		if ( ! is_array( $plan ) ) {
			return new WP_Error(
				'woobe_mcp_no_plan',
				'No such plan, or it has expired - plans last two hours. Anything already created is in the catalogue; make a new plan for the rest.'
			);
		}

		$limit  = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : self::PORTION;
		$offset = intval( $plan['offset'] );
		$total  = count( $plan['items'] );
		$made   = array();
		$failed = 0;

		$started = time();

		for ( $i = $offset; $i < $total && count( $made ) < $limit; $i++ ) {

			$id = $this->build( $plan['items'][ $i ], $plan );

			if ( $id ) {
				$made[] = $id;
			} else {
				++$failed;
			}

			// a shop on shared hosting is slower than the arithmetic suggests,
			// and a timeout mid-product leaves a half written one behind
			if ( ( time() - $started ) >= 20 ) {
				++$i;
				break;
			}
		}

		$plan['offset']  = $i;
		$plan['created'] = array_merge( $plan['created'], $made );

		set_transient( self::PLAN_PREFIX . $plan_id, $plan, self::PLAN_TTL );

		$this->mcp->clear_caches_for( $made );

		$done = $i >= $total;

		if ( $done ) {

			$by_type = array();

			foreach ( $plan['items'] as $item ) {
				$by_type[ $item['type'] ] = isset( $by_type[ $item['type'] ] ) ? $by_type[ $item['type'] ] + 1 : 1;
			}

			return array(
				'plan_id'   => $plan_id,
				'created'   => count( $made ),
				'total'     => count( $plan['created'] ),
				'planned'   => $total,
				'failed'    => $failed,
				'done'      => true,
				// ids is what this call made, the same as in every other call;
				// all_ids is the whole batch, for checking or cleaning it up
				// without searching by name
				'ids'       => array_map( 'intval', $made ),
				'all_ids'   => array_map( 'intval', $plan['created'] ),
				'by_type'   => $by_type,
				'note'      => 'Finished. Give the user a short table - how many of each type, the price range, and that they are ' . $plan['settings']['status'] . '. Then stop: he asked for a filled shop, not a report on it.',
			);
		}

		return array(
			'plan_id'      => $plan_id,
			'created'      => count( $made ),
			'total'        => count( $plan['created'] ),
			'planned'      => $total,
			'failed'       => $failed,
			'done'         => false,
			'ids'          => array_map( 'intval', $made ),
			'percent_done' => intval( round( $i * 100 / $total ) ),
			'note'         => 'Call woobe_generate_run again with the same plan_id. Say the running total as you go - ' . count( $plan['created'] ) . ' of ' . $total . ' - because a silent minute looks like a hang.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// working out what to make

	private function items_from_list( $list, $count, $settings, $mix ) {

		$items = array();

		foreach ( $list as $raw ) {

			if ( count( $items ) >= $count ) {
				break;
			}

			$name = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : $this->roll_type( $mix );

			$items[] = array(
				'name'              => $name,
				'type'              => in_array( $type, array( 'simple', 'variable', 'external', 'grouped' ), true ) ? $type : 'simple',
				'description'       => isset( $raw['description'] ) ? wp_kses_post( $raw['description'] ) : '',
				'short_description' => isset( $raw['short_description'] ) ? wp_kses_post( $raw['short_description'] ) : '',
				'price'             => isset( $raw['price'] ) ? (string) $raw['price'] : $this->roll_price( $settings ),
				'sku'               => isset( $raw['sku'] ) ? sanitize_text_field( $raw['sku'] ) : $this->make_sku( $name, count( $items ) + 1, $settings['sku_prefix'] ),
				'stock'             => isset( $raw['stock'] ) ? intval( $raw['stock'] ) : $this->roll_stock( $settings ),
				// A price the user wrote out is the price he wants. Rolling a
				// discount under it anyway put sale prices on items that were
				// given 11, 12 and 13 on purpose, with nothing in the plan to
				// say so. Random sales stay for invented prices only.
				'sale'              => isset( $raw['price'] ) ? false : $this->roll_sale( $settings ),
			);
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'woobe_mcp_empty_list', 'None of the items had a name.' );
		}

		return $items;
	}

	private function items_from_vocabulary( $args, $count, $settings, $mix ) {

		$vocab = isset( $args['vocabulary'] ) && is_array( $args['vocabulary'] ) ? $args['vocabulary'] : array();

		$adjectives = $this->words( $vocab, 'adjectives' );
		$materials  = $this->words( $vocab, 'materials' );
		$nouns      = $this->words( $vocab, 'nouns' );
		$sentences  = $this->words( $vocab, 'sentences' );

		if ( empty( $nouns ) ) {
			return new WP_Error(
				'woobe_mcp_no_vocabulary',
				'Names have to come from somewhere. Either write the products out in items - name for each, and optionally price, description, sku, stock; the right choice for a small batch like ' . intval( $count ) . ' - or pass a vocabulary for the server to combine: nouns at least, preferably adjectives and materials too, a dozen of each. Ask the user what the shop sells and write words that fit it.'
			);
		}

		$combinations = max( 1, count( $nouns ) ) * max( 1, count( $adjectives ) ) * max( 1, count( $materials ) );

		if ( $combinations < $count ) {
			return new WP_Error(
				'woobe_mcp_thin_vocabulary',
				'Those words make about ' . $combinations . ' distinct names and ' . $count . ' are needed, so the batch would be full of near-duplicates. Add more nouns - they multiply fastest.'
			);
		}

		$items = array();
		$used  = array();
		$tries = 0;

		while ( count( $items ) < $count && $tries < $count * 20 ) {

			++$tries;

			$noun = $nouns[ array_rand( $nouns ) ];
			$adj  = empty( $adjectives ) ? '' : $adjectives[ array_rand( $adjectives ) ];
			$mat  = empty( $materials ) ? '' : $materials[ array_rand( $materials ) ];

			$name = trim( $adj . ' ' . $mat . ' ' . $noun );
			$key  = strtolower( $name );

			if ( isset( $used[ $key ] ) ) {
				continue;
			}

			$used[ $key ] = true;

			$sentence = empty( $sentences ) ? '' : $sentences[ array_rand( $sentences ) ];
			$body     = str_replace(
				array( '{name}', '{material}', '{adjective}', '{noun}' ),
				array( $name, $mat, $adj, $noun ),
				$sentence
			);

			$items[] = array(
				'name'              => $name,
				'type'              => $this->roll_type( $mix ),
				'description'       => $body,
				'short_description' => $body ? wp_trim_words( $body, 14 ) : '',
				'price'             => $this->roll_price( $settings ),
				'sku'               => $this->make_sku( $name, count( $items ) + 1, $settings['sku_prefix'] ),
				'stock'             => $this->roll_stock( $settings ),
				'sale'              => $this->roll_sale( $settings ),
			);
		}

		return $items;
	}

	private function words( $vocab, $key ) {

		if ( empty( $vocab[ $key ] ) || ! is_array( $vocab[ $key ] ) ) {
			return array();
		}

		$out = array();

		foreach ( $vocab[ $key ] as $word ) {

			$word = trim( sanitize_text_field( $word ) );

			if ( '' !== $word ) {
				$out[] = $word;
			}
		}

		return array_values( array_unique( $out ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// dice

	private function pick_mix( $args, $attributes ) {

		$mix = array(
			'simple'   => 70,
			'variable' => 20,
			'external' => 7,
			'grouped'  => 3,
		);

		if ( ! empty( $args['type_mix'] ) && is_array( $args['type_mix'] ) ) {

			$mix = array(
				'simple'   => isset( $args['type_mix']['simple'] ) ? intval( $args['type_mix']['simple'] ) : 0,
				'variable' => isset( $args['type_mix']['variable'] ) ? intval( $args['type_mix']['variable'] ) : 0,
				'external' => isset( $args['type_mix']['external'] ) ? intval( $args['type_mix']['external'] ) : 0,
				'grouped'  => isset( $args['type_mix']['grouped'] ) ? intval( $args['type_mix']['grouped'] ) : 0,
			);
		}

		// no attributes to vary along means no variable products, whatever the
		// mix says - a variable product without variations is a broken one
		if ( empty( $attributes ) ) {
			$mix['simple']  += $mix['variable'];
			$mix['variable'] = 0;
		}

		if ( array_sum( $mix ) < 1 ) {
			$mix['simple'] = 100;
		}

		return $mix;
	}

	private function roll_type( $mix ) {

		$total = array_sum( $mix );
		$roll  = wp_rand( 1, $total );
		$seen  = 0;

		foreach ( $mix as $type => $share ) {

			$seen += $share;

			if ( $roll <= $seen ) {
				return $type;
			}
		}

		return 'simple';
	}

	/**
	 * A price that looks like somebody set it rather than a random number.
	 *
	 * Real catalogues mix three habits: round figures, the ones ending in 99 or
	 * 95, and prices that are simply whatever the sum came to. A generator that
	 * uses only one of them produces a catalogue that reads as fake at a glance,
	 * which defeats the point of filling a shop to test against.
	 */
	private function roll_price( $settings ) {

		$min = intval( $settings['price_min'] * 100 );
		$max = intval( $settings['price_max'] * 100 );

		if ( $max <= $min ) {
			$max = $min + 100;
		}

		$value = wp_rand( $min, $max ) / 100;
		$roll  = wp_rand( 1, 100 );

		if ( $roll <= 40 ) {
			// round: 45.00, 120.00
			$value = max( 1, round( $value ) );
		} elseif ( $roll <= 80 ) {
			// the shopkeeper's ending: 44.99, 119.95
			$value = max( 1, round( $value ) ) - ( wp_rand( 0, 1 ) ? 0.01 : 0.05 );
		}
		// the remaining fifth keeps its cents exactly as they fell

		return number_format( max( 0.5, $value ), 2, '.', '' );
	}

	private function roll_stock( $settings ) {

		if ( 'none' === $settings['stock_mode'] ) {
			return null;
		}

		// mixed: a fifth of a real catalogue does not track stock at all, and a
		// test shop without that fifth hides every bug that only shows up when
		// stock is unmanaged
		if ( 'mixed' === $settings['stock_mode'] && wp_rand( 1, 100 ) <= 20 ) {
			return null;
		}

		$min = intval( $settings['stock_min'] );
		$max = max( $min, intval( $settings['stock_max'] ) );

		return wp_rand( $min, $max );
	}

	/**
	 * A SKU for every product and every variation.
	 *
	 * A generated shop without SKUs is useless for testing half of what a bulk
	 * editor does - searching by SKU, importing, exporting, spotting duplicates.
	 * Built from the name when no prefix is given, so it stays readable.
	 */
	private function make_sku( $name, $index, $prefix, $suffix = '' ) {

		if ( '' !== $prefix ) {
			$base = $prefix . '-' . str_pad( $index, 4, '0', STR_PAD_LEFT );
		} else {

			$words = preg_split( '/\s+/', $name );
			$stub  = '';

			foreach ( array_slice( $words, 0, 3 ) as $word ) {
				$stub .= strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $word ), 0, 3 ) );
			}

			$base = ( '' === $stub ? 'SKU' : $stub ) . '-' . str_pad( $index, 4, '0', STR_PAD_LEFT );
		}

		return $suffix ? $base . '-' . $suffix : $base;
	}

	private function roll_sale( $settings ) {
		return wp_rand( 1, 100 ) <= intval( $settings['sale_chance'] );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// what the shop already has

	private function pick_categories( $args ) {

		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			return array_map( 'intval', $args['categories'] );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 50,
			)
		);

		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	private function pick_taxonomies( $args ) {

		$wanted = ! empty( $args['taxonomies'] ) && is_array( $args['taxonomies'] )
			? array_map( 'sanitize_text_field', $args['taxonomies'] )
			: array( 'product_tag' );

		$out = array();

		foreach ( $wanted as $taxonomy ) {

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => 100,
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$out[ $taxonomy ] = array_map( 'intval', $terms );
			}
		}

		return $out;
	}

	private function pick_attributes( $args ) {

		$wanted = ! empty( $args['attributes'] ) && is_array( $args['attributes'] )
			? array_map( 'sanitize_text_field', $args['attributes'] )
			: wc_get_attribute_taxonomy_names();

		$out = array();

		foreach ( (array) $wanted as $taxonomy ) {

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 30,
				)
			);

			if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
				continue;
			}

			$out[ $taxonomy ] = array();

			foreach ( $terms as $term ) {
				$out[ $taxonomy ][] = array(
					'id'   => intval( $term->term_id ),
					'slug' => $term->slug,
				);
			}
		}

		// three is the ceiling. A fourth axis multiplies the variations past the
		// point where a generated catalogue is useful to test against - and a
		// product with eighty variations is slow to open for reasons that have
		// nothing to do with what is being tested.
		return array_slice( $out, 0, 3, true );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// writing

	/**
	 * A name no product in the shop is using yet.
	 *
	 * Suffixed rather than rejected: the caller asked for a hundred products
	 * and would rather have "Wool Scarf 2" than ninety nine.
	 */
	private function unique_name( $name ) {

		$try   = $name;
		$round = 1;

		while ( $round < 50 && $this->name_taken( $try ) ) {
			++$round;
			$try = $name . ' ' . $round;
		}

		// fifty collisions means the vocabulary is exhausted rather than
		// unlucky; a random tail keeps the run going
		return $this->name_taken( $try ) ? $name . ' ' . wp_generate_password( 4, false, false ) : $try;
	}

	private function name_taken( $name ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type = 'product'
					AND post_status != 'trash'
					AND post_title = %s
				  LIMIT 1",
				$name
			)
		);
	}

	/**
	 * A SKU nothing in the shop is using.
	 *
	 * wc_product_has_unique_sku is the same check WooCommerce runs on save, so
	 * asking it first turns a lost product into a suffixed one.
	 */
	private function unique_sku( $sku ) {

		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return $sku;
		}

		$try   = $sku;
		$round = 1;

		while ( $round < 50 && wc_get_product_id_by_sku( $try ) ) {
			++$round;
			$try = $sku . '-' . $round;
		}

		return wc_get_product_id_by_sku( $try ) ? $sku . '-' . wp_generate_password( 4, false, false ) : $try;
	}

	private function build( $item, $plan ) {

		$settings = $plan['settings'];

		// Checked against the shop as it is now, not against the plan. The plan
		// only knows what it is about to create; the catalogue may already hold
		// a product of the same name from an earlier run, or from the shop's
		// real life. A duplicate name is confusing rather than fatal, but a
		// duplicate SKU makes WooCommerce refuse the product outright and the
		// whole row is lost.
		$item['name'] = $this->unique_name( $item['name'] );

		if ( '' !== $item['sku'] ) {
			$item['sku'] = $this->unique_sku( $item['sku'] );
		}

		switch ( $item['type'] ) {
			case 'variable':
				$product = new WC_Product_Variable();
				break;
			case 'external':
				$product = new WC_Product_External();
				break;
			case 'grouped':
				$product = new WC_Product_Grouped();
				break;
			default:
				$product = new WC_Product_Simple();
		}

		$product->set_name( $item['name'] );
		$product->set_status( $settings['status'] );

		if ( '' !== $item['description'] ) {
			$product->set_description( $item['description'] );
		}

		if ( '' !== $item['short_description'] ) {
			$product->set_short_description( $item['short_description'] );
		}

		if ( in_array( $item['type'], array( 'simple', 'external' ), true ) ) {

			$product->set_regular_price( $item['price'] );

			if ( $item['sale'] ) {

				// a different depth per product: one shop where everything is
				// exactly twenty percent off is a shop nobody believes
				$off   = wp_rand( intval( $settings['sale_min'] ), intval( $settings['sale_max'] ) );
				$value = floatval( $item['price'] ) * ( 100 - $off ) / 100;

				$product->set_sale_price( number_format( max( 0.5, $value ), 2, '.', '' ) );
			}
		}

		// Stock belongs to products the shop actually holds. A grouped product
		// is a list of other products and an external one is sold elsewhere -
		// WooCommerce throws on both rather than ignoring it, which takes the
		// whole run down with it.
		if ( ! is_null( $item['stock'] ) && in_array( $item['type'], array( 'simple', 'variable' ), true ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $item['stock'] );
		}

		if ( ! empty( $plan['categories'] ) ) {
			$product->set_category_ids( $this->some_of( $plan['categories'], 1, 2 ) );
		}

		if ( 'external' === $item['type'] ) {
			$product->set_product_url( 'https://example.com/' . sanitize_title( $item['name'] ) );
			$product->set_button_text( 'Buy from the maker' );
		}

		if ( 'grouped' === $item['type'] && ! empty( $plan['created'] ) ) {
			$product->set_children( $this->some_of( $plan['created'], 2, 4 ) );
		}

		if ( 'variable' === $item['type'] && ! empty( $plan['attributes'] ) ) {
			$product->set_attributes( $this->attribute_objects( $plan['attributes'] ) );
		}

		try {
			$id = $product->save();
		} catch ( Exception $e ) {
			// one product WooCommerce refuses should not end a run of five
			// hundred; the caller counts it as failed and carries on
			return 0;
		}

		if ( ! $id ) {
			return 0;
		}

		// after the save, or the uniqueness check runs against an id of zero
		// and decides every SKU is taken
		if ( '' !== $item['sku'] ) {
			try {
				$product->set_sku( $item['sku'] );
				$product->save();
			} catch ( Exception $e ) {
				// a clash costs the SKU, not the product
			}
		}

		foreach ( $plan['taxonomies'] as $taxonomy => $term_ids ) {
			wp_set_object_terms( $id, $this->some_of( $term_ids, 1, 3 ), $taxonomy, false );
		}

		if ( 'variable' === $item['type'] && ! empty( $plan['attributes'] ) ) {
			$this->build_variations( $id, $item, $plan['attributes'] );
			WC_Product_Variable::sync( $id );
		}

		return $id;
	}

	private function attribute_objects( $attributes ) {

		$out      = array();
		$position = 0;

		foreach ( $attributes as $taxonomy => $terms ) {

			$object = new WC_Product_Attribute();
			$object->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$object->set_name( $taxonomy );
			$object->set_options( wp_list_pluck( $terms, 'id' ) );
			$object->set_position( $position++ );
			$object->set_visible( true );
			$object->set_variation( true );

			$out[] = $object;
		}

		return $out;
	}

	private function build_variations( $parent_id, $item, $attributes ) {

		// every combination of the first two attributes, capped: a generated
		// catalogue is more useful with many products than with many variations
		// of a few
		$combos = array( array() );

		foreach ( $attributes as $taxonomy => $terms ) {

			$next = array();

			foreach ( $combos as $combo ) {
				foreach ( $terms as $term ) {
					$copy              = $combo;
					// the parent's own key for the attribute - see create.php:
					// a raw non-Latin taxonomy name leaves the variation on "any"
					$copy[ sanitize_title( $taxonomy ) ] = $term['slug'];
					$next[]            = $copy;
				}
			}

			$combos = $next;
		}

		shuffle( $combos );
		$combos = array_slice( $combos, 0, wp_rand( 2, min( 6, count( $combos ) ) ) );

		foreach ( $combos as $combo ) {

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_attributes( $combo );

			// the parent's SKU with the combination appended, which is how a
			// real shop numbers variations and what makes them findable. The
			// two digits on the end matter: a cyrillic slug can decode to
			// nothing, and two variations would then claim the same SKU.
			$tail = array();

			foreach ( $combo as $slug ) {

				$plain = preg_replace( '/[^a-zA-Z0-9]/', '', urldecode( $slug ) );

				// A cyrillic or japanese term leaves nothing behind when
				// stripped to latin, and the SKU comes out as RUJP-0001--63.
				// Transliterating would be worse: it invents letters the shop
				// owner never chose. A short hash of the slug is meaningless
				// but stable, which is what a SKU segment needs to be.
				if ( '' === $plain ) {
					$plain = strtoupper( substr( md5( $slug ), 0, 3 ) );
				}

				$tail[] = strtoupper( substr( $plain, 0, 3 ) );
			}

			$variation_sku = $item['sku'] . '-' . implode( '-', array_filter( $tail ) ) . '-' . wp_rand( 10, 99 );

			// variations of one product differ a little in price, as they do in
			// a real shop where a larger size costs more
			$variation->set_regular_price(
				number_format( floatval( $item['price'] ) * ( wp_rand( 90, 130 ) / 100 ), 2, '.', '' )
			);

			if ( ! is_null( $item['stock'] ) ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( wp_rand( 0, max( 1, $item['stock'] ) ) );
			}

			try {

				$variation_id = $variation->save();

				// after the save: uniqueness is checked against the variation's
				// own id, and before the first save there is none
				if ( $variation_id && '' !== $item['sku'] ) {
					try {
						// asked first rather than caught after: set_sku throws
						// on a clash, and a caught exception leaves the
						// variation with no SKU at all
						$variation->set_sku( $this->unique_sku( $variation_sku ) );
						$variation->save();
					} catch ( Exception $e ) {
						// a clash costs the SKU, not the variation
					}
				}
			} catch ( Exception $e ) {
				// a variation the shop rejects leaves the parent intact
				continue;
			}
		}
	}

	private function some_of( $pool, $min, $max ) {

		if ( empty( $pool ) ) {
			return array();
		}

		$pool = array_values( $pool );
		shuffle( $pool );

		return array_slice( $pool, 0, wp_rand( $min, min( $max, count( $pool ) ) ) );
	}
}