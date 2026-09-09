<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The maintenance buttons from WooCommerce → Status → Tools, reachable from a
 * conversation.
 *
 * These exist because a shop occasionally shows something that is not there:
 * a variable product listed as simple, a category count that does not match
 * what the filter returns, prices that stayed the same after an edit. Almost
 * always the data is correct and a cache is not, and the fix is one button in
 * an admin screen most people have never opened.
 *
 * The work is done by WooCommerce's own tool controller rather than
 * reimplemented here. Those routines change between releases - clear_transients
 * alone touches transients, attribute counts, shipping versions and the filter
 * data cache, and that list has grown twice in recent versions. Copying it
 * would mean quietly falling behind.
 *
 * Only the safe, repeatable tools are exposed. Anything that resets roles,
 * deletes tax rates, drops customer sessions or reinstalls pages is left in the
 * admin where it belongs: those have consequences a conversation cannot
 * meaningfully confirm, and none of them fix the kind of problem an agent is
 * asked about.
 */
final class WOOBE_MCP_TOOL_MAINTENANCE extends WOOBE_MCP_TOOL {

	/**
	 * WooCommerce tool id => when it is the right answer.
	 *
	 * The reasons are written for an agent deciding which one to reach for, and
	 * they double as the explanation the user hears.
	 */
	private function catalogue() {

		return array(

			'clear_transients' => array(
				'title'   => 'Clear product and shop caches',
				'fixes'   => 'The shop displays something that does not match the data: a product type or price that stayed on the old value after an edit, an attribute or category filter listing the wrong products, a sale that is over but still shown. This is the first thing to try for any "it shows the wrong thing" report, and it is safe to run at any time.',
				'safe'    => true,
			),

			'recount_terms' => array(
				'title'   => 'Recount category and tag totals',
				'fixes'   => 'Category or tag counts disagree with what is actually in them - a category says 12 products and lists 9. Happens after products are hidden, moved or deleted in bulk. Only touches the counters, never the products.',
				'safe'    => true,
			),

			'regenerate_product_lookup_tables' => array(
				'title'   => 'Rebuild the product lookup tables',
				'fixes'   => 'Sorting by price or popularity is wrong, or the catalogue filters miss products that clearly match. Those features read summary tables rather than the products themselves, and a bulk edit can leave them behind. Runs in the background and can take a while on a large catalogue - warn the user before starting it.',
				'safe'    => true,
				'slow'    => true,
			),

			'clear_expired_transients' => array(
				'title'   => 'Delete expired cache rows',
				'fixes'   => 'Housekeeping rather than a fix: removes cache rows that have already expired but are still taking up space in the options table. Worth running on a shop whose database has grown unexpectedly large.',
				'safe'    => true,
			),

			'delete_orphaned_variations' => array(
				'title'   => 'Delete orphaned variations',
				'fixes'   => 'Variations left behind after their parent product was deleted. They are invisible in the admin but still counted, still indexed and still occasionally sold. This one deletes data permanently, so say what it does and get a clear yes before running it.',
				'safe'    => false,
			),

			'clear_expired_download_permissions' => array(
				'title'   => 'Clean up used download permissions',
				'fixes'   => 'Removes download permissions that have expired or have no downloads left. Only relevant on shops selling downloadable products. Deletes data, so confirm first.',
				'safe'    => false,
			),
		);
	}

