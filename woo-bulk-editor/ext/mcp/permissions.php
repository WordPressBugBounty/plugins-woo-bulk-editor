<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The permission map: what one identity may do through MCP, sector by sector.
 *
 * An owner writes it in functions.php or a small plugin, without naming tools:
 *
 *   add_filter( 'woobe_mcp_permissions', function ( $map, $user_id ) {
 *       if ( 57 === $user_id ) {
 *           $map['export']  = 'none';
 *           $map['refunds'] = 'read';
 *       }
 *       return $map;
 *   }, 10, 2 );
 *
 * Three levels: full (read and write), read (read-only tools only), none
 * (nothing). '*' is the level of every sector the map does not name, so
 * array( '*' => 'read', 'products' => 'full' ) is an allow-list: a sector
 * added in a later version stays closed until the owner opens it.
 *
 * Every tool names its sector in its definition ('sector' => 'orders') next
 * to annotations.readOnlyHint, which says whether it writes. The system sector
 * - connection, capabilities, shop description, field list, cases - cannot
 * be restricted: without it an agent could not even find out what it may do.
 *
 * The map can only narrow. For a personal key it is narrowed once more by
 * the user's own WordPress capabilities, so full never means more than the
 * user may do by hand. One instance per request, built for the acting
 * identity the first time it is asked.
 */
final class WOOBE_MCP_PERMISSIONS {

	const FULL = 'full';
	const READ = 'read';
	const NONE = 'none';

	const SYSTEM = 'system';

	/**
	 * @var int the acting identity: a real user id, or the shop key's id (-777)
	 */
	private $user_id;

	/**
	 * @var int the user behind a personal key, 0 for the shop-wide key
	 */
	private $personal;

	/**
	 * @var array|null sector => level, computed once
	 */
	private $map = null;

	/**
	 * @var string level of the '*' entry, for tools without a known sector
	 */
	private $default = self::FULL;

	/**
	 * @var array sector => capability that narrowed it, for the refusal text
	 */
	private $narrowed = array();

	/**
	 * @var array tools already reported as having no sector on this request
	 */
	private $unassigned_logged = array();

	public function __construct( $user_id, $personal = 0 ) {
		$this->user_id  = intval( $user_id );
		$this->personal = absint( $personal );
	}

	/**
	 * Every sector the map knows, with what it covers in a few words. The
	 * system sector is not in this list: it cannot be restricted.
	 *
	 * @return array sector => description
	 */
	public static function sectors() {

		return array(
			'products'    => 'finding, reading, editing, creating, generating, bulk editing, deleting and restoring products',
			'variations'  => 'the variations of variable products: adding, removing, changing their attribute axes',
			'taxonomy'    => 'categories, tags, attributes and their terms',
			'media'       => 'the media library, product images, image import and upload links',
			'coupons'     => 'coupons: listing, creating, editing, deleting, restoring',
			'orders'      => 'orders: list, single order, creating orders, status changes, order notes',
			'refunds'     => 'refunding orders and the refund reports',
			'reports'     => 'sales, customers, margin, payment and shipping breakdowns, coupon usage, stock velocity',
			'export'      => 'CSV export of products and its download link',
			'history'     => 'reading the edit history and rolling bulk edits back',
			'maintenance' => 'shop maintenance actions (caches, counters, lookup tables, cleanups)',
			'memory'      => 'the owner\'s standing instructions for assistants',
		);
	}

	/**
	 * Whether a sector key names a real sector, system included.
	 */
	public static function is_sector( $sector ) {
		return self::SYSTEM === $sector || array_key_exists( $sector, self::sectors() );
	}

