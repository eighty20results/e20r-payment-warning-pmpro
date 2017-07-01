<?php
/**
 * Copyright (c) $today.year. - Eighty / 20 Results by Wicked Strong Chicks.
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
use E20R\Payment_Warning\User_Data;
use E20R\Payment_Warning\Utilities\Cache;
use E20R\Payment_Warning\Utilities\Utilities;
use E20R\Licensing\Licensing;
use Stripe\Customer;
use Stripe\Stripe;
use Stripe\Subscription;

if ( ! class_exists( 'E20R\Payment_Warning\Addon\Stripe_Gateway_Addon' ) ) {
	
	if ( ! defined( 'DEBUG_STRIPE_KEY' ) ) {
		define( 'DEBUG_STRIPE_KEY', null );
	}
	
	class E20R_Stripe_Gateway_Addon extends E20R_PW_Gateway_Addon {
		
		const CACHE_GROUP = 'e20r_stripe_addon';
		
		/**
		 * The name of this class
		 *
		 * @var string
		 */
		private $class_name;
		
		/**
		 * @var E20R_Stripe_Gateway_Addon
		 */
		private static $instance;
		
		/**
		 * @var array
		 */
		private $gateway_sub_statuses = array();
		
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
		private $pmpro_gateway = array();
		
		/**
		 * @var null|\Stripe\Stripe
		 */
		private $gateway = null;
		
		/**
		 * @var array
		 */
		private $subscriptions = array();
		
		/**
		 * @var null|string
		 */
		private $gateway_name = null;
		
		/**
		 * @var bool
		 */
		protected $gateway_loaded = false;
		
		/**
		 * @var null|string $current_gateway_type Can be set to 'live' or 'sandbox' or null
		 */
		protected $current_gateway_type = null;
		
		/**
		 * Name of the WordPress option key
		 *
		 * @var string $option_name
		 */
		private $option_name = 'e20r_egwao_stripe';
		
		/**
		 *  E20R_Stripe_Gateway_Addon constructor.
		 */
		public function __construct() {
			
			parent::__construct();
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_stub_name' ) );
			
			if ( is_null( self::$instance ) ) {
				self::$instance = $this;
			}
			
			$this->class_name   = $this->maybe_extract_class_name( get_class( $this ) );
			$this->gateway_name = 'stripe';
			
			if ( function_exists( 'pmpro_getOption' ) ) {
				$this->current_gateway_type = pmpro_getOption( "gateway_environment" );
			}
			
			$this->define_settings();
		}
		
		/**
		 * Set the name of the add-on (using the class name as an identifier)
		 *
		 * @param null $name
		 *
		 * @return null|string
		 */
		public function set_stub_name( $name = null ) {
			
			$name = strtolower( $this->get_class_name() );
			
			return $name;
		}
		
		/**
		 * Get the add-on name
		 *
		 * @return string
		 */
		public function get_class_name() {
			
			if ( empty( $this->class_name ) ) {
				$this->class_name = $this->maybe_extract_class_name( get_class( self::$instance ) );
			}
			
			return $this->class_name;
		}
		
		private function maybe_extract_class_name( $string ) {
			
			$utils = Utilities::get_instance();
			$utils->log( "Supplied (potential) class name: {$string}" );
			
			$class_array = explode( '\\', $string );
			$name        = $class_array[ ( count( $class_array ) - 1 ) ];
			
			return $name;
		}
		
		/**
		 * Filter Handler: Add the 'add bbPress add-on license' settings entry
		 *
		 * @filter e20r-license-add-new-licenses
		 *
		 * @param array $settings
		 *
		 * @return array
		 */
		public function add_new_license_info( $settings ) {
			
			global $e20r_pw_addons;
			
			$utils = Utilities::get_instance();
			
			if ( ! isset( $settings['new_licenses'] ) ) {
				$settings['new_licenses'] = array();
				$utils->log( "Init array of licenses entry" );
			}
			
			$stub = strtolower( $this->get_class_name() );
			$utils->log( "Have " . count( $settings['new_licenses'] ) . " new licenses to process already. Adding {$stub}... " );
			
			$settings['new_licenses'][ $stub ] = array(
				'label_for'     => $stub,
				'fulltext_name' => $e20r_pw_addons[ $stub ]['label'],
				'new_product'   => $stub,
				'option_name'   => "e20r_license_settings",
				'name'          => 'license_key',
				'input_type'    => 'password',
				'value'         => null,
				'email_field'   => "license_email",
				'email_value'   => null,
				'placeholder'   => sprintf( __( "Paste the purchased %s key here", "e20r-licensing" ), $e20r_pw_addons[ $stub ]['label'] ),
			);
			
			return $settings;
		}
		
		
		/**
		 * Action handler: Core E20R Payment Warnings plugin activation hook
		 *
		 * @action e20r_pw_addon_activating_core
		 *
		 * @access public
		 * @since  1.0
		 */
		public function activate_addon() {
			
			$util = Utilities::get_instance();
			$util->log( "Triggering Plugin activation actions for: Stripe Payment Gateway add-on" );
			
			// FixMe: Any and all activation activities that are add-on specific
			return;
		}
		
		
		/**
		 * Action handler: Core E20R Payment Warnings plugin deactivation hook
		 *
		 * @action e20r_pw_addon_deactivating_core
		 *
		 * @param bool $clear
		 *
		 * @access public
		 * @since  1.0
		 */
		public function deactivate_addon( $clear = false ) {
			
			$util = Utilities::get_instance();
			if ( true == $clear ) {
				
				// FixMe: Delete all option entries from the Database for this paymnt gateway add-on
				$util->log( "Deactivate the add-on specific settings for the Stripe Payment Gateway" );
			}
		}
		
		/**
		 * Loads the default settings (keys & values)
		 *
		 * TODO: Specify settings for this add-on
		 *
		 * @return array
		 *
		 * @access private
		 * @since  1.0
		 */
		private function load_defaults() {
			
			return array(
				'stripe_api_version' => '2017-07-17',
				'deactivation_reset' => false,
			);
			
		}
		
		/**
		 * Load the saved options, or generate the default settings
		 */
		protected function define_settings() {
			
			parent::define_settings();
			
			$this->settings = get_option( $this->option_name, $this->load_defaults() );
			$defaults       = $this->load_defaults();
			
			foreach ( $defaults as $key => $dummy ) {
				$this->settings[ $key ] = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $defaults[ $key ];
			}
		}
		
		/**
		 * Action Hook: Enable/disable this add-on. Will clean up if we're being deactivated & configured to do so
		 *
		 * @action e20r_pw_addon_toggle_addon
		 *
		 * @param string $addon
		 * @param bool   $is_active
		 *
		 * @return bool
		 */
		public function toggle_addon( $addon, $is_active = false ) {
			
			global $e20r_pw_addons;
			
			$self = strtolower( $this->get_class_name() );
			
			if ( $self !== $addon ) {
				return $is_active;
			}
			
			$utils = Utilities::get_instance();
			$utils->log( "In toggle_addon action handler for the {$e20r_pw_addons[$addon]['label']} add-on" );
			
			$expected_stub = strtolower( $this->get_class_name() );
			
			if ( $expected_stub !== $addon ) {
				$utils->log( "Not processing the {$e20r_pw_addons[$addon]['label']} add-on: {$addon}" );
				
				return;
			}
			
			if ( $is_active === false ) {
				
				$utils->log( "Deactivating the add-on so disable the license" );
				Licensing::deactivate_license( $addon );
			}
			
			if ( $is_active === false && true == $this->load_option( 'deactivation_reset' ) ) {
				
				// TODO: During add-on deactivation, remove all capabilities for levels & user(s)
				// FixMe: Delete the option entry/entries from the Database
				
				$utils->log( "Deactivate the capabilities for all levels & all user(s)!" );
			}
			
			$e20r_pw_addons[ $addon ]['is_active'] = $is_active;
			
			$utils->log( "Setting the {$addon} option to {$is_active}" );
			update_option( "e20r_pw_addon_{$addon}_enabled", $is_active, true );
		}
		
		/**
		 * Load the specific option from the option array
		 *
		 * @param string $option_name
		 *
		 * @return bool
		 */
		public function load_option( $option_name ) {
			
			$this->settings = get_option( "{$this->option_name}" );
			
			if ( isset( $this->settings[ $option_name ] ) && ! empty( $this->settings[ $option_name ] ) ) {
				
				return $this->settings[ $option_name ];
			}
			
			return false;
			
		}
		
		/**
		 * Load add-on actions/filters when the add-on is active & enabled
		 *
		 * @param string $stub Lowercase Add-on class name
		 */
		final public static function is_enabled( $stub ) {
			
			$utils = Utilities::get_instance();
			global $e20r_pw_addons;
			
			// TODO: Set the filter name to match the sub for this plugin.
			$utils->log( "Running for {$stub}" );
			/**
			 * Toggle ourselves on/off, and handle any deactivation if needed.
			 */
			add_action( 'e20r_pw_addon_toggle_addon', array( self::get_instance(), 'toggle_addon' ), 10, 2 );
			add_action( 'e20r_pw_addon_activating_core', array( self::get_instance(), 'activate_addon', ), 10, 0 );
			add_action( 'e20r_pw_addon_deactivating_core', array( self::get_instance(), 'deactivate_addon', ), 10, 1 );
			
			/**
			 * Configuration actions & filters
			 */
			add_filter( 'e20r-license-add-new-licenses', array(
				self::get_instance(),
				'add_new_license_info',
			), 10, 1 );
			add_filter( "e20r_pw_addon_options_{$e20r_pw_addons[$stub]['class_name']}", array(
				self::get_instance(),
				'register_settings',
			), 10, 1 );
			
			if ( true === parent::is_enabled( $stub ) ) {
				
				$utils->log( "{$e20r_pw_addons[$stub]['label']} active: Loading add-on specific actions/filters" );
				
				/**
				 * Membership related settings for role(s) add-on
				 */
				/*
				add_action( 'e20r_pw_level_settings', array( self::get_instance(), 'load_level_settings' ), 10, 2 );
				add_action( 'e20r_pw_level_settings_save', array(
					self::get_instance(),
					'save_level_settings',
				), 10, 2 );
				add_action( 'e20r_pw_level_settings_delete', array(
					self::get_instance(),
					'delete_level_settings',
				), 10, 2 );
				*/
				
				// Add-on specific filters and actions
				add_action( 'e20r_pw_addon_load_gateway', array( self::get_instance(), 'load_gateway' ), 10, 0 );
				add_action( 'e20r_pw_addon_save_email_error_data', array(
					self::get_instance(),
					'save_email_error',
				), 10, 4 );
				add_action( 'e20r_pw_addon_save_subscription_mismatch', array(
					self::get_instance(),
					'save_subscription_mismatch',
				), 10, 4 );
				add_filter( 'e20r_pw_addon_get_user_subscriptions', array(
					self::get_instance(),
					'get_gateway_subscriptions',
				), 10, 1 );
				
				add_filter( 'e20r_pw_addon_get_user_payments', array(
					self::get_instance(),
					'get_gateway_payments',
				), 10, 1 );
				
				
				add_filter( 'e20r_pw_addon_get_user_customer_id', array(
					self::get_instance(),
					'get_local_user_customer_id',
				), 10, 3 );
				
				add_filter( 'e20r_pw_addon_gateway_subscr_statuses', array(
					self::get_instance(),
					'valid_stripe_subscription_statuses',
				), 10, 2 );
				
				add_filter( 'e20r_pw_addon_process_cc_info', array(
					self::get_instance(),
					'update_credit_card_info',
				), 10, 3 );
				
				if ( WP_DEBUG ) {
					add_action( 'wp_ajax_test_get_gateway_subscriptions', array(
						self::get_instance(),
						'test_gateway_subscriptions',
					) );
				}
			}
		}
		
		/**
		 * Save error info about mismatched gateway customer ID and email record(s).
		 *
		 * @action e20r_pw_addon_save_email_error_data - Action hook to save data mis-match between payment gateway & local email address on file
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
					
					if ( false !== $current && ! empty( $current['gateway_name'] ) && $gateway_name === $current['gateway_name'] ) {
						update_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', $metadata );
					} else if ( $gateway_name === $current['gateway_name'] ) {
						add_user_meta( $user_data->ID, 'e20rpw_gateway_email_mismatched', $metadata );
					}
				}
			}
		}
		
		/**
		 * Save error info about unexpected subscription entries on upstream gateway
		 *
		 * @action e20r_pw_addon_save_subscription_mismatch - Action hook to save subscription mis-match between payment gateway & local data
		 *
		 * @param string       $gateway_name
		 * @param User_Data    $user_data
		 * @param \MemberOrder $member_order
		 * @param Subscription $gateway_subscription_data
		 *
		 * @return mixed
		 */
		public function save_subscription_mismatch( $gateway_name, $user_data, $member_order, $gateway_subscription_data ) {
			
			$util = Utilities::get_instance();
			
			$metadata = array(
				'gateway_name'     => $gateway_name,
				'local_subscr_id'  => $member_order->subscription_transaction_id,
				'remote_subscr_id' => $gateway_subscription_data->id,
			);
			
			$util->log( "Expected Subscription ID from upstream: {$member_order->subscription_transaction_id}, got something different ({$gateway_subscription_data->id})!" );
			
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
		 * Return the array of supported subscription statuses to capture data about
		 *
		 * @param array  $statuses Array of valid gateway statuses
		 * @param string $gateway  The gateway name we're processing for
		 *
		 * @return array
		 */
		public function valid_stripe_subscription_statuses( $statuses, $gateway ) {
			
			if ( $gateway === $this->gateway_name ) {
				
				$statuses = array( 'trialing', 'active', 'unpaid', 'past_due', );
			}
			
			return $statuses;
		}
		
		/**
		 * Fetch the (current) Payment Gateway specific customer ID from the local Database
		 *
		 * @param string    $gateway_customer_id
		 * @param string    $gateway_name
		 * @param User_Data $user_info
		 *
		 * @return mixed
		 */
		public function get_local_user_customer_id( $gateway_customer_id, $gateway_name, $user_info ) {
			
			$util = Utilities::get_instance();
			
			// Don't run this action handler (unexpected gateway name)
			if ( $gateway_name != $this->gateway_name ) {
				$util->log( "Specified gateway name doesn't match this add-on's gateway: {$gateway_name} vs {$this->gateway_name}. Returning: {$gateway_customer_id}" );
				
				return $gateway_customer_id;
			}
			
			$gateway_customer_id = get_user_meta( $user_info->get_user_ID(), 'pmpro_stripe_customerid', true );
			$util->log( "Located Stripe user ID: {$gateway_customer_id} for WP User " . $user_info->get_user_ID() );
			
			return $gateway_customer_id;
		}
		
		/**
		 * Do what's required to make Stripe libraries visible/active
		 */
		private function load_stripe_libs() {
			
			$this->pmpro_gateway = new \PMProGateway_stripe();
			$this->pmpro_gateway->loadStripeLibrary();
			$this->gateway_loaded = true;
			
		}
		
		/**
		 * Load the payment gateway specific class/code/settings from PMPro
		 */
		public function load_gateway() {
			
			$util = Utilities::get_instance();
			
			// This will load the Stripe/PMPro Gateway class _and_ its library(ies)
			$util->log( "PMPro loaded? " . ( defined( 'PMPRO_VERSION' ) ? 'Yes' : 'No' ) );
			$util->log( "PMPro Stripe gateway loaded? " . ( class_exists( "\PMProGateway_stripe" ) ? 'Yes' : 'No' ) );
			$util->log( "Stripe Class(es) loaded? " . ( class_exists( 'Stripe\Stripe' ) ? 'Yes' : 'No' ) );
			
			if ( defined( 'PMPRO_VERSION' ) && class_exists( "\PMProGateway_stripe" ) && class_exists( 'Stripe\Stripe' ) && false === $this->gateway_loaded ) {
				$util->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_stripe_libs();
				
			} else {
				$util->log( "Egad! Stripe library is missing/not loaded!!!" );
				$this->load_stripe_libs();
			}
			
			try {
				
				if ( defined( 'DEBUG_STRIPE_KEY' ) && '' != DEBUG_STRIPE_KEY ) {
					
					$util->log( "Using Test Key for Stripe API" );
					$api_key = DEBUG_STRIPE_KEY;
				} else {
					$util->log( "Using PMPro specified Key for Stripe API" );
					$api_key = pmpro_getOption( 'stripe_secretkey' );
				}
				
				Stripe::setApiKey( $api_key );
				
				$api_version = $this->load_option( 'stripe_api_version' );
				
				// Not configured locally, so using whatever the Dashboard is configured for.
				if ( empty( $api_version ) ) {
					$api_version = Stripe::getApiVersion();
					$util->log( "Having to fetch the upstream API version to use: {$api_version}" );
				}
				
				$util->log( "Using Stripe API Version: {$api_version}" );
				
				// Configure Stripe API call version
				Stripe::setApiVersion( $api_version );
				
				return true;
			} catch ( \Exception $e ) {
				
				$utils = Utilities::get_instance();
				$utils->add_message( sprintf( __( 'Unable to load the Stripe.com Payment Gateway settings of PMPro (%s)', Payment_Warning::plugin_slug ), $e->getMessage() ), 'error', 'backend' );
				
				return false;
			}
		}
		
		/**
		 * Configure the subscription information for the user data for the current Payment Gateway
		 *
		 * @param User_Data $user_data The User_Data record to process for
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_subscriptions( User_Data $user_data ) {
			
			$utils = Utilities::get_instance();
			$stub  = strtolower( $this->get_class_name() );
			$data  = null;
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway for this plugin" );
				
				return false;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_stripe_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			
			try {
				
				$utils->log( "Accessing Stripe API service for {$cust_id}" );
				$data = Customer::retrieve( $cust_id, array( 'include' => array( 'total_count' ) ) );
				
			} catch ( \Exception $exeption ) {
				
				$utils->log( "Error fetching customer data: " . $exeption->getMessage() );
				$utils->add_message( sprintf( __( "Unable to fetch Stripe.com data for %s", Payment_Warning::plugin_slug ), $user_data->get_user_email() ), 'warning', 'backend' );
				
				$user_data->set_active_subscription( false );
				
				return false;
			}
			
			$user_email = $user_data->get_user_email();
			
			$utils->log( "All available Stripe subscription data collected for {$cust_id} -> {$user_email}" );
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $data->email ) && $user_email !== $data->email ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email Stripe account {$data->email} doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $data->email, $user_email );
				
				return false;
			}
			
			// $utils->log( "Retrieved customer data: " . print_r( $data, true ) );
			
			$utils->log( "Loading most recent local PMPro order info" );
			
			$local_order     = $user_data->get_last_pmpro_order();
			$stripe_statuses = apply_filters( 'e20r_pw_addon_gateway_subscr_statuses', array(), $this->gateway_name );
			
			$user_data->add_subscription_list( $data->subscriptions->data );
			
			// Iterate through subscription plans on Stripe.com & fetch required date info
			foreach ( $data->subscriptions->data as $subscription ) {
				
				$user_data->set_active_subscription( true );
				
				if ( $subscription->id == $local_order->subscription_transaction_id && in_array( $subscription->status, $stripe_statuses ) ) {
					
					
					$utils->log( "Processing {$subscription->id} for customer ID {$cust_id}" );
					
					if ( empty( $subscription->cancel_at_period_end ) && empty( $subscription->cancelled_at ) && in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$utils->log( "Setting payment status to 'active' for {$cust_id}" );
						$user_data->set_payment_status( 'active' );
					}
					
					if ( ! empty( $subscription->cancel_at_period_end ) || ! empty( $subscription->cancelled_at ) || ! in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$utils->log( "Setting payment status to 'stopped' for {$cust_id}" );
						$user_data->set_payment_status( 'stopped' );
					}
					
					// Set the date for the next payment
					if ( $user_data->get_payment_status() === 'active' ) {
						
						// Get the date when the currently paid for period ends.
						$current_payment_until = date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end );
						$user_data->set_end_of_paymentperiod( $current_payment_until );
						$utils->log( "End of the current payment period: {$current_payment_until}" );
						
						// Add a day (first day of new payment period)
						$payment_next = date_i18n( 'Y-m-d H:i:s', ( $subscription->current_period_end + 1 ) );
						$user_data->set_next_payment( $payment_next );
						$utils->log( "Next payment on: {$payment_next}" );
						
						global $pmpro_currencies;
						$plan_currency = ! empty( $subscription->plan->currency ) ? strtoupper( $subscription->plan->currency ) : 'USD';
						$user_data->set_payment_currency( $plan_currency );
						
						$utils->log( "Payments are made in: {$plan_currency}" );
						$has_decimals = true;
						
						if ( isset( $pmpro_currencies[ $plan_currency ]['decimals'] ) ) {
							
							// Is this for a no-decimal currency?
							if ( 0 === $pmpro_currencies[ $plan_currency ]['decimals'] ) {
								$utils->log( "The specified currency ({$plan_currency}) doesn't use decimal points" );
								$has_decimals = false;
							}
						}
						
						// Get the amount & cast it to a floating point value
						$amount = number_format_i18n( ( (float) ( $has_decimals ? ( $subscription->plan->amount / 100 ) : $subscription->plan->amount ) ), ( $has_decimals ? 2 : 0 ) );
						$user_data->set_next_payment_amount( $amount );
						$utils->log( "Next payment will be scheduled for {$plan_currency} {$amount} within 24 hours of {$payment_next}" );
						
						$user_data->set_reminder_type( 'recurring' );
					} else {
						
						$utils->log( "Subscription payment plan is going to end after: " . date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end + 1 ) );
						$user_data->set_subscription_end();
					}
					
					$utils->log( "Attempting to load credit card (payment method) info from gateway" );
					// Trigger handler for credit card data
					$user_data = $this->process_credit_card_info( $user_data, $data->sources->data, $this->gateway_name );
					
				} else {
					$utils->log( "Mismatch between expected (local) subscription ID {$local_order->subscription_transaction_id} and remote ID {$subscription->id}" );
					/**
					 * @action e20r_pw_addon_save_subscription_mismatch
					 *
					 * @param string       $this->gateway_name
					 * @param User_Data    $user_data
					 * @param \MemberOrder $local_order
					 * @param Subscription $subscription
					 */
					do_action( 'e20r_pw_addon_save_subscription_mismatch', $this->gateway_name, $user_data, $local_order, $subscription );
				}
			}
			
			$utils->log( "Returning possibly updated user data to calling function" );
			
			return $user_data;
		}
		
		/**
		 * Configure Charges (one-time charges) for the user data from the specified payment gateway
		 *
		 * @param User_Data $user_data User data to update/process
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_payments( User_Data $user_data ) {
			
			$utils = Utilities::get_instance();
			$stub  = strtolower( $this->get_class_name() );
			$data  = null;
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway for this plugin" );
				
				return false;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_stripe_libs();
			}
			
			// $user_data->set_reminder_type( 'expiration' );
			
			return $user_data;
		}
		
		/**
		 * Filter handler for upstream user Credit Card data...
		 *
		 * @filter e20r_pw_addon_process_cc_info
		 *
		 * @param           $card_data
		 * @param User_Data $user_data
		 * @param           $gateway_name
		 *
		 * @return User_Data
		 */
		public function update_credit_card_info( User_Data $user_data, $card_data, $gateway_name ) {
			
			$utils = Utilities::get_instance();
			
			if ( $gateway_name !== $this->gateway_name ) {
				$utils->log( "Not processing data from the {$this->gateway_name} gateway. Returning..." );
				
				return $user_data;
			}
			
			// $user_data->clear_card_info( $user_data->get_user_ID() );
			
			// Add payment information expiration data
			foreach ( $card_data as $payment_source ) {
				
				// Only applies for cards
				if ( $payment_source->object === 'card' ) {
					
					$month = sprintf( "%02d", $payment_source->exp_month );
					$utils->log( "Adding {$payment_source->brand} ( {$payment_source->last4} ) with {$month}/{$payment_source->exp_year}" );
					$user_data->add_card( $payment_source->brand, $payment_source->last4, $month, $payment_source->exp_year );
				}
			}
			
			return $user_data;
		}
		
		/**
		 * Append this add-on to the list of configured & enabled add-ons
		 *
		 * @param array $addons
		 */
		public function configure_addon( $addons ) {
			
			$class = self::get_instance();
			$name  = strtolower( $class->get_class_name() );
			
			parent::is_enabled( $name );
		}
		
		/**
		 * Configure the settings page/component for this add-on
		 *
		 * @param array $settings
		 *
		 * @return array
		 */
		public function register_settings( $settings = array() ) {
			
			$utils = Utilities::get_instance();
			
			$settings['setting'] = array(
				'option_group'        => "{$this->option_name}_settings",
				'option_name'         => "{$this->option_name}",
				'validation_callback' => array( $this, 'validate_settings' ),
			);
			
			$utils->log( " Loading settings for..." . print_r( $settings, true ) );
			
			$settings['section'] = array(
				array(
					'id'              => 'e20rpw_addon_stripe_global',
					'label'           => __( "Stripe Gateway Settings", Payment_Warning::plugin_slug ),
					'render_callback' => array( $this, 'render_settings_text' ),
					'fields'          => array(
						array(
							'id'              => 'primary_gateway',
							'label'           => __( "Primary Payment Gateway", Payment_Warning::plugin_slug ),
							'render_callback' => array( $this, 'render_select' ),
						),
						array(
							'id'              => 'deactivation_reset',
							'label'           => __( "Clean up on Deactivate", Payment_Warning::plugin_slug ),
							'render_callback' => array( $this, 'render_cleanup' ),
						),
					),
				),
			);
			
			return $settings;
		}
		
		/**
		 * Checkbox for the role/capability cleanup option on the global settings page
		 */
		public function render_cleanup() {
			
			$cleanup = $this->load_option( 'deactivation_reset' );
			?>
            <input type="checkbox" id="<?php esc_attr_e( $this->option_name ); ?>-deactivation_reset"
                   name="<?php esc_attr_e( $this->option_name ); ?>[deactivation_reset]"
                   value="1" <?php checked( 1, $cleanup ); ?> />
			<?php
		}
		
		/**
		 * Validate the option responses before saving them
		 *
		 * @param mixed $input
		 *
		 * @return mixed $validated
		 */
		public function validate_settings( $input ) {
			
			if ( WP_DEBUG ) {
				error_log( "Input for save in Stripe_Gateway_Addon:: " . print_r( $input, true ) );
			}
			
			$defaults = $this->load_defaults();
			
			foreach ( $defaults as $key => $value ) {
				
				if ( false !== stripos( 'level_settings', $key ) && isset( $input[ $key ] ) ) {
					
					foreach ( $input['level_settings'] as $level_id => $settings ) {
						
						// TODO: Add level specific capabilities
					}
					
				} else if ( isset( $input[ $key ] ) ) {
					
					$this->settings[ $key ] = $input[ $key ];
				} else {
					$this->settings[ $key ] = $defaults[ $key ];
				}
				
			}
			
			if ( WP_DEBUG ) {
				error_log( "Stripe_Gateway_Addon saving " . print_r( $this->settings, true ) );
			}
			
			return $this->settings;
		}
		
		/**
		 * Informational text about the bbPress Role add-on settings
		 */
		public function render_settings_text() {
			?>
            <p class="e20r-example-global-settings-text">
				<?php _e( "Configure global settings for the E20R Payment Warnings: Stripe Gateway add-on", Payment_Warning::plugin_slug ); ?>
            </p>
			<?php
		}
		
		/**
		 * Display the select option for the "Allow anybody to read forum posts" global setting (select)
		 */
		public function render_select() {
			
			$utils = Utilities::get_instance();
			
			$primary_gateway = $this->load_option( 'primary_gateway' );
			$utils->log( "Setting for Stripe gateway: {$primary_gateway}" );
			?>
            <select name="<?php esc_attr_e( $this->option_name ); ?>[primary_gateway]"
                    id="<?php esc_attr_e( $this->option_name ); ?>_primary_gateway">
                <option value="0" <?php selected( $primary_gateway, 0 ); ?>>
					<?php _e( 'Disabled', Payment_Warning::plugin_slug ); ?>
                </option>
				<?php
				$all_gateways = pmpro_gateways();
				
				foreach ( $all_gateways as $gw ) {
					?>
                    <option value="<?php esc_attr_e( $gw ); ?>" <?php selected( $primary_gateway, $gw ); ?>>
						<?php echo ucwords( $gw ); ?>
                    </option>
				<?php } ?>
            </select>
			<?php
		}
		
		/**
		 * Fetch the properties for the Stripe Gateway add-on class
		 *
		 * @return E20R_Stripe_Gateway_Addon
		 *
		 * @since  1.0
		 * @access public
		 */
		public static function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				
				self::$instance = new self;
			}
			
			return self::$instance;
		}
	}
}

add_filter( "e20r_pw_addon_e20r_stripe_gateway_addon_name", array(
	E20R_Stripe_Gateway_Addon::get_instance(),
	'set_stub_name',
) );

// Configure the add-on (global settings array)
global $e20r_pw_addons;
$stub = apply_filters( "e20r_pw_addon_e20r_stripe_gateway_addon_name", null );

$e20r_pw_addons[ $stub ] = array(
	'class_name'            => 'E20R_Stripe_Gateway_Addon',
	'is_active'             => true,
	//( get_option( "e20r_pw_addon_{$stub}_enabled", false ) == 1 ? true : false ),
	'active_license'        => true,
	// ( get_option( "e20r_pw_addon_{$stub}_licensed", false ) == true ? true : false ),
	'status'                => 'active',
	'label'                 => 'Payment Warnings: Stripe',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);