<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Plan a sale on a set of products, running between two dates.
 *
 * The only case that leads to a write, and deliberately the only one that does
 * not perform it. Everything here is read only: it finds the products, works
 * out what the sale price would be on each, and hands back the exact arguments
 * for woobe_apply_bulk. The agent shows that to the owner, and the write
 * happens afterwards, from his answer - which is the same rule every price
 * change in this plugin follows, and the reason a mistake is always survivable.
 *
 * Needs params:
 *   percent    how much off, as a number: 40 means 40% off the regular price
 *   date_from  when the sale starts
 *   date_to    when it ends, inclusive - the whole of that day is on sale
 *   filter     optional, a woobe_find_products filter naming which products.
 *              Left out, it plans the sale for the entire catalogue, and that
 *              has to be said out loud before anything is applied.
 *
 * The discount is computed from the regular price rather than from whatever
 * sale price a product already carries: depercent_regular_price means "40% off
 * list" no matter what state the product is in, so running the same sale twice
 * cannot compound into 64% off.
 *
 * Variations are included because the price of a variable product lives on
 * them. A sale written on the parent changes nothing a customer can see.
 */
final class WOOBE_MCP_CASE_TIMED_SALE extends WOOBE_MCP_CASE {

	public function title() {
		return 'Plan a sale with an end date';
	}

	public function question() {
		return 'Discount these products by so much, from this date until that one.';
	}

	public function answers() {
		return 'The products that would go on sale, what each price becomes, the warnings that apply - and the exact call to make it happen. Nothing is written until you say so.';
	}

	public function tags() {
		return array( 'pricing', 'promo', 'sale', 'planning' );
	}

	public function steps() {

		return array(
			array(
				'key'       => 'targets',
				'tool'      => 'woobe_find_products',
				'arguments' => array(
					'filter'             => '@params.filter',
					'include_variations' => 'all',
					'sample'             => 0,
				),
			),
			array(
				'key'       => 'preview',
				'tool'      => 'woobe_preview_bulk',
				'arguments' => array(
					'selection_id' => '@targets.selection_id',
					'limit'        => 15,
					'operations'   => array(
						array(
							'field'    => 'sale_price',
							'behavior' => 'depercent_regular_price',
							'value'    => '@params.percent',
						),
						array(
							'field'    => 'date_on_sale_from',
							'behavior' => 'new',
							'value'    => '@params.date_from',
						),
						array(
							'field'    => 'date_on_sale_to',
							'behavior' => 'new',
							'value'    => '@params.date_to',
						),
					),
				),
			),
		);
	}

	/**
	 * Adds the call that would apply this, so the agent does not have to
	 * reassemble it from the preview and get a number wrong.
	 */
	public function derive( $results ) {

		if ( ! isset( $results['preview']['selection_id'] ) ) {
			return $results;
		}

		$results['to_apply'] = array(
			'tool'      => 'woobe_apply_bulk',
			'arguments' => array(
				'selection_id'  => $results['preview']['selection_id'],
				// the count the selection actually holds - apply_bulk checks
				// against that, not against how many rows will end up written
				'confirm_count' => isset( $results['preview']['affected_count'] ) ? $results['preview']['affected_count'] : 0,
				'writable_note' => isset( $results['preview']['writable_count'] ) ? $results['preview']['writable_count'] . ' of those will actually change; the rest have nothing to compute from and are skipped.' : '',				'operations'    => 'the same three operations this case previewed',
			),
			'note'      => 'Read the preview and the warnings to the owner first, name how many products it touches, and only call this after he agrees. Selections expire after two hours - past that, run the case again rather than guessing the id.',
		);

		return $results;
	}
}
