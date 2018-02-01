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

use Braintree\Exception;
use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Cache;
use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;
use Stripe\Account;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Error\Api;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\Subscription;

if ( ! class_exists( 'E20R\Payment_Warning\Addon\Stripe_Gateway_Addon' ) ) {
	
	if ( ! defined( 'DEBUG_STRIPE_KEY' ) ) {
		define( 'DEBUG_STRIPE_KEY', null );
	}
	
	class Stripe_Gateway_Addon extends E20R_PW_Gateway_Addon {
		
		const CACHE_GROUP = 'e20r_stripe_addon';
		
		/**
		 * The name of this class
		 *
		 * @var string
		 */
		protected $class_name;
		
		/**
		 * @var Stripe_Gateway_Addon
		 */
		private static $instance;
		
		/**
		 * @var array
		 */
		protected $gateway_sub_statuses = array();
		
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
		protected $pmpro_gateway = array();
		
		/**
		 * @var null|\Stripe\Stripe
		 */
		protected $gateway = null;
		
		/**
		 * @var array
		 */
		protected $subscriptions = array();
		
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
		 * Name of the WordPress option key
		 *
		 * @var string $option_name
		 */
		protected $option_name = 'e20r_egwao_stripe';
		
		/**
		 * Return the array of supported subscription statuses to capture data about
		 *
		 * @param string[]  $statuses Array of valid gateway statuses
		 * @param string $gateway  The gateway name we're processing for
		 * @param string $addon
		 *
		 * @return array
		 */
		public function valid_gateway_subscription_statuses( $statuses, $gateway, $addon ) {
			
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
			$stub = apply_filters( 'e20r_pw_addon_stripe_gateway_addon_name', null );
			
			if ( false === $this->verify_gateway_processor( $user_info, $stub, $this->gateway_name ) ) {
				$util->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $gateway_customer_id;
			}
			
			// Don't run this action handler (unexpected gateway name)
			if ( 1 !== preg_match( "/{$this->gateway_name}/i", $gateway_name ) ) {
				$util->log( "Specified gateway name doesn't match tihis add-on's gateway: {$gateway_name} vs {$this->gateway_name}. Returning: {$gateway_customer_id}" );
				
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
		 *
		 * @param string $addon_name
		 *
		 * @return bool
		 */
		public function load_gateway( $addon_name ) {
			
			$util = Utilities::get_instance();
			
			if ( $addon_name !== 'stripe' ) {
				$util->log("Not processing for this addon (stripe): {$addon_name}" );
				return false;
			}
			
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
				
				if ( empty( $this->gateway_timezone ) ) {
					
					$account = Account::retrieve();
					
					if ( ! empty( $account ) ) {
						$util->log( "Using the Stripe.com Gateway timezone info" );
						$this->gateway_timezone = $account->timezone;
					} else {
						$util->log( "Using the default WordPress instance timezone info" );
						// Default to the same TZ as the WordPress server has.
						$this->gateway_timezone = get_option( 'timezone_string' );
					}
					
					$util->log( "Using {$this->gateway_timezone} as the timezone value" );
				}
				
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
		 * @param User_Data|bool $user_data The User_Data record to process for
		 * @param string $addon The addon we're processing for
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_subscriptions( $user_data, $addon ) {
			
			$utils = Utilities::get_instance();
			$stub  = apply_filters( "e20r_pw_addon_stripe_gateway_addon_name", null );
			$data  = null;
			
			if ( 1 !== preg_match( "/stripe/i", $addon ) ) {
				$utils->log("While in the Stripe module, the system asked to process for {$addon}");
				return $user_data;
			}
			
			if ( empty( $user_data )) {
				$utils->log("Error: No user data received!" );
				return $user_data;
			}
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway licence for the add-on" );
				
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
				$data = Customer::retrieve( $cust_id );
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer data: " . $exception->getMessage() );
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
			$stripe_statuses = apply_filters( 'e20r_pw_addon_subscr_statuses', array(), $this->gateway_name, $addon );
			
			// $user_data->add_subscription_list( $data->subscriptions->data );
			
			// Iterate through subscription plans on Stripe.com & fetch required date info
			foreach ( $data->subscriptions->data as $subscription ) {
				
				$payment_next  = date( 'Y-m-d H:i:s', ( $subscription->current_period_end + 1 ) );
				$already_saved = $user_data->has_subscription_id( $subscription->id );
				$saved_next    = $user_data->get_next_payment( $subscription->id );
				
				$utils->log( "Using {$payment_next} for payment_next and saved_next: {$saved_next}" );
				$utils->log( "Stored subscription ID? " . ( $already_saved ? 'Yes' : 'No' ) );
				
				/*if ( true === $already_saved && $payment_next == $saved_next ) {
					
					$utils->log( "Have a current version of the upstream subscription record. No need to process!" );
					continue;
				}
				*/
				$user_data->set_gw_subscription_id( $subscription->id );
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
						$utils->log( "Setting payment status to 'stopped' for {$cust_id}/" . $user_data->get_user_ID() );
						$user_data->set_payment_status( 'stopped' );
					}
					
					// Set the date for the next payment
					if ( $user_data->get_payment_status() === 'active' ) {
						
						// Get the date when the currently paid for period ends.
						$current_payment_until = date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end );
						$user_data->set_end_of_paymentperiod( $current_payment_until );
						$utils->log( "End of the current payment period: {$current_payment_until}" );
						
						// Add a day (first day of new payment period)
						
						$user_data->set_next_payment( $payment_next );
						$utils->log( "Next payment on: {$payment_next}" );
						
						$plan_currency = ! empty( $subscription->plan->currency ) ? strtoupper( $subscription->plan->currency ) : 'USD';
						$user_data->set_payment_currency( $plan_currency );
						
						$utils->log( "Payments are made in: {$plan_currency}" );
						
						$amount = $utils->amount_by_currency( $subscription->plan->amount, $plan_currency );
						$user_data->set_next_payment_amount( $amount );
						
						$utils->log( "Next payment of {$plan_currency} {$amount} will be charged within 24 hours of {$payment_next}" );
						
						$user_data->set_reminder_type( 'recurring' );
					} else {
						
						$utils->log( "Subscription payment plan is going to end after: " . date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end + 1 ) );
						$user_data->set_subscription_end();
						
						$ends = date( 'Y-m-d H:i:s', $subscription->current_period_end );
						
						$utils->log( "Setting end of membership to {$ends}" );
						$user_data->set_end_of_membership_date( $ends );
					}
					
					$utils->log( "Attempting to load credit card (payment method) info from gateway" );
					
					// Trigger handler for credit card data
					$user_data = $this->process_credit_card_info( $user_data, $data->sources->data, $this->gateway_name );
					
					// Save the processing module for this record
					$user_data->set_module( $addon );
					
				} else {
					
					$utils->log( "Mismatch between expected (local) subscription ID {$local_order->subscription_transaction_id} and remote ID {$subscription->id}" );
					/**
					 * @action e20r_pw_addon_save_subscription_mismatch
					 *
					 * @param string       $this ->gateway_name
					 * @param User_Data    $user_data
					 * @param \MemberOrder $local_order
					 * @param Subscription $subscription
					 */
					do_action( 'e20r_pw_addon_save_subscription_mismatch', $this->gateway_name, $user_data, $local_order, $subscription->id );
					
				}
			}
			
			$utils->log( "Returning possibly updated user data to calling function" );
			
			return $user_data;
		}
		
		/**
		 * Configure Charges (one-time charges) for the user data from the specified payment gateway
		 *
		 * @param User_Data|bool $user_data User data to update/process
		 * @param string $addon The addon we're processing for
		 *
		 * @return bool|User_Data
		 */
		public function get_gateway_payments( $user_data, $addon ) {
			
			$utils = Utilities::get_instance();
			$stub  = apply_filters( 'e20r_pw_addon_stripe_gateway_addon_name', null );
			$data  = null;
			
			if ( 1 !== preg_match( "/stripe/i", $addon ) ) {
				$utils->log("While in the Stripe module, the system asked to process for {$addon}");
				return $user_data;
			}
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway addon licence for the Stripe add-on" );
				
				return $user_data;
			}
			
			if ( $user_data->get_gateway_name() !== $this->gateway_name ) {
				$utils->log("Gateway name: {$this->gateway_name} vs " . $user_data->get_gateway_name() );
				return $user_data;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_stripe_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			$user_data->set_active_subscription( false );
			
			$last_order    = $user_data->get_last_pmpro_order();
			$last_order_id = ! empty( $last_order->payment_transaction_id ) ? $last_order->payment_transaction_id : null;
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			
			if ( empty( $last_order_id ) ) {
				$utils->log( "Unexpected: There's no Transaction ID for " . $user_data->get_user_ID() . " / {$cust_id}" );
				
				return false;
			}
			
			try {
				
				$utils->log( "Accessing Stripe API service for {$cust_id}" );
				$customer = Customer::retrieve( $cust_id );
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer data: " . $exception->getMessage() );
				
				// $utils->add_message( sprintf( __( "Unable to fetch Stripe.com data for %s", Payment_Warning::plugin_slug ), $user_data->get_user_email() ), 'warning', 'backend' );
				
				return false;
			}
			
			if ( $customer->subscriptions->total_count > 0 ) {
				
				$utils->log( "User ID ({$cust_id}) on stripe.com has {$customer->subscriptions->total_count} subscription plans. Skipping payment/charge processing" );
				
				return false;
			}
			
			$user_email = $user_data->get_user_email();
			
			// Last order was of the Stripe Invoice type
			if ( ! empty( $last_order_id ) && false !== strpos( $last_order_id, 'in_' ) ) {
				
				$utils->log( "Local order saved a Stripe Invoice ID, not a Charge ID" );
				
				try {
					$inv = Invoice::retrieve( $last_order_id );
					
					// Retrieve the charge object related to this invoice (which is what's needed)
					if ( ! empty( $inv ) ) {
						$last_order_id = $inv->charge;
					}
				} catch ( \Exception $exception ) {
					$utils->log( "Error fetching invoice info: " . $exception->getMessage() );
					
					return false;
				}
			}
			
			// Last order was of the Stripe Charge type
			if ( ! empty( $last_order_id ) && ( false !== strpos( $last_order_id, 'ch_' ) ) ) {
				try {
					
					$utils->log( "Loading charge data for {$last_order_id}" );
					$charge = Charge::retrieve( $last_order_id );
					
				} catch ( \Exception $exception ) {
					$utils->log( "Error fetching charge/payment: " . $exception->getMessage() );
					
					return false;
				}
			}
			
			$utils->log( "Stripe payment data collected for {$last_order_id} -> {$user_email}" );
			
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $customer->email ) && $user_email !== $customer->email ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email Stripe account {$customer->email} doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $customer->email, $user_email );
				
				return false;
			}
			
			if ( ! empty( $charge ) ) {
				
				if ( 'charge' != $charge->object ) {
					$utils->log( "Error: This is not a valid Stripe Charge! " . print_r( $charge, true ) );
					
					return $user_data;
				}
				
				$amount = $utils->amount_by_currency( $charge->amount, $charge->currency );
				
				$user_data->set_charge_info( $last_order_id );
				$user_data->set_payment_amount( $amount, $charge->currency );
				
				$payment_status = ( 'paid' == $charge->paid ? true : false );
				$user_data->is_payment_paid( $payment_status, $charge->failure_message );
				
				$user_data->set_payment_date( $charge->created, $this->gateway_timezone );
				$user_data->set_end_of_membership_date();
				
				$user_data->set_reminder_type( 'expiration' );
				// $user_data->add_charge_list( $charge );
				
				// Add any/all credit card info used for this transaction
				$user_data = $this->process_credit_card_info( $user_data, $charge->source, $this->gateway_name );
				
				// Save the processing module for this record
				$user_data->set_module( $addon );
			}
			
			
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
			$stub  = apply_filters( 'e20r_pw_addon_stripe_gateway_addon_name', null );
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $user_data;
			}
			
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
		 * Return the gateway name for the matching add-on
		 *
		 * @param null|string $gateway_name
		 * @param string     $addon
		 *
		 * @return null|string
		 */
		public function get_gateway_class_name( $gateway_name = null , $addon ) {
			
			// "Punch through" unless the gateway name matches the addon specified
			if ( is_null($gateway_name) && 1 === preg_match( "/{$addon}/i", $this->gateway_name ) ) {
				$gateway_name =  $this->get_class_name();
			}
			
			return $gateway_name;
		}
		
		/**
		 * List of Stripe API versions
		 * TODO: Maintain the STRIPE API version list manually (no call to fetch current versions as of 07/03/2017)
		 *
		 * @return array
		 */
		public function fetch_stripe_api_versions() {
			
			$versions = apply_filters( 'e20r_pw_addon_stripe_api_versions', array(
				'2018-01-23',
				'2017-12-14',
				'2017-08-15',
				'2017-06-05',
				'2017-05-25',
				'2017-04-06',
				'2017-02-14',
				'2017-01-27',
				'2016-10-19',
				'2016-07-06',
				'2016-06-15',
				'2016-03-07',
				'2016-02-29',
			) );
			
			return $versions;
		}
		
		/**
		 *  Stripe_Gateway_Addon constructor.
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
		
		/**
		 * Filter Handler: Add the 'add bbPress add-on license' settings entry
		 *
		 * @filter e20r-license-add-new-licenses
		 *
		 * @param array $license_settings
		 * @param array $plugin_settings
		 *
		 * @return array
		 */
		public function add_new_license_info( $license_settings, $plugin_settings ) {
			
			global $e20r_pw_addons;
			
			$utils = Utilities::get_instance();
			
			if ( ! isset( $license_settings['new_licenses'] ) ) {
				$license_settings['new_licenses'] = array();
				$utils->log( "Init array of licenses entry" );
			}
			
			$stub = strtolower( $this->get_class_name() );
			$utils->log( "Have " . count( $license_settings['new_licenses'] ) . " new licenses to process already. Adding {$stub}... " );
			
			$license_settings['new_licenses'][ $stub ] = array(
				'label_for'     => $stub,
				'fulltext_name' => $e20r_pw_addons[ $stub ]['label'],
				'new_product'   => $stub,
				'option_name'   => "e20r_license_settings",
				'name'          => 'license_key',
				'input_type'    => 'password',
				'value'         => null,
				'email_field'   => "license_email",
				'email_value'   => null,
				'placeholder'   => sprintf( __( "Paste Payment Warning %s key here", "e20r-licensing" ), $e20r_pw_addons[ $stub ]['label'] ),
			);
			
			return $license_settings;
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
		 * @return array
		 *
		 * @access private
		 * @since  1.0
		 */
		private function load_defaults() {
			
			return array(
				'stripe_api_version' => 0,
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
				
				return false;
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
			
			$utils->log( "Setting the {$addon} option to {$e20r_pw_addons[ $addon ]['is_active']}" );
			update_option( "e20r_pw_addon_{$addon}_enabled", $e20r_pw_addons[ $addon ]['is_active'], 'no' );
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
		 *
		 * @return mixed
		 *
		 * @since 2.1 - Add additional argument to '' and '' filters (for active gateway)
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
			), 10, 2 );
			add_filter( "e20r_pw_addon_options_{$e20r_pw_addons[$stub]['class_name']}", array(
				self::get_instance(),
				'register_settings',
			), 10, 1 );
			
			if ( true === parent::is_enabled( $stub ) ) {
				
				$utils->log( "{$e20r_pw_addons[$stub]['label']} active: Loading add-on specific actions/filters" );
				
				parent::load_hooks_for( self::get_instance() );
				
				if ( WP_DEBUG ) {
					add_action( 'wp_ajax_test_get_gateway_subscriptions', array(
						self::get_instance(),
						'test_gateway_subscriptions',
					) );
				}
			}
		}
		
		/**
		 * Loading add-on specific handler for Stripe (use early handling to stay out of the way of PMPro itself)
		 *
		 * @param string|null $stub
		 */
		public function load_webhook_handler( $stub = null ) {
			
			global $e20r_pw_addons;
			$util = Utilities::get_instance();
			
			if ( empty( $stub ) ) {
				$stub = strtolower( $this->get_class_name() );
			}
			
			parent::load_webhook_handler( $stub );
			
			$util->log( "Site has the expected Stripe WebHook action: " . (
				has_action(
					"wp_ajax_{$e20r_pw_addons[$stub]['handler_name']}",
					array( self::get_instance(), 'webhook_handler', ) ) ? 'Yes' : 'No' )
			);
		}
		
		/**
		 * IPN handler for Stripe. Ensures that the PMPro Webhook will run too.
		 *
		 * @return bool
		 */
		public function webhook_handler() {
			
			global $pmpro_stripe_event;
			
			$util    = Utilities::get_instance();
			$event   = null;
			$is_live = false;
			
			if ( ! function_exists( 'pmpro_getOption' ) ) {
				$util->log( "The Paid Memberships Pro plugin is _not_ loaded and activated! Exiting..." );
				
				return false;
			}
			
			$util->log( "In the Stripe.com Gateway Webhook handler for Payment Warnings plugin" );
			
			$util->log( "Incoming request: " . print_r( $_REQUEST, true ) );
			
			if ( false === $this->gateway_loaded ) {
				$util->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_stripe_libs();
			}
			
			try {
				Stripe::setApiKey( pmpro_getOption( "stripe_secretkey" ) );
				
			} catch ( \Exception $e ) {
				
				$util->log( "Unable to set API key for Stripe gateway: " . $e->getMessage() );
				
				return false;
			}
			
			$api_version_to_use = $this->load_option( 'stripe_api_version' );
			
			try {
				Stripe::setApiVersion( $api_version_to_use );
			} catch ( \Exception $e ) {
				$util->log( "Error attempting to set the API version to {$api_version_to_use}: " . $e->getMessage() );
				
				return false;
			}
			
			$event_id = $util->get_variable( 'event_id', null );
			
			if ( ! empty( $event_id ) ) {
				$util->log( "Loading event: {$event_id} from Stripe.com" );
				try {
					$event = Event::retrieve( $event_id );
				} catch ( \Exception $e ) {
					$util->log( "Error proceesing {$event_id}: " . $e->getMessage() );
				}
			} else if ( empty( $pmpro_stripe_event ) && empty( $event_id ) ) {
				
				$util->log( "Having to try to load the body of the request from the input stream:" );
				
				$body = @file_get_contents( 'php://input' );
				
				if ( ! empty( $body ) ) {
					
					$util->log( "Got 'something' (json?) from the input stream" );
					
					// Attempt to decode JSON (and return as Class/Object
					$post_event = json_decode( $body, false );
					
					if ( ! isset( $post_event->object ) || ( isset( $post_event->object ) && 'event' !== $post_event->object ) ) {
						
						if ( ! empty( $post_event ) && ! is_bool( $post_event ) ) {
							
							
							//Find the event ID
							if ( ! empty( $post_event ) ) {
								
								$event_id = $post_event->id;
							}
							
							$util->log( "Attempting to grab the event from Stripe.com for {$event_id}" );
							
							try {
								
								$event = Event::retrieve( $event_id );
								
							} catch ( \Exception $e ) {
								
								$util->log( "Error retrieving {$event_id}: " . $e->getMessage() );
								
								return false;
							}
							
						} else {
							$util->log( "Error attempting to decode the Stripe.com JSON text" );
							
							return false;
						}
						
						if ( ! empty( $event_id ) && ! isset( $_REQUEST['event_id'] ) ) {
							
							$util->log( "Re-adding the event ID for stripe's webhook to the REQUEST array" );
							$_REQUEST['event_id'] = $event_id;
						}
						
					} else {
						
						$util->log( "Body/Event Parsed." );
						$event = $post_event;
					}
				}
				
			} else if ( ! empty( $pmpro_stripe_event ) ) {
				
				$util->log( "Grabbing event from PMPro and using it for processing: {$pmpro_stripe_event->id}" );
				$event = $pmpro_stripe_event;
			}
			
			// Have what we believe to be a Stripe event object
			if ( ! empty( $event ) ) {
				
				$util->log( "Loading data for {$event->id}" );
				
				$is_live   = is_bool( $event->livemode ) ? (bool) $event->livemode : false;
				$data_list = $event->data;
				
				if ( ( true === $is_live && ! empty( $data_list ) ) || ( true === WP_DEBUG && ! empty( $data_list ) ) ) {
					
					$util->log( "Sending data to processing by event type. In DEBUG mode? " . ( true === WP_DEBUG ? 'Yes' : 'No' ) );
					
					return $this->process_event_data( $event->type, $data_list );
				}
			}
			
			return false;
		}
		
		/**
		 * Process and select the event type handler for this webhook event
		 *
		 * @param string $event_type
		 * @param array  $data_array
		 */
		public function process_event_data( $event_type, $data_array ) {
			
			$util = Utilities::get_instance();
			
			switch ( $event_type ) {
				
				case 'customer.source.updated': // Credit Card was updated by Stripe.com
					$util->log( "Customer payment source was updated" );
					$this->maybe_update_credit_card( 'update', $data_array );
					break;
				
				case 'customer.source.deleted': // Credit Card was deleted at Stripe.com
					$util->log( "Customer payment source was deleted" );
					$this->maybe_update_credit_card( 'delete', $data_array );
					break;
				
				case 'customer.source.created': // Credit Card was added at Stripe.com
					$util->log( "Customer payment source was added" );
					$this->maybe_update_credit_card( 'add', $data_array );
					break;
				
				case 'customer.subscription.deleted': // Subscription plan was deleted / expired / ended
					$util->log( "Customer subscription plan was deleted" );
					$this->maybe_update_subscription( 'delete', $data_array );
					break;
				
				case 'customer.subscription.created': // Subscription plan was deleted / expired / ended
					$util->log( "Customer subscription plan was deleted" );
					$this->maybe_update_subscription( 'add', $data_array );
					break;
				/*
				//				case 'customer.subscription.updated': // Subscription plan was deleted / expired / ended
				//					$util->log( "Customer subscription plan was deleted" );
				//					$this->maybe_update_subscription( 'update', $data_array );
				//					break;
				*/
				case 'source.failed': //Payment source failed!
					$util->log( "Payement by customer's payment source failed" );
					$this->maybe_send_payment_failure_message( $data_array );
					break;
				
				case 'invoice.upcoming':
					$util->log( "The customer has an upcoming invoice" );
					// An invoice is about to be charged in X days (default: 7 days) (options: 3, 7, 15, 30, 45 days)
					$this->maybe_update_subscription( 'update', $data_array );
					break;
				
				case 'customer.subscription.trial_will_end': // Triggers when the first _recurring_ payment is about to happen (3 days before)
					$util->log( "The customer's first automatic membership payment will be charged in 3 days" );
					$this->maybe_update_subscription( 'update', $data_array );
					
					break;
				
				default:
					$util->log( "No processing defined for {$event_type}: " . print_r( $data_array, true ) );
			}
			
		}
		
		/**
		 * @param mixed $data
		 */
		public function maybe_send_payment_failure_message( $data ) {
			$util = Utilities::get_instance();
			$util->log( "Dumping Payment failure data: " . print_r( $data, true ) );
		}
		
		/**
		 * @param $operation
		 * @param $data
		 *
		 * @return bool
		 */
		public function maybe_update_credit_card( $operation, $data ) {
			
			$util = Utilities::get_instance();
			$util->log( "Dumping Credit Card event data (for: {$operation}): " . print_r( $data, true ) );
			$customer_id = null;
			$customer    = null;
			$user        = null;
			
			if ( isset( $data->object->customer ) ) {
				
				$util->log( "Found stripe customer ID: {$data->object->customer}" );
				$customer_id = $data->object->customer;
				
			} else if ( isset( $data->object->owner->email ) ) {
				
				$util->log( "Found user email ({$data->object->owner->email}). Loading the Stripe customer ID..." );
				$user = get_user_by( 'email', $data->object->owner->email );
				
				if ( ! empty( $user ) ) {
					$customer_id = get_user_meta( $user->ID, 'pmpro_stripe_customer_id', true );
				}
			}
			
			if ( ! empty( $customer_id ) ) {
				
				try {
					$customer = Customer::retrieve( $customer_id );
					$user     = get_user_by( 'email', $customer->email );
					
				} catch ( \Exception $e ) {
					$util->log( "Unable to retrieve customer data from object: {$data->object->customer} (probably not a Credit Card)" );
				}
				
			} else {
				$util->log( "No customer ID to use to update credit card data" );
			}
			
			if ( 'card' !== $data->object->object || empty( $user ) ) {
				$util->log( "Not processing a credit card, and didn't find a user!" );
				
				return false;
			}
			
			$util->log( "Processing {$operation} card operation for user ID: {$user->ID}" );
			
			// We're looking to add/update
			if ( 'delete' !== $operation ) {
				
				$util->log( "It's an update or add operation" );
				
				if ( 'update' === $operation ) {
					$old_card_attributes = (array) $data->previous_attributes;
					$util->log( "Previously saved (now updated): " . print_r( $old_card_attributes, true ) );
				}
				
				$current_card_attributes = (array) $data->object;
				$util->log( "Current saved (aka the updated) card details: " . print_r( $current_card_attributes, true ) );
				
				$search_for            = User_Data::default_card_format();
				$search_for['user_id'] = $user->ID;
				
				foreach ( $search_for as $key => $value ) {
					
					if ( ! empty( $current_card_attributes[ $key ] ) ) {
						$util->log( "Saving new/existing card info for {$key}: {$current_card_attributes[$key]}" );
						$search_for[ $key ] = $current_card_attributes[ $key ];
					}
					
					if ( 'update' === $operation && ! empty( $old_card_attributes[ $key ] ) ) {
						$util->log( "Updating search array for key {$key} with: {$old_card_attributes[ $key ]}" );
						$search_for[ $key ] = $old_card_attributes[ $key ];
					}
				}
				
				$card_id = User_Data::find_card_info( $search_for );
				
				if ( false !== $card_id ) {
					$search_for['ID'] = $card_id;
				}
				
				$search_for['user_id'] = $user->ID;
				
				$util->log( "Found card with record ID? " . ( isset( $search_for['ID'] ) ? 'Yes' : 'No' ) );
				
				foreach ( $search_for as $key => $value ) {
					
					if ( isset( $current_card_attributes[ $key ] ) && ! empty( $current_card_attributes[ $key ] ) ) {
						$search_for[ $key ] = $current_card_attributes[ $key ];
					}
				}
				
				$util->log( "Will insert/update credit card info for {$user->user_email}: " . print_r( $search_for, true ) );
				
				if ( false === User_Data::save_credit_card( $search_for, isset( $search_for['ID'] ) ) ) {
					
					$util->log( "Unable to save/update credit card info locally..." );
					
					return false;
				}
				
				// Deleting the card
			} else if ( 'delete' === $operation ) {
				
				$current_card_attributes = (array) $data->object;
				$search_for              = $current_card_attributes;
				
				$util->log( "Attempting to delete card: " );
				
				$card_info            = User_Data::default_card_format();
				$card_info['user_id'] = $user->ID;
				
				$received = (array) $data->object;
				
				$util->log( "Received data for {$operation} operation: " . print_r( $received, true ) );
				
				foreach ( $card_info as $key => $value ) {
					
					if ( isset( $current_card_attributes[ $key ] ) ) {
						$card_info[ $key ] = $current_card_attributes[ $key ];
					}
				}
				
				$card_id = User_Data::find_card_info( $card_info );
				
				if ( ! empty( $card_id ) ) {
					
					$util->log( "Found card with ID: {$card_id}" );
					
					if ( false === User_Data::delete_card( $card_id ) ) {
						
						$util->log( "Unable to delete {$card_info['brand']}/{$card_info['last4']} with ID {$card_id}" );
						
						return false;
					}
					
					$util->log( "Deleted card with ID: {$card_id}" );
				} else {
					
					$util->log( "Card with ID {$card_id} not found???" );
					
					return true;
				}
			}
			
			return true;
		}
		
		/**
		 * Update/Delete subscription data for specified user
		 *
		 * @param string $operation
		 * @param array  $data
		 *
		 * @return bool
		 */
		public function maybe_update_subscription( $operation, $data ) {
			
			$util = Utilities::get_instance();
			$util->log( "Dumping subscription related event data (for: {$operation}) -> " . print_r( $data, true ) );
			
			if ( isset( $data->object->object ) && 'subscription' !== $data->object->object ) {
				$util->log( "Not a subscription object! Exiting" );
				
				return false;
			}
			
			$subscription = $data->object;
			$customer_id  = isset( $subscription->customer ) ? $subscription->customer : null;
			
			if ( ! empty( $customer_id ) ) {
				
				try {
					$customer = Customer::retrieve( $customer_id );
					$user     = get_user_by( 'email', $customer->email );
				} catch ( \Exception $e ) {
					$util->log( "Error fetching customer data from Stripe.com: " . $e->getMessage() );
					
					return false;
				}
			}
			
			if ( empty( $user ) ) {
				$util->log( "Customer/User with Stripe ID {$customer_id} not found on local system!" );
				
				return false;
			}
			
			if ( 'delete' === $operation ) {
				$util->log( "Will be removing subscription data for {$subscription->id}/{$customer_id}/{$user->user_email}" );
				
				if ( false === User_Data::delete_subscription_record( $user->ID, $subscription->id ) ) {
					$util->log( "Error deleting subscription record: {$subscription->id} for {$user->ID}" );
				}
				
				return true;
			}
			
			if ( 'add' === $operation ) {
				
				$util->log( "Wanting to add a new subscription for user {$customer_id}/{$user->ID}" );
				
				if ( ! empty( $customer ) && ! empty( $user ) && ! empty( $subscription ) ) {
					
					$util->log( "Adding local PMPro order for {$user->ID}/{$customer->id}" );
					$user_info = $this->add_local_subscription_order( $customer, $user, $subscription );
					
					$user_info->set_gw_subscription_id( $subscription->id );
					$user_info->set_payment_currency( $subscription->items->data[0]->plan->currency );
					
					if ( empty( $subscription->cancel_at_period_end ) && empty( $subscription->cancelled_at ) && in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$util->log( "Setting payment status to 'active' for {$customer->id}" );
						$user_info->set_payment_status( 'active' );
					}
					
					if ( ! empty( $subscription->cancel_at_period_end ) || ! empty( $subscription->cancelled_at ) || ! in_array( $subscription->status, array(
							'trialing',
							'active',
						) )
					) {
						$util->log( "Setting payment status to 'stopped' for {$customer->id}" );
						$user_info->set_payment_status( 'stopped' );
					}
					
					// Set the date for the next payment
					if ( $user_info->get_payment_status() === 'active' ) {
						
						// Get the date when the currently paid for period ends.
						$current_payment_until = date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end );
						$user_info->set_end_of_paymentperiod( $current_payment_until );
						$util->log( "End of the current payment period: {$current_payment_until}" );
						
						// Add a day (first day of new payment period)
						$payment_next = date_i18n( 'Y-m-d H:i:s', ( $subscription->current_period_end + 1 ) );
						
						$user_info->set_next_payment( $payment_next );
						$util->log( "Next payment on: {$payment_next}" );
						
						global $pmpro_currencies;
						$plan_currency = ! empty( $subscription->plan->currency ) ? strtoupper( $subscription->plan->currency ) : 'USD';
						$user_info->set_payment_currency( $plan_currency );
						
						$util->log( "Payments are made in: {$plan_currency}" );
						$has_decimals = true;
						
						if ( isset( $pmpro_currencies[ $plan_currency ]['decimals'] ) ) {
							
							// Is this for a no-decimal currency?
							if ( 0 === $pmpro_currencies[ $plan_currency ]['decimals'] ) {
								$util->log( "The specified currency ({$plan_currency}) doesn't use decimal points" );
								$has_decimals = false;
							}
						}
						
						// Get the amount & cast it to a floating point value
						$amount = number_format_i18n( ( (float) ( $has_decimals ? ( $subscription->plan->amount / 100 ) : $subscription->plan->amount ) ), ( $has_decimals ? 2 : 0 ) );
						$user_info->set_next_payment_amount( $amount );
						$util->log( "Next payment of {$plan_currency} {$amount} will be charged within 24 hours of {$payment_next}" );
						
						$user_info->set_reminder_type( 'recurring' );
					} else {
						
						$util->log( "Subscription payment plan is going to end after: " . date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end + 1 ) );
						$user_info->set_subscription_end();
					}
					
					$user_info->set_active_subscription( true );
					$util->log( "Attempting to load credit card (payment method) info from gateway" );
					
					// Trigger handler for credit card data
					$user_info = $this->process_credit_card_info( $user_info, $customer->sources->data, $this->gateway_name );
					
					$user_info->save_to_db();
					
					return true;
				}
			}
			
			if ( 'update' === $operation ) {
			
			}
			
			return false;
		}
		
		/**
		 * Add a local order record if we can't find one by the stripe Subscription ID
		 *
		 * @param Customer $stripe_customer
		 * @param \WP_User $user
		 * @param Subscription    $subscription
		 *
		 * @return User_Data
		 */
		public function add_local_subscription_order( $stripe_customer, $user, $subscription ) {
			
			$util = Utilities::get_instance();
			
			$order = new \MemberOrder();
			
			$order->getLastMemberOrderBySubscriptionTransactionID( $subscription->id );
			
			// Add a new order record to local system if needed
			if ( empty( $order->user_id ) ) {
				
				$order->getEmptyMemberOrder();
				
				$order->setGateway( 'stripe' );
				$order->gateway_environment = pmpro_getOption( "gateway_environment" );
				
				$order->user_id = $user->ID;
				
				// Set the current level info if needed
				if ( ! isset( $user->membership_level ) || empty( $user->membership_level ) ) {
					
					$util->log( "Adding membership level info for user {$user->ID}" );
					$user->membership_level = pmpro_getMembershipLevelForUser( $user->ID );
				}
				
				$order->membership_id               = isset( $user->membership_level->id ) ? $user->membership_level->id : 0;
				$order->membership_name             = isset( $user->membership_level->name ) ? $user->membership_level->name : null;
				$order->subscription_transaction_id = $subscription->id;
				
				// No initial payment info found...
				$order->InitialPayment = 0;
				
				if ( isset( $subscription->items ) ) {
					
					// Process the subscription plan(s)
					global $pmpro_currencies;
					if ( count( $subscription->items->data ) <= 1 ) {
						
						$util->log( "One or less Plans for the Subscription" );
						$plan = $subscription->items->data[0]->plan;
						
						$currency        = $pmpro_currencies[ strtoupper( $plan->currency ) ];
						$decimal_divisor = 100;
						$decimals        = 2;
						
						if ( isset( $currency['decimals'] ) ) {
							$decimals = $currency['decimals'];
						}
						
						$decimal_divisor = intval( sprintf( "1'%0{$decimals}d", $decimal_divisor ) );
						$util->log( "Using decimal divisor of: {$decimal_divisor}" );
						
						$order->PaymentAmount    = floatval( $plan->amount / $decimal_divisor );
						$order->BillingPeriod    = ucfirst( $plan->interval );
						$order->BillingFrequency = intval( $plan->interval_count );
						$order->ProfileStartDate = date_i18n( 'Y-m-d H:i:s', $plan->created );
						
						$order->status = 'success';
					}
				}
				
				
				$order->billing   = $this->get_billing_info( $stripe_customer );
				$order->FirstName = $user->first_name;
				$order->LastName  = $user->last_name;
				$order->Email     = $user->user_email;
				
				$order->Address1 = $order->billing->street;
				$order->City     = $order->billing->city;
				$order->State    = $order->billing->state;
				
				$order->Zip         = $order->billing->zip;
				$order->PhoneNumber = null;
				
				// Card data
				$order->cardtype        = $stripe_customer->sources->data[0]->brand;
				$order->accountnumber   = hideCardNumber( $stripe_customer->sources->data[0]->last4 );
				$order->expirationmonth = $stripe_customer->sources->data[0]->exp_month;
				$order->expirationyear  = $stripe_customer->sources->data[0]->exp_year;
				
				// Custom card expiration info
				$order->ExpirationDate        = "{$order->expirationmonth}{$order->expirationyear}";
				$order->ExpirationDate_YdashM = "{$order->expirationyear}-{$order->expirationmonth}";
				
				$order->saveOrder();
				$order->getLastMemberOrder( $user->ID );
				
				$util->log( "Saved new (local) order for user ({$user->ID})" );
			}
			
			$user_info = new User_Data( $user, $order, 'recurring' );
			
			return $user_info;
		}
		
		/**
		 * @param Customer $customer -- Stripe Customer Object
		 *
		 * @return \stdClass
		 */
		public function get_billing_info( $customer ) {
			
			$stripe_billing_info = $customer->sources->data[0];
			
			$billing          = new \stdClass();
			$billing->name    = $stripe_billing_info->name;
			$billing->street  = $stripe_billing_info->address_line1;
			$billing->city    = $stripe_billing_info->address_city;
			$billing->state   = $stripe_billing_info->address_state;
			$billing->zip     = $stripe_billing_info->address_zip;
			$billing->country = $stripe_billing_info->address_country;
			$billing->phone   = null;
			
			return $billing;
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
			
			// $utils->log( " Loading settings for..." . print_r( $settings, true ) );
			
			$settings['section'] = array(
				array(
					'id'              => 'e20rpw_addon_stripe_global',
					'label'           => __( "Stripe Gateway Settings", Payment_Warning::plugin_slug ),
					'render_callback' => array( $this, 'render_settings_text' ),
					'fields'          => array(
						array(
							'id'              => 'stripe_api_version',
							'label'           => __( "Stripe API Version to use", Payment_Warning::plugin_slug ),
							'render_callback' => array( $this, 'render_select' ),
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
			
			$stripe_api_version = $this->load_option( 'stripe_api_version' );
			$utils->log( "Setting for Stripe API Version: {$stripe_api_version}" );
			?>
			<select name="<?php esc_attr_e( $this->option_name ); ?>[stripe_api_version]"
			        id="<?php esc_attr_e( $this->option_name ); ?>_stripe_api_version">
				<option value="0" <?php selected( $stripe_api_version, 0 ); ?>>
					<?php _e( 'Default', Payment_Warning::plugin_slug ); ?>
				</option>
				<?php
				$all_api_versions = $this->fetch_stripe_api_versions();
				
				foreach ( $all_api_versions as $version ) {
					?>
					<option
						value="<?php esc_attr_e( $version ); ?>" <?php selected( $version, $stripe_api_version ); ?>>
						<?php esc_attr_e( $version ); ?>
					</option>
				<?php } ?>
			</select>
			<?php
		}
		
		/**
		 * Fetch the properties for the Stripe Gateway add-on class
		 *
		 * @return Stripe_Gateway_Addon
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

add_filter( "e20r_pw_addon_stripe_gateway_addon_name", array(
	Stripe_Gateway_Addon::get_instance(),
	'set_stub_name',
) );

// Configure the add-on (global settings array)
global $e20r_pw_addons;
$stub = apply_filters( "e20r_pw_addon_stripe_gateway_addon_name", null );

$e20r_pw_addons[ $stub ] = array(
	'class_name'            => 'Stripe_Gateway_Addon',
	'handler_name'          => 'stripe_webhook',
	'is_active'             => ( get_option( "e20r_pw_addon_{$stub}_enabled", false ) == 1 ? true : false ),
	'active_license'        => ( get_option( "e20r_pw_addon_{$stub}_licensed", false ) == 1 ? true : false ),
	'status'                => 'deactivated',
	// ( 1 == get_option( "e20r_pw_addon_{$stub}_enabled", false ) ? 'active' : 'deactivated' ),
	'label'                 => 'Stripe',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);
