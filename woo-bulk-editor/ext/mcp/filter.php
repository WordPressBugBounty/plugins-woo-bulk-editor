<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The check a woobe_find_products filter passes before WOOBE's filter engine
 * runs it.
 *
 * The engine was written for the filter form of the editor screen, which can
 * only send what its own inputs hold. It reads the keys it knows, in the shape
 * that form sends, and ignores everything else without a word. Through MCP
 * anything can arrive: a misspelt field, a date as {from, to} where the form
 * sends two flat fields, a select as an object, a range with one end, a zero
 * as a JSON number. The engine dropped such a condition, the query ran without
 * it, and the whole catalogue came back as if it were the narrowed selection
 * the agent asked for - one bulk edit away from rewriting every product.
 *
 * So every key is checked here against what the engine will really apply:
 * a key it does not apply, or a value in a shape it cannot read, is refused
 * with the key named and the accepted shape shown; what it can apply is
 * handed over in exactly the form the editor screen would send it. Nothing is
 * dropped. This check changes nothing in the engine: the editor screen's own
 * filters do not pass through it.
 *
 * The ranges keep the engine's meaning: from is included and to is not
 * (regular_price, sale_price, stock_quantity, weight, length, width,
 * height), both ends are included for the other number fields, which the
 * engine compares with BETWEEN. A missing end is open. from equal to to means
 * exactly that value.
 */
final class WOOBE_MCP_FILTER {

	// A missing end of a range: the engine has no open end of its own for
	// most ranges, regular_price_where() already uses this number for one.
	const OPEN_HIGH = '999999999';
	const OPEN_LOW  = '-999999999';

	// the smallest step the engine's range queries tell apart (%f)
	const STEP = 0.000001;

	const TEXT_BEHAVIORS = array( 'like', 'exact', 'begin', 'end', 'not', 'empty' );
	const URL_BEHAVIORS  = array( 'like', 'exact', 'begin', 'end', 'not' );

	// custom meta text fields go through a meta query, which knows LIKE, =,
	// != and NOT LIKE - the editor screen sends those, an agent the words the
	// tool description names
	const META_BEHAVIORS = array(
		'like'      => 'LIKE',
		'exact'     => '=',
		'not'       => 'NOT LIKE',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
		'LIKE'      => 'LIKE',
		'='         => '=',
		'!='        => '!=',
		'NOT LIKE'  => 'NOT LIKE',
	);

	// catalog_visibility as woobe_list_fields names it, in the values the
	// engine's product_visibility filter takes
	const VISIBILITY = array(
		'visible' => 'visible',
		'catalog' => 'shop_only',
		'search'  => 'search_only',
		'hidden'  => 'hidden',
	);

	/**
	 * @var array WOOBE's field definitions, key => definition
	 */
	private $fields;

	/**
	 * @var array the filter as the call gave it
	 */
	private $filter;

	/**
	 * Checks a filter and turns it into the engine's form.
	 *
	 * @param array $filter the filter as the call gave it.
	 * @param array $fields WOOBE's field definitions - the list the engine reads.
	 * @return array|WP_Error the filter to run, or every problem found in it.
	 */
	public static function check( $filter, $fields ) {

		$check = new self( $filter, $fields );

		return $check->run();
	}

	private function __construct( $filter, $fields ) {
		$this->filter = (array) $filter;
		$this->fields = (array) $fields;
	}

	private function run() {

		$out      = array();
		$problems = array();

		foreach ( $this->filter as $key => $value ) {

			$key    = (string) $key;
			$result = $this->key( $key, $value );

			if ( is_string( $result ) ) {
				$problems[] = $key . ': ' . $result;
				continue;
			}

			// a key can come out under the engine's own name, catalog_visibility
			// as product_visibility for one
			foreach ( $result as $engine_key => $engine_value ) {
				$out[ $engine_key ] = $engine_value;
			}
		}

		if ( ! empty( $problems ) ) {
			return new WP_Error(
				'woobe_mcp_bad_filter',
				'The filter was not run, nothing was selected. ' . implode( ' ', $problems ) . ' Field keys come from woobe_list_fields; the description of woobe_find_products shows every shape.'
			);
		}

		return $out;
	}

