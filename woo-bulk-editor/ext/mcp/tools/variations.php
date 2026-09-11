<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Variations of products that already exist.
 *
 * woobe_create_product builds a variable product with its variations in one go.
 * This is what comes after: a red XL added to a hoodie that has been selling
 * for a year, the whole orange line dropped, a material added as a new choice,
 * the size axis retired. Without it the story of a product ends the day it is
 * created, and everything after is wp-admin.
 *
 * Prices, stock, SKUs and every other field of a variation are not here - a
 * variation is a product to the bulk editor, and woobe_update_product and
 * woobe_apply_bulk already change them, with history and rollback. This pack is
 * only about structure: which combinations exist and which axes the product
 * varies along.
 *
 * Words used below. An axis is an attribute the product varies along - Size,
 * Colour. A value is one choice on an axis - XL, Red. A combination is one
 * value per axis, and a variation is a combination with a price.
 *
 * "Any". A variation with no value on an axis matches every value of it: one
 * "Any size, Red" variation sells red in all sizes. That is legitimate, but a
 * shop usually has it by accident, and it quietly swallows whatever explicit
 * red variations are added later. It is reported everywhere it matters.
 *
 * None of this is in BEAR history. Removed variations go to the trash and come
 * back with woobe_restore_products; structural changes are previewed first and
 * answered with what they replaced.
 */
final class WOOBE_MCP_TOOL_VARIATIONS extends WOOBE_MCP_TOOL {

	// the same ceiling woobe_create_product uses, for the same reason: past it
	// the request was almost certainly not what the user meant
	const MAX_NEW = 200;

	// how many missing combinations to spell out before summarising
	const MAX_LISTED = 50;

