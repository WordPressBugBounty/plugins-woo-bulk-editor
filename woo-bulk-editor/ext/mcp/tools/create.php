<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Creating products.
 *
 * The rest of this connection edits what is already in the catalogue. This adds
 * the missing half - a product described in a sentence becomes a real one,
 * variations included.
 *
 * Two rules shape everything here.
 *
 * New products are created as drafts. A product assembled over several calls is
 * incomplete while it is being built, and a half finished item on a live shop
 * is worse than no item at all. The owner looks it over and publishes it
 * himself, which is also the moment he notices anything the assistant got
 * wrong.
 *
 * Nothing is written without a preview. A variable product with three colours
 * and four sizes is twelve variations, and the difference between twelve and
 * one hundred and twenty is a misplaced word in a request. The plan is shown as
 * a table first, and the owner can strike rows out of it before agreeing.
 */
final class WOOBE_MCP_TOOL_CREATE extends WOOBE_MCP_TOOL {

	const MAX_VARIATIONS = 200;

	public function tools() {

		$shared = array(
			'type'        => array(
				'type'        => 'string',
				'enum'        => array( 'simple', 'variable', 'external', 'grouped' ),
				'description' => 'simple is an ordinary product. variable has variations. external sells on another site. grouped is a container for other products and has no price of its own.',
			),
			'name'        => array(
				'type'        => 'string',
				'description' => 'The product title, as a customer would see it.',
			),
			'description' => array(
				'type'        => 'string',
				'description' => 'The long description. Write it only if the user gave one or asked for one - do not invent copy for his shop.',
			),
			'short_description' => array( 'type' => 'string' ),
			'price'       => array(
				'type'        => 'string',
				'description' => 'Regular price. On a variable product this is the fallback for variations that have none of their own; on a grouped product it is ignored.',
			),
			'sale_price'  => array( 'type' => 'string' ),
			'sku'         => array( 'type' => 'string' ),
			'stock'       => array(
				'type'        => 'integer',
				'description' => 'Stock quantity. Setting it switches stock management on.',
			),
			'categories'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'Category term ids from woobe_list_terms. Names are not accepted - look the ids up first so a typo cannot silently create a new category.',
			),
			'taxonomies'  => array(
				'type'        => 'object',
				'description' => 'Any other taxonomy to file the product under, as a map of taxonomy name to term ids - {"product_tag":[65],"product_brand":[12]}. Ids, not names: woobe_list_terms finds them, and a typo in a name would quietly create a new term rather than using the one meant. Categories have their own argument.',
			),
			'attributes'  => array(
				'type'        => 'array',
				'description' => 'For a variable product: the axes its variations vary along. Each entry is an object with name, values, and optionally taxonomy - for example {"taxonomy":"pa_color","values":["Red","Blue"]} for a global attribute, or {"name":"Length","values":["30cm","40cm"]} for one that belongs to this product alone.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'taxonomy' => array( 'type' => 'string' ),
						'values'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			),
			'variations'  => array(
				'type'        => 'array',
				'description' => 'Optional. Leave it out and every combination of the attributes is created. Give it to create only some, or to price them individually: each entry is an object with attributes (a map of attribute name to value) and optionally price, sale_price, sku, stock.',
				'items'       => array( 'type' => 'object' ),
			),
			'product_url' => array(
				'type'        => 'string',
				'description' => 'For an external product: where the buy button sends the customer.',
			),
			'button_text' => array( 'type' => 'string' ),
			'children'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'For a grouped product: the ids of the products it contains.',
			),
		);

		return array(

			'woobe_create_preview' => array(
				'name'        => 'woobe_create_preview',
				'description' => 'Shows what would be created without creating it: the product, and for a variable one every variation with its attributes and price, as a table. Always run this first and read the table out - the user can strike rows before agreeing, and a combination he did not expect is far easier to remove now than afterwards.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => $shared,
					'required'   => array( 'type', 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_create_product' => array(
				'name'        => 'woobe_create_product',
				'description' => 'Creates the product described, as a draft. Requires confirmed true, given after the user has seen woobe_create_preview and agreed to it. For a variable product it also creates the variations and registers the attributes on the parent, which WooCommerce needs before a variation can exist. The product does not appear in the shop until the owner publishes it - say that when you report what was made.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$shared,
						array(
							'confirmed' => array(
								'type'        => 'boolean',
								'description' => 'Set only after the user has seen the preview and said yes to it.',
							),
							'status'    => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'publish' ),
								'description' => 'Defaults to draft. Only pass publish when the user explicitly asked for the product to go live immediately.',
							),
						)
					),
					'required'   => array( 'type', 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_create_preview':
				return $this->preview( $args );
			case 'woobe_create_product':
				return $this->create( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function preview( $args ) {

		$plan = $this->plan( $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return array(
			'will_create'  => $plan['summary'],
			'product'      => $plan['product'],
			'attributes'   => $plan['attributes'],
			'variations'   => $plan['variations'],
			'variation_count' => count( $plan['variations'] ),
			'warnings'     => $plan['warnings'],
			'note'         => 'Nothing was created. Read the table out - the variation count especially, because three colours and four sizes is twelve rows and it is easy to ask for more than you meant. The user can drop rows by naming them, or change prices per row, before you call woobe_create_product with confirmed true. The product will be a draft: it is not in the shop until he publishes it.',
		);
	}

	private function create( $args ) {

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Creating needs an explicit yes from the user, given after he has seen woobe_create_preview. Nothing was created. Show him the plan first.'
			);
		}

		$plan = $this->plan( $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$status = ( isset( $args['status'] ) && 'publish' === $args['status'] ) ? 'publish' : 'draft';

		$product = $this->build_parent( $args, $plan, $status );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$parent_id  = $product->get_id();
		$made       = array();
		$variations = array();

		if ( 'variable' === $plan['type'] ) {

			foreach ( $plan['variations'] as $row ) {

				$variation_id = $this->build_variation( $parent_id, $row, $plan );

				if ( $variation_id ) {
					$variations[] = array(
						'id'         => $variation_id,
						'attributes' => $row['attributes'],
						'price'      => $row['price'],
					);
				}
			}

			// the parent caches its own price range from the variations, and it
			// is built before they exist
			WC_Product_Variable::sync( $parent_id );
		}

		$made[] = $parent_id;

		foreach ( $variations as $v ) {
			$made[] = $v['id'];
		}

		$this->mcp->clear_caches_for( $made );

		return array(
			'id'             => $parent_id,
			'type'           => $plan['type'],
			'name'           => $plan['product']['name'],
			'status'         => $status,
			'edit_url'       => admin_url( 'post.php?post=' . $parent_id . '&action=edit' ),
			'view_url'       => get_permalink( $parent_id ),
			'publish_with'   => 'draft' === $status
				? 'woobe_update_product with product_id ' . $parent_id . ', field post_status, value publish'
				: null,
			'variations'     => $variations,
			'variation_count' => count( $variations ),
			'warnings'       => $plan['warnings'],
			'note'           => 'draft' === $status
				? 'Created as a draft, so it is not in the shop yet. Tell the user what was made, give him the edit link so he can look it over, and offer to publish it from here - one call to woobe_update_product setting post_status to publish, no trip to wp-admin needed unless he wants one. Do not publish on your own initiative: a draft costs nothing, a wrong product on a live shop costs a customer.'
				: 'Created and published - it is live in the shop now. Say so plainly, and mention the edit link in case he wants to look it over.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the plan, shared by the preview and the real thing so they cannot disagree

	private function plan( $args ) {

		$type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';

		if ( ! in_array( $type, array( 'simple', 'variable', 'external', 'grouped' ), true ) ) {
			return new WP_Error( 'woobe_mcp_bad_type', 'Unknown product type: ' . $type . '. Use simple, variable, external or grouped.' );
		}

		if ( '' === $name ) {
			return new WP_Error( 'woobe_mcp_no_name', 'A product needs a name.' );
		}

		$warnings = array();

		// a duplicate title is not an error, but it is almost always a repeated
		// request rather than a second product
		$existing = get_page_by_title( $name, OBJECT, 'product' );

		if ( $existing ) {
			$warnings[] = array(
				'code' => 'name_already_used',
				'text' => 'A product called "' . $name . '" already exists, id ' . $existing->ID . '. Check with the user whether he meant to create a second one or to edit that one.',
			);
		}

		$product = array(
			'name'        => $name,
			'type'        => $type,
			'price'       => isset( $args['price'] ) ? (string) $args['price'] : '',
			'sale_price'  => isset( $args['sale_price'] ) ? (string) $args['sale_price'] : '',
			'sku'         => isset( $args['sku'] ) ? sanitize_text_field( $args['sku'] ) : '',
			'stock'       => isset( $args['stock'] ) ? intval( $args['stock'] ) : null,
			'categories'  => isset( $args['categories'] ) && is_array( $args['categories'] ) ? array_map( 'intval', $args['categories'] ) : array(),
		);

		// other taxonomies: tags, brands, whatever the shop has registered
		$product['taxonomies'] = array();

		if ( ! empty( $args['taxonomies'] ) && is_array( $args['taxonomies'] ) ) {

			foreach ( $args['taxonomies'] as $taxonomy => $term_ids ) {

				$taxonomy = sanitize_text_field( wp_unslash( $taxonomy ) );

				if ( ! taxonomy_exists( $taxonomy ) ) {
					return new WP_Error(
						'woobe_mcp_no_taxonomy',
						'There is no taxonomy called ' . $taxonomy . ' on this shop. woobe_describe_shop lists the ones that exist.'
					);
				}

				$named = array();

				foreach ( (array) $term_ids as $term_id ) {

					$term = get_term( intval( $term_id ), $taxonomy );

					if ( $term && ! is_wp_error( $term ) ) {
						$named[] = array(
							'id'   => intval( $term->term_id ),
							'name' => $term->name,
						);
					} else {
						$warnings[] = array(
							'code' => 'term_missing',
							'text' => 'Term ' . intval( $term_id ) . ' does not exist in ' . $taxonomy . ' and will be skipped.',
						);
					}
				}

				if ( ! empty( $named ) ) {
					$product['taxonomies'][ $taxonomy ] = $named;
				}
			}
		}

		if ( 'external' === $type ) {

			if ( empty( $args['product_url'] ) ) {
				return new WP_Error( 'woobe_mcp_no_url', 'An external product needs product_url - the address its buy button sends the customer to.' );
			}

			$product['product_url'] = esc_url_raw( $args['product_url'] );
			$product['button_text'] = isset( $args['button_text'] ) ? sanitize_text_field( $args['button_text'] ) : '';
		}

		if ( 'grouped' === $type ) {

			$children = isset( $args['children'] ) && is_array( $args['children'] ) ? array_map( 'intval', $args['children'] ) : array();
			$named    = array();

			foreach ( $children as $child_id ) {

				$child = $this->products()->get_product( $child_id );

				if ( ! $child ) {
					$warnings[] = array(
						'code' => 'child_missing',
						'text' => 'Product ' . $child_id . ' does not exist and will be left out of the group.',
					);
					continue;
				}

				$named[] = array(
					'id'    => $child_id,
					'title' => $this->product_label( $child_id ),
					'price' => $child->get_regular_price(),
				);
			}

			$product['children'] = $named;

			if ( empty( $named ) ) {
				$warnings[] = array(
					'code' => 'empty_group',
					'text' => 'A grouped product with nothing in it shows a customer an empty page. Ask which products belong in it.',
				);
			}
		}

		$attributes = array();
		$variations = array();

		if ( 'variable' === $type ) {

			$attributes = $this->plan_attributes( $args );

			if ( is_wp_error( $attributes ) ) {
				return $attributes;
			}

			if ( empty( $attributes ) ) {
				return new WP_Error(
					'woobe_mcp_no_attributes',
					'A variable product needs attributes - the axes its variations vary along, such as colour and size. Ask the user what they are, and use woobe_list_terms to find the values if he names a global attribute.'
				);
			}

			$variations = $this->plan_variations( $args, $attributes, $product );

			if ( is_wp_error( $variations ) ) {
				return $variations;
			}

			if ( count( $variations ) > self::MAX_VARIATIONS ) {
				return new WP_Error(
					'woobe_mcp_too_many_variations',
					'That comes to ' . count( $variations ) . ' variations. Creation through this connection is capped at ' . self::MAX_VARIATIONS
					. ' - a number that large is usually a misunderstanding about which attributes vary. Check with the user before going further.'
				);
			}

			$priceless = 0;

			foreach ( $variations as $row ) {
				if ( '' === $row['price'] ) {
					++$priceless;
				}
			}

			if ( $priceless ) {
				$warnings[] = array(
					'code' => 'variations_without_price',
					'text' => $priceless . ' of the variations have no price. WooCommerce hides a variation with no price from the shop, so those will exist but not be buyable. Give a price for the product as a whole to use for all of them, or one per variation.',
				);
			}
		}

		$summary = 'variable' === $type
			? 'A variable product "' . $name . '" with ' . count( $variations ) . ' variations'
			: 'A ' . $type . ' product "' . $name . '"';

		return array(
			'type'       => $type,
			'summary'    => $summary,
			'product'    => $product,
			'attributes' => $attributes,
			'variations' => $variations,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Turns the requested attributes into something both the preview and the
	 * writer can use: for a global attribute the taxonomy and its term ids, for
	 * a local one the plain values.
	 */
	private function plan_attributes( $args ) {

		if ( empty( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			return array();
		}

		$out = array();

		foreach ( $args['attributes'] as $raw ) {

			if ( empty( $raw['values'] ) || ! is_array( $raw['values'] ) ) {
				continue;
			}

			$taxonomy = isset( $raw['taxonomy'] ) ? sanitize_text_field( wp_unslash( $raw['taxonomy'] ) ) : '';
			$label    = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';

			if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'woobe_mcp_no_taxonomy',
					'There is no attribute called ' . $taxonomy . ' on this shop. woobe_describe_shop lists the ones that exist. Either use one of those, or leave taxonomy out and give a name instead, which creates an attribute belonging to this product alone.'
				);
			}

			$values = array();

			foreach ( $raw['values'] as $value ) {

				$value = sanitize_text_field( $value );

				if ( '' === $value ) {
					continue;
				}

				if ( '' !== $taxonomy ) {

					// match on either the visible name or the slug, so "Red"
					// and "red" both find the same term
					$term = get_term_by( 'name', $value, $taxonomy );

					if ( ! $term ) {
						$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
					}

					$values[] = array(
						'label'   => $term ? $term->name : $value,
						'slug'    => $term ? $term->slug : sanitize_title( $value ),
						'term_id' => $term ? intval( $term->term_id ) : 0,
						'is_new'  => ! $term,
					);
				} else {
					$values[] = array(
						'label'   => $value,
						'slug'    => $value,
						'term_id' => 0,
						'is_new'  => false,
					);
				}
			}

			if ( empty( $values ) ) {
				continue;
			}

			$out[] = array(
				'taxonomy' => $taxonomy,
				'name'     => '' !== $taxonomy ? wc_attribute_label( $taxonomy ) : $label,
				'key'      => '' !== $taxonomy ? $taxonomy : sanitize_title( $label ),
				'values'   => $values,
			);
		}

		return $out;
	}

	/**
	 * Either every combination of the attributes, or the ones the caller listed.
	 */
	private function plan_variations( $args, $attributes, $product ) {

		$fallback_price = $product['price'];

		// listed explicitly: the caller decides which combinations exist
		if ( ! empty( $args['variations'] ) && is_array( $args['variations'] ) ) {

			$out = array();

			foreach ( $args['variations'] as $row ) {

				$combo = array();

				foreach ( $attributes as $attribute ) {

					$asked = '';

					if ( isset( $row['attributes'] ) && is_array( $row['attributes'] ) ) {
						foreach ( $row['attributes'] as $k => $v ) {
							if ( sanitize_title( $k ) === sanitize_title( $attribute['name'] ) || $k === $attribute['key'] ) {
								$asked = sanitize_text_field( $v );
							}
						}
					}

					if ( '' === $asked ) {
						return new WP_Error(
							'woobe_mcp_variation_incomplete',
							'One of the listed variations does not say which ' . $attribute['name'] . ' it is. Every variation needs a value for every attribute, or WooCommerce cannot tell them apart.'
						);
					}

					$combo[ $attribute['name'] ] = $asked;
				}

				$out[] = array(
					'attributes' => $combo,
					'price'      => isset( $row['price'] ) ? (string) $row['price'] : $fallback_price,
					'sale_price' => isset( $row['sale_price'] ) ? (string) $row['sale_price'] : '',
					'sku'        => isset( $row['sku'] ) ? sanitize_text_field( $row['sku'] ) : '',
					'stock'      => isset( $row['stock'] ) ? intval( $row['stock'] ) : null,
				);
			}

			return $out;
		}

		// otherwise every combination, which is what "red and blue in S and M"
		// means without anybody spelling out four rows
		$combos = array( array() );

		foreach ( $attributes as $attribute ) {

			$next = array();

			foreach ( $combos as $combo ) {
				foreach ( $attribute['values'] as $value ) {
					$copy = $combo;
					$copy[ $attribute['name'] ] = $value['label'];
					$next[] = $copy;
				}
			}

			$combos = $next;
		}

		$out = array();

		foreach ( $combos as $combo ) {
			$out[] = array(
				'attributes' => $combo,
				'price'      => $fallback_price,
				'sale_price' => isset( $product['sale_price'] ) ? $product['sale_price'] : '',
				'sku'        => '',
				'stock'      => null,
			);
		}

		return $out;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// writing

	private function build_parent( $args, $plan, $status ) {

		switch ( $plan['type'] ) {
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

		$product->set_name( $plan['product']['name'] );
		$product->set_status( $status );

		if ( isset( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
		}

		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
		}

		// price belongs to the variations on a variable product and to nothing
		// at all on a grouped one
		if ( in_array( $plan['type'], array( 'simple', 'external' ), true ) ) {

			if ( '' !== $plan['product']['price'] ) {
				$product->set_regular_price( $plan['product']['price'] );
			}

			if ( '' !== $plan['product']['sale_price'] ) {
				$product->set_sale_price( $plan['product']['sale_price'] );
			}
		}

		if ( ! is_null( $plan['product']['stock'] ) && 'grouped' !== $plan['type'] ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $plan['product']['stock'] );
		}

		if ( ! empty( $plan['product']['categories'] ) ) {
			$product->set_category_ids( $plan['product']['categories'] );
		}

		if ( 'external' === $plan['type'] ) {
			$product->set_product_url( $plan['product']['product_url'] );

			if ( '' !== $plan['product']['button_text'] ) {
				$product->set_button_text( $plan['product']['button_text'] );
			}
		}

		if ( 'grouped' === $plan['type'] && ! empty( $plan['product']['children'] ) ) {
			$product->set_children( wp_list_pluck( $plan['product']['children'], 'id' ) );
		}

		if ( 'variable' === $plan['type'] ) {
			$product->set_attributes( $this->build_attributes( $plan['attributes'] ) );
		}

		$id = $product->save();

		if ( ! $id ) {
			return new WP_Error( 'woobe_mcp_create_failed', 'The product could not be created.' );
		}

		// SKU after the first save, deliberately. set_sku() checks uniqueness
		// against the product's own id, and an unsaved object has an id of 0 -
		// so the check compares against nothing, decides the SKU is taken and
		// throws, which the catch below then swallows in silence. With a real
		// id the same check passes.
		if ( '' !== $plan['product']['sku'] ) {
			try {
				$product->set_sku( $plan['product']['sku'] );
				$product->save();
			} catch ( Exception $e ) {
				$plan['warnings'][] = array(
					'code' => 'sku_taken',
					'text' => 'The SKU ' . $plan['product']['sku'] . ' is already used by another product, so it was left empty.',
				);
			}
		}

		// after the save: the product needs an id before terms can be attached,
		// and the CRUD class only knows about categories and tags
		foreach ( $plan['product']['taxonomies'] as $taxonomy => $terms ) {
			wp_set_object_terms( $id, wp_list_pluck( $terms, 'id' ), $taxonomy, false );
		}

		return $product;
	}

	/**
	 * Attributes as WooCommerce wants them, with the terms attached to the
	 * product - a variation cannot reference a term the parent does not have.
	 */
	private function build_attributes( $attributes ) {

		$out = array();

		foreach ( $attributes as $attribute ) {

			$object = new WC_Product_Attribute();

			if ( '' !== $attribute['taxonomy'] ) {

				$term_ids = array();

				foreach ( $attribute['values'] as $value ) {

					if ( $value['term_id'] ) {
						$term_ids[] = $value['term_id'];
						continue;
					}

					// a value the shop has never used before: created rather
					// than silently dropped, because the user asked for it
					$term = wp_insert_term( $value['label'], $attribute['taxonomy'] );

					if ( ! is_wp_error( $term ) ) {
						$term_ids[] = intval( $term['term_id'] );
					}
				}

				$object->set_id( wc_attribute_taxonomy_id_by_name( $attribute['taxonomy'] ) );
				$object->set_name( $attribute['taxonomy'] );
				$object->set_options( $term_ids );
			} else {
				$object->set_id( 0 );
				$object->set_name( $attribute['name'] );
				$object->set_options( wp_list_pluck( $attribute['values'], 'label' ) );
			}

			$object->set_position( count( $out ) );
			$object->set_visible( true );
			$object->set_variation( true );

			$out[] = $object;
		}

		return $out;
	}

	private function build_variation( $parent_id, $row, $plan ) {

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );

		$attributes = array();

		foreach ( $plan['attributes'] as $attribute ) {

			$asked = isset( $row['attributes'][ $attribute['name'] ] ) ? $row['attributes'][ $attribute['name'] ] : '';

			if ( '' === $asked ) {
				continue;
			}

			if ( '' !== $attribute['taxonomy'] ) {

				// the slug, not the label: WooCommerce matches variations to
				// the parent's terms by slug and shows nothing when they differ
				$slug = sanitize_title( $asked );

				foreach ( $attribute['values'] as $value ) {
					if ( $value['label'] === $asked ) {
						$slug = $value['slug'];
					}
				}

				// Keyed the way the parent keys it. WC_Product::set_attributes()
				// files every attribute under sanitize_title() of its name, and a
				// variation only matches its parent on that same key. For pa_color
				// the two are identical, which is why this went unnoticed; for a
				// taxonomy with non-Latin letters sanitize_title() percent-encodes
				// the name, the raw key matched nothing, and the variation came out
				// as "any" on that axis.
				$attributes[ sanitize_title( $attribute['taxonomy'] ) ] = $slug;
			} else {
				$attributes[ sanitize_title( $attribute['name'] ) ] = $asked;
			}
		}

		$variation->set_attributes( $attributes );

		if ( '' !== $row['price'] ) {
			$variation->set_regular_price( $row['price'] );
		}

		if ( '' !== $row['sale_price'] ) {
			$variation->set_sale_price( $row['sale_price'] );
		}

		if ( ! is_null( $row['stock'] ) ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $row['stock'] );
		}

		$variation_id = $variation->save();

		// same as on the parent: uniqueness is checked against the variation's
		// own id, and before the first save there is none
		if ( $variation_id && '' !== $row['sku'] ) {
			try {
				$variation->set_sku( $row['sku'] );
				$variation->save();
			} catch ( Exception $e ) {
				// a clash costs the SKU, not the variation
			}
		}

		return $variation_id;
	}
}