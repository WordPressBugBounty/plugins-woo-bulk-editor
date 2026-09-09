<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Bootstrap for the MCP extension.
 *
 * Everything else about MCP lives in mcp.php as an ordinary WOOBE extension.
 * This file exists for one reason only, and it is a WordPress ordering problem
 * rather than a design choice:
 *
 *   WOOBE::init() is hooked on 'init'. REST routes have to be registered on
 *   'rest_api_init', which fires later. Worse, on a REST request the constant
 *   REST_REQUEST is defined during parse_request - that is, AFTER 'init' - so
 *   at the moment WOOBE::init() runs it sees neither is_admin() nor REST and
 *   returns immediately. An extension constructed inside init() can therefore
 *   never register a REST route on a REST request: by then nothing has been
 *   constructed at all.
 *
 * So this file is required from the plugin root, hooks 'rest_api_init' itself,
 * and inside that hook brings WOOBE up and hands the request to the extension.
 * Roughly twenty lines of plumbing; no plugin logic lives here.
 */
final class WOOBE_MCP_BOOT {

	const NS = 'woobe/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {

		register_rest_route(
			self::NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				// some clients probe the endpoint with GET before opening a session
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
		
		
		// Download link for a generated export. Its own token authenticates it,
		// because a browser click cannot send a header - and the shop's key was
		// deliberately kept out of URLs. The token maps to one file and expires
		// in fifteen minutes.
		register_rest_route(
			self::NS,
			'/mcp/export/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_export' ),
				'permission_callback' => '__return_true',
			)
		);
	}
	
	/**
	 * 403 rather than 401, deliberately.
	 *
	 * 401 is the literally correct code for a missing credential, but in MCP it
	 * is also the signal that starts OAuth discovery: the client fetches the
	 * resource metadata document, looks for an authorization server, finds
	 * neither - because this server has none, it authenticates with a static
	 * key - and reports that the server is broken. The connector UI does the
	 * same thing visually, offering an OAuth section instead of the header
	 * field the user actually needs.
	 *
	 * The spec's WWW-Authenticate requirement applies to servers that use
	 * OAuth. Sending that header without an authorization server behind it
	 * would make the confusion worse, not better. 403 says plainly "your
	 * credential is not accepted" and starts no discovery.
	 */
	public static function permission( $request ) {
		
		// The connector setup dialog probes the endpoint before the user has
		// entered anything, and a refusal at that point is drawn as a broken
		// server. A handshake carries no shop data - the server name and
		// protocol version, nothing else - so it answers unauthenticated and
		// the key is demanded the moment real work is requested.
		$body   = json_decode( $request->get_body(), true );
		$method = ( is_array( $body ) && isset( $body['method'] ) ) ? (string) $body['method'] : '';

		if ( in_array( $method, array( 'initialize', 'ping' ), true ) ) {
			return true;
		}

		$expected = trim( (string) self::option( 'mcp_key' ) );

		if ( '' === $expected ) {
			return new WP_Error(
				'woobe_mcp_no_key',
				'No MCP key is configured on this shop. Open the plugin settings once to have one issued.',
				array( 'status' => 403 )
			);
		}

		$given = self::key_from_request( $request );

		// hash_equals rather than == : a plain comparison leaks the key one
		// character at a time to anyone willing to measure the response
		if ( '' !== $given && hash_equals( $expected, $given ) ) {
			return true;
		}

		return new WP_Error(
			'woobe_mcp_bad_key',
			'Wrong or missing MCP key. Send it as "Authorization: Bearer <key>" or in the X-WOOBE-KEY header.',
			array( 'status' => 403 )
		);
	}

	/**
	 * The key the caller presented.
	 *
	 * Headers only. A secret in a URL is written down everywhere the URL goes -
	 * the web server's access log, the browser history, a Referer header on the
	 * way out, and every proxy and CDN in between. A header appears in none of
	 * them, and every client worth connecting can send one.
	 */
	private static function key_from_request( $request ) {

		// Authorization: Bearer <key> - the standard spelling, and the one most
		// connector interfaces offer in their header list
		$auth = (string) $request->get_header( 'authorization' );

		if ( '' === $auth ) {
			// some Apache setups drop the header before PHP sees it and leave a
			// rewritten copy behind instead
			$auth = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '';
		}

		if ( '' !== $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		return trim( (string) $request->get_header( 'x_woobe_key' ) );
	}

	/**
	 * Brings the plugin up inside the REST request and returns the extension
	 * instance, or a WP_Error explaining exactly what is missing. The error text
	 * is deliberately specific: this is the one place where a wrong integration
	 * shows up, and "something went wrong" would cost an hour of guessing.
	 */
	private static function ext() {
		
		if ( ! defined( 'WOOBE_MCP_REQUEST' ) ) {
			define( 'WOOBE_MCP_REQUEST', true );
		}

		global $WOOBE;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woobe_mcp_no_woo', 'WooCommerce is not active on this site.' );
		}

		if ( ! isset( $WOOBE ) || ! is_object( $WOOBE ) ) {
			return new WP_Error( 'woobe_mcp_no_plugin', 'WOOBE is not loaded.' );
		}

		// Without a logged in user WOOBE_SETTINGS resolves an empty role, and the
		// per field permission check then reads the woobe_shop_manager_visibility
		// option - which is not an array until somebody saves the settings screen.
		// The result is a silent 'forbidden' from every write. Added per request.
		add_filter(
			'woobe_permit_special_roles',
			function ( $roles ) {
				$roles[] = '';
				return $roles;
			}
		);

		add_filter( 'woobe_user_can_edit', '__return_true', 99 );

				if ( is_null( $WOOBE->products ) ) {
			$WOOBE->init();
		}

		self::strip_price_filters();

		if ( is_null( $WOOBE->products ) ) {
			return new WP_Error(
				'woobe_mcp_not_inited',
				'WOOBE did not initialise for this request. Check is_should_init() in index.php: it must return true when REQUEST_URI contains woobe/v1/.'
			);
		}

		if ( ! isset( $WOOBE->mcp ) || is_null( $WOOBE->mcp ) ) {
			return new WP_Error(
				'woobe_mcp_ext_missing',
				'The mcp extension was not loaded. Add "mcp" to the $ext array in index.php and declare a public $mcp property on the WOOBE class.'
			);
		}

		return $WOOBE->mcp;
	}

	public static function handle( $request ) {

		$ext = self::ext();

		if ( is_wp_error( $ext ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32603,
						'message' => $ext->get_error_message(),
					),
				),
				500
			);
		}

		return $ext->handle( $request );
	}

	public static function handle_get() {

		return new WP_REST_Response(
			array(
				'server'   => 'WOOBE bulk editor MCP',
				'version'  => defined( 'WOOBE_VERSION' ) ? WOOBE_VERSION : '',
				'protocol' => WOOBE_MCP::PROTOCOLS[0],
				'note'     => 'This endpoint speaks JSON-RPC 2.0 over POST.',
			),
			200
		);
	}
	
		/**
	 * Removes every callback attached to WooCommerce price and currency hooks.
	 *
	 * A bulk editor must see the raw shop value. A multi currency plugin that
	 * converts on read poisons three things at once, and silently, because the
	 * converted number still looks like a price: the table shows it, the bulk
	 * engine calculates the next value from it, and the history stores it as
	 * prev_val - so a later rollback writes the converted number back and the
	 * error compounds instead of undoing.
	 *
	 * Removing all callbacks rather than naming one plugin is deliberate. The
	 * currency switcher we know about is only the one we happen to know about,
	 * and the failure mode is identical for any other. Nothing in WooCommerce
	 * core hooks these by default, and this runs on the MCP request only - the
	 * admin screens and the shop front are untouched.
	 */
	private static function strip_price_filters() {

		$hooks = apply_filters(
			'woobe_mcp_strip_price_filters',
			array(
				'woocommerce_product_get_price',
				'woocommerce_product_get_regular_price',
				'woocommerce_product_get_sale_price',
				'woocommerce_product_variation_get_price',
				'woocommerce_product_variation_get_regular_price',
				'woocommerce_product_variation_get_sale_price',
				'woocommerce_get_variation_price',
				'woocommerce_get_variation_regular_price',
				'woocommerce_get_variation_sale_price',
				'woocommerce_variation_prices',
				'woocommerce_variation_prices_price',
				'woocommerce_variation_prices_regular_price',
				'woocommerce_variation_prices_sale_price',
				'woocommerce_get_variation_prices_hash',
				'raw_woocommerce_price',
				'formatted_woocommerce_price',
				'woocommerce_currency',
				'woocommerce_product_get_tax_class',
			)
		);

		foreach ( $hooks as $hook ) {
			remove_all_filters( $hook );
		}

		// some switchers also keep a per request "current currency" of their own,
		// and reading it back to the shop default costs nothing when it exists
		if ( class_exists( 'WOOCS' ) ) {
			global $WOOCS;
			if ( is_object( $WOOCS ) && method_exists( $WOOCS, 'reset_currency' ) ) {
				$WOOCS->reset_currency();
			}
		}
	}
	
	/**
	 * Shop wide plugin options, read straight from the database.
	 *
	 * The permission callback runs before the plugin is booted - extensions are
	 * not included yet, WOOBE_SETTINGS does not exist, and nothing that walks
	 * the settings objects is available. Anything the gate needs must therefore
	 * come from here, not from the settings model. Any future shop wide option
	 * the endpoint has to check goes through this one function.
	 */
	public static function options() {

		static $cache = null;

		if ( is_null( $cache ) ) {
			$cache = get_option( 'woobe_options_global' );
			$cache = is_array( $cache ) ? $cache : array();
		}

		return $cache;
	}

	public static function option( $key, $default = '' ) {

		$options = self::options();

		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}
	
	/**
	 * Streams one generated export file.
	 *
	 * The token is the credential here, so there is no key check: it is single
	 * purpose, expires on its own, and names exactly one file. The file name is
	 * taken from the transient rather than from the URL, so nothing the caller
	 * sends can point at another path.
	 */
	public static function serve_export( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$name  = get_transient( 'woobe_mcp_export_' . $token );

		if ( ! $name ) {
			return new WP_Error( 'woobe_mcp_export_expired', 'This download link has expired. Ask for the export again.', array( 'status' => 404 ) );
		}

		global $WOOBE;

		if ( ! isset( $WOOBE->export ) || is_null( $WOOBE->export ) ) {
			$ext = self::ext();

			if ( is_wp_error( $ext ) ) {
				return $ext;
			}
		}

		$folder = $WOOBE->export->get_export_folder();
		$file   = $folder . basename( $name );

		if ( empty( $folder ) || ! file_exists( $file ) ) {
			return new WP_Error( 'woobe_mcp_export_gone', 'That export file is no longer on the server.', array( 'status' => 404 ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $name ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		exit;
	}
}

WOOBE_MCP_BOOT::init();