	public function tools() {

		$ids = array_keys( $this->catalogue() );

		return array(

			'woobe_maintenance_list' => array(
				'name'        => 'woobe_maintenance_list',
				'description' => 'The maintenance actions available on this shop, each with the symptom it fixes. Read this when the user reports that the shop is showing something that does not match reality - a product type that will not change, counts that disagree, sorting that ignores a price you just set. Most of those are a stale cache rather than lost data, and one of these actions fixes them without leaving the conversation.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'woobe_maintenance_run' => array(
				'name'        => 'woobe_maintenance_run',
				'description' => 'Runs one maintenance action - the same code as the corresponding button under WooCommerce, Status, Tools. Start with clear_transients for anything that looks like the shop showing stale information; it is safe, quick and fixes most of these reports. Actions marked as not safe delete data and need an explicit yes from the user first, quoted back to him in his own words. Say what the action did afterwards, and if the symptom persists, say that too rather than running more actions hoping one sticks.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action'  => array(
							'type'        => 'string',
							'enum'        => $ids,
							'description' => 'Which action to run. woobe_maintenance_list describes what each one is for.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Required for actions that delete data. Set it only after the user has said yes to that specific action.',
						),
					),
					'required'   => array( 'action' ),
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
			case 'woobe_maintenance_list':
				return $this->list_actions();
			case 'woobe_maintenance_run':
				return $this->run_action( $args );
		}

		return new WP_Error( 'woobe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function list_actions() {

		$out = array();

		foreach ( $this->catalogue() as $id => $tool ) {
			$out[] = array(
				'action'          => $id,
				'title'           => $tool['title'],
				'fixes'           => $tool['fixes'],
				'safe_to_run'     => $tool['safe'],
				'may_take_a_while' => ! empty( $tool['slow'] ),
			);
		}

		return array(
			'actions' => $out,
			'note'    => 'These are the same actions as the buttons under WooCommerce, Status, Tools. When a user reports that the shop is showing the wrong thing, try clear_transients first and check whether the symptom is gone before doing anything else - a stale cache explains most of these reports, and the rest of the list rarely applies.',
		);
	}

	private function run_action( $args ) {

		$action  = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : '';
		$catalog = $this->catalogue();

		if ( ! isset( $catalog[ $action ] ) ) {
			return new WP_Error(
				'woobe_mcp_unknown_action',
				'Unknown maintenance action: ' . $action . '. Call woobe_maintenance_list for what is available. Actions that reset roles, delete tax rates or clear customer sessions are deliberately not exposed here - those belong in the admin, where the person doing them can see what they affect.'
			);
		}

		if ( empty( $catalog[ $action ]['safe'] ) && empty( $args['confirm'] ) ) {
			return new WP_Error(
				'woobe_mcp_needs_confirm',
				$catalog[ $action ]['title'] . ' deletes data permanently and cannot be undone from history. ' . $catalog[ $action ]['fixes']
				. ' Explain that to the user, get a clear yes, and call again with confirm true.'
			);
		}

		// WooCommerce's own controller: these routines have grown across
		// releases and reimplementing them here would mean falling behind
		if ( ! class_exists( 'WC_REST_System_Status_Tools_V2_Controller' ) ) {
			return new WP_Error(
				'woobe_mcp_no_wc_tools',
				'The WooCommerce system status tools are not available on this installation.'
			);
		}

		$controller = new WC_REST_System_Status_Tools_V2_Controller();

		if ( ! method_exists( $controller, 'execute_tool' ) ) {
			return new WP_Error(
				'woobe_mcp_no_execute_tool',
				'This WooCommerce version does not expose execute_tool(), so the action cannot be run from here. Use WooCommerce, Status, Tools in the admin instead.'
			);
		}

		$started = microtime( true );
		$result  = $controller->execute_tool( $action );
		$seconds = round( microtime( true ) - $started, 2 );

		$ran     = is_array( $result ) && ! empty( $result['success'] );
		$message = ( is_array( $result ) && ! empty( $result['message'] ) ) ? $result['message'] : '';

		return array(
			'action'   => $action,
			'title'    => $catalog[ $action ]['title'],
			'ran'      => $ran,
			'message'  => $message,
			'seconds'  => $seconds,
			'note'     => $ran
				? 'Tell the user what was done in one sentence and ask him to reload the screen where he saw the problem. If it is still there, the cause is not a cache and running more of these will not help - look at the data itself instead.'
				: 'The action reported no success. Say so plainly rather than assuming it worked.',
		);
	}
}