	public function tools() {

		$product_id = array(
			'type'        => 'integer',
			'description' => 'The variable product - the parent, not one of its variations.',
		);

		return array(

			'woobe_variations' => array(
				'name'        => 'woobe_variations',
				'description' => 'The structure of a variable product: the axes it varies along with their values, every variation with its price, stock and SKU, and what is off about it - combinations that are missing, two variations for the same combination, variations set to "any" that cover a whole axis. Read this first whenever the user talks about adding, removing or reshaping variations, and read the table back: people rarely remember which combinations actually exist. To change prices or stock of variations use woobe_update_product or woobe_find_products with include_variations then woobe_apply_bulk - "all XL five more" is a bulk edit, not a structural one.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => $product_id,
					),
					'required'   => array( 'product_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_add_variations' => array(
				'name'        => 'woobe_add_variations',
				'description' => 'Adds variations to an existing variable product. List them, each with a value for every axis and a price - or set fill_missing to create every combination the product does not have yet. A value the product never had, XL on a hoodie sold in S to L, is added to the product, and to the shop\'s attribute too if the shop has never used it. A combination that already exists is refused rather than duplicated. Call once without confirmed and read the table back - the count and any new values especially - then again with confirmed true. Not in BEAR history: removing them again is woobe_remove_variations.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'   => $product_id,
						'variations'   => array(
							'type'        => 'array',
							'description' => 'Each entry: attributes, a map of axis name to value - {"Size":"XL","Colour":"Red"}; axis names as woobe_variations shows them, taxonomy names like pa_size work too - and optionally price, sale_price, sku, stock.',
							'items'       => array( 'type' => 'object' ),
						),
						'fill_missing' => array(
							'type'        => 'boolean',
							'description' => 'Create every combination of the product\'s current values that has no variation yet. Combine with price, or they are created without one and cannot be bought.',
						),
						'price'        => array(
							'type'        => 'string',
							'description' => 'Regular price for any new variation that does not give its own. Never guess one: ask, or leave it out and the preview says which variations would have no price.',
						),
						'sale_price'   => array( 'type' => 'string' ),
						'stock'        => array(
							'type'        => 'integer',
							'description' => 'Stock for any new variation that does not give its own. Setting it switches stock management on for them.',
						),
						'confirmed'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'product_id' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
				),
			),

			'woobe_remove_variations' => array(
				'name'        => 'woobe_remove_variations',
				'description' => 'Removes variations from a variable product - by their ids, or every variation carrying a value ("all the red ones"). They go to the trash and come back with woobe_restore_products. With drop_values, a value no remaining variation uses is also taken off the product, so the shop stops offering a colour that cannot be bought. Call once without confirmed, read the list back, then confirm.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'  => $product_id,
						'ids'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'match'       => array(
							'type'        => 'object',
							'description' => 'Axis name to value - {"Colour":"Red"} takes every red variation; two axes take only variations matching both. A variation set to "any" on that axis is NOT taken: it sells every colour, and removing it would take far more than was asked.',
						),
						'drop_values' => array(
							'type'        => 'boolean',
							'description' => 'Also remove from the product any value that no remaining variation uses. On by default when removing by match, off by default when removing by ids.',
						),
						'confirmed'   => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'product_id' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),

			'woobe_change_variation_axes' => array(
				'name'        => 'woobe_change_variation_axes',
				'description' => 'Changes which attributes a variable product varies along. One operation per call. add_axis adds a new choice such as Material; existing variations take value_for_existing, or "any" if it is left out. remove_axis stops varying by an attribute - refused if two variations would become the same, and those are listed so the user can decide which to remove first; with keep_as_info the attribute stays on the product page as a plain fact. set_defaults picks the variation preselected on the product page. Call once without confirmed and read it back, then confirm. Not in BEAR history.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'         => $product_id,
						'operation'          => array(
							'type' => 'string',
							'enum' => array( 'add_axis', 'remove_axis', 'set_defaults' ),
						),
						'attribute'          => array(
							'type'        => 'string',
							'description' => 'For add_axis: a shop attribute taxonomy such as pa_material, or a plain name for an attribute belonging to this product alone. For remove_axis: the axis as woobe_variations names it.',
						),
						'values'             => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'For add_axis: the values the new axis offers.',
						),
						'value_for_existing' => array(
							'type'        => 'string',
							'description' => 'For add_axis: which of the values every existing variation gets. Leave it out and they get "any", which means each of them sells in every value of the new axis - usually not what the user wants, so ask.',
						),
						'keep_as_info'       => array(
							'type'        => 'boolean',
							'description' => 'For remove_axis: keep the attribute and its values on the product page, just no longer as a choice.',
						),
						'defaults'           => array(
							'type'        => 'object',
							'description' => 'For set_defaults: axis name to value. An empty object clears the preselection.',
						),
						'confirmed'          => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'product_id', 'operation' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_variations':
				return $this->read( $args );
			case 'woobe_add_variations':
				return $this->add( $args );
			case 'woobe_remove_variations':
				return $this->remove( $args );
			case 'woobe_change_variation_axes':
				return $this->axes( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the product as this pack sees it

	/**
	 * The variable product, or a reason it cannot be worked on.
	 */
	private function parent( $args ) {

		$id      = isset( $args['product_id'] ) ? intval( $args['product_id'] ) : 0;
		$product = $id ? wc_get_product( $id ) : false;

		if ( ! $product ) {
			return new WP_Error( 'woobe_mcp_no_product', 'There is no product ' . $id . ' on this shop.' );
		}

		if ( $product->is_type( 'variation' ) ) {
			return new WP_Error(
				'woobe_mcp_is_variation',
				$id . ' is one variation of product ' . $product->get_parent_id() . '. Pass the parent: variations are added to and removed from the product they belong to.'
			);
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return new WP_Error(
				'woobe_mcp_not_variable',
				$product->get_name() . ' is a ' . $product->get_type() . ' product, so it has no variations yet. If the user wants it to have them, it can be turned into a variable product in four steps: note its current price, stock and SKU; set product_type to variable with woobe_update_product; add the axis with woobe_change_variation_axes, operation add_axis; then create the variations with woobe_add_variations, carrying the old price and stock over. Two things to handle on the way. Stock: if the product managed its own stock, that number stays on the parent and variations without a stock of their own share it - either switch manage_stock off on the parent or give every variation its own stock, and ask the user which he means. SKU: a variation cannot reuse the parent\'s SKU, so leave it on the parent or give the variations new ones. Check woobe_list_fields first: if product_type is not editable there, this edition cannot change it through the connection and the user has to do it on the product screen in wp-admin. Read the plan back and get a yes before the first step - a changed type is not undone by removing variations.'
			);
		}

		return $product;
	}

	/**
	 * The axes a product varies along, in its own order.
	 *
	 * key is what WooCommerce stores on the variation - the taxonomy name for a
	 * shop attribute, the sanitised name for one of the product's own. values
	 * maps what is stored to what a person reads: a term slug to its name for
	 * a shop attribute, the text to itself otherwise.
	 */
	private function axes_of( $product, $variation_only = true ) {

		$out = array();

		foreach ( $product->get_attributes() as $key => $attribute ) {

			if ( $variation_only && ! $attribute->get_variation() ) {
				continue;
			}

			$values = array();

			if ( $attribute->is_taxonomy() ) {
				foreach ( (array) $attribute->get_terms() as $term ) {
					$values[ $term->slug ] = $term->name;
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$values[ $option ] = $option;
				}
			}

			$out[ $key ] = array(
				'key'       => $key,
				'name'      => $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name(),
				'taxonomy'  => $attribute->is_taxonomy() ? $attribute->get_name() : null,
				'values'    => $values,
				'variation' => (bool) $attribute->get_variation(),
				'visible'   => (bool) $attribute->get_visible(),
				'object'    => $attribute,
			);
		}

		return $out;
	}

	/**
	 * The variations that count - published or private, not trashed - each as
	 * its combination of stored values.
	 */
	private function variations_of( $product, $axes ) {

		$out = array();

		foreach ( $product->get_children() as $child_id ) {

			$variation = wc_get_product( $child_id );

			if ( ! $variation ) {
				continue;
			}

			$stored = $variation->get_attributes();
			$combo  = array();

			foreach ( $axes as $key => $axis ) {
				$combo[ $key ] = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
			}

			$out[ $child_id ] = array(
				'id'        => $child_id,
				'combo'     => $combo,
				'variation' => $variation,
			);
		}

		return $out;
	}

	/**
	 * A combination as a person reads it: axis name => value name, "any" for
	 * an empty value.
	 */
	private function readable( $combo, $axes ) {

		$out = array();

		foreach ( $combo as $key => $value ) {

			if ( ! isset( $axes[ $key ] ) ) {
				continue;
			}

			if ( '' === $value ) {
				$out[ $axes[ $key ]['name'] ] = 'any';
				continue;
			}

			$out[ $axes[ $key ]['name'] ] = isset( $axes[ $key ]['values'][ $value ] ) ? $axes[ $key ]['values'][ $value ] : $value;
		}

		return $out;
	}

	/**
	 * Which axis a name from the user means: the taxonomy, the key, or the
	 * label, whichever he used, in any case.
	 */
	private function find_axis( $name, $axes ) {

		$name = trim( (string) $name );
		$want = strtolower( $name );

		foreach ( $axes as $key => $axis ) {

			if ( $want === strtolower( $key )
				|| $want === strtolower( $axis['name'] )
				|| ( $axis['taxonomy'] && $want === strtolower( $axis['taxonomy'] ) )
				|| sanitize_title( $name ) === $key ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * What a value from the user becomes on this axis.
	 *
	 * @return array stored value, label, and what has to be created for it:
	 *               'none', 'product' (the product lacks it) or 'shop' (the
	 *               shop's attribute has no such term yet).
	 */
	private function resolve_value( $value, $axis ) {

		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return new WP_Error( 'woobe_mcp_empty_value', 'An empty value was given for ' . $axis['name'] . '.' );
		}

		// already on the product, by stored value or by what the user reads
		foreach ( $axis['values'] as $stored => $label ) {
			if ( strtolower( $value ) === strtolower( (string) $label ) || strtolower( $value ) === strtolower( (string) $stored ) ) {
				return array(
					'stored' => (string) $stored,
					'label'  => (string) $label,
					'needs'  => 'none',
				);
			}
		}

		if ( $axis['taxonomy'] ) {

			$term = get_term_by( 'name', $value, $axis['taxonomy'] );

			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $value ), $axis['taxonomy'] );
			}

			if ( $term ) {
				return array(
					'stored'  => $term->slug,
					'label'   => $term->name,
					'needs'   => 'product',
					'term_id' => intval( $term->term_id ),
				);
			}

			return array(
				'stored' => sanitize_title( $value ),
				'label'  => $value,
				'needs'  => 'shop',
			);
		}

		return array(
			'stored' => $value,
			'label'  => $value,
			'needs'  => 'product',
		);
	}

	/**
	 * Whether an existing combination already sells this one: equal on every
	 * axis, or "any" where they differ.
	 *
	 * @return string '' no, 'same' identical, 'any' covered by an "any" value
	 */
	private function covered( $existing, $wanted ) {

		$via_any = false;

		foreach ( $wanted as $key => $value ) {

			$have = isset( $existing[ $key ] ) ? $existing[ $key ] : '';

			if ( $have === $value ) {
				continue;
			}

			if ( '' === $have ) {
				$via_any = true;
				continue;
			}

			return '';
		}

		return $via_any ? 'any' : 'same';
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// reading

	private function read( $args ) {

		$product = $this->parent( $args );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$axes       = $this->axes_of( $product );
		$variations = $this->variations_of( $product, $axes );

		$rows = array();

		foreach ( $variations as $v ) {

			$variation = $v['variation'];

			$rows[] = array(
				'id'            => $v['id'],
				'attributes'    => $this->readable( $v['combo'], $axes ),
				'sku'           => $variation->get_sku(),
				'regular_price' => $variation->get_regular_price(),
				'sale_price'    => $variation->get_sale_price(),
				'stock'         => $variation->managing_stock() ? $variation->get_stock_quantity() : null,
				'stock_status'  => $variation->get_stock_status(),
				'enabled'       => 'publish' === $variation->get_status(),
			);
		}

		$analysis = $this->analyse( $axes, $variations );

		$axis_out = array();

		foreach ( $axes as $axis ) {
			$axis_out[] = array(
				'name'     => $axis['name'],
				'taxonomy' => $axis['taxonomy'],
				'values'   => array_values( $axis['values'] ),
			);
		}

		$info = array();

		foreach ( $this->axes_of( $product, false ) as $axis ) {
			if ( ! $axis['variation'] ) {
				$info[] = array(
					'name'   => $axis['name'],
					'values' => array_values( $axis['values'] ),
				);
			}
		}

		$out = array(
			'id'              => $product->get_id(),
			'name'            => $product->get_name(),
			'status'          => $product->get_status(),
			'axes'            => $axis_out,
			'info_attributes' => $info,
			'defaults'        => $this->readable( $this->default_combo( $product, $axes ), $axes ),
			'variation_count' => count( $rows ),
			'variations'      => $rows,
			'analysis'        => $analysis,
			'edit_url'        => admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ),
			'note'            => 'Render variations as a table. analysis says what is off: missing lists combinations with no variation, duplicates are groups of variations for the same combination (checkout picks one of them, the others never sell), any_variations sell every value of the axis marked any, and unmapped_values are variations using a value the product does not offer, so customers cannot choose them - woobe_add_variations listing the same combination puts the value back on the product and makes them selectable. enabled false means the variation is switched off and not sold. info_attributes are shown on the product page but are not choices.',
		);

		// A product in the trash takes its variations with it, and this read
		// then showed a variable product with none and every combination
		// missing - which looks like broken data rather than a deletion.
		if ( 'trash' === $product->get_status() ) {
			$out['note'] = 'This product is in the trash, and so are its variations - that is why none are listed and every combination shows as missing. Nothing is broken. woobe_restore_products brings the product back together with them.';
		}

		return $out;
	}

	private function analyse( $axes, $variations ) {

		$possible = 1;

		foreach ( $axes as $axis ) {
			$possible *= max( 1, count( $axis['values'] ) );
		}

		// every combination of the current values, checked against what exists
		$missing = array();
		$missing_count = 0;

		foreach ( $this->combinations( $axes ) as $combo ) {

			$sold = false;

			foreach ( $variations as $v ) {
				if ( '' !== $this->covered( $v['combo'], $combo ) ) {
					$sold = true;
					break;
				}
			}

			if ( ! $sold ) {
				++$missing_count;
				if ( count( $missing ) < self::MAX_LISTED ) {
					$missing[] = $this->readable( $combo, $axes );
				}
			}
		}

		$groups   = array();
		$any      = array();
		$unmapped = array();

		foreach ( $variations as $v ) {

			$groups[ wp_json_encode( $v['combo'] ) ][] = $v['id'];

			// a value the product itself does not offer: the variation exists
			// but the product page has no way to pick it. Imports leave these
			// behind, and so does anything that wrote the variation without
			// telling the parent.
			foreach ( $v['combo'] as $key => $stored ) {
				if ( '' !== $stored && ! isset( $axes[ $key ]['values'][ $stored ] ) ) {
					$unmapped[] = array(
						'id'    => $v['id'],
						'axis'  => $axes[ $key ]['name'],
						'value' => $stored,
					);
				}
			}

			if ( in_array( '', $v['combo'], true ) ) {
				$any[] = array(
					'id'         => $v['id'],
					'attributes' => $this->readable( $v['combo'], $axes ),
				);
			}
		}

		$duplicates = array();

		foreach ( $groups as $json => $ids ) {
			if ( count( $ids ) > 1 ) {
				$duplicates[] = array(
					'attributes' => $this->readable( json_decode( $json, true ), $axes ),
					'ids'        => $ids,
				);
			}
		}

		return array(
			'possible_combinations' => $possible,
			'missing_count'         => $missing_count,
			'missing'               => $missing,
			'duplicates'            => $duplicates,
			'any_variations'        => $any,
			'unmapped_values'       => $unmapped,
		);
	}

	/**
	 * Every combination of the axes' current values, as stored values.
	 */
	private function combinations( $axes ) {

		$combos = array( array() );

		foreach ( $axes as $key => $axis ) {

			$next = array();

			foreach ( $combos as $combo ) {
				foreach ( array_keys( $axis['values'] ) as $stored ) {
					$copy         = $combo;
					$copy[ $key ] = (string) $stored;
					$next[]       = $copy;
				}
			}

			$combos = $next;

			// a product with five axes of ten values has a hundred thousand
			// combinations; nobody wants them listed and building them would
			// take the request down
			if ( count( $combos ) > 5000 ) {
				return array_slice( $combos, 0, 5000 );
			}
		}

		return empty( $axes ) ? array() : $combos;
	}

	private function default_combo( $product, $axes ) {

		$defaults = $product->get_default_attributes();
		$out      = array();

		foreach ( $axes as $key => $axis ) {
			if ( isset( $defaults[ $key ] ) && '' !== $defaults[ $key ] ) {
				$out[ $key ] = (string) $defaults[ $key ];
			}
		}

		return $out;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// adding

	private function add( $args ) {

		$product = $this->parent( $args );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$axes = $this->axes_of( $product );

		if ( empty( $axes ) ) {
			return new WP_Error( 'woobe_mcp_no_axes', $product->get_name() . ' has no attribute used for variations yet. Add one first with woobe_change_variation_axes, operation add_axis.' );
		}

		$existing = $this->variations_of( $product, $axes );

		$default_price = isset( $args['price'] ) ? trim( (string) $args['price'] ) : '';
		$default_sale  = isset( $args['sale_price'] ) ? trim( (string) $args['sale_price'] ) : '';
		$default_stock = isset( $args['stock'] ) ? intval( $args['stock'] ) : null;

		// the shared defaults are checked once here; a listed row with its own
		// price is checked in plan_row(), and fill_missing rows use these as-is
		foreach ( array( 'price' => $default_price, 'sale_price' => $default_sale ) as $field => $amount ) {
			if ( '' !== $amount && ( ! is_numeric( $amount ) || floatval( $amount ) < 0 ) ) {
				return new WP_Error( 'woobe_mcp_bad_price', $field . ' ' . $amount . ' is not a price.' );
			}
		}

		if ( '' !== $default_sale && '' !== $default_price && floatval( $default_sale ) >= floatval( $default_price ) ) {
			return new WP_Error( 'woobe_mcp_bad_price', 'The sale price ' . $default_sale . ' is not below the regular price ' . $default_price . '.' );
		}

		$rows = array();

		if ( ! empty( $args['variations'] ) && is_array( $args['variations'] ) ) {

			foreach ( $args['variations'] as $i => $raw ) {

				$row = $this->plan_row( $raw, $axes, $default_price, $default_sale, $default_stock, $i + 1 );

				if ( is_wp_error( $row ) ) {
					return $row;
				}

				$rows[] = $row;
			}
		}

		if ( ! empty( $args['fill_missing'] ) ) {

			foreach ( $this->combinations( $axes ) as $combo ) {

				$rows[] = array(
					'combo'      => $combo,
					'new_values' => array(),
					'price'      => $default_price,
					'sale_price' => $default_sale,
					'sku'        => '',
					'stock'      => $default_stock,
				);
			}
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'woobe_mcp_nothing_to_add', 'Say which variations to add, or set fill_missing to create every combination the product does not have yet.' );
		}

		// drop what already exists and what was asked for twice; with
		// fill_missing this is what turns "every combination" into "the
		// missing ones", and for a listed row it is a refusal worth explaining
		$plan     = array();
		$reattach = array();
		$warnings = array();
		$seen     = array();

		foreach ( $rows as $row ) {

			$json = wp_json_encode( $row['combo'] );

			if ( isset( $seen[ $json ] ) ) {
				continue;
			}

			$seen[ $json ] = true;
			$clash         = null;

			foreach ( $existing as $v ) {

				$how = $this->covered( $v['combo'], $row['combo'] );

				if ( 'same' === $how ) {
					$clash = array( 'same', $v['id'] );
					break;
				}

				if ( 'any' === $how && ! $clash ) {
					$clash = array( 'any', $v['id'] );
				}
			}

			if ( $clash && 'same' === $clash[0] ) {

				// Identical to an existing variation, and using a value the
				// product itself does not offer: that variation is not a rival
				// but an orphan - restored from the trash after its value was
				// dropped, or left by an import. Giving the value back to the
				// product makes it selectable again; a second variation would
				// only duplicate it.
				if ( ! empty( $row['new_values'] ) ) {
					$reattach[] = array(
						'id'  => $clash[1],
						'row' => $row,
					);
					continue;
				}

				if ( empty( $args['fill_missing'] ) || ! empty( $row['listed'] ) ) {
					return new WP_Error(
						'woobe_mcp_variation_exists',
						'Variation ' . $clash[1] . ' already is ' . wp_json_encode( $this->readable( $row['combo'], $axes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
						. '. Change its price or stock with woobe_update_product instead of adding a second one - checkout would only ever sell one of them.'
					);
				}

				continue;
			}

			if ( $clash && 'any' === $clash[0] ) {

				// fill_missing never creates what an "any" variation sells
				if ( empty( $row['listed'] ) ) {
					continue;
				}

				$warnings[] = 'Variation ' . $clash[1] . ' is set to "any" on at least one axis and already sells '
					. wp_json_encode( $this->readable( $row['combo'], $axes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '. The new one will take over that combination from it at checkout - usually what is wanted, but say so.';
			}

			$plan[] = $row;
		}

		if ( empty( $plan ) && empty( $reattach ) ) {
			return new WP_Error( 'woobe_mcp_nothing_missing', 'Every combination of the current values already has a variation. To offer a new value, list it: {"Size":"XL", ...}.' );
		}

		if ( count( $plan ) > self::MAX_NEW ) {
			return new WP_Error(
				'woobe_mcp_too_many_variations',
				'That would add ' . count( $plan ) . ' variations. The ceiling is ' . self::MAX_NEW . ' in one go - more is almost never what was meant. Narrow it down.'
			);
		}

		$new_values = array();
		$no_price   = 0;

		foreach ( $reattach as $r ) {
			foreach ( $r['row']['new_values'] as $nv ) {
				$new_values[ $nv['key'] . '|' . $nv['stored'] ] = $nv;
			}
		}

		foreach ( $plan as $row ) {

			foreach ( $row['new_values'] as $nv ) {
				$new_values[ $nv['key'] . '|' . $nv['stored'] ] = $nv;
			}

			if ( '' === $row['price'] ) {
				++$no_price;
			}
		}

		if ( $no_price ) {
			$warnings[] = $no_price . ' of the new variations have no price. WooCommerce does not sell a variation without one - it stays invisible to customers until a price is set.';
		}

		$table = array();

		foreach ( $plan as $row ) {
			$table[] = array(
				'attributes' => $this->readable_planned( $row['combo'], $axes, $row['new_values'] ),
				'price'      => '' === $row['price'] ? null : $row['price'],
				'sale_price' => '' === $row['sale_price'] ? null : $row['sale_price'],
				'sku'        => '' === $row['sku'] ? null : $row['sku'],
				'stock'      => $row['stock'],
			);
		}

		$values_out = array();

		foreach ( $new_values as $nv ) {
			$values_out[] = array(
				'axis'  => $axes[ $nv['key'] ]['name'],
				'value' => $nv['label'],
				'new_to' => 'shop' === $nv['needs'] ? 'the shop and this product' : 'this product',
			);
		}

		$reattach_out = array();

		foreach ( $reattach as $r ) {
			$reattach_out[] = array(
				'id'         => $r['id'],
				'attributes' => $this->readable_planned( $r['row']['combo'], $axes, $r['row']['new_values'] ),
			);
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was changed yet. ' . ( $plan ? count( $plan ) . ' variations would be created on ' : 'No variation would be created on ' ) . $product->get_name()
				. ( $plan ? ': ' . wp_json_encode( $table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '' )
				. ( $reattach_out ? '. These existing variations use a value the product had lost and become selectable again, without a second variation being made: ' . wp_json_encode( $reattach_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '' )
				. ( $values_out ? '. New values: ' . wp_json_encode( $values_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '' )
				. ( $warnings ? '. Also say: ' . rtrim( implode( ' ', $warnings ), '.' ) : '' )
				. '. Read the table back, count included, get a yes, then call again with confirmed true.'
			);
		}

		// the parent first: a variation cannot use a value its parent lacks
		$extended = $this->extend_parent( $product, $axes, $new_values );

		if ( is_wp_error( $extended ) ) {
			return $extended;
		}

		$made      = array();
		$sku_fails = array();
		$position  = count( $existing );

		foreach ( $plan as $row ) {

			$id = $this->build_variation( $product->get_id(), $row, $position++, $sku_fails );

			if ( $id ) {
				$made[] = array(
					'id'         => $id,
					'attributes' => $this->readable_planned( $row['combo'], $axes, $row['new_values'] ),
					'price'      => '' === $row['price'] ? null : $row['price'],
				);
			}
		}

		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
		$this->mcp->clear_caches_for( array_merge( array( $product->get_id() ), wp_list_pluck( $made, 'id' ) ) );

		foreach ( $sku_fails as $sku ) {
			$warnings[] = 'The SKU ' . $sku . ' is already used by another product, so that variation was created without one.';
		}

		// orphans whose value is back on the product: caches only, their own
		// data never changed
		$this->mcp->clear_caches_for( wp_list_pluck( $reattach_out, 'id' ) );

		return array(
			'product_id' => $product->get_id(),
			'added'      => $made,
			'count'      => count( $made ),
			'reattached' => $reattach_out,
			'new_values' => $values_out,
			'warnings'   => $warnings,
			'note'       => ( $made ? 'Created. Name the new variations back. They are live on the product at once if it is published. Not in BEAR history - to take them away again, woobe_remove_variations with these ids.' : 'Nothing new was created.' )
				. ( $reattach_out ? ' The variations in reattached had their value put back on the product and can be chosen again.' : '' ),
		);
	}

	/**
	 * One listed variation, checked and turned into stored values.
	 */
	private function plan_row( $raw, $axes, $default_price, $default_sale, $default_stock, $number ) {

		$asked = ( isset( $raw['attributes'] ) && is_array( $raw['attributes'] ) ) ? $raw['attributes'] : array();
		$combo = array();
		$new   = array();

		// match what the user wrote to the product's axes, and nothing else
		$given = array();

		foreach ( $asked as $name => $value ) {

			$key = $this->find_axis( $name, $axes );

			if ( is_null( $key ) ) {
				$names = implode( ', ', wp_list_pluck( $axes, 'name' ) );
				return new WP_Error(
					'woobe_mcp_unknown_axis',
					'Variation ' . $number . ' names ' . $name . ', which this product does not vary along. Its axes are: ' . $names . '. A new axis is added with woobe_change_variation_axes first.'
				);
			}

			$given[ $key ] = $value;
		}

		foreach ( $axes as $key => $axis ) {

			if ( ! isset( $given[ $key ] ) ) {
				return new WP_Error(
					'woobe_mcp_variation_incomplete',
					'Variation ' . $number . ' does not say which ' . $axis['name'] . ' it is. Every new variation needs a value on every axis: without one it becomes "any" and sells every ' . $axis['name'] . ' at once.'
				);
			}

			$value = $this->resolve_value( $given[ $key ], $axis );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$combo[ $key ] = $value['stored'];

			if ( 'none' !== $value['needs'] ) {
				$new[] = array_merge( $value, array( 'key' => $key ) );
			}
		}

		$price = isset( $raw['price'] ) ? trim( (string) $raw['price'] ) : $default_price;
		$sale  = isset( $raw['sale_price'] ) ? trim( (string) $raw['sale_price'] ) : $default_sale;

		foreach ( array( 'price' => $price, 'sale_price' => $sale ) as $field => $amount ) {
			if ( '' !== $amount && ( ! is_numeric( $amount ) || floatval( $amount ) < 0 ) ) {
				return new WP_Error( 'woobe_mcp_bad_price', 'Variation ' . $number . ': ' . $field . ' ' . $amount . ' is not a price.' );
			}
		}

		if ( '' !== $sale && '' !== $price && floatval( $sale ) >= floatval( $price ) ) {
			return new WP_Error( 'woobe_mcp_bad_price', 'Variation ' . $number . ': the sale price ' . $sale . ' is not below the regular price ' . $price . '.' );
		}

		return array(
			'combo'      => $combo,
			'new_values' => $new,
			'price'      => $price,
			'sale_price' => $sale,
			'sku'        => isset( $raw['sku'] ) ? sanitize_text_field( $raw['sku'] ) : '',
			'stock'      => isset( $raw['stock'] ) ? intval( $raw['stock'] ) : $default_stock,
			'listed'     => true,
		);
	}

	/**
	 * Like readable(), but also naming values that do not exist on the
	 * product yet.
	 */
	private function readable_planned( $combo, $axes, $new_values ) {

		$labels = array();

		foreach ( $new_values as $nv ) {
			$labels[ $nv['key'] ][ $nv['stored'] ] = $nv['label'];
		}

		$out = array();

		foreach ( $combo as $key => $stored ) {
			$name         = $axes[ $key ]['name'];
			$out[ $name ] = isset( $labels[ $key ][ $stored ] ) ? $labels[ $key ][ $stored ]
				: ( isset( $axes[ $key ]['values'][ $stored ] ) ? $axes[ $key ]['values'][ $stored ] : $stored );
		}

		return $out;
	}

	/**
	 * Puts new values on the parent, creating shop terms that do not exist.
	 */
	private function extend_parent( $product, $axes, $new_values ) {

		if ( empty( $new_values ) ) {
			return true;
		}

		$attributes = $product->get_attributes();

		foreach ( $new_values as $nv ) {

			$key = $nv['key'];

			// A copy, not the object itself. get_attributes() hands back the very
			// instances the product holds; changed in place, the new array is
			// identical to the old one, WC_Data::set_prop() sees no change and
			// save() quietly skips the attributes - the variation was created and
			// the product never learned its new value.
			$attribute = clone $attributes[ $key ];

			if ( $axes[ $key ]['taxonomy'] ) {

				$term_id = isset( $nv['term_id'] ) ? $nv['term_id'] : 0;

				if ( ! $term_id ) {

					$made = wp_insert_term( $nv['label'], $axes[ $key ]['taxonomy'] );

					if ( is_wp_error( $made ) ) {

						// created meanwhile by somebody else: use it
						$existing = get_term_by( 'name', $nv['label'], $axes[ $key ]['taxonomy'] );

						if ( ! $existing ) {
							return new WP_Error( 'woobe_mcp_term_failed', 'Could not add ' . $nv['label'] . ' to ' . $axes[ $key ]['name'] . ': ' . $made->get_error_message() );
						}

						$term_id = intval( $existing->term_id );
					} else {
						$term_id = intval( $made['term_id'] );
					}
				}

				$attribute->set_options( array_values( array_unique( array_merge( array_map( 'intval', $attribute->get_options() ), array( $term_id ) ) ) ) );

			} else {
				$attribute->set_options( array_values( array_unique( array_merge( $attribute->get_options(), array( $nv['stored'] ) ) ) ) );
			}

			$attributes[ $key ] = $attribute;
		}

		// the product data store attaches the terms to the product on save
		$product->set_attributes( $attributes );
		$product->save();

		return true;
	}

	private function build_variation( $parent_id, $row, $position, &$sku_fails ) {

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_menu_order( $position );

		// a new shop term gets its slug from WordPress, which may differ from
		// the sanitised label planned here - read it back rather than guess
		$attributes = array();

		foreach ( $row['combo'] as $key => $stored ) {

			foreach ( $row['new_values'] as $nv ) {
				if ( $nv['key'] === $key && $nv['stored'] === $stored && 'shop' === $nv['needs'] && taxonomy_exists( $key ) ) {
					$term = get_term_by( 'name', $nv['label'], $key );
					if ( $term ) {
						$stored = $term->slug;
					}
				}
			}

			$attributes[ $key ] = $stored;
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

		$id = $variation->save();

		// after the first save, for the reason create.php gives: the SKU
		// check needs the variation's own id
		if ( $id && '' !== $row['sku'] ) {
			try {
				$variation->set_sku( $row['sku'] );
				$variation->save();
			} catch ( Exception $e ) {
				$sku_fails[] = $row['sku'];
			}
		}

		return $id;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// removing

	private function remove( $args ) {

		$product = $this->parent( $args );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$axes     = $this->axes_of( $product );
		$existing = $this->variations_of( $product, $axes );
		$by_match = ! empty( $args['match'] ) && is_array( $args['match'] );
		$targets  = array();

		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {

			$foreign = array();

			foreach ( array_map( 'intval', $args['ids'] ) as $id ) {
				if ( isset( $existing[ $id ] ) ) {
					$targets[ $id ] = $existing[ $id ];
				} else {
					$foreign[] = $id;
				}
			}

			if ( ! empty( $foreign ) ) {
				return new WP_Error(
					'woobe_mcp_not_its_variation',
					'Not live variations of ' . $product->get_name() . ': ' . implode( ', ', $foreign ) . '. woobe_variations lists the ones it has.'
				);
			}

		} elseif ( $by_match ) {

			$want = array();

			foreach ( $args['match'] as $name => $value ) {

				$key = $this->find_axis( $name, $axes );

				if ( is_null( $key ) ) {
					return new WP_Error( 'woobe_mcp_unknown_axis', $product->get_name() . ' does not vary along ' . $name . '. Its axes: ' . implode( ', ', wp_list_pluck( $axes, 'name' ) ) . '.' );
				}

				$value = $this->resolve_value( $value, $axes[ $key ] );

				if ( is_wp_error( $value ) ) {
					return $value;
				}

				if ( 'none' !== $value['needs'] ) {
					return new WP_Error( 'woobe_mcp_no_such_value', $product->get_name() . ' has no ' . $axes[ $key ]['name'] . ' ' . $value['label'] . '. Values: ' . implode( ', ', $axes[ $key ]['values'] ) . '.' );
				}

				$want[ $key ] = $value['stored'];
			}

			// an "any" variation is deliberately not matched, see the schema
			foreach ( $existing as $id => $v ) {

				$hit = true;

				foreach ( $want as $key => $stored ) {
					if ( $v['combo'][ $key ] !== $stored ) {
						$hit = false;
						break;
					}
				}

				if ( $hit ) {
					$targets[ $id ] = $v;
				}
			}

		} else {
			return new WP_Error( 'woobe_mcp_no_target', 'Give the variation ids, or match with the value to remove - {"Colour":"Red"}.' );
		}

		if ( empty( $targets ) ) {
			return new WP_Error( 'woobe_mcp_nothing_matched', 'No live variation of ' . $product->get_name() . ' matches that. Variations set to "any" are never taken by a match.' );
		}

		$drop = isset( $args['drop_values'] ) ? (bool) $args['drop_values'] : $by_match;

		// values nobody would use any more once these are gone
		$remaining = array_diff_key( $existing, $targets );
		$orphans   = array();

		if ( $drop ) {
			foreach ( $axes as $key => $axis ) {

				// an "any" variation that stays keeps every value of its axis alive
				$any_left = false;
				$used     = array();

				foreach ( $remaining as $v ) {
					if ( '' === $v['combo'][ $key ] ) {
						$any_left = true;
					}
					$used[ $v['combo'][ $key ] ] = true;
				}

				if ( $any_left ) {
					continue;
				}

				foreach ( $axis['values'] as $stored => $label ) {
					if ( ! isset( $used[ (string) $stored ] ) ) {
						$orphans[ $key ][] = (string) $stored;
					}
				}
			}
		}

		$list = array();

		foreach ( $targets as $v ) {
			$list[] = array(
				'id'         => $v['id'],
				'attributes' => $this->readable( $v['combo'], $axes ),
				'stock'      => $v['variation']->managing_stock() ? $v['variation']->get_stock_quantity() : null,
			);
		}

		$dropped = array();

		foreach ( $orphans as $key => $values ) {
			foreach ( $values as $stored ) {
				$dropped[] = $axes[ $key ]['name'] . ': ' . $axes[ $key ]['values'][ $stored ];
			}
		}

		$warnings = array();

		if ( empty( $remaining ) ) {
			$warnings[] = 'That is every variation the product has. It stays in the shop but nothing on it can be bought until variations are added again.';
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was removed yet. ' . count( $list ) . ' variations of ' . $product->get_name() . ' would go to the trash: ' . wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. ( $dropped ? '. These values would also come off the product, since nothing would use them: ' . implode( ', ', $dropped ) : '' )
				. ( $warnings ? '. ' . rtrim( implode( ' ', $warnings ), '.' ) : '' )
				. '. Variations come back with woobe_restore_products; values taken off the product do not come back with them and would have to be added again. Get a yes, then call again with confirmed true.'
			);
		}

		$trashed = array();

		foreach ( $targets as $id => $v ) {
			if ( wp_trash_post( $id ) ) {
				$trashed[] = $id;
			}
		}

		if ( ! empty( $orphans ) ) {

			$attributes = $product->get_attributes();

			foreach ( $orphans as $key => $values ) {

				// a copy, for the reason extend_parent() gives
				$attribute = clone $attributes[ $key ];

				if ( $attribute->is_taxonomy() ) {

					$keep = array();

					foreach ( (array) $attribute->get_terms() as $term ) {
						if ( ! in_array( $term->slug, $values, true ) ) {
							$keep[] = intval( $term->term_id );
						}
					}

					$attribute->set_options( $keep );
				} else {
					$attribute->set_options( array_values( array_diff( $attribute->get_options(), $values ) ) );
				}

				$attributes[ $key ] = $attribute;
			}

			$product->set_attributes( $attributes );

			// a default pointing at a value that is gone preselects nothing
			// and confuses the product page
			$defaults = $product->get_default_attributes();

			foreach ( $orphans as $key => $values ) {
				if ( isset( $defaults[ $key ] ) && in_array( (string) $defaults[ $key ], $values, true ) ) {
					unset( $defaults[ $key ] );
				}
			}

			$product->set_default_attributes( $defaults );
			$product->save();
		}

		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
		$this->mcp->clear_caches_for( array_merge( array( $product->get_id() ), $trashed ) );

		return array(
			'product_id'     => $product->get_id(),
			'trashed'        => $trashed,
			'values_dropped' => $dropped,
			'remaining'      => count( $remaining ),
			'warnings'       => $warnings,
			'note'           => 'In the trash. woobe_restore_products with these ids brings them back. If values were dropped, a restored variation is not selectable until its value is back on the product: woobe_add_variations listing that same combination does exactly that, without creating a second variation.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// reshaping

	private function axes( $args ) {

		$product = $this->parent( $args );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$operation = isset( $args['operation'] ) ? sanitize_key( $args['operation'] ) : '';

		switch ( $operation ) {
			case 'add_axis':
				return $this->add_axis( $product, $args );
			case 'remove_axis':
				return $this->remove_axis( $product, $args );
			case 'set_defaults':
				return $this->set_defaults( $product, $args );
		}

		return new WP_Error( 'woobe_mcp_bad_operation', 'operation has to be add_axis, remove_axis or set_defaults.' );
	}

	private function add_axis( $product, $args ) {

		$name = isset( $args['attribute'] ) ? trim( sanitize_text_field( wp_unslash( $args['attribute'] ) ) ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'woobe_mcp_no_attribute', 'Say which attribute to add - a shop attribute such as pa_material, or a plain name.' );
		}

		$all = $this->axes_of( $product, false );

		if ( ! is_null( $this->find_axis( $name, $all ) ) ) {

			$key = $this->find_axis( $name, $all );

			return new WP_Error(
				'woobe_mcp_axis_exists',
				$product->get_name() . ' already has ' . $all[ $key ]['name']
				. ( $all[ $key ]['variation'] ? ' as a choice. New values go in with woobe_add_variations.' : ' as information on the product page. Making it a choice as well is done in wp-admin for now.' )
			);
		}

		// a shop attribute by its taxonomy, or a product-only one by name;
		// "Material" when the shop has pa_material means the shop's one
		$taxonomy = '';

		if ( taxonomy_exists( $name ) && 0 === strpos( $name, 'pa_' ) ) {
			$taxonomy = $name;
		} elseif ( taxonomy_exists( wc_attribute_taxonomy_name( $name ) ) ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
		}

		$axis = array(
			// the key the parent files the attribute under, and the only one a
			// variation matches it on: sanitize_title() of the taxonomy. For
			// pa_material it is the same string; for a non-Latin taxonomy it
			// is percent-encoded, and the raw name left every existing
			// variation on "any" while the answer said they got the value
			'key'      => $taxonomy ? sanitize_title( $taxonomy ) : sanitize_title( $name ),
			'name'     => $taxonomy ? wc_attribute_label( $taxonomy ) : $name,
			'taxonomy' => $taxonomy ? $taxonomy : null,
			'values'   => array(),
		);

		$values = array();

		foreach ( ( isset( $args['values'] ) && is_array( $args['values'] ) ) ? $args['values'] : array() as $raw ) {

			$value = $this->resolve_value( $raw, $axis );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$values[ $value['stored'] ] = $value;
		}

		if ( empty( $values ) ) {
			return new WP_Error( 'woobe_mcp_no_values', 'A new axis needs its values - ["Cotton","Wool"].' );
		}

		$for_existing = null;

		if ( isset( $args['value_for_existing'] ) && '' !== trim( (string) $args['value_for_existing'] ) ) {

			$want = strtolower( trim( (string) $args['value_for_existing'] ) );

			foreach ( $values as $stored => $value ) {
				if ( $want === strtolower( $value['label'] ) || $want === strtolower( (string) $stored ) ) {
					$for_existing = $value;
				}
			}

			if ( ! $for_existing ) {
				return new WP_Error( 'woobe_mcp_bad_value', 'value_for_existing has to be one of the values given: ' . implode( ', ', wp_list_pluck( $values, 'label' ) ) . '.' );
			}
		}

		$variation_axes = $this->axes_of( $product );
		$existing       = $this->variations_of( $product, $variation_axes );

		$new_to_shop = array();

		foreach ( $values as $value ) {
			if ( 'shop' === $value['needs'] ) {
				$new_to_shop[] = $value['label'];
			}
		}

		$warnings = array();

		if ( ! empty( $existing ) && ! $for_existing ) {
			$warnings[] = 'The ' . count( $existing ) . ' existing variations get no ' . $axis['name'] . ' - "any" - so each of them sells in every ' . $axis['name'] . ' at its current price. Usually the user means them to be one particular value: ask, and pass it as value_for_existing.';
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was changed yet. ' . $product->get_name() . ' would vary by ' . $axis['name'] . ' as well, with values ' . implode( ', ', wp_list_pluck( $values, 'label' ) )
				. ( $new_to_shop ? ' (new to the shop: ' . implode( ', ', $new_to_shop ) . ')' : '' )
				. '.' . ( $for_existing ? ' The ' . count( $existing ) . ' existing variations become ' . $for_existing['label'] . '.' : '' )
				. ( $warnings ? ' ' . implode( ' ', $warnings ) : '' )
				. ' No variations are added for the other values - that is woobe_add_variations afterwards, with fill_missing if every combination is wanted. Get a yes, then call again with confirmed true.'
			);
		}

		$attribute = new WC_Product_Attribute();

		if ( $taxonomy ) {

			$term_ids = array();

			foreach ( $values as $stored => $value ) {

				if ( ! empty( $value['term_id'] ) ) {
					$term_ids[] = $value['term_id'];
					continue;
				}

				$made = wp_insert_term( $value['label'], $taxonomy );

				if ( is_wp_error( $made ) ) {
					return new WP_Error( 'woobe_mcp_term_failed', 'Could not add ' . $value['label'] . ' to ' . $axis['name'] . ': ' . $made->get_error_message() );
				}

				$term_ids[] = intval( $made['term_id'] );

				// WordPress may have given it a different slug than planned
				$term = get_term( intval( $made['term_id'] ), $taxonomy );

				if ( $for_existing && $for_existing['stored'] === $stored && $term && ! is_wp_error( $term ) ) {
					$for_existing['stored'] = $term->slug;
				}
			}

			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( $term_ids );

		} else {
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array_keys( $values ) );
		}

		$attributes = $product->get_attributes();

		$attribute->set_position( count( $attributes ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$attributes[ $axis['key'] ] = $attribute;

		$product->set_attributes( $attributes );
		$product->save();

		$touched = array();

		if ( $for_existing ) {
			foreach ( $existing as $id => $v ) {

				$variation = $v['variation'];
				$stored    = $variation->get_attributes();

				$stored[ $axis['key'] ] = $for_existing['stored'];

				$variation->set_attributes( $stored );
				$variation->save();

				$touched[] = $id;
			}
		}

		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
		$this->mcp->clear_caches_for( array_merge( array( $product->get_id() ), $touched ) );

		// Read back rather than trust the write. The answer used to report
		// every variation as updated while they sat on "any"; checking each
		// one from the database means the number said is the number done.
		$confirmed_ids = array();

		foreach ( $touched as $id ) {

			$fresh = wc_get_product( $id );
			$got   = $fresh ? $fresh->get_attributes() : array();

			if ( isset( $got[ $axis['key'] ] ) && (string) $got[ $axis['key'] ] === (string) $for_existing['stored'] ) {
				$confirmed_ids[] = $id;
			}
		}

		$failed = array_values( array_diff( $touched, $confirmed_ids ) );

		if ( $failed ) {
			$warnings[] = count( $failed ) . ' of ' . count( $touched ) . ' existing variations did not take ' . $for_existing['label'] . ' and still read as "any": ' . implode( ', ', $failed ) . '. Tell the user, and check them with woobe_variations.';
		}

		$touched = $confirmed_ids;

		return array(
			'product_id'         => $product->get_id(),
			'axis_added'         => $axis['name'],
			'values'             => array_values( wp_list_pluck( $values, 'label' ) ),
			'existing_became'    => $for_existing ? $for_existing['label'] : 'any',
			'variations_updated' => count( $touched ),
			'warnings'           => $warnings,
			'note'               => 'Done. The other values have no variations yet - offer woobe_add_variations, with fill_missing and a price if every combination is wanted.',
		);
	}

	private function remove_axis( $product, $args ) {

		$all  = $this->axes_of( $product );
		$name = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
		$key  = $this->find_axis( $name, $all );

		if ( is_null( $key ) ) {
			return new WP_Error( 'woobe_mcp_unknown_axis', $product->get_name() . ' does not vary along ' . $name . '. Its axes: ' . implode( ', ', wp_list_pluck( $all, 'name' ) ) . '.' );
		}

		$keep_info = ! empty( $args['keep_as_info'] );
		$existing  = $this->variations_of( $product, $all );

		// what the variations look like without this axis, and who collides
		$rest = $all;
		unset( $rest[ $key ] );

		$groups = array();

		foreach ( $existing as $id => $v ) {
			$combo = $v['combo'];
			unset( $combo[ $key ] );
			$groups[ wp_json_encode( $combo ) ][] = $id;
		}

		$collisions = array();

		foreach ( $groups as $json => $ids ) {
			if ( count( $ids ) > 1 ) {

				$members = array();

				foreach ( $ids as $id ) {
					$members[] = array(
						'id'         => $id,
						'attributes' => $this->readable( $existing[ $id ]['combo'], $all ),
						'price'      => $existing[ $id ]['variation']->get_regular_price(),
					);
				}

				$collisions[] = array(
					'would_become' => empty( $rest ) ? 'the only variation' : $this->readable( json_decode( $json, true ), $rest ),
					'variations'   => $members,
				);
			}
		}

		if ( ! empty( $collisions ) ) {
			return new WP_Error(
				'woobe_mcp_axis_collision',
				'Without ' . $all[ $key ]['name'] . ', these variations would be indistinguishable and checkout would only ever sell one of each group: ' . wp_json_encode( $collisions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. '. Ask the user which one of each group to keep - prices may differ - remove the rest with woobe_remove_variations, then remove the axis.'
			);
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was changed yet. ' . $product->get_name() . ' would stop varying by ' . $all[ $key ]['name'] . '; the ' . count( $existing ) . ' variations stay, without it. '
				. ( $keep_info
					? 'The attribute stays on the product page as information: ' . implode( ', ', $all[ $key ]['values'] ) . '.'
					: 'The attribute and its values come off the product entirely.' )
				. ' Get a yes, then call again with confirmed true.'
			);
		}

		$attributes = $product->get_attributes();
		$previous   = array_values( $all[ $key ]['values'] );

		if ( $keep_info ) {
			// a copy, for the reason extend_parent() gives
			$attributes[ $key ] = clone $attributes[ $key ];
			$attributes[ $key ]->set_variation( false );
		} else {
			unset( $attributes[ $key ] );
		}

		$product->set_attributes( $attributes );

		$defaults = $product->get_default_attributes();
		unset( $defaults[ $key ] );
		$product->set_default_attributes( $defaults );

		$product->save();

		// the variation data store deletes attribute meta that is no longer
		// in the set, so saving without the key is the whole job
		$touched = array();

		foreach ( $existing as $id => $v ) {

			$variation = $v['variation'];
			$stored    = $variation->get_attributes();

			if ( array_key_exists( $key, $stored ) ) {
				unset( $stored[ $key ] );
				$variation->set_attributes( $stored );
				$variation->save();
				$touched[] = $id;
			}
		}

		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
		$this->mcp->clear_caches_for( array_merge( array( $product->get_id() ), $touched ) );

		return array(
			'product_id'         => $product->get_id(),
			'axis_removed'       => $all[ $key ]['name'],
			'kept_as_info'       => $keep_info,
			'previous_values'    => $previous,
			'variations_updated' => count( $touched ),
			'note'               => 'Done. Not in BEAR history: putting the axis back is add_axis with previous_values, and each variation would need its value set again.',
		);
	}

	private function set_defaults( $product, $args ) {

		$axes     = $this->axes_of( $product );
		$asked    = ( isset( $args['defaults'] ) && is_array( $args['defaults'] ) ) ? $args['defaults'] : array();
		$defaults = array();

		foreach ( $asked as $name => $value ) {

			$key = $this->find_axis( $name, $axes );

			if ( is_null( $key ) ) {
				return new WP_Error( 'woobe_mcp_unknown_axis', $product->get_name() . ' does not vary along ' . $name . '.' );
			}

			$resolved = $this->resolve_value( $value, $axes[ $key ] );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( 'none' !== $resolved['needs'] ) {
				return new WP_Error( 'woobe_mcp_no_such_value', $product->get_name() . ' has no ' . $axes[ $key ]['name'] . ' ' . $resolved['label'] . '.' );
			}

			$defaults[ $key ] = $resolved['stored'];
		}

		$before = $this->readable( $this->default_combo( $product, $axes ), $axes );
		$after  = $this->readable( $defaults, $axes );

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was changed yet. Preselected on the product page now: ' . ( $before ? wp_json_encode( $before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : 'nothing' )
				. '; it would become: ' . ( $after ? wp_json_encode( $after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : 'nothing - the customer picks every choice himself' )
				. '. Get a yes, then call again with confirmed true.'
			);
		}

		$product->set_default_attributes( $defaults );
		$product->save();

		$this->mcp->clear_caches_for( array( $product->get_id() ) );

		return array(
			'product_id' => $product->get_id(),
			'defaults'   => $after,
			'previous'   => $before,
			'note'       => 'Done.',
		);
	}
}