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
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;

/**
 * PayPal Merchants API Namespace declarations
 */

use PayPal\EBLBaseComponents\GetRecurringPaymentsProfileDetailsResponseDetailsType;
use PayPal\PayPalAPI\GetRecurringPaymentsProfileDetailsRequestType;
use PayPal\PayPalAPI\GetRecurringPaymentsProfileDetailsReq;
use PayPal\PayPalAPI\GetRecurringPaymentsProfileDetailsResponseType;
use PayPal\EBLBaseComponents\PaymentTransactionType;
use PayPal\PayPalAPI\GetTransactionDetailsReq;
use PayPal\PayPalAPI\GetTransactionDetailsRequestType;
use PayPal\PayPalAPI\GetTransactionDetailsResponseType;
use PayPal\Service\PayPalAPIInterfaceServiceService;

class PayPal_Gateway_Addon extends E20R_PW_Gateway_Addon {
	
	/**
	 * PayPal Service keys
	 */
	const E20R_PW_PAYPAL_EXPRESS = 1;
	const E20R_PW_PAYPAL_STANDARD = 2;
	const E20R_PW_PAYPAL_PAYMENTSPRO = 3;
	const E20R_PW_PAYPAL_PAYFLOWPRO = 4;
	const E20R_PW_PAYPAL_BRAINTREE = 5;
	
	const CACHE_GROUP = 'e20rpw_paypal_addon';
	/**
	 * @var PayPal_Gateway_Addon|null
	 */
	private static $instance = null;
	/**
	 * The name of this class
	 *
	 * @var string
	 */
	protected $class_name;
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
	 * @var null|PayPalAPIInterfaceServiceService
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
	protected $option_name = 'e20r_egwao_paypal';
	/**
	 * Timezone string that the current gateway is using
	 *
	 * @var string $gateway_timezone
	 */
	protected $gateway_timezone = 'GMT';
	/**
	 * @var array $paypal_settings
	 */
	private $paypal_settings;
	
	/**
	 * PayPal IPN info about the customer being processed
	 *
	 * @var array $ipn_customer
	 */
	private $ipn_customer = array();
	
	/**
	 * PayPal IPN info about the transaction (recurring billing) being processed
	 *
	 * @var array $ipn_payment_info
	 */
	private $ipn_payment_info = array();
	
	/**
	 * PayPal IPN info about the/any credit card use(d)/updated
	 *
	 * @var array $ipn_cc_info
	 */
	private $ipn_cc_info = array();
	
	/**
	 *  PayPal_Gateway_Addon constructor.
	 */
	public function __construct() {
		
		parent::__construct();
		$utils = Utilities::get_instance();
		
		add_filter( 'e20r-licensing-text-domain', array( $this, 'set_stub_name' ) );
		
		if ( is_null( self::$instance ) ) {
			self::$instance = $this;
		}
		
		$this->class_name   = $this->maybe_extract_class_name( get_class( $this ) );
		$this->gateway_name = 'paypal';
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			$this->current_gateway_type = pmpro_getOption( "gateway_environment" );
		}
		