	/**
	 * One key of the filter.
	 *
	 * @return array|string array( engine key => engine value ), or the problem.
	 */
	private function key( $key, $value ) {

		switch ( $key ) {
			case 'taxonomies':
				return $this->taxonomies( $value );
			case 'taxonomies_operators':
				return $this->operators( $value );
			case 'post__in':
				return $this->post_in( $value );
			case 'post_date_from':
			case 'post_date_to':
				return $this->post_date( $key, $value );
			case 'menu_order_from':
			case 'menu_order_to':
				return $this->menu_order( $key, $value );
			case 'product_visibility':
				return $this->select( $key, $value, array_values( self::VISIBILITY ) );
			case '_tax_class':
				return $this->select( $key, $value, $this->options( 'tax_class' ) );
		}

		if ( isset( $this->fields[ $key ] ) ) {
			return $this->field( $key, $value, $this->fields[ $key ] );
		}

		// the date range of a custom calendar field comes as two flat keys,
		// its meta key with _from and _to
		if ( preg_match( '/^(.+)_(from|to)$/', $key, $m ) && isset( $this->fields[ $m[1] ] ) ) {

			if ( $this->is_meta_calendar( $m[1] ) ) {
				return $this->date( $key, $value );
			}

			return 'no such filter key - filter on ' . $m[1] . ' itself, in the shape the description of woobe_find_products gives.';
		}

		return 'no such filter key. The filter takes the field keys of woobe_list_fields, and besides them taxonomies, taxonomies_operators, post__in, post_date_from, post_date_to, menu_order_from and menu_order_to.';
	}

