<?php
/*
Plugin Name: E20R Payment Warning Messages for Paid Memberships Pro
Description: Send Email warnings to members (Credit Card & Membership Expiration warnings + Upcoming recurring membership payment notices)
Plugin URI: https://eighty20results.com/wordpress-plugins/e20r-payment-warning-pmpro
Author: Eighty / 20 Results by Wicked Strong Chicks, LLC <thomas@eighty20results.com>
Author URI: https://eighty20results.com/thomas-sjolshagen/
Developer: Thomas Sjolshagen <thomas@eighty20results.com>
Developer URI: https://eighty20results.com/thomas-sjolshagen/
PHP Version: 5.4
Version: 4.4.2
License: GPL2
Text Domain: e20r-payment-warning-pmpro
Domain Path: /languages

 * Copyright (c) 2017-2018 - Eighty / 20 Results by Wicked Strong Chicks.
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

namespace E20R\Payment_Warning;

use E20R\Payment_Warning\Editor\Reminder_Editor;
use E20R\Payment_Warning\Tools\Membership_Settings;
use E20R\Payment_Warning\Upgrades;
use E20R\Utilities\Cache;
use E20R\Payment_Warning\Tools\Cron_Handler;
use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;
use E20R\Payment_Warning\Tools\Global_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die( __( "Cannot access", Payment_Warning::plugin_slug ) );
}

if ( ! defined( 'E20R_PW_VERSION' ) ) {
	define( 'E20R_PW_VERSION', '4.4.2' );
}

if ( ! defined( 'E20R_PW_DIR' ) ) {
	define( 'E20R_PW_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'E20R_WP_TEMPLATES' ) ) {
	define( 'E20R_WP_TEMPLATES', plugin_dir_path( __FILE__ ) . 'templates' );
}

if ( ! class_exists( 'E20R\Payment_Warning\Payment_Warning' ) ) {
	
	global $e20r_pw_addons;
	$e20r_pw_addons = array();
	
	global $e20rpw_db_version;
	$e20rpw_db_version = 4; // The current version of the DB schema
	
	class Payment_Warning {
		
		/**
		 * Various constants for the class/plugin
		 */
		const plugin_prefix = 'e20r_payment_warning_';
		const plugin_slug = 'e20r-payment-warning-pmpro';
		
		const cache_group = 'e20r_pw';
		const option_group = 'e20r_pw_options';
		
		const version = E20R_PW_VERSION;
		/**
		 * Instance of this class (Payment_Warning)
		 * @var Payment_Warning
		 *
		 * @access private
		 * @since  1.0
		 */
		static private $instance = null;
		
		protected $process_subscriptions = null;
		
		protected $process_payments = null;
		
		protected $lsubscription_requests = null;
		
		protected $lpayment_requests = null;
		
		/**
		 * Payment_Warning constructor.
		 *
		 * @access private
		 * @since  1.0
		 *
		 * @since  2.1 - ENHANCEMENT: Subscription & Payment data collection didn't work when having multiple active gateways!
		 */
		private function __construct() {
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_translation_domain' ) );
			
			$utils = Utilities::get_instance();
			
			$this->lsubscription_requests = new Large_Request_Handler( 'subscriptions' );
			$this->lpayment_requests      = new Large_Request_Handler( 'payments' );
			$this->process_payments       = array();
			$this->process_subscriptions  = array();
			$addon_options                = $this->get_addons();
			
			foreach ( $addon_options as $addon ) {
				
				$utils->log( "Loading subscription and payment handling class for the {$addon} module" );
				$this->process_subscriptions[ $addon ] = new Handle_Subscriptions( $addon );
				$this->process_payments[ $addon ]      = new Handle_Payments( $addon );
				
			}
			
			$message_types = apply_filters( 'e20rpw_warning_message_types', array(
				'recurring',
				'expiring',
				'ccexpiring',
			) );
			
			// Loop for the available Payment Warning message types to process (create handler processes)
			foreach ( $message_types as $type ) {
				
				$utils->log( "Loading messsage handling class for {$type} messages" );
				
				$handler_name          = "process_{$type}";
				$this->{$handler_name} = new Handle_Messages( $type );
			}
			
			// First thing to do on activation (Required for this plugin)
			add_action( 'e20r_pw_addon_activating_core', '\E20R\Payment_Warning\Tools\DB_Tables::create', - 1 );
			add_action( 'e20r_pw_addon_activating_core', array(
				Cron_Handler::get_instance(),
				'configure_cron_schedules',
			), 10 );
			
			// Add Plugin deactivation actions
			add_action( 'e20r_pw_addon_deactivating_core', array(
				Cron_Handler::get_instance(),
				'remove_cron_jobs',
			), 10 );
		}
		
		/**
		 * Returns the handler for the request type
		 *
		 * @param string $type
		 * @param string $addon
		 *
		 * @return Handle_Messages|Handle_Payments|Handle_Subscriptions|Large_Request_Handler|null
		 *
		 * @since 2.1 - ENHANCEMENT: Subscription & Payment data collection didn't work when having multiple active gateways!
		 */
		public function get_handler( $type, $addon = null ) {
			
			$handler = null;
			$utils   = Utilities::get_instance();
			
			$utils->log( "Fetch handler for {$type}" );
			
			switch ( $type ) {
				case "lhr_subscriptions":
					$handler = $this->lsubscription_requests;
					break;
				
				case 'lhr_payments':
					$handler = $this->lpayment_requests;
					break;
				case "subscription":
					$handler = $this->process_subscriptions[ $addon ];
					break;
				case "payment":
					$handler = $this->process_payments[ $addon ];
					break;
				
				default:
					$handler_name = "process_{$type}";
					$handler      = ! empty( $this->{$handler_name} ) ? $this->{$handler_name} : null;
			}
			
			if ( empty( $handler ) ) {
				$utils = Utilities::get_instance();
				$utils->log( "Error: Unable to assign handler for type {$type}!!!" );
			}
			
			return $handler;
		}
		
		/**
		 * Returns the instance of this class (singleton pattern)
		 *
		 * @return Payment_Warning
		 *
		 * @access public
		 * @since  1.0
		 */
		static public function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				
				self::$instance = new self;
			}
			
			return self::$instance;
		}
		
		/**
		 * Disable actions/jobs for PMPro if equivalent service is enabled in this plugin
		 */
		public function disable_pmpro_actions() {
			
			$util = Utilities::get_instance();
			
			// Disable Recurring payment warnings if enabled in plugin
			if ( true == Global_Settings::load_options( 'enable_payment_warnings' ) ) {
				
				// Disable default PMPro (addon) recurring payment notice;
				$util->log( "Disable recurring payment emails action if present" );
				add_filter( 'pmprorm_send_reminder_to_user', '__return_false', 999 );
				remove_action( "pmpro_cron_expiration_warnings", "pmpror_recurring_emails", 30 );
			}
			
			// Disable expiration warnings if enabled in plugin
			if ( true == Global_Settings::load_options( 'enable_expiration_warnings' ) ) {
				
				// Disable default PMPro expiration warnings;
				$util->log( "Disable membership expiration warning emails, if present" );
				
				add_filter( "pmpro_send_expiration_warning_email", "__return_false", 999 );
				remove_action( "pmpro_cron_expiration_warnings", "pmproeewe_extra_emails", 30 );
				remove_action( "pmpro_cron_expiration_warnings", "pmpro_cron_expiration_warnings", 10 );
			}
			
			// Disable Credit Card Expiration warnings if enabled in plugin
			if ( true == Global_Settings::load_options( 'enable_cc_expiration_warnings' ) ) {
				
				// Disable PMPro Credit Card Expiration warning messages
				$util->log( "Disable credit card expiration warning emails action if present" );
				
				remove_action( 'pmpro_cron_credit_card_expiring_warnings', 'pmpro_cron_credit_card_expiring_warnings', 10 );
			}
		}
		
		/**
		 * Configure actions & filters for this plugin
		 *
		 * @access public
		 * @since  1.0
		 * @since  1.9.9 - ENHANCEMENT: Add 30 minute Cron schedule
		 * @since  3.8 - ENHANCEMENT: Be more discering about the hooks/filters being when not doing a CRON job or the user isn't logged in
		 */
		public function plugins_loaded() {
			
			$utils = Utilities::get_instance();
			
			$utils->log( "Checking that we're not working on a license check (loopback)" );
			preg_match( "/eighty20results\.com/i", Licensing::E20R_LICENSE_SERVER_URL, $is_licensing_server );
			
			if ( 'slm_check' == $utils->get_variable( 'slm_action', false ) && ! empty( $is_licensing_server ) ) {
				$utils->log( "Processing license server operation (self referential check). Bailing!" );
				
				return;
			}
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_translation_domain' ), 10, 1 );
			add_action( 'init', array( $this, 'load_translation' ) );
			
			/**
			 * @since v4.3 - BUG FIX: Load the payment reminder message filters on init (decides whether to send the message, etc)
			 */
			add_filter( 'init', array( Payment_Reminder::get_instance(), 'load_hooks' ) );
			
			// Configure E20R_DEBUG_OVERRIDE constant in wp-config.php during testing
			if ( defined( 'E20R_DEBUG_OVERRIDE' ) && true === E20R_DEBUG_OVERRIDE ) {
				
				$utils->log( "Admin requested that we ignore the schedule delays/settings for testing purposes" );
				add_filter( 'e20r_payment_warning_schedule_override', '__return_true' );
				add_filter( 'e20r-email-notice-send-override', '__return_true' );
				add_filter( 'e20r-payment-warning-send-email', array(
					Payment_Reminder::get_instance(),
					'send_reminder_override',
				), 9999, 4 );
				
				if ( defined( 'E20R_DEBUG_TIMEOUT' ) && true === E20R_DEBUG_TIMEOUT ) {
					add_filter( 'e20r-payment-warning-fetch-timeout', array( $this, 'debug_timeout_value' ), 10, 1 );
				}
			}
			
			$this->load_addon_settings();
			
			/**
			 * Limit activity when not logged in or executing the CRON jobs
			 *
			 * @since v3.8 - ENHANCEMENT: Limit activity when not logged in or executing the CRON jobs
			 */
			if ( is_user_logged_in() || true === wp_doing_cron() ) {
				
				// Maybe upgrade the DB
				add_action( 'init', array( Payment_Warning::get_instance(), 'trigger_db_upgrade' ), - 1 );
				
				// Add any licensing warnings
				add_action( 'init', array( self::get_instance(), 'check_license_warnings' ), 10 );
				
				// Deactivate PMPro actions
				add_action( 'init', array( self::get_instance(), 'disable_pmpro_actions' ), 999 );
				
				add_action( 'current_screen', array( $this, 'check_admin_screen' ), 10 );
				
				/**
				 * Add 30 minute cron job schedule
				 *
				 * @since v1.9.9 - ENHANCEMENT: Add 30 minute Cron schedule
				 */
				add_filter( 'cron_schedules', array( Cron_Handler::get_instance(), 'cron_schedules' ), 10, 1 );
				
				/**
				 * @since 3.8 - ENHANCEMENT: Only load certain actions if we're exclusively executing a CRON job
				 */
				if ( true === wp_doing_cron() || Utilities::is_admin() ) {
					
					add_action( 'e20r_run_remote_data_update', array(
						Cron_Handler::get_instance(),
						'fetch_gateway_payment_info',
					) );
					add_action( 'e20r_send_payment_warning_emails', array(
						Cron_Handler::get_instance(),
						'send_reminder_messages',
					) );
					add_action( 'e20r_send_expiration_warning_emails', array(
						Cron_Handler::get_instance(),
						'send_expiration_messages',
					) );
					add_action( 'e20r_send_creditcard_warning_emails', array(
						Cron_Handler::get_instance(),
						'send_cc_warning_messages',
					) );
					
					/**
					 * Add hook to monitor background job mutext settings
					 *
					 * @since v1.9.9 - ENHANCEMENT: WP_Cron hook that monitors background data collection jobs
					 */
					add_action( 'e20r_check_job_status', array( Cron_Handler::get_instance(), 'clear_mutexes' ) );
					
					add_action( 'e20r_pw_cron_trigger_capture_data', array(
						self::$instance,
						'load_active_subscriptions',
					), 10, 2 );
					add_action( 'e20r_pw_cron_trigger_send_messages', array(
						self::$instance,
						'send_recurring_payment_warnings',
					), 10 );
				}
				
				/**
				 * @since 3.8 - ENHANCEMENT - Only load certain actions if we're exclusively loading the WP backend
				 */
				if ( Utilities::is_admin() ) {
					
					// Load the admin & settings menu
					add_action( 'admin_menu', array(
						Global_Settings::get_instance(),
						'load_admin_settings_page',
					), 10 );
					add_action( 'admin_menu', array( Reminder_Editor::get_instance(), 'load_tools_menu_item' ) );
					
					// add_action( 'admin_enqueue_scripts', array( $this, 'admin_register_scripts' ), 9 );
					// add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 20 );
					
					if ( ! empty ( $GLOBALS['pagenow'] )
					     && ( 'options-general.php' === $GLOBALS['pagenow']
					          || 'options.php' === $GLOBALS['pagenow']
					     )
					) {
						add_action( 'admin_init', array(
							Global_Settings::get_instance(),
							'register_settings_page',
						), 10 );
					}
					
					add_action( 'pmpro_save_discount_code_level', array(
						Membership_Settings::get_instance(),
						'updated_discount_codes',
					), 10, 2 );
					add_action( 'pmpro_save_membership_level', array(
						Membership_Settings::get_instance(),
						'updated_membership_level',
					), 10, 1 );
					
					add_action( 'wp_ajax_e20rpw_save_template', array(
						Reminder_Editor::get_instance(),
						'save_template',
					) );
					add_action( 'wp_ajax_e20rpw_reset_template', array(
						Reminder_Editor::get_instance(),
						'reset_template',
					) );
					
					// add_filter( 'e20r_pw_message_substitution_variables', 'E20R\Payment_Warning\Tools\Email_Message::replace_variable_text', 10, 3 );
					
					if ( Utilities::is_admin() || true === wp_doing_cron() ) {
						
						$this->load_licensed_modules();
						
						add_filter( 'e20r-email-notice-footer-company-name', array(
							$this,
							'get_company_name',
						), 10, 2 );
						add_filter( 'e20r-email-notice-footer-company-address', array(
							$this,
							'get_company_address',
						), 10, 2 );
						add_filter( 'e20r-email-notice-footer-text', array( $this, 'load_unsubscribe_notice' ), 10, 2 );
						
						if ( true === (bool) Global_Settings::load_options( 'enable_clear_old_data' ) ) {
							
							$utils->log( "Enable deletion of old/stale records in local DB when cron job(s) complete" );
							add_filter( 'e20r-payment-warning-clear-old-records', '__return_true' );
						}
					}
					
					// Only load if both DEBUG and TEST_HOOKS is enabled
					if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG &&
					     defined( 'E20R_PW_TEST_HOOKS' ) && true === E20R_PW_TEST_HOOKS
					) {
						$utils->log("Loading test hooks for E20R Payment Warnings");
						
						add_action( 'wp_ajax_test_get_remote_fetch', array(
							Fetch_User_Data::get_instance(),
							'configure_remote_subscription_data_fetch',
						) );
						add_action( 'wp_ajax_test_get_remote_payment', array(
							Fetch_User_Data::get_instance(),
							'configure_remote_payment_data_fetch',
						) );
						add_action( 'wp_ajax_test_fetch_remote_info', array(
							Cron_Handler::get_instance(),
							'fetch_gateway_payment_info',
						) );
						add_action( 'wp_ajax_test_run_record_check', array(
							Payment_Reminder::get_instance(),
							'process_reminders',
						) );
						add_action( 'wp_ajax_test_clear_cache', array(
							Fetch_User_Data::get_instance(),
							'clear_member_cache',
						) );
						add_action( 'wp_ajax_test_update_period', array(
							Cron_Handler::get_instance(),
							'find_shortest_recurring_period',
						) );
						add_action( 'wp_ajax_test_send_reminder', array(
							Cron_Handler::get_instance(),
							'send_reminder_messages',
						) );
						
						add_action( 'wp_ajax_test_send_ccexpiration', array(
								Cron_Handler::get_instance(),
								'send_cc_warning_messages',
							)
						);
					}
				}
			}
			
			// Last thing to do on deactivation (Required for this plugin)
			add_action( 'e20r_pw_addon_deactivating_core', '\E20R\Payment_Warning\Tools\DB_Tables::remove', 9999, 1 );
			add_action( 'e20r_pw_addon_deactivating_core', array(
				Reminder_Editor::get_instance(),
				'deactivate_plugin',
			), 10, 1 );
			
			// add_action( 'e20r_pw_addon_deactivating_core', array( Handle_Subscriptions::get_instance(), 'deactivate' ), 10, 1);
			// add_action( 'e20r_pw_addon_activating_core', array( Cron_Handler::get_instance(), 'configure_cron_schedules'), 10, 0);
			
			$utils->log( "Loading any/all remote IPN/Webhook/SilentPost/etc handlers for add-ons" );
			
			/**
			 * Add all module remote AJAX call actions
			 *
			 * @since v3.8 - ENHANCEMENT: Always load the remote webhook/silent post/IPN handler functions for the plugin
			 */
			do_action( 'e20r_pw_addon_remote_call_handler' );
		}
		
		/**
		 * Override the timeout value for queue based processing during debug
		 *
		 * @param int $value
		 *
		 * @return int
		 */
		public function debug_timeout_value( $value ) {
			return - 2;
		}
		
		/**
		 * Filter handler to add a footer text (unubscribe link, etc)
		 *
		 * @filter e20r-email-notice-footer-text
		 *
		 * @param string $footer_text
		 * @param string $plugin
		 *
		 * @return mixed
		 */
		public function load_unsubscribe_notice( $footer_text, $plugin ) {
			
			if ( $plugin === Payment_Warning::plugin_slug ) {
				$footer_text = null; // FIXME: Add notice unsubscribe link for the plugin - $this->load_options('company_address');
			}
			
			return $footer_text;
		}
		
		/**
		 * Filter handler to load company address setting from DB
		 *
		 * @filter e20r-email-notice-footer-company-address
		 *
		 * @param string $company_address
		 * @param string $plugin
		 *
		 * @return mixed
		 */
		public function get_company_address( $company_address, $plugin ) {
			
			if ( $plugin === Payment_Warning::plugin_slug ) {
				$company_address = Global_Settings::load_options( 'company_address' );
			}
			
			return $company_address;
		}
		
		/**
		 * Filter handler to load company name setting from DB
		 *
		 * @filter e20r-email-notice-footer-company-name
		 *
		 * @param string $company_name
		 * @param string $plugin
		 *
		 * @return mixed
		 */
		public function get_company_name( $company_name, $plugin ) {
			
			if ( $plugin === Payment_Warning::plugin_slug ) {
				$company_name = Global_Settings::load_options( 'company_name' );
			}
			
			return $company_name;
		}
		
		/**
		 * Load all modules that require an active license (in the 'init' action!)
		 */
		public function load_licensed_modules() {
			
			$utils = Utilities::get_instance();
			$utils->log( "Loading licensed functionality for..." );
			
			// Load licensed modules (if applicable)
			add_action( 'e20r-pw-load-licensed-modules', array( Reminder_Editor::get_instance(), 'load_hooks' ) );
			
			$active_addons = array( 'stripe_gateway_addon', 'paypal_gateway_addon', 'check_gateway_addon' );
			$has_loaded    = false;
			
			foreach ( $active_addons as $addon_name ) {
				
				if ( true === Licensing::is_licensed( $addon_name ) ) {
					
					$utils->log( "Enable licensed module: {$addon_name}" );
					$has_loaded = true;
				}
			}
			
			if ( true === $has_loaded ) {
				$utils->log( "Loading licensed modules/functionality!" );
				do_action( 'e20r-pw-load-licensed-modules' );
			}
		}
		
		/**
		 * Validate that we're on the plugin specific screen/page for this add-on
		 *
		 * @param array $current_screen
		 */
		public function check_admin_screen( $current_screen ) {
			
			$utils = Utilities::get_instance();
			
			if ( ! empty( $this->settings_page_hook ) && $this->settings_page_hook === $current_screen->id ) {
				$utils->log( "Clear cache on register settings page" );
				Cache::delete( Licensing::CACHE_KEY, Licensing::CACHE_GROUP );
			}
		}
		
		/**
		 * Configure the Language domain for the licensing class/code
		 *
		 * @param string $domain
		 *
		 * @return string
		 */
		public function set_translation_domain( $domain ) {
			
			return Payment_Warning::plugin_slug;
		}
		
		/**
		 * Load language/translation file(s)
		 */
		public function load_translation() {
			
			$locale = apply_filters( "plugin_locale", get_locale(), Payment_Warning::plugin_slug );
			$mo     = Payment_Warning::plugin_slug . "-{$locale}.mo";
			
			// Paths to local (plugin) and global (WP) language files
			$local_mo  = plugin_dir_path( __FILE__ ) . "/languages/{$mo}";
			$global_mo = WP_LANG_DIR . "/" . Payment_Warning::plugin_slug . "/{$mo}";
			
			// Load global version first
			load_textdomain( Payment_Warning::plugin_slug, $global_mo );
			
			// Load local version second
			load_textdomain( Payment_Warning::plugin_slug, $local_mo );
			
		}
		
		/**
		 * Return list of available add-on classes in add-on directory (may not be loaded yet)
		 *
		 * @return string[]
		 */
		public function get_addons() {
			
			$utils      = Utilities::get_instance();
			$addon_list = array();
			
			$addon_directory_list = apply_filters( 'e20r_pw_addon_directory_path', array( plugin_dir_path( __FILE__ ) . "class/add-on/" ) );
			
			// Search through all of the addon directories supplied
			foreach ( $addon_directory_list as $addon_directory ) {
				
				if ( false !== ( $files = scandir( $addon_directory ) ) ) {
					
					$utils->log( "Found add-on files in {$addon_directory}" );
					
					$excluded = apply_filters( 'e20r_licensing_excluded', array(
						'e20r_default_license',
						'example_gateway_addon',
						'new_licenses',
					) );
					
					foreach ( $files as $file ) {
						
						// Skip (ignore) as add-ons to process/list
						if ( '.' === $file || '..' === $file || 'e20r-pw-gateway-addon' === $file ) {
							continue;
						}
						
						$parts      = explode( '.', $file );
						$class_name = $parts[ count( $parts ) - 2 ];
						$class_name = preg_replace( '/-/', '_', $class_name );
						$addon_info = explode( '_', $class_name );
						$addon      = array_shift( $addon_info );
						
						if ( ! in_array( $addon, $addon_list ) &&
						     ! in_array( $addon, array( 'e20r', 'example' ) )
						) {
							
							$utils->log( "Added {$addon} to list of possible add-ons" );
							$addon_list[] = strtolower( $addon );
						}
					}
				}
			}
			
			return $addon_list;
		}
		
		/**
		 * Load add-on classes & configure them
		 */
		private function load_addon_settings() {
			
			global $e20r_pw_addons;
			
			$utils                = Utilities::get_instance();
			$addon_directory_list = apply_filters( 'e20r_pw_addon_directory_path', array( plugin_dir_path( __FILE__ ) . "class/add-on/" ) );
			
			// Search through all of the addon directories supplied
			foreach ( $addon_directory_list as $addon_directory ) {
				
				if ( false !== ( $files = scandir( $addon_directory ) ) ) {
					
					$utils->log( "Found files in {$addon_directory}" );
					
					foreach ( $files as $file ) {
						
						if ( '.' === $file || '..' === $file || 'e20r-pw-gateway-addon' === $file ) {
							$utils->log( "Skipping file: {$file}" );
							continue;
						}
						
						$parts      = explode( '.', $file );
						$class_name = $parts[ count( $parts ) - 2 ];
						$class_name = preg_replace( '/-/', '_', $class_name );
						
						$utils->log( "Searching for: {$class_name}" );
						
						/**
						 * BUG: Assumes the e20r_pw_addons list contains the list of active add-ons
						 */
						if ( is_array( $e20r_pw_addons ) && ! empty( $e20r_pw_addons ) ) {
							$utils->log( "Addons loaded (yet) configured!" );
							$setting_names = array_map( 'strtolower', array_keys( $e20r_pw_addons ) );
						} else {
							$setting_names = array();
						}
						
						$excluded = apply_filters( 'e20r_licensing_excluded', array(
							'e20r_default_license',
							'example_gateway_addon',
							'new_licenses',
						) );
						
						if ( ! in_array( $class_name, $setting_names ) && ! in_array( $class_name, $excluded ) && false === strpos( $class_name, 'e20r_pw_gateway_addon' )
						) {
							
							$utils->log( "Found unlisted class: {$class_name}" );
							
							$var_name = 'class_name';
							$path     = $addon_directory . sanitize_file_name( $file );
							
							$utils->log( "Path to {$class_name}: {$path}" );
							
							// Include the add-on source file
							if ( file_exists( $path ) ) {
								
								$utils->log( "Loading source file for {$class_name}" );
								require_once( $path );
								
								$class = $e20r_pw_addons[ $class_name ][ $var_name ];
								
								if ( empty( $class ) ) {
									$utils->log( "Expected class {$class_name} was not found!" );
									continue;
								}
								
								$class = "E20R\\Payment_Warning\\Addon\\{$class}";
								
								$utils->log( "Checking if {$class} add-on is enabled?" );
								$enabled = $class::is_enabled( $class_name );
								
								if ( true == $enabled ) {
									$utils->log( "Triggered load of filters & hooks for {$class}" );
									// $class::configure_addon();
								}
							}
						} else {
							$utils->log( "Skipping {$class_name}" );
						}
					}
				}
			}
		}
		
		/**
		 * Returns true if one of the available Gateway add-ons has an active license.
		 *
		 * @return bool
		 */
		public function has_licensed_gateway() {
			
			global $e20r_pw_addons;
			
			$has_licensed_gateway = false;
			
			if ( ! empty( $e20r_pw_addons ) ) {
				
				foreach ( $e20r_pw_addons as $stub => $settings ) {
					
					if ( $stub !== 'standard_gateway_addon' ) {
						$has_licensed_gateway = $has_licensed_gateway || Licensing::is_licensed( $stub );
					}
				}
			}
			
			return $has_licensed_gateway;
		}
		
		/**
		 * Check if the license(s) are valid/active/about to renew
		 * @since v4.3 - ENHANCEMENT: Send license warning/expiration email to admin on fixed frequency
		 * @since v4.3 - ENHANCEMENT: Added `e20rpw_send_license_reminder` filter to configure # of days between reminders
		 */
		public function check_license_warnings() {
			
			global $e20r_pw_addons;
			$products = array_keys( $e20r_pw_addons );
			$utils    = Utilities::get_instance();
			
			$reminder_frequency = apply_filters( 'e20rpw_send_license_reminder', 7 );
			$is_sent            = ( ( intval( get_option( 'e20rpw_license_warning_sent', 0 ) ) ) <=
			                        current_time( 'timestamp' ) + ( $reminder_frequency * DAY_IN_SECONDS ) );
			$admin_email        = get_option( 'admin_email' );
			
			$utils->log( "Check for any license warnings!" );
			
			foreach ( $products as $stub ) {
				
				$utils->log( "Checking status for {$stub}" );
				$license_status = Licensing::is_license_expiring( $stub );
				$utils->log( "Status is: {$license_status}" );
				
				if ( true === $license_status ) {
					
					$msg = sprintf( __( 'The license for the plugin that sends upcoming payment warnings and membership expiration promotions to your members (using the %1$s payment gateway) will renew soon. To keep sending these reminders to your customers who paid via the %1$s payment gateway, please verify that <a href="%2$s" target="_blank">your license</a> will renew automatically by going to <a href="%3$s" target="_blank">your account page</a> and verify the status of your payment method and license' ), $e20r_pw_addons[ $stub ]['label'], 'https://eighty20results.com/licenses/', 'https://eighty20results.com/account/' );
					
					if ( is_admin() ) {
						$utils->add_message( $msg, 'warning', 'backend' );
					}
					
					if ( false === $is_sent ) {
						$utils->log( "Sending license renewing email warning to {$admin_email}" );
						wp_mail( $admin_email, __( "Important: The payment and expiration warnings license may renew soon", Payment_Warning::plugin_slug ), "<p>{$msg}</p>" );
						update_option( 'e20rpw_license_warning_sent', current_time( 'timestamp' ), 'no' );
					}
					
					if ( - 1 === $license_status ) {
						
						$msg = sprintf( __( 'Your %1$s add-on license for sending upcoming payment reminders and membership expiration promotion messages has expired. To resume sending these warnings to your members (using the %1$s payment gateway), you will need to <a href="%2$s" target="_blank">purchase and install a new %1$s license</a>.' ), $e20r_pw_addons[ $stub ]['label'], 'https://eighty20results.com/licenses/' );
						
						if ( is_admin() ) {
							$utils->add_message( $msg, 'error', 'backend' );
						}
						
						if ( false === $is_sent ) {
							$utils->log( "Sending license expired email warning to {$admin_email}" );
							wp_mail(
								$admin_email,
								__( "CRITICAL: Not sending payment and expiration warnings to your members!", Payment_Warning::plugin_slug ),
								"<p>{$msg}</p>"
							);
							update_option( 'e20rpw_license_warning_sent', current_time( 'timestamp' ), 'no' );
						}
					}
				}
			}
		}
		
		/**
		 * Returns an array of add-ons that are active for this plugin
		 *
		 * @return array
		 *
		 * @access public
		 *
		 * @since  2.1 - BUG FIX: Would cache an empty list of active add-ons (if they existed)
		 * @since  2.1 - ENHANCEMENT: Make the get_active_addons() method public
		 */
		public function get_active_addons() {
			
			global $e20r_payment_gateways;
			
			if ( null === ( $active = Cache::get( 'e20r_pw_active_addons', Payment_Warning::cache_group ) ) ) {
				
				$active = array();
				
				foreach ( $e20r_payment_gateways as $addon => $settings ) {
					
					if ( true == $settings['status'] ) {
						$active[] = $addon;
					}
				}
				
				// Only save cache if the list contains something
				if ( ! empty( $active ) ) {
					Cache::set( 'e20r_pw_active_addons', $active, ( 5 * DAY_IN_SECONDS ), Payment_Warning::cache_group );
				}
			}
			
			return $active;
		}
		
		/**
		 * Default response to unprivileged AJAX calls
		 *
		 * @access public
		 * @since  1.0
		 */
		public function unprivileged_error() {
			
			wp_send_json_error( array( 'error_message' => __( 'Error: You do not have access to this feature', self::plugin_slug ) ) );
			wp_die();
		}
		
		/**
		 * Load Admin page JavaScript
		 *
		 * @param $hook
		 *
		 * @since  1.0
		 * @access public
		 */
		public function admin_register_scripts( $hook ) {
			
			if ( 'toplevel_page_pmpro-membershiplevels' != $hook ) {
				return;
			}
			
			wp_enqueue_style( Payment_Warning::plugin_slug . '-admin', plugins_url( 'css/e20r-payment-warning-pmpro-admin.css', __FILE__ ) );
			wp_register_script( Payment_Warning::plugin_slug . '-admin', plugins_url( 'javascript/e20r-payment-warning-pmpro-admin.js', __FILE__ ) );
			
			$vars = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'timeout' => intval( apply_filters( 'e20r_roles_for_pmpro_ajax_timeout_secs', 10 ) * 1000 ),
				// 'nonce'      => wp_create_nonce( Payment_Warning::ajax_fix_action ),
			);
			
			$key = Payment_Warning::plugin_prefix . 'vars';
			
			wp_localize_script( Payment_Warning::plugin_slug . '-admin', $key, $vars );
		}
		
		/**
		 * Delayed enqueue of wp-admin JavasScript (allow unhook)
		 *
		 * @param $hook
		 *
		 * @since  1.0
		 * @access public
		 */
		public function admin_enqueue_scripts( $hook ) {
			
			if ( 'toplevel_page_pmpro-membershiplevels' != $hook ) {
				return;
			}
			
			wp_enqueue_script( self::plugin_slug . '-admin' );
		}
		
		/**
		 * Plugin activation
		 */
		public static function activate() {
			
			$util = Utilities::get_instance();
			
			if ( 0 > version_compare( PHP_VERSION, '5.4' ) ) {
				
				$util->log( "Current PHP Version: " . PHP_VERSION );
				$util->log( 'Plugin name: ' . plugin_basename( E20R_PW_DIR ) );
				wp_die( __( "E20R Payment Warnings for Paid Memberships Pro requires a server configured with PHP version 5.4.0 or later. Please upgrade PHP on your server before attempting to activate this plugin.", Payment_Warning::plugin_slug ) );
				
			} else {
				
				$util->log( "Trigger activation action for add-ons" );
				do_action( 'e20r_pw_addon_activating_core' );
			}
		}
		
		/**
		 * Plugin deactivation
		 */
		public static function deactivate() {
			
			$clean_up = Global_Settings::load_options( 'deactivation_reset' );
			$utils    = Utilities::get_instance();
			
			$utils->log( "Deleting all first-run trigger options" );
			delete_option( 'e20r_pw_firstrun_cc_msg' );
			delete_option( 'e20r_pw_firstrun_exp_msg' );
			delete_option( 'e20r_pw_firstrun_reminder_msg' );
			delete_option( 'e20r_pw_firstrun_gateway_check' );
			
			$utils->log( "Trigger deactivation action for add-ons" );
			do_action( 'e20r_pw_addon_deactivating_core', $clean_up );
		}
		
		/**
		 * Trigger a DB version specific upgrade action
		 */
		public function trigger_db_upgrade() {
			
			global $e20rpw_db_version;
			
			$utils = Utilities::get_instance();
			
			$installed_ver = get_option( 'e20rpw_db_version', 0 );
			
			$upgraded = $this->load_db_upgrades();
			
			$utils->log( "Current version of DB: {$installed_ver} vs needed version: {$e20rpw_db_version}" );
			
			if ( $installed_ver < $e20rpw_db_version ) {
				$utils->log( "Trigger database upgrade to version {$e20rpw_db_version} from {$installed_ver}" );
				do_action( "e20rpw_trigger_database_upgrade_{$e20rpw_db_version}", $installed_ver );
			}
		}
		
		/**
		 * Define list of upgrade classes to use/include
		 *
		 * @return array
		 */
		public function load_db_upgrades() {
			
			$classes   = array();
			$classes[] = new Upgrades\Upgrade_2();
			$classes[] = new Upgrades\Upgrade_3();
			$classes[] = new Upgrades\Upgrade_4();
			
			return $classes;
		}
		
		/**
		 * Class auto-loader for the Payment Warnings for PMPro plugin
		 *
		 * @param string $class_name Name of the class to auto-load
		 *
		 * @since  1.0
		 * @access public static
		 */
		public static function auto_loader( $class_name ) {
			
			if ( false === stripos( $class_name, 'e20r' ) ) {
				return;
			}
			
			$parts     = explode( '\\', $class_name );
			$c_name    = strtolower( preg_replace( '/_/', '-', $parts[ ( count( $parts ) - 1 ) ] ) );
			$base_path = plugin_dir_path( __FILE__ ) . 'classes/';
			
			if ( file_exists( plugin_dir_path( __FILE__ ) . 'class/' ) ) {
				$base_path = plugin_dir_path( __FILE__ ) . 'class/';
			}
			
			$filename = "class.{$c_name}.php";
			$iterator = new \RecursiveDirectoryIterator( $base_path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveIteratorIterator::SELF_FIRST | \RecursiveIteratorIterator::CATCH_GET_CHILD | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS );
			
			/**
			 * Locate class member files, recursively
			 */
			$filter = new \RecursiveCallbackFilterIterator( $iterator, function ( $current, $key, $iterator ) use ( $filename ) {
				
				$file_name = $current->getFilename();
				
				// Skip hidden files and directories.
				if ( $file_name[0] == '.' || $file_name == '..' ) {
					return false;
				}
				
				if ( $current->isDir() ) {
					// Only recurse into intended subdirectories.
					return $file_name() === $filename;
				} else {
					// Only consume files of interest.
					return strpos( $file_name, $filename ) === 0;
				}
			} );
			
			foreach ( new \ RecursiveIteratorIterator( $iterator ) as $f_filename => $f_file ) {
				
				$class_path = $f_file->getPath() . "/" . $f_file->getFilename();
				
				if ( $f_file->isFile() && false !== strpos( $class_path, $filename ) ) {
					require_once( $class_path );
				}
			}
		}
	}
}

/**
 * Register the auto-loader and the activation/deactiviation hooks.
 */
spl_autoload_register( 'E20R\Payment_Warning\Payment_Warning::auto_loader' );

register_activation_hook( __FILE__, array( 'E20R\Payment_Warning\Payment_Warning', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'E20R\Payment_Warning\Payment_Warning', 'deactivate' ) );

// Load this plugin
add_action( 'plugins_loaded', array( Payment_Warning::get_instance(), 'plugins_loaded' ), 10 );

// One-click update handler
if ( ! class_exists( '\\Puc_v4_Factory' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'plugin-updates/plugin-update-checker.php' );
}

$plugin_updates = \Puc_v4_Factory::buildUpdateChecker(
	'https://eighty20results.com/protected-content/e20r-payment-warning-pmpro/metadata.json',
	__FILE__,
	'e20r-payment-warning-pmpro'
);
