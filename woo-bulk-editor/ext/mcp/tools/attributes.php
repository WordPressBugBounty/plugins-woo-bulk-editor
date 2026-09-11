<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Product attributes: the axes variations vary along.
 *
 * An attribute is two things at once, and that is why it has its own pack
 * rather than living with the taxonomies. There is a taxonomy - pa_color, an
 * ordinary WordPress taxonomy holding terms - and there is a row in
 * WooCommerce's own attribute register, which is what makes the taxonomy
 * appear in the product editor, in the layered nav, and in the variation
 * dropdowns.
 *
 * Create only the taxonomy and you get terms nobody can reach. Create only the
 * register row and the terms have nowhere to live. wc_create_attribute does
 * both and then needs the taxonomies registered again before terms can be
 * added in the same request - which is the part that catches people out, and
 * the reason this exists as a tool rather than as advice.
 */
final class WOOBE_MCP_TOOL_ATTRIBUTES extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_attributes' => array(
				'name'        => 'woobe_attributes',
				'description' => 'Every product attribute on this shop with its terms - the axes variations can vary along. Read it before creating anything: an attribute called Colour may already exist as pa_color, and a second one would split the catalogue in two.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'with_terms' => array(
							'type'        => 'boolean',
							'description' => 'Include the terms of each attribute. On by default; turn it off on a shop with hundreds of them.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_create_attribute' => array(
				'name'        => 'woobe_create_attribute',
				'description' => 'Creates a product attribute and, if given, its terms in one go. Use it when the user wants to vary products along something the shop has no attribute for - a sleeve length, a finish, a voltage. Check woobe_attributes first: attributes are cheap to create and expensive to merge afterwards, because every product has to be re-tagged by hand.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'         => array(
							'type'        => 'string',
							'description' => 'What the customer sees - "Sleeve Length". The taxonomy name is derived from it: pa_sleeve-length.',
						),
						'slug'         => array(
							'type'        => 'string',
							'description' => 'Optional. Overrides the derived name. Keep it short - WordPress caps taxonomy names at 32 characters including the pa_ prefix, and a long attribute name silently gets cut.',
						),
						'terms'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Values to create straight away - ["Short","Long"]. Optional, but an attribute with no terms does nothing.',
						),
						'type'         => array(
							'type'        => 'string',
							'enum'        => array( 'select', 'text' ),
							'description' => 'select gives a dropdown of terms and is what almost every shop wants. text lets each product type a free value and cannot be used for variations.',
						),
						'order_by'     => array(
							'type'        => 'string',
							'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
							'description' => 'How terms are sorted when shown. name_num sorts 2 before 10, which is what sizes and voltages need; plain name puts 10 first.',
						),
						'has_archives' => array(
							'type'        => 'boolean',
							'description' => 'Whether each term gets its own page on the shop. Off by default - most attributes are for filtering, not for browsing.',
						),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_attribute_terms' => array(
				'name'        => 'woobe_attribute_terms',
				'description' => 'Adds values to an attribute that already exists - another colour, another size. A value already present is skipped rather than duplicated.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attribute' => array(
							'type'        => 'string',
							'description' => 'The taxonomy name - pa_color - or the attribute label. Either is accepted.',
						),
						'terms'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'attribute', 'terms' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_delete_attribute' => array(
				'name'        => 'woobe_delete_attribute',
				'description' => 'Removes an attribute and every one of its terms. This is heavier than it sounds: variations built on that attribute lose the thing that told them apart, and a variable product left with indistinguishable variations behaves badly in the shop. Say how many products use it before asking, and require confirmed. There is no undo: neither the attribute nor its terms can be restored, and BEAR history does not cover either. Most of what this connection does can be rolled back or taken out of the trash, so the user will assume this can too unless you tell him otherwise.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attribute' => array( 'type' => 'string' ),
						'confirmed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'attribute' ),
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
			case 'woobe_attributes':
				return $this->attributes( $args );
			case 'woobe_create_attribute':
				return $this->create_attribute( $args );
			case 'woobe_attribute_terms':
				return $this->attribute_terms( $args );
			case 'woobe_delete_attribute':
				return $this->delete_attribute( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function attributes( $args ) {

		$with_terms = isset( $args['with_terms'] ) ? (bool) $args['with_terms'] : true;
		$out        = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {

			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

			$row = array(
				'id'           => intval( $attribute->attribute_id ),
				'taxonomy'     => $taxonomy,
				'label'        => $attribute->attribute_label,
				'type'         => $attribute->attribute_type,
				'order_by'     => $attribute->attribute_orderby,
				'has_archives' => (bool) $attribute->attribute_public,
			);

			if ( taxonomy_exists( $taxonomy ) ) {

				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);

				$row['term_count'] = is_wp_error( $terms ) ? 0 : count( $terms );

				if ( $with_terms && ! is_wp_error( $terms ) ) {

					$row['terms'] = array();

					foreach ( $terms as $term ) {
						$row['terms'][] = array(
							'id'       => intval( $term->term_id ),
							'name'     => $term->name,
							'slug'     => $term->slug,
							'products' => intval( $term->count ),
						);
					}
				}
			} else {
				// registered with WooCommerce but never turned into a taxonomy,
				// which happens when an attribute is created directly in the
				// database rather than through the API
				$row['term_count'] = 0;
				$row['broken']     = 'The taxonomy for this attribute is not registered. It will not appear in the product editor.';
			}

			$out[] = $row;
		}

		return array(
			'count'      => count( $out ),
			'attributes' => $out,
			'note'       => 'products against a term is how many use it. An attribute with no terms cannot be used for variations - it exists but has nothing to choose between.',
		);
	}

	private function create_attribute( $args ) {

		$label = isset( $args['name'] ) ? trim( sanitize_text_field( wp_unslash( $args['name'] ) ) ) : '';

		if ( '' === $label ) {
			return new WP_Error( 'woobe_mcp_no_name', 'An attribute needs a name.' );
		}

		$slug = ! empty( $args['slug'] ) ? sanitize_title( $args['slug'] ) : sanitize_title( $label );

		// WordPress caps a taxonomy name at 32 characters and pa_ takes three.
		// Over the limit the taxonomy is silently truncated and stops matching
		// what WooCommerce recorded, which is a hard failure to diagnose later.
		if ( strlen( $slug ) > 28 ) {
			return new WP_Error(
				'woobe_mcp_name_too_long',
				'"' . $label . '" makes a taxonomy name longer than WordPress allows - 28 characters is the limit once pa_ is added. Give a shorter slug: something like "sleeve" rather than "sleeve-length-in-centimetres".'
			);
		}

		foreach ( wc_get_attribute_taxonomies() as $existing ) {

			if ( $existing->attribute_name === $slug || strtolower( $existing->attribute_label ) === strtolower( $label ) ) {
				return new WP_Error(
					'woobe_mcp_attribute_exists',
					'An attribute like this already exists: ' . $existing->attribute_label . ' (' . wc_attribute_taxonomy_name( $existing->attribute_name ) . '). Add terms to that one with woobe_attribute_terms rather than making a second - two attributes meaning the same thing split the catalogue and merging them afterwards means re-tagging every product by hand.'
				);
			}
		}

		$id = wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => ( isset( $args['type'] ) && 'text' === $args['type'] ) ? 'text' : 'select',
				'order_by'     => isset( $args['order_by'] ) ? sanitize_key( $args['order_by'] ) : 'menu_order',
				'has_archives' => ! empty( $args['has_archives'] ),
			)
		);

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'woobe_mcp_create_failed', $id->get_error_message() );
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );

		// The taxonomy is registered on init, and init is long past by the time
		// a REST request runs. Without this the attribute exists and terms
		// cannot be added to it until the next page load - which looks like the
		// tool half worked.
		register_taxonomy(
			$taxonomy,
			'product',
			array(
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			)
		);

		$created = array();
		$failed  = array();

		if ( ! empty( $args['terms'] ) && is_array( $args['terms'] ) ) {

			foreach ( $args['terms'] as $term_name ) {

				$term_name = trim( sanitize_text_field( wp_unslash( $term_name ) ) );

				if ( '' === $term_name ) {
					continue;
				}

				$result = wp_insert_term( $term_name, $taxonomy );

				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'name'   => $term_name,
						'reason' => $result->get_error_message(),
					);
					continue;
				}

				$created[] = array(
					'term_id' => intval( $result['term_id'] ),
					'name'    => $term_name,
				);
			}
		}

		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		return array(
			'id'       => intval( $id ),
			'taxonomy' => $taxonomy,
			'label'    => $label,
			'terms'    => $created,
			'failed'   => $failed,
			'note'     => empty( $created )
				? 'The attribute exists but has no values yet, so nothing can be varied along it. Ask the user what the values are and add them with woobe_attribute_terms.'
				: 'Ready to use. It will appear in the product editor and can be used for variations straight away.',
		);
	}

	private function attribute_terms( $args ) {

		$taxonomy = $this->resolve( isset( $args['attribute'] ) ? $args['attribute'] : '' );

		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		if ( empty( $args['terms'] ) || ! is_array( $args['terms'] ) ) {
			return new WP_Error( 'woobe_mcp_no_terms', 'No values were given.' );
		}

		$created = array();
		$skipped = array();
		$failed  = array();

		foreach ( $args['terms'] as $term_name ) {

			$term_name = trim( sanitize_text_field( wp_unslash( $term_name ) ) );

			if ( '' === $term_name ) {
				continue;
			}

			$existing = term_exists( $term_name, $taxonomy );

			if ( $existing ) {
				$skipped[] = array(
					'name'    => $term_name,
					'term_id' => intval( is_array( $existing ) ? $existing['term_id'] : $existing ),
				);
				continue;
			}

			$result = wp_insert_term( $term_name, $taxonomy );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'name'   => $term_name,
					'reason' => $result->get_error_message(),
				);
				continue;
			}

			$created[] = array(
				'term_id' => intval( $result['term_id'] ),
				'name'    => $term_name,
			);
		}

		clean_taxonomy_cache( $taxonomy );

		return array(
			'taxonomy' => $taxonomy,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'note'     => 'New values exist but no product uses them yet. Adding a value does not put it on anything - that is a separate edit. Removing a value later is final, so it is worth agreeing the list now rather than adding everything and pruning after.',
		);
	}

	private function delete_attribute( $args ) {

		$taxonomy = $this->resolve( isset( $args['attribute'] ) ? $args['attribute'] : '' );

		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$id = wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $taxonomy ) );

		if ( ! $id ) {
			return new WP_Error( 'woobe_mcp_no_attribute', 'That attribute is not registered with WooCommerce.' );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$terms = is_wp_error( $terms ) ? array() : $terms;
		$used  = 0;

		foreach ( $terms as $term ) {
			$used += intval( $term->count );
		}

		// A term's count covers published products only. A draft or a product
		// in the trash still carries the attribute, and would come back from
		// the trash with variations nothing tells apart - so "used 0 times"
		// was more reassuring than true. Count every product holding a value.
		$holders   = $terms ? get_objects_in_term( wp_list_pluck( $terms, 'term_id' ), $taxonomy ) : array();
		$holders   = is_wp_error( $holders ) ? array() : array_unique( array_map( 'intval', $holders ) );
		$unlisted  = array();

		foreach ( $holders as $holder ) {
			$status = get_post_status( $holder );
			if ( $status && 'publish' !== $status ) {
				$unlisted[ $status ] = isset( $unlisted[ $status ] ) ? $unlisted[ $status ] + 1 : 1;
			}
		}

		$unlisted_text = '';

		if ( $unlisted ) {
			$parts = array();
			foreach ( $unlisted as $status => $n ) {
				$parts[] = $n . ' ' . ( 'trash' === $status ? 'in the trash' : 'in ' . $status );
			}
			$unlisted_text = ' Not in that count are products that also carry it: ' . implode( ', ', $parts ) . '. If one of them is published or restored later, its variations will have nothing to tell them apart.';
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was deleted. ' . $taxonomy . ' has ' . count( $terms ) . ' values and they are used ' . $used
				. ' times across the published catalogue.' . $unlisted_text . ' Removing it takes every one of those values with it, and any variation that was told apart by this attribute loses what distinguished it - a variable product with two identical variations misbehaves in the shop rather than failing loudly.'
				. ' None of it can be undone: an attribute and its terms have no trash and no history entry. Read all of that out, get a clear yes, then call again with confirmed true.'
			);
		}

		$result = wc_delete_attribute( $id );

		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		if ( ! $result ) {
			return new WP_Error( 'woobe_mcp_delete_failed', 'WooCommerce refused to delete that attribute.' );
		}

		return array(
			'taxonomy'      => $taxonomy,
			'terms_removed' => count( $terms ),
			'was_used'      => $used,
			'note'          => 'Gone, along with its values. Products that used it keep their variations but those variations no longer differ by anything - if the user relied on this attribute, the affected products need looking at.',
		);
	}

	/**
	 * Accepts pa_color, color, or the label as it appears in the admin.
	 *
	 * People refer to an attribute by whichever of the three they last saw, and
	 * refusing two of them would be pedantry rather than safety.
	 */
	private function resolve( $name ) {

		$name = trim( sanitize_text_field( wp_unslash( (string) $name ) ) );

		if ( '' === $name ) {
			return new WP_Error( 'woobe_mcp_no_attribute', 'Which attribute?' );
		}

		if ( taxonomy_exists( $name ) && 0 === strpos( $name, 'pa_' ) ) {
			return $name;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {

			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

			if ( $attribute->attribute_name === $name
				|| $taxonomy === $name
				|| strtolower( $attribute->attribute_label ) === strtolower( $name ) ) {
				return $taxonomy;
			}
		}

		return new WP_Error(
			'woobe_mcp_no_attribute',
			'There is no attribute called ' . $name . ' on this shop. woobe_attributes lists them with both their names and their labels.'
		);
	}
}