	/**
	 * The WordPress capability each sector needs for a personal key, to read
	 * and to write. An empty entry means nothing beyond manage_woocommerce,
	 * which every personal key holder has already. These are the
	 * capabilities WordPress and WooCommerce themselves ask for on the
	 * screens where the same work is done by hand.
	 *
	 * @return array sector => array( read capability, write capability )
	 */
	public static function sector_capabilities() {

		return (array) apply_filters(
			'woobe_mcp_sector_capabilities',
			array(
				'products'    => array( 'edit_products', 'edit_products' ),
				'variations'  => array( 'edit_products', 'edit_products' ),
				'taxonomy'    => array( '', 'manage_product_terms' ),
				'media'       => array( 'upload_files', 'upload_files' ),
				'coupons'     => array( 'edit_shop_coupons', 'edit_shop_coupons' ),
				'orders'      => array( 'edit_shop_orders', 'edit_shop_orders' ),
				'refunds'     => array( 'edit_shop_orders', 'edit_shop_orders' ),
				'reports'     => array( 'view_woocommerce_reports', 'view_woocommerce_reports' ),
				'export'      => array( '', '' ),
				'history'     => array( '', '' ),
				'maintenance' => array( '', '' ),
				'memory'      => array( '', '' ),
			)
		);
	}

	/**
	 * A level as written by an owner, or '' when it is not one.
	 */
	private static function level_of( $value ) {

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = strtolower( trim( $value ) );

		return in_array( $value, array( self::FULL, self::READ, self::NONE ), true ) ? $value : '';
	}

	/**
	 * The effective map, sector => level, system included.
	 *
	 * @return array
	 */
	public function map() {

		if ( is_null( $this->map ) ) {
			$this->build();
		}

		return $this->map + array( self::SYSTEM => self::FULL );
	}

	/**
	 * Reads the owner's map once, keeps what is valid and says - once, in the
	 * debug log - what was ignored. A broken callback can never lock a shop
	 * out or open it wider than the default: an invalid '*' falls back to full,
	 * which is exactly the behaviour without any callback at all.
	 */
	private function build() {

		$raw      = apply_filters( 'woobe_mcp_permissions', array( '*' => self::FULL ), $this->user_id );
		$problems = array();

		if ( ! is_array( $raw ) ) {
			$problems[] = 'the woobe_mcp_permissions filter returned ' . gettype( $raw ) . ' instead of an array, so the default (everything full) applies';
			$raw        = array( '*' => self::FULL );
		}

		$this->default = self::FULL;

		if ( array_key_exists( '*', $raw ) ) {

			$level = self::level_of( $raw['*'] );

			if ( '' === $level ) {
				$problems[] = 'invalid level for "*" (' . self::show( $raw['*'] ) . '), full is used instead';
			} else {
				$this->default = $level;
			}
		}

		$map = array_fill_keys( array_keys( self::sectors() ), $this->default );

		foreach ( $raw as $key => $value ) {

			if ( '*' === $key ) {
				continue;
			}

			$sector = strtolower( trim( (string) $key ) );

			if ( self::SYSTEM === $sector ) {
				$problems[] = 'the system sector cannot be restricted, "' . $key . '" was ignored';
				continue;
			}

			if ( ! isset( $map[ $sector ] ) ) {
				$problems[] = 'unknown sector "' . $key . '" was ignored';
				continue;
			}

			$level = self::level_of( $value );

			if ( '' === $level ) {
				$problems[] = 'invalid level for "' . $key . '" (' . self::show( $value ) . ') was ignored';
				continue;
			}

			$map[ $sector ] = $level;
		}

		// a personal key never gets more than the user's own capabilities
		if ( $this->personal ) {

			$caps = self::sector_capabilities();

			foreach ( $map as $sector => $level ) {

				if ( empty( $caps[ $sector ] ) || self::NONE === $level ) {
					continue;
				}

				$read  = isset( $caps[ $sector ][0] ) ? (string) $caps[ $sector ][0] : '';
				$write = isset( $caps[ $sector ][1] ) ? (string) $caps[ $sector ][1] : '';

				if ( '' !== $read && ! user_can( $this->personal, $read ) ) {
					$map[ $sector ]             = self::NONE;
					$this->narrowed[ $sector ] = $read;
				} elseif ( self::FULL === $level && '' !== $write && ! user_can( $this->personal, $write ) ) {
					$map[ $sector ]             = self::READ;
					$this->narrowed[ $sector ] = $write;
				}
			}
		}

		$this->map = $map;

		if ( $problems ) {
			$this->log( 'map for identity ' . $this->user_id . ': ' . implode( '; ', $problems ) . '.' );
		}
	}

