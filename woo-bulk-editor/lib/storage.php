<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// keeps current user data
final class WOOBE_STORAGE {

	// 'option' is the default type: an option row always lives in the wp_options table,
	// so an object cache flush (Redis, Memcached) can only evict it from memory,
	// it can never destroy the data. Transients under a persistent object cache
	// are stored in the cache ONLY and are lost on every flush.
	// 'transient', 'session' and 'cookie' are kept for backward compatibility.
	public $type = 'option';

	private $option_prefix = 'woobe_memory_';
	private $storage_key   = null;
	private $user_ip       = null;
	private $transient_key = null;
	private $ttl           = 86400; // 1 day - the same lifetime the transient storage had
	private $data          = null;  // in request cache of the whole data set

	public function __construct( $type = '' ) {

		if ( ! empty( $type ) ) {
			$this->type = $type;
		} else {
			// site wide setting from the plugin options page
			$global = get_option( 'woobe_options_global' );
			if ( is_array( $global ) and ! empty( $global['storage_type'] ) ) {
				$this->type = $global['storage_type'];
			}
		}

		$this->type = apply_filters( 'woobe_storage_type', $this->type );
		$this->ttl  = intval( apply_filters( 'woobe_storage_ttl', $this->ttl ) );

		if ( $this->type == 'session' ) {
			if ( ! session_id() ) {
				try {
					@session_start();
				} catch ( Exception $e ) {
					// ***
				}
			}
		}

		$this->user_ip       = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP );
		$this->transient_key = md5( $this->user_ip . 'woobe_salt' );

		// the user id is available here because the class is created inside WOOBE::init()
		// which runs on the 'init' hook, long after pluggable.php is loaded
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$this->storage_key = $this->option_prefix . $user_id;
		} else {
			// no logged in user - keep the old behaviour and separate visitors by ip
			$this->storage_key = $this->option_prefix . $this->transient_key;
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// option type helpers

	private function read_data() {

		if ( is_array( $this->data ) ) {
			return $this->data;
		}

		$data = get_option( $this->storage_key );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$this->data = $data;

		return $this->data;
	}

	private function write_data( $data ) {

		$this->data = $data;

		if ( empty( $data ) ) {
			delete_option( $this->storage_key );
			return;
		}

		// autoload must stay off: this row is needed on the plugin page only
		// and must never be loaded on every page of the site
		if ( false === get_option( $this->storage_key, false ) ) {
			add_option( $this->storage_key, $data, '', 'no' );
		} else {
			update_option( $this->storage_key, $data, false );
		}
	}

	// an option has no expiration of its own, so we emulate the transient ttl here,
	// otherwise old bulk keys would pile up in the row forever
	private function clean_expired( $data ) {

		$now = time();

		foreach ( $data as $k => $row ) {
			if ( ! is_array( $row ) or ! isset( $row['expires'] ) or $row['expires'] < $now ) {
				unset( $data[ $k ] );
			}
		}

		return $data;
	}
	
		// WooCommerce starts its session on the front end only, so in the admin
	// WC()->session is null - fall back to a standalone handler
	private function get_session() {

		if ( function_exists( 'WC' ) and ! is_null( WC()->session ) ) {
			return WC()->session;
		}

		if ( ! class_exists( 'WC_Session_Handler' ) ) {
			return null;
		}

		static $sess = null;

		if ( is_null( $sess ) ) {
			$sess = new WC_Session_Handler();
			$sess->init();
		}

		return $sess;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	public function set_val( $key, $value ) {
		switch ( $this->type ) {
			case 'option':
				$data         = $this->clean_expired( $this->read_data() );
				$data[ $key ] = array(
					'value'   => $value,
					'expires' => time() + $this->ttl,
				);
				$this->write_data( $data );
				break;
			case 'session':
				$sess = $this->get_session();
				if ( ! is_null( $sess ) ) {
					$sess->set( $key, $value );
				}
				break;
			case 'transient':
				$data = get_transient( $this->transient_key );
				if ( ! is_array( $data ) ) {
					$data = array();
				}
				$data[ $key ] = $value;
				set_transient( $this->transient_key, $data, $this->ttl );
				break;
			case 'cookie':
				setcookie( $key, $value, time() + $this->ttl );
				break;

			default:
				break;
		}
	}

	public function get_val( $key ) {
		$value = null;
		switch ( $this->type ) {
			case 'option':
				$data = $this->read_data();
				if ( isset( $data[ $key ]['expires'] ) and $data[ $key ]['expires'] >= time() ) {
					$value = $data[ $key ]['value'];
				}
				break;
			case 'session':
				$sess = $this->get_session();
				if ( ! is_null( $sess ) ) {
					$value = $sess->__get( $key );
				}
				break;
			case 'transient':
				$data = get_transient( $this->transient_key );
				if ( ! is_array( $data ) ) {
					$data = array();
				}
				if ( isset( $data[ $key ] ) ) {
					$value = $data[ $key ];
				}
				break;
			case 'cookie':
				if ( $this->is_isset( $key ) ) {
					$value = $_COOKIE[ $key ];
				}
				break;

			default:
				break;
		}

		return $value;
	}

	public function unset_val( $key ) {

		switch ( $this->type ) {
			case 'option':
				$data = $this->clean_expired( $this->read_data() );
				unset( $data[ $key ] );
				$this->write_data( $data );
				break;
			case 'session':
				$sess = $this->get_session();
				if ( ! is_null( $sess ) ) {
					$sess->__unset( $key );
				}
				break;
			case 'transient':
				$data = get_transient( $this->transient_key );
				if ( isset( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
				set_transient( $this->transient_key, $data, $this->ttl );
				break;
			case 'cookie':
				if ( $this->is_isset( $key ) ) {
					unset( $_COOKIE[ $key ] );
					setcookie( $key, '', time() - 3600, '/' );
				}
				break;

			default:
				break;
		}

		return false;
	}

	public function is_isset( $key ) {
		$isset = false;
		switch ( $this->type ) {
			case 'option':
				$isset = ! is_null( $this->get_val( $key ) );
				break;
			case 'session':
				$sess = $this->get_session();
				if ( ! is_null( $sess ) ) {
					$isset = $sess->__isset( $key );
				}
				break;
			case 'transient':
				$isset = (bool) $this->get_val( $key );
				break;
			case 'cookie':
				$isset = isset( $_COOKIE[ $key ] );
				break;

			default:
				break;
		}

		return $isset;
	}

	// called on the 'delete_user' hook, see index.php
	public static function delete_user_storage( $user_id ) {
		delete_option( 'woobe_memory_' . intval( $user_id ) );
	}
}