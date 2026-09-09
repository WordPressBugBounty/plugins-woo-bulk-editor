<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Base for a ready made case.
 *
 * A case is a question a shop owner actually asks, with the tool calls that
 * answer it already written down. It exists because the tools are general and
 * most people do not know what to ask for: "what can you do" deserves a better
 * answer than a list of twenty function names.
 *
 * One file, one case. The file name is both the class name and the case id, so
 * ext/mcp/cases/stock_health.php holds WOOBE_MCP_CASE_STOCK_HEALTH and answers
 * to the id stock_health. Nothing registers it - the loader scans the folder.
 * Deleting a case is deleting a file, and reading one means opening one file
 * rather than scrolling a list of eight.
 *
 * A case is not a new capability. Everything it does can be asked for in plain
 * words, and the agent is told to say so - the list is a starting point, not a
 * menu the shop is limited to.
 *
 * steps() names the tools to call. A later step can use an earlier one's output
 * by writing @step_key.field as an argument value, which is what makes "find
 * these products, then report on them" a single call rather than a
 * conversation.
 *
 * Cases are READ ONLY by construction: the runner refuses any tool that writes,
 * whatever a case file asks for. A prepared recipe that edits a live shop
 * without the owner reading a preview first would undo the whole safety model.
 */
abstract class WOOBE_MCP_CASE {

	/**
	 * @var WOOBE_MCP
	 */
	protected $mcp;

	public function __construct( $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * A few words, shown in the list.
	 */
	abstract public function title();

	/**
	 * The owner's own question, in his words rather than yours.
	 */
	abstract public function question();

	/**
	 * One line on what he will be able to see or decide.
	 */
	abstract public function answers();

	/**
	 * The tool calls, in order. Each: key, tool, arguments.
	 */
	abstract public function steps();

	/**
	 * Hint for the client: table, chart_bar, chart_line, cards.
	 */
	public function render() {
		return 'table';
	}

	/**
	 * Whether this case makes sense on this shop. A case that needs data the
	 * shop does not keep should hide rather than answer with zeros.
	 */
	public function is_available() {
		return true;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * The whole catalogue including variations, as a first step.
	 *
	 * Half the cases start here, and a variable parent holds no price and sells
	 * no units - so a report built without its variations is quietly missing
	 * most of the shop.
	 */
	protected function catalogue_step( $key = 'catalogue' ) {

		return array(
			'key'       => $key,
			'tool'      => 'woobe_find_products',
			'arguments' => array(
				'filter'             => array(),
				'include_variations' => 'all',
				'sample'             => 0,
			),
		);
	}
	
	/**
	 * What this case is about, in a word or two: stock, sales, refunds,
	 * pricing, promo, checkout.
	 *
	 * Lets the agent offer what fits the conversation instead of reciting the
	 * whole list. Someone asking about the warehouse should hear the two stock
	 * cases, not all eleven.
	 */
	public function tags() {
		return array();
	}

	/**
	 * Optional. Reshapes the collected results before they are returned.
	 *
	 * This is what keeps a case from having to change a tool. A tool exists to
	 * fetch data the shop holds; anything that is arithmetic on data already
	 * fetched belongs here - a rate rescaled to weeks, a ratio between two
	 * steps, a subtotal, a sorted subset. So a new case that needs a new angle
	 * on existing numbers is still just one file dropped into this folder.
	 *
	 * A tool only has to change when the answer needs something the database
	 * was never asked for.
	 *
	 * @param array $results step key => whatever that step returned
	 * @return array
	 */
	public function derive( $results ) {
		return $results;
	}
}