	private static function show( $value ) {
		return is_scalar( $value ) ? '"' . (string) $value . '"' : gettype( $value );
	}

	/**
	 * Only with WP_DEBUG on, and once per request - the map is built once.
	 */
	private function log( $message ) {

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- asked for by the owner's debug setting
			error_log( 'WOOBE MCP permissions: ' . $message );
		}
	}

	/**
	 * The level of one sector. A tool without a known sector gets the '*'
	 * level, so an allow-list keeps it closed.
	 */
	public function level( $sector ) {

		if ( self::SYSTEM === $sector ) {
			return self::FULL;
		}

		$map = $this->map();

		return isset( $map[ $sector ] ) ? $map[ $sector ] : $this->default;
	}

	/**
	 * Whether a tool definition may be called at all under the map.
	 *
	 * @param string $name tool name.
	 * @param array  $def  its definition: sector and annotations.readOnlyHint.
	 * @return true|string true, or the refusal as the agent should read it.
	 */
	public function check_tool( $name, $def ) {

		$sector = isset( $def['sector'] ) ? (string) $def['sector'] : '';
		$writes = empty( $def['annotations']['readOnlyHint'] );

		if ( ! self::is_sector( $sector ) && ! isset( $this->unassigned_logged[ $name ] ) ) {
			$this->unassigned_logged[ $name ] = true;
			$this->log( 'the tool ' . $name . ' names no known sector, so the "*" level (' . $this->default . ') applies to it.' );
		}

		$level = self::is_sector( $sector ) ? $this->level( $sector ) : $this->default;

		if ( self::FULL === $level || ( self::READ === $level && ! $writes ) ) {
			return true;
		}

		return $this->refusal( self::is_sector( $sector ) ? $sector : '*', $level, $name . ( $writes ? ' changes data and' : '' ) . ' was refused.' );
	}

	/**
	 * For a tool whose write lands in another sector's data: asks for that
	 * sector too, before anything is written.
	 *
	 * @param string $sector the other sector.
	 * @param bool   $write  true when write access is needed.
	 * @param string $what   what the call does there, e.g. "creates new terms".
	 * @return true|WP_Error
	 */
	public function require_access( $sector, $write, $what ) {

		$level = $this->level( $sector );

		if ( self::FULL === $level || ( ! $write && self::READ === $level ) ) {
			return true;
		}

		return new WP_Error(
			'woobe_mcp_access_denied',
			$this->refusal( $sector, $level, 'This call ' . $what . ', which needs ' . ( $write ? 'write' : 'read' ) . ' access to ' . $sector . ', so it was refused and nothing was changed.' )
		);
	}

	/**
	 * The refusal text: sector and level first, in the words the owner used
	 * in the map, then what was refused.
	 */
	private function refusal( $sector, $level, $tail ) {

		if ( isset( $this->narrowed[ $sector ] ) ) {
			$head = 'Your WordPress account on this shop does not have the ' . $this->narrowed[ $sector ] . ' capability, so your access to ' . $sector . ' is ' . ( self::READ === $level ? 'read only' : 'closed' ) . ' here.';
		} elseif ( self::READ === $level ) {
			$head = 'Your access to ' . $sector . ' is read only on this shop.';
		} else {
			$head = 'Your access to ' . $sector . ' is closed on this shop (none).';
		}

		return $head . ' ' . $tail . ' Tell the user plainly; only the shop owner can change this.';
	}

	/**
	 * What woobe_capabilities says about access: the effective map and a
	 * sentence the agent can pass on.
	 */
	public function describe() {

		return array(
			'permissions'      => $this->map(),
			'permissions_note' => 'Your access on this shop, sector by sector: full means read and write, read means only the tools that change nothing, none means the sector is closed. Tools you may not call are left out of every list you get. When the user asks for something outside this, say plainly which part of the shop is closed or read only for him instead of trying another way round - there is none.',
		);
	}
}