	private function field( $key, $value, $f ) {

		$type = isset( $f['field_type'] ) ? $f['field_type'] : '';
		$view = isset( $f['edit_view'] ) ? $f['edit_view'] : '';

		switch ( $key ) {
			case 'post_title':
			case 'post_content':
			case 'post_excerpt':
			case 'post_name':
			case 'sku':
				return $this->text( $key, $value, self::TEXT_BEHAVIORS );

			case 'product_url':
				return $this->text( $key, $value, self::URL_BEHAVIORS );

			case 'regular_price':
			case 'sale_price':
			case 'stock_quantity':
			case 'weight':
			case 'length':
			case 'width':
			case 'height':
				return $this->range( $key, $value, false );

			case 'post_status':
			case 'stock_status':
			case 'backorders':
				return $this->select( $key, $value, $this->options( $key ) );

			case 'product_type':
				return $this->product_type( $value );

			case 'downloadable':
			case 'manage_stock':
			case 'sold_individually':
				return $this->yes_no( $key, $value, 'yes', 'no' );

			case 'featured':
				// the engine's featured filter reads 1 for featured and 2 for
				// not featured, the values of the editor screen's select
				return $this->yes_no( $key, $value, 1, 2 );

			case 'catalog_visibility':
				$checked = $this->select( $key, $value, array_keys( self::VISIBILITY ) );
				return is_string( $checked ) ? $checked : array( 'product_visibility' => self::VISIBILITY[ $checked[ $key ] ] );

			case 'tax_class':
				$checked = $this->select( $key, $value, $this->options( $key ) );
				return is_string( $checked ) ? $checked : array( '_tax_class' => $checked[ $key ] );

			case 'post_author':
				return $this->author( $value );

			case '_thumbnail_id':
				return $this->select( $key, $value, array( 'empty', 'not_empty' ), ' - whether the product has an image' );

			case 'date_on_sale_from':
			case 'date_on_sale_to':
				return $this->date( $key, $value );

			case 'post_date':
				return 'the product date filters as two keys, post_date_from and post_date_to: {"post_date_from":"2026-01-01","post_date_to":"2026-01-31"}.';

			case 'menu_order':
				return 'the menu order filters as two keys, menu_order_from and menu_order_to (both included): {"menu_order_from":1,"menu_order_to":5}.';

			case 'ID':
				return 'ids filter through post__in: {"post__in":{"value":"12,15,20-30"}}.';
		}

		if ( in_array( $type, array( 'taxonomy', 'attribute' ), true ) ) {
			return 'a taxonomy goes under taxonomies, as term ids from woobe_list_terms: {"taxonomies":{"' . $key . '":[12]}}.';
		}

		if ( 'meta' === $type ) {

			if ( 'gallery_popup_editor' === $view ) {
				return 'this field cannot be filtered on.';
			}

			if ( 'calendar' === $view ) {
				return 'a calendar field filters as two keys, ' . $key . '_from and ' . $key . '_to: {"' . $key . '_from":"2026-01-01"}.';
			}

			if ( 'switcher' === $view ) {
				return $this->yes_no( $key, $value, '1', 'zero' );
			}
		}

		// what the engine's generic meta filters take: a number field as a
		// range compared with BETWEEN, a field with options as one of them,
		// any other text as a meta query
		if ( ! empty( $f['meta_key'] ) && isset( $f['type'] ) ) {

			if ( 'number' === $f['type'] ) {
				return $this->range( $key, $value, true );
			}

			if ( 'string' === $f['type'] ) {
				return empty( $f['select_options'] ) ? $this->meta_text( $key, $value ) : $this->select( $key, $value, $this->options( $key ) );
			}
		}

		return 'this field cannot be filtered on.';
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the shapes

	/**
	 * A text field: {value, behavior}. The behavior defaults to like; value
	 * may be left out for behavior empty only.
	 */
	private function text( $key, $value, $behaviors ) {

		$shape = '{"' . $key . '":{"value":"tweed","behavior":"like"}}, behavior ' . $this->words( $behaviors, 'or' );

		$parts = $this->value_behavior( $value, $shape );

		if ( is_string( $parts ) ) {
			return $parts;
		}

		list( $text, $behavior ) = $parts;

		$behavior = strtolower( '' === $behavior ? 'like' : $behavior );

		if ( ! in_array( $behavior, $behaviors, true ) ) {
			return 'behavior "' . $parts[1] . '" is not one this field takes. Use ' . $shape . '.';
		}

		if ( 'empty' === $behavior ) {
			return array(
				$key => array(
					'value'    => '',
					'behavior' => 'empty',
				),
			);
		}

		if ( '' === trim( $text ) ) {
			return 'value is empty - give the text to look for. Use ' . $shape . '.';
		}

		return array(
			$key => array(
				'value'    => $text,
				'behavior' => $behavior,
			),
		);
	}

	/**
	 * A custom meta text field. A meta query cannot say "begins with", so
	 * begin and end are refused rather than run as an exact match.
	 */
	private function meta_text( $key, $value ) {

		$shape = '{"' . $key . '":{"value":"abc","behavior":"like"}}, behavior like, exact, not, empty or not_empty (begin and end work on the product\'s own text fields only)';

		$parts = $this->value_behavior( $value, $shape );

		if ( is_string( $parts ) ) {
			return $parts;
		}

		list( $text, $behavior ) = $parts;

		$given   = '' === $behavior ? 'like' : $behavior;
		$compare = null;

		// the words in lower case, the editor screen's operators as it sends them
		foreach ( array( $given, strtolower( $given ), strtoupper( $given ) ) as $try ) {
			if ( array_key_exists( $try, self::META_BEHAVIORS ) ) {
				$compare = self::META_BEHAVIORS[ $try ];
				break;
			}
		}

		if ( null === $compare ) {
			return 'behavior "' . $given . '" is not one this field takes. Use ' . $shape . '.';
		}

		if ( in_array( $compare, array( 'empty', 'not_empty' ), true ) ) {
			return array(
				$key => array(
					'value'    => '',
					'behavior' => $compare,
				),
			);
		}

		if ( '' === trim( $text ) ) {
			return 'value is empty - give the text to look for. Use ' . $shape . '.';
		}

		return array(
			$key => array(
				'value'    => $text,
				'behavior' => $compare,
			),
		);
	}

	/**
	 * The value and behavior of a text filter, or the problem with its shape.
	 *
	 * @return array|string array( value, behavior ) or the problem.
	 */
	private function value_behavior( $value, $shape ) {

		if ( ! $this->is_object( $value ) ) {
			return 'a text field takes an object, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'value', 'behavior' ) );

		if ( ! empty( $extra ) ) {
			return 'unknown key ' . implode( ', ', $extra ) . ' in it. Use ' . $shape . '.';
		}

		$text     = isset( $value['value'] ) ? $value['value'] : '';
		$behavior = isset( $value['behavior'] ) ? $value['behavior'] : '';

		if ( ! is_scalar( $text ) || is_bool( $text ) || ! is_string( $behavior ) ) {
			return 'value must be text and behavior a word. Use ' . $shape . '.';
		}

		return array( (string) $text, trim( $behavior ) );
	}

	/**
	 * A number range, {from, to}. $inclusive: the field compares with BETWEEN
	 * and includes to (custom meta numbers, total_sales, review_count,
	 * average_rating); otherwise to is excluded.
	 *
	 * The values go to the engine as text, the way the editor screen sends
	 * them: a JSON 0 is empty() in PHP and the engine skipped such a range as
	 * not given. A missing end becomes an open one.
	 */
	private function range( $key, $value, $inclusive ) {

		$whole = 'stock_quantity' === $key;
		$shape = '{"' . $key . '":{"from":' . ( $whole ? '1' : '10' ) . ',"to":' . ( $whole ? '5' : '20' ) . '}} - ' . ( $inclusive ? 'both ends included' : 'from included, to excluded' ) . ', either end may be left out' . ( $whole ? ', whole numbers' : '' );

		if ( ! $this->is_object( $value ) ) {
			return 'a range takes an object, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'from', 'to' ) );

		if ( ! empty( $extra ) ) {
			return 'unknown key ' . implode( ', ', $extra ) . ' in it. Use ' . $shape . '.';
		}

		$ends = array();

		foreach ( array( 'from', 'to' ) as $end ) {

			$v = isset( $value[ $end ] ) ? $value[ $end ] : null;

			if ( is_string( $v ) ) {
				$v = trim( $v );
			}

			if ( null === $v || '' === $v ) {
				$ends[ $end ] = null;
				continue;
			}

			if ( is_bool( $v ) || ! is_numeric( $v ) ) {
				return $end . ' must be a number. Use ' . $shape . '.';
			}

			if ( $whole && floor( (float) $v ) !== (float) $v ) {
				return $end . ' must be a whole number. Use ' . $shape . '.';
			}

			$ends[ $end ] = (float) $v;
		}

		list( $from, $to ) = array( $ends['from'], $ends['to'] );

		if ( null === $from && null === $to ) {
			return 'give from, to or both. Use ' . $shape . '.';
		}

		if ( null !== $from && null !== $to && $from > $to ) {
			return 'from is above to. Use ' . $shape . '.';
		}

		$text = function ( $n ) {
			return rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
		};

		if ( null !== $from && null !== $to && $from === $to ) {

			// exactly that value. The regular price and a dimension of 0 are
			// no exact match in the engine - the first reads from = to as an
			// empty range, the second skips a 0 - so they get the smallest
			// range around the value the engine tells apart
			if ( 'regular_price' === $key || ( 0.0 === $from && in_array( $key, array( 'weight', 'length', 'width', 'height' ), true ) ) ) {
				$to = $from + self::STEP;
			}
		}

		// regular_price_where() reads a to of 0 or less as "no upper limit"
		if ( 'regular_price' === $key && null !== $to && $to <= 0 ) {
			return 'a regular price range has to end above 0. Use ' . $shape . '.';
		}

		return array(
			$key => array(
				'from' => null === $from ? self::OPEN_LOW : $text( $from ),
				'to'   => null === $to ? self::OPEN_HIGH : $text( $to ),
			),
		);
	}

	/**
	 * One of the field's options, as a plain value.
	 */
	private function select( $key, $value, $options, $what = '' ) {

		$shape = '{"' . $key . '":"' . ( isset( $options[0] ) && '' !== $options[0] ? $options[0] : ( isset( $options[1] ) ? $options[1] : '' ) ) . '"}, one of: ' . $this->words( array_map( array( $this, 'option_words' ), $options ), 'or' ) . $what;

		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return 'takes one of its options as a plain value, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		if ( ! in_array( (string) $value, array_map( 'strval', $options ), true ) ) {
			return '"' . $value . '" is not one of its options. Use ' . $shape . '.';
		}

		return array( $key => (string) $value );
	}

	/**
	 * A yes / no field, as the engine wants it. true and false are read too.
	 */
	private function yes_no( $key, $value, $yes, $no ) {

		$shape = '{"' . $key . '":"yes"} or "no"';

		if ( true === $value || 'yes' === $value || ( is_string( $yes ) && $yes === (string) $value ) || 1 === $value ) {
			return array( $key => $yes );
		}

		if ( false === $value || 'no' === $value || '0' === $value || 0 === $value ) {
			return array( $key => $no );
		}

		return 'takes yes or no, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '. Use ' . $shape . '.';
	}

	/**
	 * product_type: one type, or a list of them.
	 */
	private function product_type( $value ) {

		$options = $this->options( 'product_type' );
		$shape   = '{"product_type":"variable"} or a list, {"product_type":["simple","variable"]}, of: ' . $this->words( $options, 'and' );
		$given   = is_array( $value ) && wp_is_numeric_array( $value ) ? $value : array( $value );

		if ( empty( $given ) ) {
			return 'no product type given. Use ' . $shape . '.';
		}

		foreach ( $given as $one ) {

			if ( ! is_string( $one ) ) {
				return 'takes a type or a list of types, not ' . $this->shape_words( $one ) . '. Use ' . $shape . '.';
			}

			if ( ! in_array( $one, $options, true ) ) {
				return '"' . $one . '" is not a product type of this shop. Use ' . $shape . '.';
			}
		}

		return array( 'product_type' => is_array( $value ) ? array_values( $given ) : $value );
	}

	private function author( $value ) {

		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && intval( $value ) > 0 ) {
			return array( 'post_author' => intval( $value ) );
		}

		return 'takes the id of a user, {"post_author":2}, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '.';
	}

	/**
	 * A date the engine reads with strtotime(): date_on_sale_from / _to and
	 * the two keys of a custom calendar field. A unix timestamp is read too.
	 */
	private function date( $key, $value ) {

		$shape = '{"' . $key . '":"2026-12-01"}';

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
			return intval( $value ) > 0 ? array( $key => '@' . intval( $value ) ) : 'a timestamp has to be above 0. Use ' . $shape . '.';
		}

		if ( ! is_string( $value ) || '' === trim( $value ) || false === strtotime( $value ) ) {
			return 'takes a date, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '. Use ' . $shape . '.';
		}

		return array( $key => trim( $value ) );
	}

