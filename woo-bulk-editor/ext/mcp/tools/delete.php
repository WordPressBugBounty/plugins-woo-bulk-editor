<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Deleting products and variations, and getting them back.
 *
 * The one part of this connection where BEAR's own safety net does not reach.
 * History records the previous value of a field before it is overwritten; a
 * deleted product has no previous value to write down, so woobe_rollback_bulk
 * cannot bring anything back and nothing here pretends otherwise.
 *
 * What takes its place is the trash. Products are trashed rather than
 * destroyed - the same thing the editor's own delete button does - and
 * woobe_restore_products puts them back, which is why deletion is safe to
 * offer here at all. A shop that has hooked the trash out of existence has
 * made that decision deliberately.
 *
 * Variations are the exception: WordPress has no trash for them, so removing
 * one is permanent. That difference is stated in the tool description, in the
 * preview and in the answer afterwards, because a user who learned from the
 * rest of this connection that everything is undoable would reasonably assume
 * it applies here too.
 */
final class WOOBE_MCP_TOOL_DELETE extends WOOBE_MCP_TOOL {

	const MAX_AT_ONCE = 500;

	public function tools() {

		return array(

			'woobe_delete_preview' => array(
				'name'        => 'woobe_delete_preview',
				'description' => 'What a deletion would remove: the products by name, whether any have sold recently, and how many variations would go with them. Always run this first and read it out - deletion is not in BEAR history, so rollback cannot undo it: products come back only from the trash, and variations removed with variations_only do not come back at all.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'selection_id'    => array( 'type' => 'string' ),
						'ids'             => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'variations_only' => array(
							'type'        => 'boolean',
							'description' => 'Remove EVERY variation of each selected product and keep the products themselves - not a subset. If the user named particular variations - one colour, one size - this is the wrong flag: find those variations with woobe_find_products using include_variations matching, and pass their own ids instead. Variations are destroyed rather than trashed, so getting this wrong cannot be undone.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_delete_products' => array(
				'name'        => 'woobe_delete_products',
				'description' => 'Moves the selected products to the trash, the same as the delete button on the editor screen. They leave the shop at once and come back with woobe_restore_products or from Products, Trash in wp-admin. Requires confirm_count equal to the selection size and an explicit yes from the user, asked for after showing woobe_delete_preview. With variations_only the variations are removed instead, and those are destroyed permanently - say so before doing it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'selection_id'    => array( 'type' => 'string' ),
						'ids'             => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'confirm_count'   => array(
							'type'        => 'integer',
							'description' => 'Must equal the number of products in the selection. The same guard as bulk editing: an agent that did not look at the number cannot guess it.',
						),
						'confirmed'       => array(
							'type'        => 'boolean',
							'description' => 'Set only after the user has said yes to deleting this specific set, in his own words, having seen the preview.',
						),
						'variations_only' => array(
							'type'        => 'boolean',
							'description' => 'Remove EVERY variation of each selected product and keep the products themselves - not a subset. If the user named particular variations, this is the wrong flag: pass their own ids instead. Variations are destroyed rather than trashed and cannot be recovered.',
						),
					),
					'required'   => array( 'confirm_count' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => false,
				),
			),

			'woobe_trash_list' => array(
				'name'        => 'woobe_trash_list',
				'description' => 'Products currently in the trash, newest first, with when they were trashed and what status they will return to. Call this when the user changes his mind about a deletion, or asks what happened to a product he cannot find.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Optional title fragment, for a shop with a lot in the trash.',
						),
						'limit'  => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_restore_products' => array(
				'name'        => 'woobe_restore_products',
				'description' => 'Brings products back from the trash, to the status they had before they were deleted. Use it the moment a user regrets a deletion - the ids are in the answer woobe_delete_products gave, and woobe_trash_list finds them otherwise. Variations removed with variations_only cannot be restored - those are destroyed outright. Variations deleted by their own id go to the trash like anything else and come back from here.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Products to bring back. Leave it out with all_recent set to restore everything trashed in the last hour.',
						),
						'all_recent' => array(
							'type'        => 'boolean',
							'description' => 'Restore everything trashed within the last hour. For "undo what you just did" when the ids are not to hand.',
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'Force a status instead of the one each product had before. Rarely needed - by default a published product comes back published and a draft comes back a draft.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'woobe_delete_preview':
				return $this->preview( $args );
			case 'woobe_delete_products':
				return $this->delete( $args );
			case 'woobe_trash_list':
				return $this->trash_list( $args );
			case 'woobe_restore_products':
				return $this->restore( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function preview( $args ) {

		$ids = $this->ids( $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_products', 'Nothing selected to delete.' );
		}

		$variations_only = ! empty( $args['variations_only'] );
		$rows            = array();
		$variation_count = 0;

		foreach ( array_slice( $ids, 0, 100 ) as $product_id ) {

			$product = $this->products()->get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$children         = $product->is_type( 'variable' ) ? $product->get_children() : array();
			$variation_count += count( $children );

			$rows[] = array(
				'id'         => intval( $product_id ),
				'title'      => $this->product_label( $product_id ),
				'sku'        => $product->get_sku(),
				'type'       => $product->get_type(),
				'status'     => get_post_status( $product_id ),
				'variations' => count( $children ),
			);
		}

		return array(
			'count'            => count( $ids ),
			// what woobe_delete_products takes, said here as find_products
			// says it, so nobody learns it from a refusal
			'confirm_count'    => count( $ids ),
			'variations_only'  => $variations_only,
			'what_happens'     => $variations_only
				? 'EVERY variation of these products is removed permanently - ' . $variation_count . ' variations across ' . count( $ids ) . ' products, not a selected few. WordPress has no trash for variations, so this cannot be undone from anywhere. If the user asked about particular variations rather than whole products, stop and say so: the right way is to find those variations and pass their own ids.'
				: 'These products go to the trash. They leave the shop at once and can be brought back with woobe_restore_products, or from Products, Trash in wp-admin.',
			'variations_going' => $variation_count,
			// a product that sold last week is rarely one anybody meant to
			// delete, and a wide filter shows up here before it shows up as a
			// support ticket
			'sold_recently'    => $this->recently_sold( $ids ),
			'products'         => $rows,
			'previewed'        => count( $rows ),
			'note'             => 'Read this out before asking for a yes: name a few of the products, give the total, and say where they can be recovered from. Then call woobe_delete_products with the same selection or ids, confirm_count ' . count( $ids ) . ' and confirmed true. If sold_recently is not empty, mention those by name first - they are where a mistaken selection usually shows itself.',
		);
	}

	private function delete( $args ) {

		$ids = $this->ids( $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'woobe_mcp_no_products', 'Nothing selected to delete.' );
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'woobe_mcp_not_confirmed',
				'Deletion needs an explicit yes from the user, and he should have seen woobe_delete_preview before giving it. Nothing was deleted.'
			);
		}

		$confirm = isset( $args['confirm_count'] ) ? intval( $args['confirm_count'] ) : -1;

		if ( count( $ids ) !== $confirm ) {
			return new WP_Error(
				'woobe_mcp_confirm_mismatch',
				( $confirm < 0 ? 'confirm_count was not given' : 'confirm_count is ' . $confirm ) . ' but this selection holds ' . count( $ids ) . ' products. Nothing was deleted. Re-read the count and confirm it - on a deletion this guard matters more than anywhere else.'
			);
		}

		if ( count( $ids ) > self::MAX_AT_ONCE ) {
			return new WP_Error(
				'woobe_mcp_delete_too_many',
				'That is ' . count( $ids ) . ' products. Deletion through this connection is capped at ' . self::MAX_AT_ONCE
				. ' at a time - a deliberate limit rather than a technical one, because a mistaken selection of thousands is unpleasant to undo even from the trash. Narrow it, or use the plugin screen where the same operation runs in front of somebody watching.'
			);
		}

		$variations_only = ! empty( $args['variations_only'] );
		$done            = array();
		$failed          = array();

		foreach ( $ids as $product_id ) {

			$product_id = intval( $product_id );
			$product    = $this->products()->get_product( $product_id );

			if ( ! $product ) {
				$failed[] = $product_id;
				continue;
			}

			if ( $variations_only ) {

				if ( ! $product->is_type( 'variable' ) ) {
					continue;
				}

				foreach ( $product->get_children() as $child_id ) {

					$child = $this->products()->get_product( $child_id );

					// force delete: there is no trash for a variation
					if ( $child ) {
						$child->delete( true );
						$done[] = intval( $child_id );
					}
				}

				continue;
			}

			// trash rather than destroy, the same as the editor's delete button
			if ( wp_trash_post( $product_id ) ) {
				$done[] = $product_id;
			} else {
				$failed[] = $product_id;
			}
		}

		$this->mcp->clear_caches_for( array_merge( $ids, $done ) );

		return array(
			'deleted'         => count( $done ),
			'ids'             => array_slice( $done, 0, 200 ),
			'failed'          => $failed,
			'variations_only' => $variations_only,
			'recoverable'     => ! $variations_only,
			'restore_with'    => $variations_only ? null : 'woobe_restore_products with these ids',
			'note'            => $variations_only
				? count( $done ) . ' variations were removed permanently. There is no trash for variations, so this cannot be undone - say that plainly rather than offering a recovery that does not exist.'
				: count( $done ) . ' products are in the trash. Keep these ids in the conversation: if the user changes his mind, woobe_restore_products brings them straight back. Do not offer woobe_rollback_bulk - history does not cover deletion.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function trash_list( $args ) {

		$limit  = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		// WP_Query rather than get_posts(): it also says how many there are in
		// all. count alone was the size of the page, and read as "50 in the
		// trash" when there were 72.
		$query = new WP_Query(
			array(
				// Variations deleted by id land in the trash like anything else,
				// and a list that hides them is a list that tells the user his
				// deletion cannot be undone when it can.
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'trash',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				's'              => $search,
			)
		);

		$posts = $query->posts;
		$total = intval( $query->found_posts );

		$rows = array();

		foreach ( $posts as $post ) {

			$trashed_at = get_post_meta( $post->ID, '_wp_trash_meta_time', true );
			$title      = $post->post_title;

			// A variation's own title is usually a slug or nothing at all, so
			// the parent's name is what the owner will recognise in a list.
			// Some carry the parent name already, and prefixing it a second
			// time reads as a stutter.
			if ( 'product_variation' === $post->post_type && $post->post_parent ) {

				$parent = get_the_title( $post->post_parent );

				if ( '' === $title ) {
					$title = $parent . ' — variation #' . $post->ID;
				} elseif ( '' !== $parent && false === stripos( $title, $parent ) ) {
					$title = $parent . ' — ' . $title;
				}
			}

			$rows[] = array(
				'id'              => $post->ID,
				'title'           => $title,
				'is_variation'    => ( 'product_variation' === $post->post_type ),
				// shop time, as every other date on this connection
				'trashed'         => $trashed_at ? wp_date( 'Y-m-d H:i:s', intval( $trashed_at ) ) : '',
				'minutes_ago'     => $trashed_at ? intval( ( time() - intval( $trashed_at ) ) / 60 ) : null,
				'returns_to'      => get_post_meta( $post->ID, '_wp_trash_meta_status', true ),
			);
		}

		return array(
			'count' => count( $rows ),
			'total' => $total,
			'rows'  => $rows,
			'note'  => ( $total > count( $rows ) ? 'Showing the ' . count( $rows ) . ' most recently trashed of ' . $total . ' in the trash - say the total, and pass a larger limit (up to 200) or a search to see more. ' : '' ) . 'returns_to is the status each product will come back as. Restore them with woobe_restore_products. Products stay in the trash until WordPress empties it, which is 30 days by default.',
		);
	}

	private function restore( $args ) {

		$ids = isset( $args['ids'] ) && is_array( $args['ids'] ) ? array_map( 'intval', $args['ids'] ) : array();

		// "undo what you just did" without having to hunt for ids
		if ( empty( $ids ) && ! empty( $args['all_recent'] ) ) {

			$recent = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'trash',
					'posts_per_page' => self::MAX_AT_ONCE,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_wp_trash_meta_time',
							'value'   => time() - HOUR_IN_SECONDS,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			$ids = array_map( 'intval', $recent );
		}

		if ( empty( $ids ) ) {
			return new WP_Error(
				'woobe_mcp_nothing_to_restore',
				'No products given and nothing trashed in the last hour. Call woobe_trash_list to see what is in the trash.'
			);
		}

		$forced   = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$restored = array();
		$skipped  = array();

		foreach ( $ids as $product_id ) {

			// A product tool restores products. Without the type check any
			// trashed post or page could be brought back and published by id,
			// which is not something a catalogue integration should be able to
			// do even with a valid key - the id is the only thing an attacker
			// would need, and ids are easy to guess.
			if ( 'trash' !== get_post_status( $product_id )
				|| ! in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true ) ) {
				$skipped[] = $product_id;
				continue;
			}

			// The status it had before, saved by WordPress when it was trashed.
			// Since WordPress 5.6 an untrashed post comes back as a draft
			// regardless, so restoring without this leaves a published product
			// invisible in the shop - and the user, who asked to undo a
			// deletion, has no reason to suspect that happened.
			$previous = get_post_meta( $product_id, '_wp_trash_meta_status', true );
			$status   = $forced ? $forced : ( $previous ? $previous : 'draft' );

			wp_untrash_post( $product_id );

			wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => $status,
				)
			);

			$restored[] = array(
				'id'     => $product_id,
				'title'  => get_the_title( $product_id ),
				'status' => get_post_status( $product_id ),
			);
		}

		$this->mcp->clear_caches_for( $ids );

		return array(
			'restored'      => count( $restored ),
			'products'      => $restored,
			'not_in_trash'  => $skipped,
			'note'          => count( $restored ) . ' products are back, each with the status it had before it was deleted. Anything listed under not_in_trash was not in the trash - it was either never deleted or already restored.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * Which of these sold in the last three months.
	 */
	private function recently_sold( $ids ) {

		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! $this->table_exists( $lookup ) || empty( $ids ) ) {
			return array();
		}

		$ph = $this->placeholders( $ids, '%d' );

		$stats = $wpdb->prefix . 'wc_order_stats';

		// Sales only: a cancelled, failed or refunded order is not a sign the
		// product is wanted, and naming it here made a test order read as a
		// reason not to delete. Statuses are written out, not bound, so the
		// query holds nothing but table names and placeholders. Dates in shop
		// time, the clock the lookup table keeps.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, SUM( l.product_qty ) AS units, MAX( l.date_created ) AS last_sale
				   FROM {$lookup} AS l
				  INNER JOIN {$stats} AS s ON s.order_id = l.order_id
				  WHERE l.product_id IN ({$ph})
					AND l.product_qty > 0
					AND s.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
					AND l.date_created >= %s
				  GROUP BY l.product_id
				  ORDER BY units DESC
				  LIMIT 20",
				array_merge( array_map( 'intval', $ids ), array( ( new DateTimeImmutable( '-3 months', wp_timezone() ) )->format( 'Y-m-d H:i:s' ) ) )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'        => intval( $r['product_id'] ),
				'title'     => $this->product_label( $r['product_id'] ),
				'units'     => intval( $r['units'] ),
				'last_sale' => $r['last_sale'],
			);
		}

		return $out;
	}
}