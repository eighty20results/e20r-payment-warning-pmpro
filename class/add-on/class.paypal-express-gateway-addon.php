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
use E20R\Payment_Warning\Utilities\Cache;
use E20R\Payment_Warning\User_Data;
use E20R\Payment_Warning\Utilities\Utilities;
use E20R\Licensing\Licensing;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

if ( ! class_exists( 'E20R\Payment_Warning\Addon\PayPal_Express_Gateway_Addon' ) ) {
	
	class PayPal_Express_Gateway_Addon extends E20R_PW_Gateway_Addon {
  
		const CACHE_GROUP = 'e20r_ppexpress_addon';
  
		/**
		 * The name of this class
		 *
		 * @var string
		 */
		protected $class_name;
		
		/**
		 * @var PayPal_Express_Gateway_Addon|null
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
		protected $pmpro_gateway = array();
		
		/**
		 * @var null|ApiContext
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
		protected $option_name = 'e20r_egwao_ppexpress';
		
		/**
		 * Loading add-on specific webhook handler for PayPal Express (late handling to stay out of the way of PMPro itself)
		 */
		public function load_webhook_handler() {
			
			$util = Utilities::get_instance();
			$util->log( "Loading PayPal Express IPN handler functions..." );
			add_action( 'wp_ajax_nopriv_ipnhandler', array( self::get_instance(), 'webhook_handler' ), 5 );
			add_action( 'wp_ajax_ipnhandler', array( self::get_instance(), 'webhook_handler' ), 5 );
			
			$util->log( "Site has the expected action: " . (
				has_action(
					'wp_ajax_ipnhandler',
					array( self::get_instance(), 'webhook_handler', ) ) ? 'Yes' : 'No' )
			);
		}
		
		/**
		 * Load the payment gateway specific class/code/settings from PMPro
		 */
		public function load_gateway() {
			
			$util = Utilities::get_instance();
			
			// This will load the PayPal Express/PMPro Gateway class _and_ its library(ies)
			$util->log( "PMPro loaded? " . ( defined( 'PMPRO_VERSION' ) ? 'Yes' : 'No' ) );
			$util->log( "PMPro PayPal Express gateway loaded? " . ( class_exists( "\PMProGateway_paypalexpress" ) ? 'Yes' : 'No' ) );
			$util->log( "PayPal Express Class(es) loaded? " . ( class_exists( 'PayPalExpress' ) ? 'Yes' : 'No' ) );
			
			// TODO: Implement load_gateway() method for PayPal Express (if needed).
		}
		
		/**
		 * Do what's required to make PayPal Express libraries visible/active
		 */
		private function load_paypalexpress_libs() {
			
			$this->pmpro_gateway = new \PMProGateway_paypalexpress();
			
			if ( !class_exists( '\PayPal\Rest\ApiContext')) {
			    require_once( E20R_PW_DIR . "/libraries/PayPalExpress/autoload.php");
			    $this->gateway = new ApiContext(
			            new OAuthTokenCredential(
			                    '',
                                ''
                        )
                );
            }
            
			$this->gateway_loaded = true;
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
			$stub  = apply_filters( 'e20r_pw_addon_e20r_paypalexpress_gateway_addon_name', null );
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $user_data;
			}
			
			if ( $gateway_name !== $this->gateway_name ) {
				$utils->log( "Not processing data from the {$this->gateway_name} gateway. Returning..." );
				
				return $user_data;
			}
			
			// TODO: Implement PayPal Express specific fetch of Credit card data
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
			$stub  = apply_filters( "e20r_pw_addon_e20r_paypalexpress_gateway_addon_name", null );
			$data  = null;
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway licence for the add-on" );
				
				return false;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro PayPal Express Gateway instance" );
				$this->load_paypalexpress_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			
			// TODO: Implement get_gateway_subscriptions() method for PayPal Express
			
			// End
			return $user_data;
		}
		
		public function get_gateway_payments( User_Data $user_data ) {
			
			$utils = Utilities::get_instance();
			$stub  = apply_filters( 'e20r_pw_addon_e20r_stripe_gateway_addon_name', null );
			$data  = null;
			
			if ( false === $this->verify_gateway_processor( $user_data, $stub, $this->gateway_name ) ) {
				$utils->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $user_data;
			}
			
			if ( false === $this->gateway_loaded ) {
				$utils->log( "Loading the PMPro Stripe Gateway instance" );
				$this->load_paypalexpress_libs();
			}
			
			$cust_id = $user_data->get_gateway_customer_id();
			$user_data->set_active_subscription( false );
			
			$last_order    = $user_data->get_last_pmpro_order();
			$last_order_id = ! empty( $last_order->payment_transaction_id ) ? $last_order->payment_transaction_id : null;
			
			if ( empty( $cust_id ) ) {
				
				$utils->log( "No Gateway specific customer ID found for specified user: " . $user_data->get_user_ID() );
				
				return false;
			}
			// TODO: Implement gateway specific get_gateway_payments filter method
			
			return $user_data;
		}
		
		public function valid_gateway_subscription_statuses( $statuses, $gateway ) {
			
			if ( $gateway === $this->gateway_name ) {
				// PayPal Express subscription statuses (from: )
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
			$stub = apply_filters( 'e20r_pw_addon_e20r_paypalexpress_gateway_addon_name', null );
			
			if ( false === $this->verify_gateway_processor( $user_info, $stub, $this->gateway_name ) ) {
				$util->log( "Failed check of gateway / gateway addon licence for the add-on" );
				
				return $gateway_customer_id;
			}
			// TODO: Load PayPal Express specific customer ID from local customer
		}
		
        /**
		 *  PayPal_Express_Gateway_Addon constructor.
		 */
		public function __construct() {
			
			parent::__construct();
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_stub_name' ) );
			
			if ( is_null( self::$instance ) ) {
				self::$instance = $this;
			}
			
			$this->class_name   = $this->maybe_extract_class_name( get_class( $this ) );
			$this->gateway_name = 'paypal_express';
			
			if ( function_exists( 'pmpro_getOption' ) ) {
				$this->current_gateway_type = pmpro_getOption( "gateway_environment" );
			}
			
			$this->define_settings();
		}
  
		public function set_stub_name( $name = null ) {
			
		    $name = strtolower( $this->get_class_name() );
			return $name;
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
		 * @param $string
		 *
		 * @return mixed
		 */
		private function maybe_extract_class_name( $string ) {
			
		    $utils = Utilities::get_instance();
            $utils->log( "Supplied (potential) class name: {$string}" );
			
			$class_array = explode( '\\', $string );
			$name        = $class_array[ ( count( $class_array ) - 1 ) ];
			
			return $name;
		}
		
		/**
		 * Filter Handler: Add the 'add Payment Warning Gateway add-on license' settings entry
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
				$utils->log("Init array of licenses entry");
			}
			
			$stub = strtolower( $this->get_class_name() );
			$utils->log("Have " . count( $settings['new_licenses'] ) . " new licenses to process already. Adding {$stub}... ");
			
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
				'placeholder'   => sprintf( __( "Paste the purchased E20R Roles %s key here", "e20r-licensing" ), $e20r_pw_addons[ $stub ]['label'] ),
			);
			
			return $settings;
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
				'paypalexpress_api_version'   => null,
				'deactivation_reset' => false,
			);
			
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
         *
         * @return boolean
		 */
		final public static function is_enabled( $stub ) {
			
			$utils = Utilities::get_instance();
			global $e20r_pw_addons;
			
			// TODO: Set the filter name to match the sub for this plugin.
   
			/**
			 * Toggle ourselves on/off, and handle any deactivation if needed.
			 */
			add_action( 'e20r_pw_addon_toggle_addon', array( self::get_instance(), 'toggle_addon' ), 10, 2 );
			add_action( 'e20r_pw_addon_deactivating_core', array(
				self::get_instance(),
				'deactivate_addon',
			), 10, 1 );
			
			/**
			 * Configuration actions & filters
			 */
			add_filter( 'e20r-license-add-new-licenses', array(
				self::get_instance(),
				'add_new_license_info',
			), 10, 1 );
   
			$class_name = self::get_instance()->get_class_name();
			
			$utils->log("Running register settings for {$class_name}");
			add_filter( "e20r_pw_addon_options_{$class_name}", array( self::get_instance(), 'register_settings', ), 10, 1 );
			
			if ( true === parent::is_enabled( $stub ) ) {
				
				$utils->log( "Loading other actions/filters for {$e20r_pw_addons[$stub]['label']}" );
				
				add_action( 'e20r_pw_addon_save_email_error_data', array(
					self::get_instance(),
					'save_email_error',
				), 10, 3 );
				add_action( 'e20r_pw_addon_save_subscription_mismatch', array( self::get_instance(), 'save_subscription_mismatch' ), 10, 3 );
				
				/**
				 * Membership related settings for role(s) add-on
				 */
//				add_action( 'e20r_pw_level_settings', array( self::get_instance(), 'load_level_settings' ), 10, 2 );
//				add_action( 'e20r_pw_level_settings_save', array( self::get_instance(), 'save_level_settings', ), 10, 2 );
//				add_action( 'e20r_pw_level_settings_delete', array( self::get_instance(), 'delete_level_settings', ), 10, 2 );
				
				add_action( 'e20r_pw_addon_load_gateway', array( self::get_instance(), 'load_gateway' ), 10, 1 );
				add_action( 'e20r_pw_addon_get_user_customer_id', array( self::get_instance(), 'get_local_user_customer_id' ), 10, 3 );
				add_action( 'e20r_pw_addon_get_user_subscriptions', array( self::get_instance(), 'get_gateway_subscriptions' ), 10, 0 );
				add_action( 'e20r_pw_addon_get_user_payments', array( self::get_instance(), 'get_gateway_payments' ), 10, 0 );
				add_action( 'e20r_pw_process_warnings', array( self::get_instance(), 'send_warnings' ), 10, 0 );
				
				add_action( 'e20r_pw_addon_add_remote_call_handler', array( self::get_instance(), 'load_webhook_handler' ) );
				
				add_filter( 'e20r_pw_addon_gateway_subscr_statuses', array( self::get_instance(), 'valid_gateway_subscription_statuses' ), 10, 2 );
				add_filter( 'e20r_pw_addon_process_cc_info', array( self::get_instance(), 'update_credit_card_info' ), 10, 3 );
			}
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
					'id'              => 'e20r_addon_paypalexpress_global',
					'label'           => __( "E20R Payment Warnings: PayPal Express Gateway Add-on Settings", Payment_Warning::plugin_slug ),
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
				error_log( "Input for save in PayPal Express Add-on:: " . print_r( $input, true ) );
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
				error_log( "PayPal_Express_Addon saving " . print_r( $this->settings, true ) );
			}
			
			return $this->settings;
		}
		
		/**
		 * Informational text about the bbPress Role add-on settings
		 */
		public function render_settings_text() {
			?>
            <p class="e20r-paypalexpress-global-settings-text">
				<?php _e( "Configure global settings for the E20R Payment Warnings: PayPal Express Gateway add-on", Payment_Warning::plugin_slug ); ?>
            </p>
			<?php
		}
		
		/**
		 * Display the select option for the "Allow anybody to read forum posts" global setting (select)
		 */
		public function render_select() {
			
			$primary_gateway = $this->load_option( 'primary_gateway' );
   
			?>
            <select name="<?php esc_attr_e( $this->option_name ); ?>[primary_gateway]"
                    id="<?php esc_attr_e( $this->option_name ); ?>_primary_gateway">
                <option value="0" <?php selected( $primary_gateway, 0 ); ?>>
					<?php _e( 'Disabled', Payment_Warning::plugin_slug ); ?>
                </option>
                <option value="1" <?php selected( $primary_gateway, 1 ); ?>>
					<?php _e( 'Read Only', Payment_Warning::plugin_slug ); ?>
                </option>
            </select>
			<?php
		}
  
		/**
		 * Fetch the properties for the Example add-on class
		 *
		 * @return PayPal_Express_Gateway_Addon|null
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

add_filter( "e20r_pw_addon_e20r_paypalexpress_gateway_addon_name", array( PayPal_Express_Gateway_Addon::get_instance(), 'set_stub_name' ) );

// Configure the add-on (global settings array)
global $e20r_pw_addons;
$stub = apply_filters( "e20r_pw_addon_e20r_paypalexpress_gateway_addon_name", null );

$e20r_pw_addons[ $stub ] = array(
	'class_name'            => 'PayPal_Express_Gateway_Addon',
	'is_active'             => ( get_option( "e20r_pw_addon_{$stub}_enabled", false ) == 1 ? true : false ),
	'active_license'        => ( get_option( "e20r_pw_addon_{$stub}_licensed", false ) == true ? true : false ),
	'status'                => 'deactivated',
	'label'                 => 'PayPal Express Gateway',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);