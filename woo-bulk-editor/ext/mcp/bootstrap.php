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

	// Set by permission() once, and only, when the caller presented the
	// shop's key. The dispatcher reads it again before running anything but a
	// handshake, so a flaw in the gate cannot turn into full access by itself.
	private static $authenticated = false;

	// The confirmed assistant connection: one at a time, stored as a hash of
	// the token together with when it was confirmed and last used.
	const CONNECTION_OPTION = 'woobe_mcp_connection';

	// This long without a single call and the connection is gone. Measured from
	// the last call rather than from the start, so long work never breaks in
	// the middle - only a connection nobody is using expires.
	const CONNECTION_IDLE = 3600;

	// Four groups of eight letters and digits, upper and lower case: about 190
	// bits, far past guessing, and still something a person can paste.
	const TOKEN_PATTERN = '/^[A-Za-z0-9]{8}(-[A-Za-z0-9]{8}){3}$/';

	const NS = 'woobe/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// the confirm and disconnect buttons on the settings screen
		add_action( 'wp_ajax_woobe_mcp_confirm_connection', array( __CLASS__, 'ajax_confirm_connection' ) );
		add_action( 'wp_ajax_woobe_mcp_drop_connection', array( __CLASS__, 'ajax_drop_connection' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_connection_script' ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// two-factor connection
	//
	// The key is the first factor and lives in the assistant's settings, where
	// it can leak - a screenshot, a shared config, a stolen laptop. With this
	// mode on, the key alone does nothing. The assistant asks the server for a
	// token, the owner pastes it into the settings screen, and only that token
	// works, only for as long as it is in use. Confirming needs a logged-in
	// administrator, which whoever holds a leaked key does not have.

	/**
	 * The idle limit as people read it, taken from CONNECTION_IDLE, so every
	 * message says what the code actually does - change the constant and the
	 * texts follow. Seconds, and minutes alongside once it is a minute or more.
	 */
	public static function idle_text() {

		$seconds = intval( self::CONNECTION_IDLE );

		if ( $seconds < 60 ) {
			return $seconds . ' seconds';
		}

		return $seconds . ' seconds (' . round( $seconds / 60, 1 ) . ' minutes)';
	}

	/**
	 * Whether the shop requires a confirmed connection on top of the key.
	 */
	public static function two_factor_on() {
		return (bool) self::option( 'mcp_2fa' );
	}

	/**
	 * A fresh token. Nothing is stored: it only starts to mean something when
	 * an administrator confirms it on the settings screen.
	 */
	public static function new_token() {

		$chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$max    = strlen( $chars ) - 1;
		$groups = array();

		for ( $g = 0; $g < 4; $g++ ) {

			$group = '';

			for ( $i = 0; $i < 8; $i++ ) {
				$group .= $chars[ random_int( 0, $max ) ];
			}

			$groups[] = $group;
		}

		return implode( '-', $groups );
	}

	/**
	 * Whether this token is the confirmed, still living connection. On
	 * success the idle clock starts again.
	 *
	 * @return true|string true, or 'none', 'expired' or 'mismatch'.
	 */
	public static function check_connection( $token ) {

		$stored = get_option( self::CONNECTION_OPTION );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return 'none';
		}

		$now = time();

		if ( $now - intval( $stored['last'] ) > self::CONNECTION_IDLE ) {
			delete_option( self::CONNECTION_OPTION );
			return 'expired';
		}

		$token = trim( (string) $token );

		if ( '' === $token || ! hash_equals( (string) $stored['hash'], hash( 'sha256', $token ) ) ) {
			return 'mismatch';
		}

		// written at most once a minute: the clock only needs to know the
		// connection is alive, not count every call
		if ( $now - intval( $stored['last'] ) > 60 ) {
			$stored['last'] = $now;
			update_option( self::CONNECTION_OPTION, $stored, false );
		}

		return true;
	}

	/**
	 * Ends the connection. The next call with the old token is refused.
	 */
	public static function drop_connection() {
		delete_option( self::CONNECTION_OPTION );
	}

	/**
	 * Confirm button on the settings screen.
	 */
	public static function ajax_confirm_connection() {

		check_ajax_referer( 'woobe_mcp_connection', 'nonce' );

		// administrators only: whoever can confirm a connection hands out
		// the whole shop to an assistant
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can confirm a connection.', 'woo-bulk-editor' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That is not a connection token. Copy the whole token the assistant gave you - four groups of eight characters separated by dashes.', 'woo-bulk-editor' ) ) );
		}

		$now = time();

		// one connection at a time: confirming a new one ends the old, so two
		// assistants never work on the same shop at once
		update_option(
			self::CONNECTION_OPTION,
			array(
				'hash'      => hash( 'sha256', $token ),
				'confirmed' => $now,
				'last'      => $now,
			),
			false
		);

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Connected. Go back to the assistant and tell it you have confirmed.', 'woo-bulk-editor' ),
				'connected' => true,
				'state'     => self::connection_state_text(),
			)
		);
	}

	/**
	 * Disconnect button on the settings screen.
	 */
	public static function ajax_drop_connection() {

		check_ajax_referer( 'woobe_mcp_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can end a connection.', 'woo-bulk-editor' ) ), 403 );
		}

		self::drop_connection();

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'woo-bulk-editor' ),
				'connected' => false,
				'state'     => self::connection_state_text(),
			)
		);
	}

	/**
	 * The script behind the two buttons, on the BEAR screen only.
	 */
	public static function enqueue_connection_script() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen we are on, nothing is changed
		if ( ! isset( $_GET['page'] ) || 'woobe' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'woobe-mcp-connection',
			WOOBE_LINK . 'ext/mcp/assets/connection.js',
			array( 'jquery' ),
			WOOBE_VERSION,
			true
		);

		wp_localize_script(
			'woobe-mcp-connection',
			'woobeMcpConnection',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'woobe_mcp_connection' ),
				'working'    => esc_html__( 'Working...', 'woo-bulk-editor' ),
				'disconnect' => esc_html__( 'Disconnect', 'woo-bulk-editor' ),
				'failed'  => esc_html__( 'Something went wrong. Reload the page and try again.', 'woo-bulk-editor' ),
			)
		);
	}

	/**
	 * The state line of the panel, as plain text. Shared by the page and the
	 * AJAX answers, so the line reads the same before and after a click.
	 */
	public static function connection_state_text() {

		$stored = get_option( self::CONNECTION_OPTION );
		$alive  = is_array( $stored ) && ! empty( $stored['hash'] ) && ( time() - intval( $stored['last'] ) <= self::CONNECTION_IDLE );

		if ( ! $alive ) {
			return __( 'No assistant is connected.', 'woo-bulk-editor' );
		}

		return sprintf(
			/* translators: 1: time the connection was confirmed, 2: time it expires if left idle */
			__( 'An assistant is connected since %1$s. If it stays idle, the connection ends at %2$s.', 'woo-bulk-editor' ),
			wp_date( get_option( 'time_format' ), intval( $stored['confirmed'] ) ),
			wp_date( get_option( 'time_format' ), intval( $stored['last'] ) + self::CONNECTION_IDLE )
		);
	}

	/**
	 * The block under the two-factor setting: a warning while the mode is
	 * off, the confirm field and the current connection while it is on.
	 * Printed by the settings screen, administrators only.
	 */
	public static function render_connection_panel() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::two_factor_on() ) {
			?>
			<div class="woobe-mcp-2fa-warning" style="margin-top: 8px; padding: 10px 12px; background: #fdecea; border-left: 4px solid #d63638; color: #8a1f11;">
				<?php
				printf(
					/* translators: %s: how long a connection may stay idle, e.g. "3600 seconds (60 minutes)" */
					esc_html__( 'Recommended: turn on two-factor connection. Without it, anyone who gets hold of the MCP key - from a screenshot, a shared config or a stolen laptop - can work with your shop. With it, every assistant session also needs a token that you confirm here yourself, and that expires after %s without use.', 'woo-bulk-editor' ),
					esc_html( self::idle_text() )
				);
				?>
			</div>
			<?php
			return;
		}

		$stored = get_option( self::CONNECTION_OPTION );
		$alive  = is_array( $stored ) && ! empty( $stored['hash'] ) && ( time() - intval( $stored['last'] ) <= self::CONNECTION_IDLE );
		?>
		<div class="woobe-mcp-connection" style="margin-top: 8px;">

			<p class="woobe-mcp-connection-state" style="margin: 0 0 8px;">
				<span class="woobe-mcp-state-text"><?php echo esc_html( self::connection_state_text() ); ?></span>
				<?php if ( $alive ) : ?>
					<button type="button" class="button woobe-mcp-drop"><?php esc_html_e( 'Disconnect', 'woo-bulk-editor' ); ?></button>
				<?php endif; ?>
			</p>

			<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Confirm assistant connection - paste the token the assistant gave you:', 'woo-bulk-editor' ); ?></label>
			<input type="text" class="woobe-mcp-token" autocomplete="off" spellcheck="false" style="width: 60%; font-family: monospace;" />
			<button type="button" class="button button-primary woobe-mcp-confirm" style="width: 100%;"><?php esc_html_e( 'Confirm connection', 'woo-bulk-editor' ); ?></button>
			<span class="woobe-mcp-message" style="margin-left: 8px;"></span>
		</div>
		<?php
	}

	public static function register_routes() {
		
		// Off by default, and off means the route does not exist. A shop that
		// never connects an assistant should not carry an endpoint at all: the
		// safest code is the code that is not reachable.
		if ( ! self::option( 'mcp_enabled' ) ) {
			return;
		}

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

		// The upload page and the handler behind it. A photograph on somebody's
		// desktop cannot be reached by a chat or an API, and encoding one as
		// text costs more than the conversation around it - so the shop opens a
		// door for fifteen minutes and lets the browser do what it is good at.
		// The token in the path is the credential: single purpose, expiring,
		// and tied to one product when a product was named.
		register_rest_route(
			self::NS,
			'/mcp/upload/(?P<token>[a-f0-9]{32})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'upload_page' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'upload_receive' ),
					'permission_callback' => '__return_true',
				),
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
	 * key - and reports that the server is broken. The connector dialog does
	 * the same thing visually, offering an OAuth section instead of the header
	 * field the user actually needs. 403 says plainly "your credential is not
	 * accepted" and starts no discovery.
	 */
	public static function permission( $request ) {

		// No exemptions. Every request, the handshake included, has to carry
		// the key: without it the endpoint says nothing about itself - not its
		// name, not its version, not how it works. Letting a handshake through
		// unauthenticated is what once let a crafted body pass the gate as
		// "initialize" and run as a batch; with no exemption there is nothing
		// for such a body to pretend to be.
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
			self::$authenticated = true;
			return true;
		}

		return new WP_Error(
			'woobe_mcp_bad_key',
			'Wrong or missing MCP key. Send it as "Authorization: Bearer <key>" or in the X-WOOBE-KEY header.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a decoded body is a JSON-RPC batch.
	 *
	 * The one place this is decided. The gate no longer needs it - it lets
	 * nothing through without the key, batch or not - but anything that ever
	 * has to tell the two apart again should call this rather than write its
	 * own test: a gate and a dispatcher reading one body two different ways is
	 * how a body with a top-level "initialize" and a numeric key "0" once
	 * passed as a handshake and ran as a batch.
	 */
	public static function is_batch( $body ) {
		return is_array( $body ) && isset( $body[0] );
	}

	/**
	 * Whether this request presented the shop's key. False for a handshake,
	 * which is let through without one.
	 */
	public static function authenticated() {
		return self::$authenticated;
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

		// Scoped to what an MCP request may touch, rather than blanket true.
		// The models ask this filter per field, and answering yes to everything
		// meant a leaked key inherited the whole plugin - including fields an
		// administrator hides from shop managers on purpose.
		add_filter(
			'woobe_user_can_edit',
			function ( $can, $field_key = '' ) {

				if ( ! defined( 'WOOBE_MCP_REQUEST' ) ) {
					return $can;
				}

				return ! in_array(
					$field_key,
					apply_filters(
						'woobe_mcp_never_editable',
						array( 'post_author', 'ID', '__checker' )
					),
					true
				);
			},
			99,
			2
		);
			
		// A product put in a subcategory does not appear when a customer
		// browses the parent: WordPress does not fill ancestors in and neither
		// does WooCommerce. In the admin somebody ticks the boxes and sees what
		// he ticked; through this connection nobody sees anything, and "put it
		// in Merino Sweaters" reasonably means "so people find it under
		// Knitwear too".
		//
		// Hooked here rather than in each tool: every path - create, generator,
		// bulk, a single field edit - ends in a term write, so one listener
		// covers them all and cannot be forgotten in a tool written later.
		add_action( 'set_object_terms', array( __CLASS__, 'mark_parent_terms' ), 20, 4 );

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

				// Order side, and for a different reason than the product side.
				// An order's totals are already stored in the currency the
				// customer paid in, so there is nothing left to convert. FOX
				// does not filter these getters, but a switcher that converted
				// on read would hand back the rate applied twice, and every
				// report on this connection would quietly be wrong. Removed as
				// a precaution for switchers we have not seen, not because of
				// a known one.
				'woocommerce_order_amount_line_subtotal',
				'woocommerce_order_amount_item_subtotal',
				'woocommerce_order_amount_item_total',
				'woocommerce_order_amount_total',
				'woocommerce_order_get_total',
				'woocommerce_order_get_subtotal',
				'woocommerce_order_item_get_subtotal',
				'woocommerce_order_item_get_total',
				'woocommerce_get_order_currency',
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

	/**
	 * The page a user drops photographs onto.
	 *
	 * The gap this closes: an image on somebody's desktop cannot be reached by
	 * a chat or an API, and encoding one as text costs more than the whole
	 * conversation around it. So the shop opens a door for fifteen minutes and
	 * lets the browser do what browsers are good at.
	 *
	 * Deliberately one self-contained page with no assets - it has to work on a
	 * phone, on a shop whose theme is broken, and fifteen minutes after the
	 * link was pasted into a message.
	 */
	public static function upload_page( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$data  = get_transient( 'woobe_mcp_upload_' . $token );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( ! is_array( $data ) ) {
			echo '<!doctype html><meta charset="utf-8"><title>Link expired</title>'
				. '<div style="font:16px/1.6 system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
				. '<h1 style="font-size:20px">This upload link has expired</h1>'
				. '<p>Upload links last fifteen minutes. Ask your assistant for a new one - anything already uploaded is safe in the media library.</p>'
				. '</div>';
			exit;
		}

		$product = $data['product_id'] ? get_the_title( intval( $data['product_id'] ) ) : '';

		?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php esc_html_e( 'Upload images', 'woo-bulk-editor' ); ?></title>
<style>
	body { font: 16px/1.6 system-ui, -apple-system, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem; color: #23282d; }
	h1 { font-size: 20px; margin: 0 0 .25rem; }
	.sub { color: #6b7280; margin: 0 0 1.5rem; }
	#drop { border: 2px dashed #b9c4e6; border-radius: 10px; padding: 2.5rem 1rem; text-align: center; background: #f6f8fe; cursor: pointer; }
	#drop.over { border-color: #2f55d4; background: #eef2fd; }
	#list { margin-top: 1.25rem; padding: 0; list-style: none; }
	#list li { padding: .4rem 0; border-bottom: 1px solid #eceef3; display: flex; justify-content: space-between; gap: 1rem; }
	.ok { color: #1baf7a; } .bad { color: #e34948; }
	small { color: #6b7280; }
</style>

<h1><?php esc_html_e( 'Add images to your shop', 'woo-bulk-editor' ); ?></h1>
<p class="sub">
	<?php
	if ( $product ) {
		/* translators: %s: product name */
		printf( esc_html__( 'They will be attached to %s.', 'woo-bulk-editor' ), '<b>' . esc_html( $product ) . '</b>' );
	} else {
		esc_html_e( 'They go into the media library, ready to put on a product.', 'woo-bulk-editor' );
	}
	?>
</p>

<div id="drop">
	<b><?php esc_html_e( 'Drop images here', 'woo-bulk-editor' ); ?></b><br>
	<small><?php esc_html_e( 'or click to choose them', 'woo-bulk-editor' ); ?></small>
	<input type="file" id="file" accept="image/*" multiple hidden>
</div>

<ul id="list"></ul>
<p id="summary" style="font-weight:600;margin-top:1rem"></p>

<script>
( function () {

	var drop = document.getElementById( 'drop' );
	var file = document.getElementById( 'file' );
	var list = document.getElementById( 'list' );
	var summary = document.getElementById( 'summary' );
	var url  = <?php echo wp_json_encode( rest_url( 'woobe/v1/mcp/upload/' . $token ) ); ?>;
	var maxBytes = <?php echo intval( wp_max_upload_size() ); ?>;
		
	drop.addEventListener( 'click', function () { file.click(); } );

	[ 'dragenter', 'dragover' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) { ev.preventDefault(); drop.classList.add( 'over' ); } );
	} );

	[ 'dragleave', 'drop' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) { ev.preventDefault(); drop.classList.remove( 'over' ); } );
	} );

	drop.addEventListener( 'drop', function ( ev ) { send( ev.dataTransfer.files ); } );
	file.addEventListener( 'change', function () { send( file.files ); } );

	function send( files ) {

		// One at a time, deliberately. Sent in parallel, every request reads the
		// product before any of them has written to it: each sees no featured
		// image, each makes itself the featured one, and the last to finish
		// wins while the rest vanish. The same race loses the record of what
		// arrived. A queue is slower by a second and correct.
		var queue = Array.prototype.slice.call( files );
		var total = queue.length;
		var done  = 0;
		var bad   = 0;

		if ( ! total ) {
			return;
		}

		summary.className = '';
		summary.textContent = '0 / ' + total;

		function next() {

			// nothing left: say how it went rather than leaving the last row
			// as the only clue, which on twenty files tells the user nothing
			if ( ! queue.length ) {
				summary.className = bad ? 'bad' : 'ok';
				summary.textContent = bad
					? ( done + ' of ' + total + ' uploaded, ' + bad + ' failed' )
					: ( 'All ' + total + ' uploaded' );
				return;
			}

			var f = queue.shift();
			var n = total - queue.length;

			var row = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			var state = document.createElement( 'span' );

			name.textContent = n + '. ' + f.name;
			state.textContent = 'sending';
			row.appendChild( name );
			row.appendChild( state );
			list.appendChild( row );

			// checked here as well as on the server: a file over the server's
			// body limit never reaches PHP at all - nginx cuts the connection
			// and answers with its own HTML, which is why an oversized upload
			// used to fail with no reason given
			if ( maxBytes && f.size > maxBytes ) {
				++bad;
				state.className = 'bad';
				state.textContent = 'too large (' + Math.round( f.size / 1048576 ) + ' MB, limit ' + Math.round( maxBytes / 1048576 ) + ' MB)';
				summary.textContent = ( done + bad ) + ' / ' + total;
				next();
				return;
			}

			var body = new FormData();
			body.append( 'file', f );

			fetch( url, { method: 'POST', body: body } )
				.then( function ( r ) {
					return r.json().catch( function () {
						// not JSON: the web server answered before PHP did
						return { message: 'rejected by the server (' + r.status + ')' };
					} );
				} )
				.then( function ( j ) {
					if ( j && j.id ) {
						++done;
						state.className = 'ok';
						state.textContent = 'added';
					} else {
						++bad;
						state.className = 'bad';
						state.textContent = ( j && j.message ) ? j.message : 'failed';
					}
				} )
				.catch( function ( e ) {
					++bad;
					state.className = 'bad';
					state.textContent = 'connection lost';
				} )
				.finally( function () {
					summary.textContent = ( done + bad ) + ' / ' + total;
					next();
				} );
		}

		next();
	}
} )();
</script>
		<?php
		exit;
	}

	/**
	 * Receives one file and records it against the token, so the assistant can
	 * ask what arrived rather than searching the library for something new.
	 */
	public static function upload_receive( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$data  = get_transient( 'woobe_mcp_upload_' . $token );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'woobe_mcp_upload_expired', 'This upload link has expired.', array( 'status' => 403 ) );
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
			return new WP_Error( 'woobe_mcp_no_file', 'No file arrived.', array( 'status' => 400 ) );
		}

		// A link is fifteen minutes of open upload, and without a ceiling that
		// is fifteen minutes of somebody filling the disk. The size limit comes
		// from what this server already accepts - the smaller of PHP's
		// upload_max_filesize and post_max_size - because a second number
		// invented here would either contradict PHP or be ignored by it.
		$max_files = intval( apply_filters( 'woobe_mcp_upload_max_files', 20 ) );
		$max_bytes = intval( apply_filters( 'woobe_mcp_upload_max_bytes', wp_max_upload_size() ) );

		if ( count( $data['uploaded'] ) >= $max_files ) {
			return new WP_REST_Response(
				array( 'message' => 'limit of ' . $max_files . ' reached' ),
				200
			);
		}

		if ( $max_bytes > 0 && intval( $files['file']['size'] ) > $max_bytes ) {
			return new WP_REST_Response(
				array( 'message' => 'too large, limit ' . size_format( $max_bytes ) ),
				200
			);
		}

		$ext = self::ext();

		if ( is_wp_error( $ext ) ) {
			return $ext;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$pack = $ext->pack( 'media' );

		if ( ! $pack ) {
			return new WP_Error( 'woobe_mcp_no_media_pack', 'The media pack is not installed on this shop.', array( 'status' => 500 ) );
		}

		$result = $pack->sideload(
			array(
				'name'     => $files['file']['name'],
				'tmp_name' => $files['file']['tmp_name'],
			),
			intval( $data['product_id'] ),
			(string) $data['as']
		);

		// answered as a normal body rather than an error: the page shows the
		// message beside the file name, and one rejected image should not look
		// like the whole upload failed
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 200 );
		}

		$data['uploaded'][] = $result['attachment']['id'];
		set_transient( 'woobe_mcp_upload_' . $token, $data, 900 );

		return new WP_REST_Response(
			array(
				'id'   => $result['attachment']['id'],
				'file' => $result['attachment']['file'],
			),
			200
		);
	}
	
	/**
	 * Ticks the ancestors of every term just written to a hierarchical
	 * product taxonomy.
	 *
	 * Only inside an MCP request - the hook is attached when the connection
	 * boots and dies with it, so nothing changes for the admin screen or for
	 * any other plugin.
	 */
	public static function mark_parent_terms( $object_id, $terms, $tt_ids, $taxonomy ) {

		// our own write below fires this hook again; without the guard the
		// second pass would find the same terms and recurse
		static $busy = false;

		if ( $busy ) {
			return;
		}

		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return;
		}

		if ( ! in_array( $taxonomy, get_object_taxonomies( 'product' ), true ) ) {
			return;
		}

		$current = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $current ) || empty( $current ) ) {
			return;
		}

		$all = $current;

		foreach ( $current as $term_id ) {

			$ancestors = get_ancestors( intval( $term_id ), $taxonomy, 'taxonomy' );

			if ( ! empty( $ancestors ) ) {
				$all = array_merge( $all, $ancestors );
			}
		}

		$all = array_unique( array_map( 'intval', $all ) );

		// nothing to add: leave it alone rather than writing the same set back
		// and clearing caches for no reason
		if ( count( $all ) === count( $current ) ) {
			return;
		}

		$busy = true;
		wp_set_object_terms( $object_id, $all, $taxonomy, false );
		$busy = false;

		clean_object_term_cache( $object_id, $taxonomy );
	}
}

WOOBE_MCP_BOOT::init();