		$this->define_settings();
	}
	
	/**
	 * Load the saved options, or generate the default settings
	 */
	protected function define_settings() {
		
		$this->settings = get_option( $this->option_name, $this->load_defaults() );
		$defaults       = $this->load_defaults();
		
		foreach ( $defaults as $key => $dummy ) {
			$this->settings[ $key ] = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $defaults[ $key ];
		}
	}
	
	/**
	 * Loads the default settings for the PayPal add-on (keys & values)
	 *
	 * @return array
	 *
	 * @access private
	 * @since  1.0
	 */
	private function load_defaults() {
		
		return array(
			'paypal_api_version' => null,
			'deactivation_reset' => false,
			'primary_service'    => 0,
		);
		
	}
	
	/**
	 * Load add-on actions/filters when the add-on is active & enabled
	 *
	 * @param string $stub Lowercase Add-on class name
	 *
	 * @return boolean
	 */
	final public static function is_enabled( $stub ) {
		
		$utils = Utilities::get_instance();
		global $e20r_pw_addons;
		
		// TODO: Set the filter name to match the sub for this plugin.
		$utils->log( "Running for {$stub}" );
		
		$class_name = self::get_instance()->get_class_name();
		
		/**
		 * Toggle ourselves on/off, and handle any deactivation if needed.
		 */
		
		add_action( 'e20r_pw_addon_toggle_addon', array( self::get_instance(), 'toggle_addon' ), 10, 2 );
		add_action( 'e20r_pw_addon_activating_core', array( self::get_instance(), 'activate_addon', ), 10, 0 );
		add_action( 'e20r_pw_addon_deactivating_core', array( self::get_instance(), 'deactivate_addon', ), 10, 1 );
		
		add_filter( "e20r_pw_addon_options_{$class_name}", array( self::get_instance(), 'register_settings', ), 10, 1 );
		
		add_filter( 'e20r-license-add-new-licenses', array( self::get_instance(), 'add_new_license_info', ), 10, 2 );
		add_filter( "e20r_pw_addon_options_{$e20r_pw_addons[$stub]['class_name']}", array(
			self::get_instance(),
			'register_settings',
		), 10, 1 );
		
		if ( true === ( $is_enabled = parent::is_enabled( $stub ) ) ) {
			
			$utils->log( "{$e20r_pw_addons[$stub]['label']} active: Loading add-on specific actions/filters" );
			
			parent::load_hooks_for( self::get_instance() );
		}
		
		return $is_enabled;
	}
	
	/**
	 * Extract/return the name of the add-on
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
	 * Fetch the class for the PayPal add-on
	 *
	 * @return PayPal_Gateway_Addon|null
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
	
	/**
	 * Append this add-on to the list of configured & enabled add-ons
	 */
	public static function configure_addon() {
		
		$class = self::get_instance();
		$name  = strtolower( $class->get_class_name() );
		
		parent::is_enabled( $name );
	}
	
	/**
	 * Loading add-on specific webhook handler for PayPal (late handling to stay out of the way of PMPro itself)
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
		
		$util->log( "Site has the expected PayPal IPN Handler action: " . (
			has_action(
				"wp_ajax_{$e20r_pw_addons[$stub]['handler_name']}",
				array( self::get_instance(), 'webhook_handler', ) ) ? 'Yes' : 'No' )
		);
		
	}
	
	/**
	 * Return the gateway name for the matching add-on
	 *
	 * @param null|string $gateway_name
	 * @param string      $addon
	 *
	 * @return null|string
	 */
	public function get_gateway_class_name( $gateway_name = null, $addon ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Gateway name: {$gateway_name}. Addon name: {$addon}" );
		
		if ( ! empty( $gateway_name ) && 1 !== preg_match( "/{$addon}/i", $this->get_class_name() ) ) {
			$utils->log( "{$addon} not matching PayPal's expected gateway add-on" );
			
			return $gateway_name;
		}
		
		$gateway_name = $this->get_class_name();
		
		return $gateway_name;
	}
	
	/**
	 * Handler for PayPal IPN messages
	 *
	 * @return bool
	 */
	public function webhook_handler() {
		
		$util    = Utilities::get_instance();
		$event   = null;
		$is_live = false;
		
		if ( ! function_exists( 'pmpro_getOption' ) ) {
			$util->log( "The Paid Memberships Pro plugin is _not_ loaded and activated! Exiting..." );
			
			return false;
		}
		
		$util->log( "In the PayPal Instant Payment Notice (IPN) handler for Payment Warnings plugin" );
		
		$util->log( "Incoming request: " . print_r( $_REQUEST, true ) );
		
		if ( false === $this->gateway_loaded ) {
			$util->log( "Loading the PayPal Merchants API libraries" );
			$this->load_paypal_libs();
		}
		
		$transaction_type = $util->get_variable( 'txn_type', null );
		$txn_info         = array();
		
		$this->ipn_customer = array(
			'payer_customer_id'     => $util->get_variable( 'payer_id', null ),
			'payer_email'           => $util->get_variable( 'payer_email', null ),
			'payer_first_name'      => $util->get_variable( 'first_name', null ),
			'payer_last_name'       => $util->get_variable( 'last_name', null ),
			'payer_address_name'    => $util->get_variable( 'address_name', null ),
			'payer_address_street'  => $util->get_variable( 'address_street', null ),
			'payer_address_city'    => $util->get_variable( 'address_city', null ),
			'payer_address_state'   => $util->get_variable( 'address_state', null ),
			'payer_address_zip'     => $util->get_variable( 'address_zip', null ),
			'payer_address_country' => $util->get_variable( 'address_country', null ),
		);
		
		$this->ipn_payment_info = array(
			'subscription_id'   => $util->get_variable( 'recurring_payment_id', null ),
			'currency'          => $util->get_variable( 'mc_currency', null ),
			'payment_date'      => $util->get_variable( 'payment_date', null ),
			'next_payment_date' => $util->get_variable( 'next_payment_date', null ),
			'amount'            => $util->get_variable( 'mc_gross', null ),
			'status'            => $util->get_variable( 'payment_status', null ),
			'type'              => $util->get_variable( 'payment_type', null ),
			'created'           => $util->get_variable( 'time_created', null ),
			'billing_interval'  => $util->get_variable( 'payment_cycle', null ),
			'billing_period'    => $util->get_variable( 'period_type', null ),
		);
		
		$this->ipn_cc_info = array(
			'last4'     => null,
			'exp_month' => null,
			'exp_year'  => null,
			'brand'     => null,
		);
		
		$txn_info['customer'] = $this->ipn_customer;
		$txn_info['payment']  = $this->ipn_payment_info;
		$txn_info['cc_info']  = $this->ipn_cc_info;
		
		$this->process_event_data( $transaction_type, $txn_info );
		
		return false;
	}
	
	/**
	 * Do what's required to make PayPal libraries visible/active
	 */
	private function load_paypal_libs() {
		
		$utils = Utilities::get_instance();
		
		$this->pmpro_gateway = new \PMProGateway_paypalexpress();
		
		if ( ! class_exists( 'PayPal\Service\PayPalAPIInterfaceServiceService' ) ) {
			
			$utils->log( "Loading PayPal Merchant API" );
			
			require_once( E20R_PW_DIR . "/libraries/autoload.php" );
		}
		
		try {
			$this->gateway = new PayPalAPIInterfaceServiceService( $this->load_config() );
		} catch ( \Exception $exception ) {
			
			$utils->log( "Error loading PayPal settings: " . $exception->getMessage() );
			
			return false;
		}
		
		$this->gateway_loaded = true;
	}
	
	/**
	 * Load the configuration/settings to access the PayPal Merchants API
	 *
	 * @return array
	 */
	private function load_config() {
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			
			$this->paypal_settings = array(
				'mode'            => ( $this->current_gateway_type === 'sandbox' ? 'sandbox' : 'tls' ),
				'acct1.UserName'  => pmpro_getOption( 'apiusername' ),
				'acct1.Password'  => pmpro_getOption( 'apipassword' ),
				'acct1.Signature' => pmpro_getOption( 'apisignature' ),
				'log.LogEnabled'  => ( WP_DEBUG === true ? true : false ),
				'log.FileName'    => E20R_PW_DIR . '/logs/paypal.log',
				'log.LogLevel'    => 'FINE',
			);
		}
		
		return $this->paypal_settings;
	}
	
	/**
	 * Process and select the event type handler for this webhook event
	 *
	 * @param string $transaction_type
	 * @param string[string]  $data_array
	 */
	public function process_event_data( $transaction_type, $data_array ) {
		
		$util = Utilities::get_instance();
		
		switch ( $transaction_type ) {
			
			// Subscription plan was deleted / expired / ended
			case 'mp_cancel':
			case 'recurring_payment_profile_cancel':
			case 'recurring_payment_expired':
			case 'subscr_cancel':
			case 'subscr_eot':
				
				$util->log( "Customer subscription plan was deleted" );
				$this->maybe_send_payment_failure_message( $this->ipn_payment_info );
				$this->maybe_update_subscription( 'delete', $data_array );
				break;
			
			// Subscription plan was added
			case 'subscr_signup':
			case 'recurring_payment_profile_created':
				$util->log( "Customer subscription/recurring billing plan was added" );
				$this->maybe_update_subscription( 'add', $data_array );
				break;
			
			/*
			case 'invoice.upcoming':
				$util->log( "The customer has an upcoming invoice" );
				// An invoice is about to be charged in X days (default: 7 days) (options: 3, 7, 15, 30, 45 days)
				$this->maybe_update_subscription( 'update', $data_array );
				break;
			*/
			
			// Triggers when the subscription was modified
			case 'subscr_modify':
				$util->log( "Subscription was updated on the PayPal gateway" );
				$this->maybe_update_subscription( 'update', $data_array );
				
				break;
			
			default:
				$util->log( "No processing defined for {$transaction_type}" );
		}
		
	}
	
	/**
	 * @param string[string] $data
	 */
	public function maybe_send_payment_failure_message( $data ) {
		$util = Utilities::get_instance();
		$util->log( "Dumping Payment failure data from PayPal: " . print_r( $data, true ) );
		
		return;
	}
	
	/**
	 * Update/Delete subscription data for specified user
	 *
	 * @param string $operation
	 * @param string[string]  $data
	 *
	 * @return bool
	 */
	public function maybe_update_subscription( $operation, $data ) {
		
		$util = Utilities::get_instance();
		$util->log( "Dumping subscription related event data (for: {$operation}) -> " . print_r( $data, true ) );
		
		if ( empty( $this->ipn_payment_info['subscription_id'] ) ) {
			$util->log( "Not a subscription! Exiting" );
			
			return false;
		}
		
		$customer_id = isset( $this->ipn_customer['payer_customer_id'] ) ? $this->ipn_customer['payer_customer_id'] : null;
		
		if ( ! empty( $customer_id ) ) {
			
			$user = get_user_by( 'email', $this->ipn_customer['payer_email'] );
		}
		
		if ( empty( $user ) ) {
			$util->log( "Customer/User with Stripe ID {$customer_id} not found on local system!" );
			
			return false;
		}
		
		/**
		 * Remove local Payment Warning data for PayPal gateway and user
		 */
		if ( 'delete' === $operation ) {
			$util->log( "Will be removing subscription data for {$this->ipn_payment_info['subscription_id']}/{$customer_id}/{$user->user_email}" );
			
			if ( false === User_Data::delete_subscription_record( $user->ID, $this->ipn_payment_info['subscription_id'] ) ) {
				$util->log( "Error deleting subscription record: {$this->ipn_payment_info['subscription_id']} for {$user->ID}" );
				
				return false;
			}
			
			return true;
		}
		
		/**
		 * Add new subscription plan info from PayPal gateway as local Payment Warning data
		 */
		if ( 'add' === $operation ) {
			
			$util->log( "Wanting to add a new subscription for user {$customer_id}/{$user->ID}" );
			
			if ( ! empty( $this->ipn_customer ) && ! empty( $user ) && ! empty( $this->ipn_customer ) ) {
				
				$util->log( "Adding local PMPro order for {$user->ID}/{$this->ipn_customer['payer_customer_id']}" );
				$user_info = $this->add_local_subscription_order( $this->ipn_customer, $user );
				
				$user_info->set_gw_subscription_id( $this->ipn_payment_info['subscription_id'] );
				$user_info->set_payment_currency( $this->ipn_payment_info['currency'] );
				
				if ( ! empty( $this->ipn_payment_info['next_payment_date'] ) && in_array( $this->ipn_payment_info['status'], array(
						'trialing',
						'active',
					) )
				) {
					$util->log( "Setting payment status to 'active' for {$this->ipn_customer['payer_customer_id']}" );
					$user_info->set_payment_status( 'active' );
				}
				
				if ( empty( $this->ipn_payment_info['next_payment_date'] ) || ! in_array( $this->ipn_payment_info['status'], array(
						'trialing',
						'active',
					) )
				) {
					$util->log( "Setting payment status to 'stopped' for {$this->ipn_customer['payer_customer_id']}" );
					$user_info->set_payment_status( 'stopped' );
				}
				
				// Set the date for the next payment
				if ( $user_info->get_payment_status() === 'active' ) {
					
					// Get the date when the currently paid for period ends.
					$current_payment_until = date( 'Y-m-d 23:59:59', ( $this->get_pp_time_as_seconds( $this->ipn_payment_info['next_payment_date'] ) - 1 ) );
					$user_info->set_end_of_paymentperiod( $current_payment_until );
					$util->log( "End of the current payment period: {$current_payment_until}" );
					
					// Add a day (first day of new payment period)
					$payment_next = date( 'Y-m-d H:i:s', $this->get_pp_time_as_seconds( $this->ipn_payment_info['next_payment_date'] ) );
					
					$user_info->set_next_payment( $payment_next );
					$util->log( "Next payment on: {$payment_next}" );
					
					$plan_currency = ! empty( $this->ipn_payment_info['currency'] ) ? strtoupper( $this->ipn_payment_info['currency'] ) : 'USD';
					$user_info->set_payment_currency( $plan_currency );
					
					$util->log( "Payments are made in: {$plan_currency}" );
					
					// Get the amount & cast it to a floating point value
					$amount = number_format_i18n( ( (float) $this->ipn_payment_info['amount'] ) );
					$user_info->set_next_payment_amount( $amount );
					$util->log( "Next payment of {$plan_currency} {$amount} will be charged within 24 hours of {$payment_next}" );
					
					$user_info->set_reminder_type( 'recurring' );
					$user_info->set_active_subscription( true );
				} else {
					
					// TODO: Fix the end of the subscription period setting(s)
					// $util->log( "Subscription payment plan is going to end after: " . date_i18n( 'Y-m-d 23:59:59', $subscription->current_period_end + 1 ) );
					$user_info->set_subscription_end();
					$user_info->set_active_subscription( false );
				}
				
				$util->log( "Attempting to load credit card (payment method) info from gateway" );
				
				// Trigger handler for credit card data
				$user_info = $this->process_credit_card_info( $user_info, $this->ipn_cc_info, $this->gateway_name );
				
				$user_info->save_to_db();
				
				return true;
			}
		}
		
		/**
		 * Update subscription plan info from PayPal gateway as local Payment Warning data
		 */
		if ( 'update' === $operation ) {
			$util->log( "Don't know how to handle a PayPal IPN request to update the subscription yet!" );
			$util->log( "Payment info: " . print_r( $this->ipn_payment_info, true ) );
			$util->log( "Payer info: " . print_r( $this->ipn_customer, true ) );
			$util->log( "Payment Method info: " . print_r( $this->ipn_cc_info, true ) );
		}
		
		
		return false;
	}
	
	/**
	 * Add a local order record if we can't find one by the stripe Subscription ID
	 *
	 * @param array    $payer
	 * @param \WP_User $user
	 *
	 * @return User_Data
	 */
	public function add_local_subscription_order( $payer, $user ) {
		
		$util = Utilities::get_instance();
		
		$order = new \MemberOrder();
		
		$current_gateway_type = $this->load_option( 'primary_service' );
		$paypal_gateway       = $this->get_paypal_gateway( $current_gateway_type );
		
		/** Get selected PayPal Gateway type from Payment Warnings settings */
		$order->setGateway( $paypal_gateway );
		$order->gateway_environment = pmpro_getOption( "gateway_environment" );
		
		$order->getLastMemberOrderBySubscriptionTransactionID( $this->ipn_payment_info['subscription_id'] );
		
		// Add a new order record to local system if needed
		if ( empty( $order->user_id ) ) {
			
			$order->getEmptyMemberOrder();
			$order->user_id = $user->ID;
			
			// Set the current level info if needed
			if ( ! isset( $user->membership_level ) || empty( $user->membership_level ) ) {
				
				$util->log( "Adding membership level info for user {$user->ID}" );
				$user->membership_level = pmpro_getMembershipLevelForUser( $user->ID );
			}
			
			$order->membership_id               = isset( $user->membership_level->id ) ? $user->membership_level->id : 0;
			$order->membership_name             = isset( $user->membership_level->name ) ? $user->membership_level->name : null;
			$order->subscription_transaction_id = $this->ipn_payment_info['subscription_id'];
			
			// No initial payment info found...
			$order->InitialPayment = 0;
			
			if ( ! empty( $this->ipn_payment_info['subscription_id'] ) ) {
				
				// Process the subscription plan(s)
				global $pmpro_currencies;
				$util->log( "One or less Plans for the Subscription" );
				
				$order->PaymentAmount    = floatval( $this->ipn_payment_info['amount'] );
				$order->BillingPeriod    = ucfirst( $this->ipn_payment_info['billing_period'] );
				$order->BillingFrequency = intval( $this->ipn_payment_info['billing_interval'] );
				$order->ProfileStartDate = date( 'Y-m-d H:i:s', $this->get_pp_time_as_seconds( $this->ipn_payment_info['created'] ) );
				
				$order->status = 'success';
			}
			
			
			$order->billing   = $this->get_billing_info( $this->ipn_customer );
			$order->FirstName = $user->first_name;
			$order->LastName  = $user->last_name;
			$order->Email     = $user->user_email;
			
			$order->Address1 = $order->billing->street;
			$order->City     = $order->billing->city;
			$order->State    = $order->billing->state;
			
			$order->Zip         = $order->billing->zip;
			$order->PhoneNumber = null;
			
			// Card data
			$order->cardtype        = $this->ipn_cc_info['brand'];
			$order->accountnumber   = hideCardNumber( $this->ipn_cc_info['last4'] );
			$order->expirationmonth = $this->ipn_cc_info['exp_month'];
			$order->expirationyear  = $this->ipn_cc_info['exp_year'];
			
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
	 * Return the PayPal gateway type info
	 *
	 * @param null|int $type
	 *
	 * @return array
	 */
	private function get_paypal_gateway( $type = null ) {
		
		$paypal_types = apply_filters( 'e20r-payment-warning-available-paypal-gateways', array(
				self::E20R_PW_PAYPAL_EXPRESS     => array(
					'label'        => __( 'PayPal Express', Payment_Warning::plugin_slug ),
					'gateway_name' => 'paypalexpress',
				),
				self::E20R_PW_PAYPAL_PAYFLOWPRO  => array(
					'label'        => __( 'PayFlow Pro', Payment_Warning::plugin_slug ),
					'gateway_name' => 'payflowpro',
				),
				self::E20R_PW_PAYPAL_PAYMENTSPRO => array(
					'label'        => __( 'PayPal Payments Pro', Payment_Warning::plugin_slug ),
					'gateway_name' => 'paypal',
				),
				self::E20R_PW_PAYPAL_STANDARD    => array(
					'label'        => __( 'PayPal Standard', Payment_Warning::plugin_slug ),
					'gateway_name' => 'paypalstandard',
				),
				self::E20R_PW_PAYPAL_BRAINTREE   => array(
					'label'        => __( 'PayPal/Braintree', Payment_Warning::plugin_slug ),
					'gateway_name' => 'braintree',
				),
			)
		);
		
		if ( empty( $type ) ) {
			return $paypal_types;
		} else {
			return $paypal_types[ $type ];
		}
	}
	
	/**
	 * Convert PayPal's Zulu time to local timezone (using seconds since epoch)
	 *
	 * @param string $time
	 *
	 * @return null|int
	 */
	private function get_pp_time_as_seconds( $time ) {
		
		$utils           = Utilities::get_instance();
		$payment_next_ts = null;
		
		try {
			
			$next_payment_time = new \DateTime( $time, new \DateTimeZone( 'GMT' ) );
			
			$utils->log( "Time as fetched from PayPal: " . $next_payment_time->format( 'U' ) );
			$tz_string = get_option( 'timezone_string', 'GMT' );
			
			$utils->log( "Local timezone is configured as: {$tz_string}" );
			$next_payment_time->setTimezone( new \DateTimeZone( $tz_string ) );
			
			$utils->log( "Time as converted to {$tz_string}: " . $next_payment_time->format( 'Y-m-d H:i:s' ) );
			$payment_next_ts = strtotime( $next_payment_time->format( 'Y-m-d H:i:s' ), current_time( 'timestamp' ) );
			$utils->log( "In seconds as converted to {$tz_string}: {$payment_next_ts}" );
			
		} catch ( \Exception $exception ) {
			$utils->log( "Error creating time object for {$time}: " . $exception->getMessage() );
		}
		
		return $payment_next_ts;
	}
	
	/**
	 * Load the billing info from the customer data as an object
	 *
	 * @param array $customer -- Info array for customer billing data
	 *
	 * @return \stdClass
	 */
	public function get_billing_info( $customer ) {
		
		return ( (object) $this->ipn_customer );
	}
	
	/**
	 * Load the payment gateway specific class/code/settings from PMPro
	 *
	 * @param string $addon_name
	 *
	 * @return bool
	 */
	public function load_gateway( $addon_name ) {
		
		$utils = Utilities::get_instance();
		
		if ( $addon_name !== 'paypal' ) {
			$utils->log( "Not processing for this addon (paypal): {$addon_name}" );
			
			return false;
		}
		
		// This will load the PayPal Express/PMPro Gateway class _and_ its library(ies)
		$utils->log( "PMPro loaded? " . ( defined( 'PMPRO_VERSION' ) ? 'Yes' : 'No' ) );
		$utils->log( "PMPro PayPal Express gateway loaded? " . ( class_exists( "\PMProGateway_paypalexpress" ) ? 'Yes' : 'No' ) );
		$utils->log( "PayPal Class(es) loaded? " . ( class_exists( 'PayPalExpress' ) ? 'Yes' : 'No' ) );
		
		// TODO: Implement load_gateway() method for PayPal Express (if needed).
		if ( defined( 'PMPRO_VERSION' ) && class_exists( "\PMProGateway_PayPal" ) && class_exists( 'Stripe\Stripe' ) && false === $this->gateway_loaded ) {
			$utils->log( "Loading the PayPal API functionality" );
			$this->load_paypal_libs();
			
		} else {
			$utils->log( "Egad! PayPal library is missing/not loaded!!!" );
			$this->load_paypal_libs();
		}
		
		return true;
	}
	
	/**
	 * Configure the subscription information for the user data for the current Payment Gateway
	 *
	 * @param User_Data|bool $user_data The User_Data record to process for
	 * @param string         $addon     - The name of the add-on being called by the cron job
	 *
	 * @return bool|User_Data
	 */
	public function get_gateway_subscriptions( $user_data, $addon ) {
		
		$utils = Utilities::get_instance();
		$stub  = apply_filters( "e20r_pw_addon_paypal_gateway_addon_name", null );
		$data  = null;
		
		if ( 1 !== preg_match( "/paypal/i", $addon ) ) {
			$utils->log( "While in the PayPal module, the system asked to process for {$addon}" );
			
			return $user_data;
		}
		
		if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
			$utils->log( "Failed check of gateway / gateway licence for the add-on" );
			
			return $user_data;
		}
		
		if ( false === $this->gateway_loaded ) {
			$utils->log( "Loading the PMPro PayPal Gateway instance" );
			$this->load_paypal_libs();
		}
		
		$cust_id = $user_data->get_gateway_customer_id();
		
		if ( empty( $cust_id ) ) {
			
			$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
			
			return $user_data;
		}
		
		$pmpro_order    = $user_data->get_last_pmpro_order();
		$transaction_id = isset( $pmpro_order->payment_transaction_id ) ? $pmpro_order->payment_transaction_id : null;
		
		if ( empty( $transaction_id ) ) {
			$utils->log( "No PayPal transaction found for this user: " . $user_data->get_user_ID() );
			
			return false;
		}
		
		if ( true === $this->gateway_loaded ) {
			
			$payment_profile            = new GetRecurringPaymentsProfileDetailsRequestType();
			$payment_profile->ProfileID = $cust_id;
			
			$find_recurring                                            = new GetRecurringPaymentsProfileDetailsReq();
			$find_recurring->GetRecurringPaymentsProfileDetailsRequest = $payment_profile;
			
			$user_email = $user_data->get_user_email();
			
			try {
				
				$utils->log( "Accessing PayPal API service for {$cust_id}" );
				
				/**
				 * @var GetRecurringPaymentsProfileDetailsResponseType $response
				 */
				$response = $this->gateway->GetRecurringPaymentsProfileDetails( $find_recurring );
				
				/**
				 * @var GetRecurringPaymentsProfileDetailsResponseDetailsType $subscription
				 */
				$subscription = $response->GetRecurringPaymentsProfileDetailsResponseDetails;
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer data: " . $exception->getMessage() );
				$utils->add_message( sprintf( __( "PayPal subscription data for %s not found: %s", Payment_Warning::plugin_slug ), $user_email, $exception->getMessage() ), 'warning', 'backend' );
				
				$user_data->set_active_subscription( false );
				
				return $user_data;
			}
			
			$utils->log( "Available PayPal subscription data collected for {$cust_id} -> {$user_email}" );
			
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $subscription->PayerInfoType ) && $user_email !== $subscription->PayerInfoType->Email ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email PayPal account {$subscription->PayerInfoType->Email} doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $subscription->PayerInfoType->Email, $user_email );
				
				return $user_data;
			}
			
			$utils->log( "Loading most recent local PMPro order info" );
			
			$paypal_statuses = apply_filters( 'e20r_pw_addon_subscr_statuses', array(), $this->gateway_name, $addon );
			
			$payment_next_ts = $this->get_pp_time_as_seconds( $subscription->RecurringPaymentsSummary->NextBillingDate );
			
			$payment_next  = ! empty( $payment_next_ts ) ? date( 'Y-m-d H:i:s', $payment_next_ts ) : null;
			$already_saved = $user_data->has_subscription_id( $subscription->ProfileID );
			$saved_next    = $user_data->get_next_payment( $subscription->ProfileID );
			
			$utils->log( "Using {$payment_next} for payment_next and saved_next: {$saved_next}" );
			$utils->log( "Stored subscription ID {$subscription->ProfileID}? " . ( $already_saved ? 'Yes' : 'No' ) );
			
			/*if ( true === $already_saved && $payment_next == $saved_next ) {
				
				$utils->log( "Have a current version of the upstream subscription record. No need to process!" );
				continue;
			}
			*/
			$user_data->set_gw_subscription_id( $subscription->ProfileID );
			$user_data->set_active_subscription( true );
			
			if ( $subscription->ProfileID == $pmpro_order->subscription_transaction_id && in_array( $subscription->ProfileStatus, $paypal_statuses ) ) {
				
				$utils->log( "Processing {$subscription->ProfileID} for customer ID {$cust_id}" );
				
				if ( ! empty( $payment_next ) && in_array( $subscription->ProfileStatus, array(
						'ActiveProfile',
						'Active',
						'Pending',
					) )
				) {
					$utils->log( "Setting payment status to 'active' for {$cust_id}" );
					$user_data->set_payment_status( 'active' );
				}
				
				if ( empty( $payment_next ) || ! in_array( $subscription->ProfileStatus, array(
						'ActiveProfile',
						'Active',
						'Pending',
					) )
				) {
					$utils->log( "Setting payment status to 'stopped' for {$cust_id}/" . $user_data->get_user_ID() );
					$user_data->set_payment_status( 'stopped' );
				}
				
				// Set the date for the next payment
				if ( $user_data->get_payment_status() === 'active' ) {
					
					// Get the date when the currently paid for period ends.
					$current_payment_until = date_i18n( 'Y-m-d 23:59:59', $payment_next_ts );
					$user_data->set_end_of_paymentperiod( $current_payment_until );
					$utils->log( "End of the current payment period: {$current_payment_until}" );
					
					// Add a day (first day of new payment period)
					
					$user_data->set_next_payment( $payment_next );
					$utils->log( "Next payment on: {$payment_next}" );
					
					// Note: Defaults to USD if not provided from Payment Gateway (PayPal)
					$plan_currency = ! empty( $subscription->CurrentRecurringPaymentsPeriod->Amount->currencyID ) ? strtoupper( $subscription->CurrentRecurringPaymentsPeriod->Amount->currencyID ) : 'USD';
					$user_data->set_payment_currency( $plan_currency );
					
					$utils->log( "Payments are made in: {$plan_currency}" );
					
					$amount = (float) $subscription->CurrentRecurringPaymentsPeriod->Amount->value;
					$user_data->set_next_payment_amount( $amount );
					
					$utils->log( "Next payment of {$plan_currency} {$amount} will be charged within 24 hours of {$payment_next}" );
					
					$user_data->set_reminder_type( 'recurring' );
				} else {
					
					$ends = date( 'Y-m-d H:i:s', $payment_next_ts + 1 );
					
					$utils->log( "Subscription payment plan is going to end after: " . date( 'Y-m-d 23:59:59', $payment_next_ts + 1 ) );
					$user_data->set_subscription_end();
					
					$utils->log( "Setting end of membership to {$ends}" );
					$user_data->set_end_of_membership_date( $ends );
				}
				
				$utils->log( "Attempting to load credit card (payment method) info from gateway" );
				
				if ( ! empty( $subscription->CreditCard ) ) {
					
					$utils->log( "Payment profile $subscription->ProfileID is funded with a Credit Card" );
					$card_data            = new \stdClass();
					$card_data->object    = 'card';
					$card_data->brand     = $subscription->CreditCard->CreditCardType;
					$card_data->last4     = $subscription->CreditCard->CreditCardNumber;
					$card_data->exp_month = $subscription->CreditCard->ExpMonth;
					$card_data->exp_year  = $subscription->CreditCard->ExpYear;
					
					// Trigger handler for credit card data
					$user_data = $this->update_credit_card_info( $user_data, array( $card_data ), $this->gateway_name );
				} else {
					$utils->log( "Payment Profile {$subscription->ProfileID} is funded directly from PayPal balance" );
				}
				
				$user_data->set_module( $addon );
				
			} else {
				
				$utils->log( "Mismatch between expected (local) subscription ID {$pmpro_order->subscription_transaction_id} and remote ID {$subscription->ProfileID}" );
				/**
				 * @action e20r_pw_addon_save_subscription_mismatch
				 *
				 * @param string                                        $this ->gateway_name
				 * @param User_Data                                     $user_data
				 * @param \MemberOrder                                  $local_order
				 * @param GetRecurringPaymentsProfileDetailsRequestType $subscription
				 */
				do_action( 'e20r_pw_addon_save_subscription_mismatch', $this->gateway_name, $user_data, $pmpro_order, $subscription->ProfileID );
			}
		}
		
		$utils->log( "Returning possibly updated user data to calling function" );
		
		// End
		return $user_data;
	}
	
	/**
	 * Filter handler for upstream user Credit Card data...
	 *
	 * @filter e20r_pw_addon_process_cc_info
	 *
	 * @param User_Data $user_data
	 * @param array     $card_data
	 * @param string    $gateway_name
	 *
	 * @return User_Data
	 *
	 * FIXME: Make update_credit_card_info() method PayPal API friendly in PayPal_Gateway_Addon
	 */
	public function update_credit_card_info( User_Data $user_data, $card_data, $gateway_name ) {
		
		$utils = Utilities::get_instance();
		$stub  = apply_filters( 'e20r_pw_addon_paypal_gateway_addon_name', null );
		
		if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
			$utils->log( "Failed check of gateway / gateway addon licence for the PayPal add-on" );
			
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
	 * Load the payments (single payment) data from the upstream PayPal gateway
	 *
	 * @param User_Data|bool $user_data
	 * @param string         $addon The payment gateway addon we're processing for
	 *
	 * FIXME: Have to add support for PayFlow gateway (doesn't contain the 'paypal' string in the gateway name)
	 *
	 * @return bool|User_Data
	 */
	public function get_gateway_payments( $user_data, $addon ) {
		
		$utils = Utilities::get_instance();
		$stub  = apply_filters( 'e20r_pw_addon_paypal_gateway_addon_name', null );
		$data  = null;
		
		$utils->log( "Processing single payment memberships for {$addon} gateway" );
		
		if ( 1 !== preg_match( "/paypal/i", $addon ) ) {
			$utils->log( "While in the PayPal module, the system asked to process payments for {$addon}" );
			
			return $user_data;
		}
		
		if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
			$utils->log( "Failed check of gateway / gateway addon licence for the add-on" );
			
			return $user_data;
		}
		
		if ( false === $this->gateway_loaded ) {
			$utils->log( "Loading the PMPro PayPal Express Gateway instance" );
			$this->load_paypal_libs();
		}
		
		$cust_id = $user_data->get_gateway_customer_id();
		$user_data->set_active_subscription( false ); // Non-recurring membership
		
		if ( empty( $cust_id ) ) {
			
			$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
			
			return $user_data;
		}
		
		$pmpro_order    = $user_data->get_last_pmpro_order();
		$transaction_id = isset( $pmpro_order->payment_transaction_id ) ? $pmpro_order->payment_transaction_id : null;
		
		if ( empty( $transaction_id ) ) {
			$utils->log( "No PayPal transaction found for this user: " . $user_data->get_user_ID() );
			
			return $user_data;
		}
		
		if ( true === $this->gateway_loaded ) {
			
			$trans_profile                = new GetTransactionDetailsRequestType();
			$trans_profile->TransactionID = $transaction_id;
			
			$find_trans                               = new GetTransactionDetailsReq();
			$find_trans->GetTransactionDetailsRequest = $trans_profile;
			
			$user_email = $user_data->get_user_email();
			
			try {
				
				$utils->log( "Accessing PayPal API service for {$cust_id}/{$transaction_id}" );
				
				/**
				 * @var GetTransactionDetailsResponseType $transaction_data
				 */
				$transaction_data = $this->gateway->GetTransactionDetails( $find_trans );
				
				/**
				 * @var PaymentTransactionType $charge
				 */
				$charge = $transaction_data->PaymentTransactionDetails;
				
			} catch ( \Exception $exception ) {
				
				$utils->log( "Error fetching customer transaction data: " . $exception->getMessage() );
				$utils->add_message( sprintf( __( "PayPal transaction data for %s not found: %s", Payment_Warning::plugin_slug ), $user_email, $exception->getMessage() ), 'warning', 'backend' );
				
				$user_data->set_active_subscription( false );
				
				$transaction = null;
				$charge      = null;
				
				return $user_data;
			}
			
			// Make sure the user email on record locally matches that of the upstream email record for the specified Stripe gateway ID
			if ( isset( $charge->PayerInfo->Payer ) && 1 !== preg_match( "/^{$user_email}$/i", $charge->PayerInfo->Payer ) ) {
				
				$utils->log( "The specified user ({$user_email}) and the customer's email PayPal account {$charge->PayerInfo->Payer} doesn't match! Saving to metadata!" );
				
				do_action( 'e20r_pw_addon_save_email_error_data', $this->gateway_name, $cust_id, $charge->PayerInfo->Payer, $user_email );
				
				// Stop processing this user/
				return $user_data;
			}
			
			if ( ! empty( $charge ) ) {
				
				$user_data->set_payment_currency( $charge->PaymentInfo->GrossAmount->currencyID );
				$user_data->set_payment_amount( $charge->PaymentInfo->GrossAmount->value, $charge->PaymentInfo->GrossAmount->currencyID );
				$user_data->set_charge_info( $transaction_id );
				
				$payment_status = ( 'Completed' == $charge->PaymentInfo->PaymentStatus ? true : false );
				$reason         = null;
				
				if ( false === $payment_status ) {
					
					switch ( $charge->PaymentInfo->PaymentStatus ) {
						case 'Pending':
						case 'Denied':
							$reason = $charge->PaymentInfo->PendingReason;
							break;
						case 'Reversed':
							$reason = $charge->PaymentInfo->ReasonCode;
							break;
						default:
							$reason = sprintf( __( "PayPal returned %s as the status for this transaction (TXN ID: %s)", Payment_Warning::plugin_slug ), $charge->PaymentInfo->PaymentStatus, $transaction_id );
					}
				}
				
				$user_data->is_payment_paid( $payment_status, $reason );
				
				$payment_ts = $this->get_pp_time_as_seconds( $charge->PaymentInfo->PaymentDate );
				$user_data->set_payment_date( $payment_ts, $this->gateway_timezone );
				$user_data->set_end_of_membership_date();
				
				$user_data->set_reminder_type( 'expiration' );
				// $user_data->add_charge_list( $charge );
				
				if ( 'instant' !== $charge->PaymentInfo->PaymentType ) {
					// Add any/all credit card info used for this transaction
					// FIXME: Fetch credit card info from PayPal for this user
					$user_data = $this->process_credit_card_info( $user_data, $charge->PaymentInfo->InstrumentDetails, $this->gateway_name );
				}
				
				// Save the processing module for this record
				$user_data->set_module( $addon );
			} else {
				
				$utils->log( "Error: This is not a valid PayPal Charge! " . print_r( $charge, true ) );
				
				return $user_data;
			}
			
			
			// Save the module processing this record
			$user_data->set_module( $addon );
		} else {
			$utils->log( "The PayPal gateway libraries were NOT loaded!" );
		}
		
		$utils->log( "Returning possibly updated user payment data to calling function" );
		
		return $user_data;
	}
	
	/**
	 * Define statuses for the subscription (recurring billing) plan supported by PayPal
	 *
	 * @param string[] $statuses
	 * @param string   $gateway
	 * @param string   $addon
	 *
	 * @return string[]
	 */
	public function valid_gateway_subscription_statuses( $statuses, $gateway, $addon ) {
		
		if ( $gateway === $this->gateway_name ) {
			// PayPal subscription statuses (from:
			// https://developer.paypal.com/docs/classic/api/merchant/GetRecurringPaymentsProfileDetails_API_Operation_NVP/ )
			$statuses = array( 'ActiveProfile', 'Active', 'Pending', 'Cancelled', 'Suspended', 'Expired' );
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
		$stub = apply_filters( 'e20r_pw_addon_paypal_gateway_addon_name', null );
		
		if ( false === $this->verify_gateway_processor( $user_info, $stub, $this->gateway_name ) ) {
			$util->log( "Failed check of gateway / gateway addon licence for the add-on for {$gateway_customer_id}" );
			
			return $gateway_customer_id;
		}
		
		// Don't run this action handler (unexpected gateway name)
		if ( 1 !== preg_match( "/{$this->gateway_name}/i", $gateway_name ) ) {
			$util->log( "Specified gateway name doesn't match this add-on's gateway: {$gateway_name} vs {$this->gateway_name}. Returning: {$gateway_customer_id}" );
			
			return $gateway_customer_id;
		}
		
		// Load this user's most recent PMPro order (to let us fetch the transaction ID aka the user ID on PayPal.com
		$order = $user_info->get_last_pmpro_order();
		
		if ( ! empty( $order ) ) {
			$gateway_customer_id = $order->payment_transaction_id;
		}
		
		$util->log( "Located PayPal account/Subscription ID: {$gateway_customer_id} for WP User " . $user_info->get_user_ID() );
		
		return $gateway_customer_id;
	}
	
	/**
	 * Set the class (stub) name
	 *
	 * @param null|string $name
	 *
	 * @return null|string
	 */
	public function set_stub_name( $name = null ) {
		
		$name = strtolower( $this->get_class_name() );
		
		return $name;
	}
	
	/**
	 * Filter Handler: Add the 'add Payment Warning Gateway add-on license' settings entry
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
	 * Action handler: Core E20R Roles for PMPro plugin's deactivation hook
	 *
	 * @action e20r_roles_addon_deactivating_core
	 *
	 * @param bool $clear
	 *
	 * @access public
	 * @since  1.0
	 */
	public function deactivate_addon( $clear = false ) {
		
		if ( true == $clear ) {
			// TODO: During core plugin deactivation, remove all capabilities for levels & user(s)
			// FixMe: Delete all option entries from the Database for this add-on
			error_log( "Deactivate the capabilities for all levels & all user(s)!" );
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
	 * @return boolean
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
			
			// Update licensing for this gateway add-on
			$e20r_pw_addons[ $addon ]['active_license'] = Licensing::is_licensed( $addon );
			update_option( "e20r_pw_{$addon}_licensed", $e20r_pw_addons[ $addon ]['active_license'], 'no' );
		}
		
		if ( $is_active === false && true == $this->load_option( 'deactivation_reset' ) ) {
			
			// TODO: During add-on deactivation, remove all capabilities for levels & user(s)
			// FixMe: Delete the option entry/entries from the Database
			
			$utils->log( "Deactivate the capabilities for all levels & all user(s)!" );
		}
		
		$e20r_pw_addons[ $addon ]['is_active']   = $is_active;
		$e20r_pw_addons[ $addon ]['is_licensed'] = Licensing::is_licensed( $addon, true );
		
		$utils->log( "Setting the {$addon} option to {$is_active}/{$e20r_pw_addons[ $addon ]['is_licensed']}" );
		update_option( "e20r_pw_{$addon}_enabled", $e20r_pw_addons[ $addon ]['is_active'], 'yes' );
		update_option( "e20r_pw_{$addon}_licensed", $e20r_pw_addons[ $addon ]['is_licensed'], 'no' );
	}
	
	/**
	 * Configure the settings page/component for this add-on
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function register_settings( $settings = array() ) {
		
		$settings['setting'] = array(
			'option_group'        => "{$this->option_name}_settings",
			'option_name'         => "{$this->option_name}",
			'validation_callback' => array( $this, 'validate_settings' ),
		);
		
		$settings['section'] = array(
			array(
				'id'              => 'e20r_addon_paypal_global',
				'label'           => __( "E20R Payment Warnings: PayPal Gateway Add-on Settings", Payment_Warning::plugin_slug ),
				'render_callback' => array( $this, 'render_settings_text' ),
				'fields'          => array(
					array(
						'id'              => 'primary_service',
						'label'           => __( "PayPal Service used", Payment_Warning::plugin_slug ),
						'render_callback' => array( $this, 'render_select' ),
					),
					/* array(
						'id'              => 'deactivation_reset',
						'label'           => __( "Clean up on Deactivate", Payment_Warning::plugin_slug ),
						'render_callback' => array( $this, 'render_cleanup' ),
					),
					*/
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
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Input for save in PayPal Add-on:: " . print_r( $input, true ) );
		
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
		
		$utils->log( "Saving " . print_r( $this->settings, true ) );
		
		return $this->settings;
	}
	
	/**
	 * Informational text about the bbPress Role add-on settings
	 */
	public function render_settings_text() {
		?>
        <p class="e20r-paypal-global-settings-text">
			<?php _e( "Configure settings for the E20R Payment Warnings: PayPal Gateway add-on", Payment_Warning::plugin_slug ); ?>
        </p>
		<?php
	}
	
	/**
	 * Display the select option for the "Allow anybody to read forum posts" global setting (select)
	 */
	public function render_select() {
		
		$primary_service_id = $this->load_option( 'primary_service' ); ?>

        <select name="<?php esc_attr_e( $this->option_name ); ?>[primary_service]"
                id="<?php esc_attr_e( $this->option_name ); ?>_primary_service">
            <option value="0" <?php selected( $primary_service_id, 0 ); ?>>
				<?php _e( 'Not selected', Payment_Warning::plugin_slug ); ?>
            </option><?php
			
			// Process all of the defined paypal gateway(s)
			foreach ( $this->get_paypal_gateway() as $service_id => $settings ) { ?>
                <option
                        value="<?php esc_attr_e( $service_id ); ?>" <?php selected( $primary_service_id, $service_id ); ?>>
					<?php esc_html_e( $settings['label'] ); ?>
                </option>
				<?php
			} ?>

        </select>
		<?php
	}
}


add_filter( "e20r_pw_addon_paypal_gateway_addon_name", array( PayPal_Gateway_Addon::get_instance(), 'set_stub_name' ) );

// Configure the add-on (global settings array)
global $e20r_pw_addons;
$stub = apply_filters( "e20r_pw_addon_paypal_gateway_addon_name", null );

$e20r_pw_addons[ $stub ] = array(
	'class_name'            => 'PayPal_Gateway_Addon',
	'handler_name'          => 'ipnhandler',
	'is_active'             => (bool) get_option( "e20r_pw_{$stub}_enabled", false ),
	'active_license'        => (bool) get_option( "e20r_pw_{$stub}_licensed", false ),
	'status'                => ( true === (bool) get_option( "e20r_pw_{$stub}_enabled", false ) ? 'active' : 'deactivated' ),
	'label'                 => 'PayPal',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);