	/**
	 * post_date_from / post_date_to. The engine compares them with the post
	 * date as written, so they must be written the way the database holds a
	 * date: 2026-01-31, or 2026-01-31 18:00.
	 */
	private function post_date( $key, $value ) {

		if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', trim( $value ) ) ) {
			return array( $key => trim( $value ) );
		}

		return 'takes a date written as 2026-01-31 (or 2026-01-31 18:00), not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . ': {"' . $key . '":"2026-01-31"}.';
	}

	/**
	 * menu_order_from / menu_order_to, both included. The engine skips a
	 * bound PHP reads as false, and 0 is one: it goes in as "-0", which reads
	 * as 0 and is not false.
	 */
	private function menu_order( $key, $value ) {

		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) ) {
			$n = intval( $value );
			return array( $key => 0 === $n ? '-0' : (string) $n );
		}

		return 'takes a whole number, {"' . $key . '":1}, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '.';
	}

	/**
	 * post__in: {value: "12,15,20-30"}, ids and ranges of ids.
	 */
	private function post_in( $value ) {

		$shape = '{"post__in":{"value":"12,15,20-30"}}';

		if ( ! $this->is_object( $value ) || ! isset( $value['value'] ) ) {
			return 'takes an object with value, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'value', 'behavior' ) );

		if ( ! empty( $extra ) || ( isset( $value['behavior'] ) && 'exact' !== $value['behavior'] ) ) {
			return 'takes value alone (the editor screen also sends behavior exact). Use ' . $shape . '.';
		}

		$ids = is_int( $value['value'] ) ? (string) $value['value'] : $value['value'];

		if ( ! is_string( $ids ) || '' === trim( $ids ) || ! preg_match( '/^[\d\s,\-]+$/', $ids ) ) {
			return 'value takes ids and ranges of ids, comma separated. Use ' . $shape . '.';
		}

		// the engine lists every id of a range one by one
		foreach ( explode( ',', $ids ) as $part ) {

			if ( substr_count( $part, '-' ) > 0 ) {

				$ends = array_map( 'intval', explode( '-', trim( $part ) ) );

				if ( count( $ends ) === 2 && abs( $ends[1] - $ends[0] ) > WOOBE_MCP::SEL_MAX ) {
					return 'the range ' . trim( $part ) . ' spans more than ' . WOOBE_MCP::SEL_MAX . ' ids. Use ' . $shape . '.';
				}
			}
		}

		return array( 'post__in' => array( 'value' => $ids ) );
	}

	/**
	 * taxonomies: taxonomy name => a list of term ids. What the terms are is
	 * checked afterwards, where names are also looked up.
	 */
	private function taxonomies( $value ) {

		$shape = '{"taxonomies":{"product_cat":[16,17]}}';

		if ( ! $this->is_object( $value ) || empty( $value ) ) {
			return 'takes an object of taxonomy names, each with a list of term ids, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		foreach ( $value as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) || ! wp_is_numeric_array( $terms ) ) {
				return $taxonomy . ' takes a list of term ids, not ' . $this->shape_words( $terms ) . '. Use ' . $shape . '.';
			}
		}

		return array( 'taxonomies' => $value );
	}

	/**
	 * taxonomies_operators: taxonomy name => IN, AND, NOT IN, EXISTS or NOT
	 * EXISTS. An operator for a taxonomy with no terms under taxonomies is
	 * applied by the engine for EXISTS and NOT EXISTS only - anything else
	 * there would be dropped.
	 */
	private function operators( $value ) {

		$shape = '{"taxonomies":{"pa_color":[28]},"taxonomies_operators":{"pa_color":"AND"}}';

		if ( ! $this->is_object( $value ) ) {
			return 'takes an object of taxonomy name to operator, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$with_terms = isset( $this->filter['taxonomies'] ) && is_array( $this->filter['taxonomies'] ) ? $this->filter['taxonomies'] : array();
		$out        = array();

		foreach ( $value as $taxonomy => $operator ) {

			$taxonomy = (string) $taxonomy;

			if ( ! is_string( $operator ) ) {
				return 'the operator for ' . $taxonomy . ' must be a word: IN, AND, NOT IN, EXISTS or NOT EXISTS.';
			}

			if ( isset( $with_terms[ $taxonomy ] ) ) {
				// checked with its terms, where OR is also read as IN
				$out[ $taxonomy ] = $operator;
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return 'no taxonomy called ' . $taxonomy . ' on this shop. woobe_taxonomies lists the real names.';
			}

			$operator = strtoupper( trim( $operator ) );

			if ( ! in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				return 'the operator ' . $operator . ' for ' . $taxonomy . ' needs its terms under taxonomies; alone, only EXISTS and NOT EXISTS apply. Use ' . $shape . '.';
			}

			$out[ $taxonomy ] = $operator;
		}

		return array( 'taxonomies_operators' => $out );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// helpers

	/**
	 * The option values of a select field, as its definition holds them.
	 */
	private function options( $key ) {

		if ( empty( $this->fields[ $key ]['select_options'] ) || ! is_array( $this->fields[ $key ]['select_options'] ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $this->fields[ $key ]['select_options'] ) );
	}

	private function is_meta_calendar( $key ) {
		return isset( $this->fields[ $key ] ) && isset( $this->fields[ $key ]['field_type'], $this->fields[ $key ]['edit_view'] ) && 'meta' === $this->fields[ $key ]['field_type'] && 'calendar' === $this->fields[ $key ]['edit_view'];
	}

	/**
	 * Whether a decoded JSON value was an object: an array with names, or an
	 * empty one (the two cannot be told apart once decoded).
	 */
	private function is_object( $value ) {
		return is_array( $value ) && ( empty( $value ) || ! wp_is_numeric_array( $value ) );
	}

	private function shape_words( $value ) {

		if ( is_array( $value ) ) {
			return ( empty( $value ) || wp_is_numeric_array( $value ) ) ? 'a list' : 'an object';
		}

		if ( is_string( $value ) ) {
			return 'the text "' . ( mb_strlen( $value ) > 40 ? mb_substr( $value, 0, 40 ) . '...' : $value ) . '"';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_null( $value ) ) {
			return 'null';
		}

		return 'the number ' . $value;
	}

	private function option_words( $option ) {
		return '' === $option ? '"" (standard)' : $option;
	}

	private function words( $items, $last ) {

		$items = array_values( $items );

		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}

		return implode( ', ', array_slice( $items, 0, -1 ) ) . ' ' . $last . ' ' . end( $items );
	}
}
