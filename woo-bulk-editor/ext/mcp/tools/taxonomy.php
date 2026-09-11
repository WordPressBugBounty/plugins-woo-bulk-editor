<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Terms of the taxonomies a shop already has.
 *
 * Categories, tags, brands, shipping classes, and anything a third party
 * plugin registered. Creating the taxonomy itself is not here: that is a
 * developer's job, done in code or with a plugin built for it, and a shop
 * owner asking an assistant to invent one has usually misunderstood the
 * question. Filling an existing one is different - "add a Winter category",
 * "rename Tshirts to T-Shirts" - and that is what this covers.
 *
 * Attributes have their own pack. They look like taxonomies and behave like
 * them, but WooCommerce keeps a separate register for them, and a term added
 * to an attribute taxonomy that is not in that register is invisible
 * everywhere it matters.
 */
final class WOOBE_MCP_TOOL_TAXONOMY extends WOOBE_MCP_TOOL {

	public function tools() {

		return array(

			'woobe_taxonomies' => array(
				'name'        => 'woobe_taxonomies',
				'description' => 'Every taxonomy registered for products on this shop, with how many terms each holds and whether its terms can be nested. Read it before creating terms so the name is right - "brand" might be product_brand, pwb-brand or something a theme invented.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_create_terms' => array(
				'name'        => 'woobe_create_terms',
				'description' => 'Adds terms to a taxonomy that already exists. Several at once. A term whose name is already there is skipped rather than duplicated, and the answer says which - WordPress would otherwise happily create a second "Winter" with a different slug and nobody would notice until the filters disagreed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'The taxonomy name, exactly as woobe_taxonomies reports it.',
						),
						'terms'    => array(
							'type'        => 'array',
							'description' => 'Terms to add. Each is an object with name, and optionally slug, description and parent. Give just the name unless the user asked for more - WordPress builds a sensible slug itself, and a hand written one is a thing that can be wrong.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'slug'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'parent'      => array(
										'type'        => 'integer',
										'description' => 'Parent term id, for a taxonomy that allows nesting. Ignored where it does not.',
									),
								),
							),
						),
					),
					'required'   => array( 'taxonomy', 'terms' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_update_term' => array(
				'name'        => 'woobe_update_term',
				'description' => 'Renames a term or changes its slug, description or parent. Say what the slug change means before making one: it is part of the term\'s public address, and old links to that category stop working. Renaming without touching the slug is safe and is usually what the user meant.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => array( 'type' => 'string' ),
						'term_id'     => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'taxonomy', 'term_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'woobe_delete_terms' => array(
				'name'        => 'woobe_delete_terms',
				'description' => 'Removes terms from a taxonomy. The products keep existing - they simply stop being in that category - but the term itself is gone for good, and any child terms are moved up to its parent rather than deleted with it. Tell the user how many products each term holds before he agrees, and require confirmed. There is no undo: terms have no trash and no history entry, so nothing can bring one back. Say that before asking for a yes, not afterwards.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'  => array( 'type' => 'string' ),
						'term_ids'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'confirmed' => array(
							'type'        => 'boolean',
							'description' => 'Set only after the user has seen what the terms hold and said yes.',
						),
					),
					'required'   => array( 'taxonomy', 'term_ids' ),
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
			case 'woobe_taxonomies':
				return $this->taxonomies();
			case 'woobe_create_terms':
				return $this->create_terms( $args );
			case 'woobe_update_term':
				return $this->update_term( $args );
			case 'woobe_delete_terms':
				return $this->delete_terms( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function taxonomies() {

		$out = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {

			$count = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);

			$out[] = array(
				'taxonomy'     => $taxonomy->name,
				'label'        => $taxonomy->label,
				'terms'        => is_wp_error( $count ) ? 0 : intval( $count ),
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'is_attribute' => ( 0 === strpos( $taxonomy->name, 'pa_' ) ),
				'public'       => (bool) $taxonomy->public,
			);
		}

		return array(
			'count'      => count( $out ),
			'taxonomies' => $out,
			'note'       => 'is_attribute marks the ones WooCommerce treats as product attributes - those belong to the attribute tools, which keep the separate register WooCommerce needs. hierarchical says whether terms can have parents; passing a parent to a flat taxonomy does nothing. Creating a taxonomy itself is not possible here and is not a gap: that is written in code or added by a plugin.',
		);
	}

	private function create_terms( $args ) {

		$taxonomy = isset( $args['taxonomy'] ) ? trim( sanitize_text_field( wp_unslash( $args['taxonomy'] ) ) ) : '';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'woobe_mcp_no_taxonomy',
				'There is no taxonomy called ' . $taxonomy . ' on this shop. woobe_taxonomies lists the ones there are - the name is often not what it looks like in the admin.'
			);
		}

		if ( empty( $args['terms'] ) || ! is_array( $args['terms'] ) ) {
			return new WP_Error( 'woobe_mcp_no_terms', 'No terms were given.' );
		}

		$created = array();
		$skipped = array();
		$failed  = array();

		$hierarchical = is_taxonomy_hierarchical( $taxonomy );

		foreach ( $args['terms'] as $raw ) {

			$name = isset( $raw['name'] ) ? trim( sanitize_text_field( wp_unslash( $raw['name'] ) ) ) : '';

			if ( '' === $name ) {
				continue;
			}

			// checked by name rather than slug: WordPress will cheerfully make
			// a second "Winter" with the slug winter-2, and nothing looks wrong
			// until a filter returns half the products it should
			$existing = term_exists( $name, $taxonomy );

			if ( $existing ) {
				$skipped[] = array(
					'name'    => $name,
					'term_id' => intval( is_array( $existing ) ? $existing['term_id'] : $existing ),
					'reason'  => 'already exists',
				);
				continue;
			}

			$fields = array();

			if ( ! empty( $raw['slug'] ) ) {
				$fields['slug'] = sanitize_title_with_dashes( trim( $raw['slug'] ) );
			}

			if ( ! empty( $raw['description'] ) ) {
				$fields['description'] = sanitize_textarea_field( $raw['description'] );
			}

			if ( $hierarchical && ! empty( $raw['parent'] ) ) {
				$fields['parent'] = intval( $raw['parent'] );
			}

			$result = wp_insert_term( $name, $taxonomy, $fields );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'name'   => $name,
					'reason' => $result->get_error_message(),
				);
				continue;
			}

			$term = get_term( intval( $result['term_id'] ), $taxonomy );

			$created[] = array(
				'term_id' => intval( $result['term_id'] ),
				'name'    => $name,
				'slug'    => $term && ! is_wp_error( $term ) ? $term->slug : '',
				'parent'  => $term && ! is_wp_error( $term ) ? intval( $term->parent ) : 0,
			);
		}

		clean_taxonomy_cache( $taxonomy );

		return array(
			'taxonomy' => $taxonomy,
			'created'  => $created,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'note'     => 'Report the skipped ones too, with their ids - a user who asked for a term that already exists usually wants to use the existing one rather than hear that nothing happened.',
		);
	}

	private function update_term( $args ) {

		$taxonomy = isset( $args['taxonomy'] ) ? trim( sanitize_text_field( wp_unslash( $args['taxonomy'] ) ) ) : '';
		$term_id  = isset( $args['term_id'] ) ? intval( $args['term_id'] ) : 0;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'woobe_mcp_no_taxonomy', 'There is no taxonomy called ' . $taxonomy . ' on this shop.' );
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'woobe_mcp_no_term', 'There is no term ' . $term_id . ' in ' . $taxonomy . '.' );
		}

		$before = array(
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => intval( $term->parent ),
		);

		$fields = array();

		if ( isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ) {
			$fields['name'] = trim( sanitize_text_field( wp_unslash( $args['name'] ) ) );
		}

		if ( isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ) {
			$fields['slug'] = sanitize_title_with_dashes( trim( $args['slug'] ) );
		}

		if ( isset( $args['description'] ) ) {
			$fields['description'] = sanitize_textarea_field( $args['description'] );
		}

		if ( isset( $args['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {

			$parent = intval( $args['parent'] );

			// a term that is its own parent disappears from every listing and
			// is awkward to find again
			if ( $parent === $term_id ) {
				return new WP_Error( 'woobe_mcp_own_parent', 'A term cannot be its own parent.' );
			}

			$fields['parent'] = $parent;
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'woobe_mcp_nothing_to_change', 'Nothing was given to change.' );
		}

		$result = wp_update_term( $term_id, $taxonomy, $fields );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'woobe_mcp_update_failed', $result->get_error_message() );
		}

		clean_taxonomy_cache( $taxonomy );

		$after = get_term( $term_id, $taxonomy );

		return array(
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'before'   => $before,
			'after'    => array(
				'name'   => $after->name,
				'slug'   => $after->slug,
				'parent' => intval( $after->parent ),
			),
			'note'     => $before['slug'] !== $after->slug
				? 'The slug changed, so the address of this term changed with it. Any link to the old one now leads nowhere - mention that, and if the shop has a redirect plugin this is the moment to say so.'
				: 'Renamed. The slug is unchanged, so existing links still work.',
		);
	}

	private function delete_terms( $args ) {

		$taxonomy = isset( $args['taxonomy'] ) ? trim( sanitize_text_field( wp_unslash( $args['taxonomy'] ) ) ) : '';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'woobe_mcp_no_taxonomy', 'There is no taxonomy called ' . $taxonomy . ' on this shop.' );
		}

		$term_ids = ( isset( $args['term_ids'] ) && is_array( $args['term_ids'] ) ) ? array_map( 'intval', $args['term_ids'] ) : array();

		if ( empty( $term_ids ) ) {
			return new WP_Error( 'woobe_mcp_no_terms', 'No terms were given.' );
		}

		if ( empty( $args['confirmed'] ) ) {

			// what the user needs in front of him before he says yes
			$rows = array();

			foreach ( $term_ids as $term_id ) {

				$term = get_term( $term_id, $taxonomy );

				if ( $term && ! is_wp_error( $term ) ) {
					$rows[] = array(
						'term_id'  => $term_id,
						'name'     => $term->name,
						'products' => intval( $term->count ),
						'children' => count( get_term_children( $term_id, $taxonomy ) ),
					);
				}
			}

			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Nothing was deleted. Read this out first and get a yes: ' . wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. '. The products themselves survive - they just leave the term - but the term is gone for good, and any child terms move up to its parent rather than going with it.'
				. ' Nothing brings a term back afterwards: there is no trash for terms and BEAR history does not cover them. Say that plainly before he answers.'
			);
		}

		$deleted = array();
		$failed  = array();

		foreach ( $term_ids as $term_id ) {

			$term = get_term( $term_id, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				$failed[] = array(
					'term_id' => $term_id,
					'reason'  => 'no such term',
				);
				continue;
			}

			$name   = $term->name;
			$result = wp_delete_term( $term_id, $taxonomy );

			if ( is_wp_error( $result ) || ! $result ) {
				$failed[] = array(
					'term_id' => $term_id,
					'reason'  => is_wp_error( $result ) ? $result->get_error_message() : 'refused',
				);
				continue;
			}

			$deleted[] = array(
				'term_id' => $term_id,
				'name'    => $name,
			);
		}

		clean_taxonomy_cache( $taxonomy );

		return array(
			'taxonomy' => $taxonomy,
			'deleted'  => $deleted,
			'failed'   => $failed,
			'note'     => 'Deleted terms cannot be restored - there is no trash for a term. Say what went and stop.',
		);
	}
}