<?php
/**
 * Copyright (c) 2017 - Eighty / 20 Results by Wicked Strong Chicks.
 * ALL RIGHTS RESERVED
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Payment_Warning\Addon;

use E20R\Payment_Warning\Payment_Warning;
use E20R\Utilities\Licensing\Licensing;
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Utilities;
use E20R\Payment_Warning\Addon\PayPal_Gateway_Addon;
use E20R\Payment_Warning\Addon\Stripe_Gateway_Addon;
use E20R\Payment_Warning\Addon\Check_Gateway_Addon;

abstract class E20R_PW_Gateway_Addon {
	
	/**
	 * @var E20R_PW_Gateway_Addon
	 */
	private static $instance = null;
	/**
	 * Instance of the active/primary Payment Gateway Class(es) for PMPro
	 *
	 * @var array|
	 *      \PMProGateway[]|
	 *      \PMProGateway_authorizenet|
	 *      \PMProGateway_braintree|
	 *      \PMProGateway_check|
	 *      \PMProGateway_cybersource|
	 *      \PMProGateway_payflowpro|
	 *      \PMProGateway_paypal|
	 *      \PMProGateway_paypalexpress|
	 *      \PMProGateway_paypalstandard|
	 *      \PMProGateway_stripe|
	 *      \PMProGateway_Twocheckout
	 */
	protected $gateway_class = array();
	/**
	 * @var mixed
	 */
	protected $gateway = null;
	/**
	 * Name of the WordPress option key
	 *
	 * @var string $option_name
	 */
	protected $option_name = 'e20r_pwgw_default';
	
	/**
	 * @var null|string Timezone string for payment gateway config
	 *
	 * @access protected
	 */
	protected $gateway_timezone;
	
	/**
	 * @var null|string
	 */
	protected $gateway_name = null;
	
	/**
	 * @var bool
	 */
	protected $gateway_loaded = false;
	
	/**
	 * @var null|string $current_gateway_type Can be set to 'live' or 'sandbox' or null
	 */
	protected $current_gateway_type = null;
	
	/**
	 * Local settings array
	 *
	 * @var array
	 */
	protected $settings = array();
	
	/**
	 * E20R_PW_Gateway_Addon constructor.
	 */
	public function __construct() {
		
		self::$instance = $this;
		
		$this->define_settings();
	}
	
	/**
	 * Load options/settings for add-on
	 */
	protected function define_settings() {
		
		$this->action = $this->option_name;
		
		$this->settings = get_option( $this->option_name, array() );
	}
	
	/**
	 * Fetch the properties for E20R_PWGW_Addon
	 *
	 * @return E20R_PW_Gateway_Addon
	 *
	 * @since  1.0
	 * @access public
	 */
	public static function get_instance() {
		
		return self::$instance;
	}
	
	/**
	 * Load the required hooks for the add-on gateway
	 *
	 * @param PayPal_Gateway_Addon|Stripe_Gateway_Addon|Check_Gateway_Addon $class
	 *
	 * @since 2.1 - ENHANCEMENT: Renamed the e20r_pw_addon_gateway_subscr_statuses filter to
	 *        e20r_pw_addon_subscr_statuses
	 */
	public static function load_hooks_for( $class ) {
		
		// Add-on specific filters and actions
		if ( method_exists( $class, 'load_webhook_handler' ) ) {
			add_action( 'e20r_pw_addon_remote_call_handler', array( $class, 'load_webhook_handler' ), 10 );
		}
		
		if ( method_exists( $class, 'load_gateway' ) ) {
			add_action( 'e20r_pw_addon_load_gateway', array( $class, 'load_gateway' ), 10, 1 );
		}
		
		if ( method_exists( $class, 'save_email_error' ) ) {
			add_action( 'e20r_pw_addon_save_email_error_data', array( $class, 'save_email_error', ), 10, 4 );
		}
		
		if ( method_exists( $class, 'save_subscription_mismatch' ) ) {
			add_action( 'e20r_pw_addon_save_subscription_mismatch', array(
				$class,
				'save_subscription_mismatch'
			), 10, 4 );
		}
		
		if ( method_exists( $class, 'get_gateway_subscriptions' ) ) {
			add_filter( 'e20r_pw_addon_get_user_subscriptions', array( $class, 'get_gateway_subscriptions' ), 10, 2 );
		}
		
		if ( method_exists( $class, 'get_gateway_payments' ) ) {
			add_filter( 'e20r_pw_addon_get_user_payments', array( $class, 'get_gateway_payments' ), 10, 2 );
		}
		
		if ( method_exists( $class, 'get_local_user_customer_id' ) ) {
			add_filter( 'e20r_pw_addon_get_user_customer_id', array( $class, 'get_local_user_customer_id' ), 10, 3 );
		}
		
		if ( method_exists( $class, 'valid_gateway_subscription_statuses' ) ) {
			add_filter( 'e20r_pw_addon_subscr_statuses', array(
				$class,
				'valid_gateway_subscription_statuses'
			), 10, 3 );
		}
		
		if ( method_exists( $class, 'update_credit_card_info' ) ) {
			add_filter( 'e20r_pw_addon_process_cc_info', array( $class, 'update_credit_card_info' ), 10, 3 );
		}
		
		if ( method_exists( $class, 'get_gateway_class_name' ) ) {
			add_filter( 'e20r_pw_get_gateway_name_for_addon', array( $class, 'get_gateway_class_name' ), 10, 2 );
		}
		
	}
	
	/**
	 * Tests whether the add-on is configured and licensed (enabled) or not
	 *
	 * @param string $stub
	 *
	 * @return bool
	 */
	public static function is_enabled( $stub ) {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Checking if {$stub} is enabled" );
		
		if ( $stub === 'example_gateway_addon' ) {
			return false;
		}
		
		$enabled = false;
		$screen  = null;
		
		global $e20r_pw_addons;
		
		$e20r_pw_addons[ $stub ]['is_active']      = (bool) get_option( "e20r_pw_addon_{$stub}_enabled", false );
		$e20r_pw_addons[ $stub ]['active_license'] = (bool) get_option( "e20r_pw_addon_{$stub}_licensed", false );
		
		$utils->log( "is_active setting for {$stub}: " . ( $e20r_pw_addons[ $stub ]['is_active'] ? 'True' : 'False' ) );
		$utils->log( "The {$stub} add-on is licensed? " . ( $e20r_pw_addons[ $stub ]['active_license'] ? 'Yes' : 'No' ) );
		
		if ( false === $e20r_pw_addons[ $stub ]['active_license'] || ( true === $e20r_pw_addons[ $stub ]['active_license'] && true === Licensing::is_license_expiring( $stub ) ) ) {
			
			$utils->log( "Checking license server for {$stub} (forced)" );
			$e20r_pw_addons[ $stub ]['active_license'] = Licensing::is_licensed( $stub, true );
			update_option( "e20r_pw_addon_{$stub}_licensed", $e20r_pw_addons[ $stub ]['active_license'], 'no' );
		}
		$utils->log( "The {$stub} add-on is enabled? {$enabled}" );
		
		$e20r_pw_addons[ $stub ]['is_active'] = ( $e20r_pw_addons[ $stub ]['is_active'] && $e20r_pw_addons[ $stub ]['active_license'] );
		
		if ( true === $e20r_pw_addons[ $stub ]['is_active'] ) {
			$e20r_pw_addons[ $stub ]['status'] = 'active';
		} else {
			$e20r_pw_addons[ $stub ]['status'] = 'deactivated';
		}
		
		$utils->log( "The {$stub} add-on status is active? {$e20r_pw_addons[ $stub ]['status']}" );
		
		return $e20r_pw_addons[ $stub ]['is_active'];
	}
	
	/**
	 * Check the add-on specific requirements
	 *
	 * @param string $stub The name of the plugin
	 */
	public static function check_requirements( $stub ) {
		
		global $e20r_pw_addons;
		
		if ( null === $stub || 'e20r_pw_gateway_addon' == $stub ) {
			return;
		}
		
		if ( WP_DEBUG ) {
			error_log( "Checking requirements for {$stub}" );
		}
		
		$utils = Utilities::get_instance();
		
		$active_plugins   = get_option( 'e20r_pw_active_plugins', array() );
		$required_plugins = $e20r_pw_addons[ $stub ]['required_plugins_list'];
		
		foreach ( $required_plugins as $plugin_file => $info ) {
			
			$is_active = in_array( $plugin_file, $active_plugins );
			
			if ( is_multisite() ) {
				
				$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins', array() );
				
				$is_active =
					$is_active ||
					key_exists( $plugin_file, $active_sitewide_plugins );
			}
			
			if ( false === $is_active ) {
				
				if ( WP_DEBUG ) {
					error_log( sprintf( "%s is not active!", $e20r_pw_addons[ $stub ]['label'] ) );
				}
				
				$utils->add_message(
					sprintf(
						__(
							'Prequisite for %1$s: Please install and/or activate <a href="%2$s" target="_blank">%3$s</a> on your site',
							Payment_Warning::plugin_slug
						),
						$e20r_pw_addons[ $stub ]['label'],
						$info['url'],
						$info['name']
					),
					'error',
					'backend'
				);
			}
		}
	}
	
	/**
	 * Process/Update all credit cards from gateway(s) for the User
	 *
	 * @param User_Data $user_data
	 * @param mixed     $card_data
	 * @param string    $gateway_name
	 *
	 * @return User_Data
	 */
	public function process_credit_card_info( User_Data $user_data, $card_data, $gateway_name ) {
		
		$util = Utilities::get_instance();
		$util->log( "Trigger process CC info filter for {$gateway_name}" );
		
		return apply_filters( 'e20r_pw_addon_process_cc_info', $user_data, $card_data, $gateway_name );
	}
	
	/**
	 * Save error info about mismatched gateway customer ID and email record(s).
	 *
	 * @action e20r_pw_addon_save_email_error_data - Action hook to save data mis-match between payment gateway & local
	 *         email address on file
	 *
	 * @param string $gateway_name
	 * @param string $gateway_cust_id
	 * @param string $gateway_email_addr
	 * @param string $local_email_addr
	 */
	public function save_email_error( $gateway_name, $gateway_cust_id, $gateway_email_addr, $local_email_addr ) {
		
		$metadata = array(
			'gateway_name'        => $gateway_name,
			'local_email_addr'    => $local_email_addr,
			'gateway_email_addr'  => $gateway_email_addr,
			'gateway_customer_id' => $gateway_cust_id,
		);
		
		$user_data        = get_user_by( 'email', $local_email_addr );
		$email_error_data = get_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', false );
		
		if ( false === $email_error_data ) {
			add_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', $metadata );
		} else {
			foreach ( $email_error_data as $current ) {
				
				if ( false !== $current && ! empty( $current['gateway_name'] ) && $this->gateway_name === $current['gateway_name'] ) {
					update_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', $metadata );
				} else if ( ! isset( $current['gateway_name'] ) || $this->gateway_name === $current['gateway_name'] ) {
					add_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', $metadata );
				}
			}
		}
	}
	
	/**
	 * Save error info about unexpected subscription entries on upstream gateway
	 *
	 * @action e20r_pw_addon_save_subscription_mismatch - Action hook to save subscription mis-match between payment
	 *         gateway & local data
	 *
	 * @param string       $gateway_name
	 * @param User_Data    $user_data
	 * @param \MemberOrder $member_order
	 * @param string       $gateway_subscription_id
	 *
	 * @return mixed
	 */
	public function save_subscription_mismatch( $gateway_name, $user_data, $member_order, $gateway_subscription_id ) {
		
		$util = Utilities::get_instance();
		
		$metadata = array(
			'gateway_name'     => $gateway_name,
			'local_subscr_id'  => $member_order->subscription_transaction_id,
			'remote_subscr_id' => $gateway_subscription_id,
		);
		
		$util->log( "Expected Subscription ID from upstream: {$member_order->subscription_transaction_id}, got something different ({$gateway_subscription_id})!" );
		
		$subscr_error_data = get_user_meta( $user_data->get_user_ID(), 'e20rpw_gateway_subscription_mismatched', false );
		$user_id           = $user_data->get_user_ID();
		
		if ( ! empty( $user_id ) && false === $subscr_error_data ) {
			add_user_meta( $user_id, 'e20rpw_gateway_subscription_mismatched', $metadata );
		} else {
			foreach ( $subscr_error_data as $current ) {
				
				if ( ! empty( $user_id ) && false !== $current && ! empty( $current['gateway_name'] ) && $gateway_name === $current['gateway_name'] ) {
					$util->log( "Updating existing subscription mismatch record for {$gateway_name}/{$user_id}" );
					update_user_meta( $user_id, 'e20rpw_gateway_subscription_mismatched', $metadata, $current );
				} else if ( $gateway_name === $current['gateway_name'] ) {
					$util->log( "Adding subscription mismatch record for {$gateway_name}/{$user_id}" );
					add_user_meta( $user_id, 'e20rpw_gateway_subscription_mismatched', $metadata );
				}
			}
		}
	}
	
	/**
	 * Core function: Verify that the user data has valid/expected gateway settings
	 *
	 * @param User_Data $user_data
	 * @param string    $stub
	 *
	 * @return bool
	 */
	public function verify_gateway_processor( User_Data $user_data, $stub, $gateway_name ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Processing gateway data fetch for {$gateway_name}" );
		
		if ( false === $this->is_active( $stub ) ) {
			$utils->log( "The {$stub} add-on is not active!" );
			
			return false;
		}
		
		$utils->log( "Local gateway name: {$gateway_name} vs " . $user_data->get_gateway_name() );
		
		if ( 1 !== preg_match( "/$gateway_name/i", $user_data->get_gateway_name() ) ) {
			$utils->log( "Not processing customer data for {$gateway_name}" );
			
			return false;
		}
		
		if ( empty( $this->current_gateway_type ) || $this->current_gateway_type != $user_data->get_user_gateway_type() ) {
			$utils->log( "The active gateway setting is {$this->current_gateway_type} vs the type the last order was performed on: " . $user_data->get_user_gateway_type() );
			$utils->log( "Skipping user with ID: " . $user_data->get_user_ID() );
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Determine whether the add-on is configured as active/licensed
	 *
	 * @param $stub
	 *
	 * @return mixed
	 */
	public function is_active( $stub ) {
		global $e20r_pw_addons;
		
		return $e20r_pw_addons[ $stub ]['is_active'];
	}
	
	/**
	 * Loading add-on specific handler for the Gateway Notifications (early handling to stay out of the way of PMPro
	 * itself)
	 *
	 * @param string $stub
	 */
	public function load_webhook_handler( $stub = null ) {
		
		global $e20r_pw_addons;
		
		if ( true === $this->is_active( $stub ) && ! empty( $e20r_pw_addons[$stub]['handler_name']) ) {
			
			$util = Utilities::get_instance();
			$util->log( "Loading {$stub} Webhook handler functions..." );
			
			$class_name = "E20R\\Payment_Warning\\Addon\\" . $e20r_pw_addons[ $stub ]['class_name'];
			$class      = $class_name::get_instance();
			
			add_action( "wp_ajax_nopriv_{$e20r_pw_addons[$stub]['handler_name']}", array(
				$class,
				'webhook_handler',
			), 5 );
			add_action( "wp_ajax_{$e20r_pw_addons[$stub]['handler_name']}", array( $class, 'webhook_handler' ), 5 );
		}
	}
	
	/**
	 * Action Hook: Enable/disable this add-on. Will clean up if we're being deactivated & configured to do so
	 *
	 * @action e20r_roles_addon_toggle_addon
	 *
	 * @param string $addon
	 * @param bool   $is_active
	 *
	 * @return bool
	 */
	public function toggle_addon( $addon, $is_active = false ) {
		
		global $e20r_pw_addons;
		
		$utils = Utilities::get_instance();
		
		$utils->log( "In toggle_addon action handler for the {$e20r_pw_addons[$addon]['label']} add-on" );
		
		if ( $is_active === false ) {
			
			$utils->log( "Deactivating the add-on so disable the license" );
			Licensing::deactivate_license( $addon );
		}
		
		if ( $is_active === false && true == $this->load_option( 'deactivation_reset' ) ) {
			
			// FixMe: Delete the option entry/entries from the Database
			
			$utils->log( "Deactivate the {$e20r_pw_addons[ $addon ]['label']}!" );
		}
		
		if ( true === $is_active && false === $e20r_pw_addons[ $addon ]['is_active'] ) {
			
			$e20r_pw_addons[ $addon ]['active_license'] = Licensing::is_licensed( $addon, true );
			
			if ( true !== $e20r_pw_addons[ $addon ]['active_license'] && is_admin() ) {
				$utils->add_message(
					sprintf(
						__(
							'The %1$s add-on is <strong>currently disabled!</strong><br/>Using it requires a license key. Please <a href="%2$s">add your license key</a>.',
							Payment_Warning::plugin_slug
						),
						$e20r_pw_addons[ $addon ]['label'],
						Licensing::get_license_page_url( $addon )
					),
					'error',
					'backend'
				);
			}
		}
		
		$e20r_pw_addons[ $addon ]['is_active'] = $is_active && $e20r_pw_addons[ $addon ]['active_license'];
		
		$e20r_pw_addons[ $addon ]['status'] = ( $e20r_pw_addons[ $addon ]['is_active'] ? 'active' : 'deactivated' );
		
		$utils->log( "Setting the {$addon} option to {$e20r_pw_addons[ $addon ]['status']}" );
		update_option( "e20r_pw_addon_{$addon}_enabled", $e20r_pw_addons[ $addon ]['is_active'], 'yes' );
		update_option( "e20r_pw_{$addon}_licensed", $e20r_pw_addons[ $addon ]['active_license'], 'no' );
		
		return $e20r_pw_addons[ $addon ]['is_active'];
	}
	
	/**
	 * Fetch the specific option.
	 *
	 * @param string $option_name
	 *
	 * @return bool
	 *
	 * @access  protected
	 * @version 1.0
	 */
	protected function load_option( $option_name ) {
		
		$options = get_option( "{$this->option_name}" );
		if ( isset( $options[ $option_name ] ) && ! empty( $options[ $option_name ] ) ) {
			
			return $options[ $option_name ];
		}
		
		return false;
	}
	
	/**
	 * Required Add-on class method: load_gateway()
	 *
	 * @param string $addon_name
	 *
	 * @return string|bool - Add-on name or false
	 */
	abstract public function load_gateway( $addon_name );
	
	/**
	 * Required Add-on class method: get_gateway_subscriptions()
	 *
	 * @param User_Data|bool $user_data
	 * @param string         $addon The payment gateway module we're processing for
	 *
	 * @return User_Data
	 */
	abstract public function get_gateway_subscriptions( $user_data, $addon );
	
	/**
	 * Required Add-on class method: get_gateway_payments()
	 *
	 * @param User_Data|bool $user_data
	 * @param string         $addon The payment gateway module we're processing for
	 *
	 * @return User_Data
	 */
	abstract public function get_gateway_payments( $user_data, $addon );
	
	/**
	 * Required Add-on class method: validate_settings()
	 *
	 * @param mixed $input
	 *
	 * @return mixed $validated
	 */
	abstract public function validate_settings( $input );
	
	/**
	 * Filter: Returns the add-on specific statuses for a payment gateway
	 *
	 * @param string[] $statuses
	 * @param string   $gateway
	 * @param string   $addon
	 *
	 * @return string[]
	 */
	abstract public function valid_gateway_subscription_statuses( $statuses, $gateway, $addon );
	
	/**
	 * Fetch the (current) Payment Gateway specific customer ID from the local Database
	 *
	 * @param string    $gateway_customer_id
	 * @param string    $gateway_name
	 * @param User_Data $user_info
	 *
	 * @return mixed
	 */
	abstract public function get_local_user_customer_id( $gateway_customer_id, $gateway_name, $user_info );
	
	/**
	 * Return the gateway name for the matching add-on
	 *
	 * @param null|string $gateway_name
	 * @param string      $addon
	 *
	 * @return null|string
	 */
	abstract public function get_gateway_class_name( $gateway_name = null, $addon );
	
	/**
	 * Return the class name w/o the Namespace portion
	 *
	 * @param $string
	 *
	 * @return string
	 *
	 * @version 2.1
	 */
	protected function maybe_extract_class_name( $string ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Supplied (potential) class name: {$string}" );
		
		$class_array = explode( '\\', $string );
		$name        = $class_array[ ( count( $class_array ) - 1 ) ];
		
		return $name;
	}
	
	/**
	 * Required Add-on class method: configure_menu();
	 * Add menu entries for the Add-on
	 *
	 * @param $menu_array
	 *
	 * @return array
	 */
	/*abstract public function configure_menu( $menu_array );*/ /* {
		
		$menu_array[] = array(
			'page_title' => __( 'Default Warning add-on', Payment_Warning::plugin_slug ),
			'menu_title' => __( 'Default Warning add-on', Payment_Warning::plugin_slug ),
			'capability' => 'manage_options',
			'menu_slug'  => 'e20r-pw-addon-default',
			'function'   => array( $this, 'generate_option_menu' ),
		);
		
		return $menu_array;
	}*/
}
