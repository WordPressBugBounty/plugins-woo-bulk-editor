<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Personal MCP access.
 *
 * The shop-wide key belongs to the administrator and keeps working exactly as
 * before. On top of it the administrator may grant MCP access to named users;
 * each of them gets a personal key and connects as himself, with his own
 * role, his own field visibility and his own two-factor connection.
 *
 * This file holds everything a person sees and clicks for that: the grant
 * list with its user search and status lines (administrators only), the
 * personal block in a granted user's own settings, the AJAX handlers behind
 * the buttons, and the two option hooks that keep the grant list clean and
 * forget the key of anyone taken off it. Authentication itself lives in
 * WOOBE_MCP_BOOT::permission().
 */
final class WOOBE_MCP_ACCESS {

	const NONCE = 'woobe_mcp_access';

	// how many users one search may return
	const SEARCH_LIMIT = 20;

	public static function init() {

		// the settings column prints this after its own settings
		add_action( 'woobe_general_settings_after', array( __CLASS__, 'render' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_woobe_mcp_search_users', array( __CLASS__, 'ajax_search_users' ) );
		add_action( 'wp_ajax_woobe_mcp_drop_user_connection', array( __CLASS__, 'ajax_drop_user_connection' ) );
		add_action( 'wp_ajax_woobe_mcp_regenerate_key', array( __CLASS__, 'ajax_regenerate_key' ) );
		add_action( 'wp_ajax_woobe_mcp_confirm_personal', array( __CLASS__, 'ajax_confirm_personal' ) );
		add_action( 'wp_ajax_woobe_mcp_drop_personal', array( __CLASS__, 'ajax_drop_personal' ) );

		// the grant list is saved with the rest of the global options
		add_filter( 'pre_update_option_woobe_options_global', array( __CLASS__, 'sanitize_global' ) );
		add_action( 'update_option_woobe_options_global', array( __CLASS__, 'after_global_update' ), 10, 2 );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// saving the grant list

	/**
	 * A list of existing user ids, whatever shape it arrived in: the form
	 * posts an array of strings, and an emptied select posts nothing at all,
	 * which the settings model stores as an empty string.
	 *
	 * @return int[]
	 */
	public static function clean_ids( $raw ) {

		if ( ! is_array( $raw ) ) {
			$raw = ( '' === trim( (string) $raw ) ) ? array() : explode( ',', (string) $raw );
		}

		$ids = array();

		foreach ( $raw as $id ) {

			$id = absint( $id );

			if ( $id && get_userdata( $id ) ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * pre_update_option_woobe_options_global: the grant list is stored as a
	 * clean array of user ids, never as whatever the form posted.
	 */
	public static function sanitize_global( $value ) {

		if ( is_array( $value ) && array_key_exists( 'mcp_users', $value ) ) {
			$value['mcp_users'] = self::clean_ids( $value['mcp_users'] );
		}

		return $value;
	}

	/**
	 * update_option_woobe_options_global: a user taken off the grant list
	 * loses his key and his connection on the same save. A value written
	 * without the list at all changes nothing, so no other writer of the
	 * global option can revoke anybody by accident.
	 */
	public static function after_global_update( $old_value, $value ) {

		if ( ! is_array( $value ) || ! array_key_exists( 'mcp_users', $value ) ) {
			return;
		}

		$before = self::clean_ids( is_array( $old_value ) && isset( $old_value['mcp_users'] ) ? $old_value['mcp_users'] : array() );
		$after  = self::clean_ids( $value['mcp_users'] );

		foreach ( array_diff( $before, $after ) as $user_id ) {
			WOOBE_MCP_BOOT::forget_personal_access( $user_id );
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// settings screen

	/**
	 * The script behind the grant list and the personal block, on the plugin
	 * screen for anyone who can open it. The nonce alone opens nothing: every
	 * handler checks who is asking.
	 */
	public static function enqueue() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen we are on, nothing is changed
		if ( ! isset( $_GET['page'] ) || 'woobe' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_enqueue_script(
			'woobe-mcp-access',
			WOOBE_LINK . 'ext/mcp/assets/access.js',
			array( 'jquery' ),
			WOOBE_VERSION,
			true
		);

		wp_localize_script(
			'woobe-mcp-access',
			'woobeMcpAccess',
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'placeholder' => esc_html__( 'Type part of a name, login or e-mail', 'woo-bulk-editor' ),
				'noResults'   => esc_html__( 'No matching user who can manage WooCommerce', 'woo-bulk-editor' ),
				'working'     => esc_html__( 'Working...', 'woo-bulk-editor' ),
				'disconnect'  => esc_html__( 'Disconnect', 'woo-bulk-editor' ),
				'regenerate'  => esc_html__( 'Issue a new key? The old key stops working at once and the assistant using it is disconnected.', 'woo-bulk-editor' ),
				'failed'      => esc_html__( 'Something went wrong. Reload the page and try again.', 'woo-bulk-editor' ),
			)
		);
	}

	/**
	 * Printed at the end of the general settings column: the grant list for
	 * an administrator, the personal block for a granted user. Anybody else
	 * sees nothing new.
	 */
	public static function render() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( WOOBE_MCP_BOOT::is_administrator() ) {
			self::render_grant_list();
		}

		$me = get_current_user_id();

		if ( WOOBE_MCP_BOOT::may_use_personal_key( $me ) ) {
			self::render_personal_block( $me );
		}
	}

	/**
	 * How a user is named in the grant list: display name, then login and
	 * e-mail so two people with the same name can be told apart.
	 */
	private static function user_label( $user ) {
		return sprintf( '%1$s (%2$s, %3$s)', $user->display_name, $user->user_login, $user->user_email );
	}

	/**
	 * One status line of the administrator overview, as plain text.
	 */
	private static function user_state_text( $user_id ) {

		if ( ! WOOBE_MCP_BOOT::may_use_personal_key( $user_id ) ) {
			return __( 'cannot connect: this user no longer has the manage_woocommerce capability', 'woo-bulk-editor' );
		}

		if ( '' === WOOBE_MCP_BOOT::personal_key( $user_id ) ) {
			return __( 'no key yet - it is issued when the user opens his settings', 'woo-bulk-editor' );
		}

		$state = WOOBE_MCP_BOOT::personal_state( $user_id );

		return $state['text'];
	}

	private static function render_grant_list() {

		$granted = WOOBE_MCP_BOOT::granted_users();
		$users   = empty( $granted ) ? array() : get_users(
			array(
				'include' => $granted,
				'orderby' => 'display_name',
			)
		);
		?>
		<div class="woobe-control-section woobe-mcp-access-admin">
			<h5><?php esc_html_e( 'MCP access for users', 'woo-bulk-editor' ); ?></h5>
			<div class="woobe-control-container">
				<div class="woobe-control" style="width: 80%;">

					<select multiple="multiple" id="woobe_mcp_users" name="woobe_options[options][mcp_users][]" data-placeholder="<?php esc_attr_e( 'Type part of a name, login or e-mail', 'woo-bulk-editor' ); ?>" style="width: 100%;">
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" selected="selected"><?php echo esc_html( self::user_label( $user ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="hidden" name="woobe_options[rendered][]" value="mcp_users" />

					<?php if ( ! empty( $users ) ) : ?>
						<ul class="woobe-mcp-users-status" style="margin: 8px 0 0; font-size: 13px;">
							<?php foreach ( $users as $user ) : ?>
								<?php $woobe_alive = null !== WOOBE_MCP_BOOT::live_connection( $user->ID ); ?>
								<li class="woobe-mcp-user-line" data-user="<?php echo esc_attr( $user->ID ); ?>" style="margin: 0 0 4px;">
									<b><?php echo esc_html( $user->display_name ); ?></b> (<?php echo esc_html( $user->user_login ); ?>):
									<span class="woobe-mcp-state-text"><?php echo esc_html( self::user_state_text( $user->ID ) ); ?></span>
									<?php if ( $woobe_alive ) : ?>
										<button type="button" class="button button-small woobe-mcp-user-drop" data-user="<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Disconnect', 'woo-bulk-editor' ); ?></button>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<div class="woobe-underfield" style="margin-top: 6px; font-size: 10px;">
						<?php esc_html_e( 'Save the settings after changing the list. A user taken off the list loses his key and his connection at once.', 'woo-bulk-editor' ); ?>
					</div>
				</div>
				<div class="woobe-description" style="width: auto; float: left;">
					<p class="description">
						<?php WOOBE_HELPER::draw_tooltip( __( 'Users picked here get a personal MCP key in their own settings and connect an AI assistant under their own WordPress account: the assistant can do exactly what they can do by hand, with their role and field visibility, never more. Two-factor connection is always on for personal keys. Only users who can manage WooCommerce are offered. The shop-wide key above is not affected and stays yours.', 'woo-bulk-editor' ) ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_personal_block( $user_id ) {

		$key   = WOOBE_MCP_BOOT::personal_key( $user_id, true );
		$state = WOOBE_MCP_BOOT::personal_state( $user_id );
		?>
		<div class="woobe-control-section woobe-mcp-personal">
			<h5><?php esc_html_e( 'Your MCP access', 'woo-bulk-editor' ); ?></h5>
			<div class="woobe-control-container">
				<div class="woobe-control" style="width: 80%;">

					<div style="margin-bottom: 6px;">
						<?php esc_html_e( 'Address for the assistant:', 'woo-bulk-editor' ); ?>
						<code style="user-select: all;"><?php echo esc_url( rest_url( 'woobe/v1/mcp' ) ); ?></code>
					</div>

					<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Your personal key:', 'woo-bulk-editor' ); ?></label>
					<input type="text" readonly="readonly" class="woobe-mcp-personal-key" value="<?php echo esc_attr( $key ); ?>" style="width: 100%; font-family: monospace;" />
					<button type="button" class="button woobe-mcp-regenerate" style="margin-top: 4px;"><?php esc_html_e( 'Regenerate key', 'woo-bulk-editor' ); ?></button>

					<div class="woobe-underfield" style="margin-top: 6px; font-size: 10px;">
						<?php esc_html_e( 'Give the assistant the address and this key as a request header: Authorization, with the value "Bearer" followed by a space and the key. Two-factor connection is always on for a personal key: every session starts with a token the assistant shows you, which you confirm below.', 'woo-bulk-editor' ); ?>
					</div>

					<p class="woobe-mcp-personal-state" style="margin: 8px 0;">
						<span class="woobe-mcp-state-text"><?php echo esc_html( $state['text'] ); ?></span>
						<?php if ( $state['connected'] ) : ?>
							<button type="button" class="button woobe-mcp-personal-drop"><?php esc_html_e( 'Disconnect', 'woo-bulk-editor' ); ?></button>
						<?php endif; ?>
					</p>

					<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Confirm assistant connection - paste the token the assistant gave you:', 'woo-bulk-editor' ); ?></label>
					<input type="text" class="woobe-mcp-personal-token" autocomplete="off" spellcheck="false" style="width: 60%; font-family: monospace;" />
					<button type="button" class="button button-primary woobe-mcp-personal-confirm" style="width: 100%;"><?php esc_html_e( 'Confirm connection', 'woo-bulk-editor' ); ?></button>
					<span class="woobe-mcp-personal-message" style="margin-left: 8px;"></span>
				</div>
				<div class="woobe-description" style="width: auto; float: left;">
					<p class="description">
						<?php WOOBE_HELPER::draw_tooltip( __( 'The administrator gave you MCP access. An assistant connected with your key works as you: it can do what you can do by hand in this plugin, with your role and field visibility. Keep the key private; if it may have leaked, press Regenerate key - the old key and its connection stop working at once.', 'woo-bulk-editor' ) ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// AJAX

	/**
	 * The checks every handler starts with: the nonce, the right to open the
	 * plugin at all, and for administrator buttons the administrator rule.
	 */
	private static function verify( $administrator_only = false ) {

		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You cannot manage WooCommerce on this site.', 'woo-bulk-editor' ) ), 403 );
		}

		if ( $administrator_only && ! WOOBE_MCP_BOOT::is_administrator() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can do this.', 'woo-bulk-editor' ) ), 403 );
		}
	}

	/**
	 * The checks of the personal buttons: the current user must be on the
	 * grant list right now. Returns his id.
	 */
	private static function verify_personal() {

		self::verify();

		$me = get_current_user_id();

		if ( ! WOOBE_MCP_BOOT::may_use_personal_key( $me ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have no MCP access on this shop. Ask the administrator to grant it.', 'woo-bulk-editor' ) ), 403 );
		}

		return $me;
	}

	/**
	 * User search behind the grant list: part of a name, login or e-mail in,
	 * at most twenty users who can manage WooCommerce out.
	 */
	public static function ajax_search_users() {

		self::verify( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$term = isset( $_POST['term'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['term'] ) ) ) : '';

		if ( '' === $term ) {
			wp_send_json_success( array( 'users' => array() ) );
		}

		$query = new WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'capability'     => 'manage_woocommerce',
				'number'         => self::SEARCH_LIMIT,
				'orderby'        => 'display_name',
				'count_total'    => false,
			)
		);

		$out = array();

		foreach ( (array) $query->get_results() as $user ) {

			// asked again per user: the query matches roles, this also sees
			// a capability granted or taken away user by user
			if ( ! user_can( $user, 'manage_woocommerce' ) ) {
				continue;
			}

			$out[] = array(
				'id'    => intval( $user->ID ),
				'label' => self::user_label( $user ),
			);
		}

		wp_send_json_success( array( 'users' => $out ) );
	}

	/**
	 * Disconnect button of one line in the administrator overview.
	 */
	public static function ajax_drop_user_connection() {

		self::verify( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		// 0 would be the shop-wide connection, which has its own button
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No user given.', 'woo-bulk-editor' ) ) );
		}

		WOOBE_MCP_BOOT::drop_connection( $user_id );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'woo-bulk-editor' ),
				'connected' => false,
				'state'     => self::user_state_text( $user_id ),
			)
		);
	}

	/**
	 * Regenerate key: a new personal key for the current user only. Ends his
	 * connection too, so a session opened with the old key dies with it.
	 */
	public static function ajax_regenerate_key() {

		$me = self::verify_personal();
		$key   = WOOBE_MCP_BOOT::regenerate_personal_key( $me );
		$state = WOOBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'A new key is issued. The old one no longer works - update it in your assistant.', 'woo-bulk-editor' ),
				'key'       => $key,
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}

	/**
	 * Confirm button of the personal block. The token lands in the current
	 * user's own meta and nowhere else, so it works with his key only.
	 */
	public static function ajax_confirm_personal() {

		$me = self::verify_personal();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( ! preg_match( WOOBE_MCP_BOOT::TOKEN_PATTERN, $token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That is not a connection token. Copy the whole token the assistant gave you - four groups of eight characters separated by dashes.', 'woo-bulk-editor' ) ) );
		}

		WOOBE_MCP_BOOT::confirm_connection( $me, $token );

		$state = WOOBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Connected. Go back to the assistant and tell it you have confirmed.', 'woo-bulk-editor' ),
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}

	/**
	 * Disconnect button of the personal block.
	 */
	public static function ajax_drop_personal() {

		$me = self::verify_personal();

		WOOBE_MCP_BOOT::drop_connection( $me );

		$state = WOOBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'woo-bulk-editor' ),
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}
}
