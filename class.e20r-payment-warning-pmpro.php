<?php
/**
 * Plugin Name: E20R Payment Warning Messages for Paid Memberships Pro
 * Description: Send Payment warnings (Expiration warnings and Recurring payments)
 * Plugin URI: https://eighty20results.com/wordpress-plugins/e20r-payment-warning-pmpro
 * Author: Thomas Sjolshagen <thomas@eighty20results.com>
 * Author URI: https://eighty20results.com/thomas-sjolshagen/
 * Version: 1.0
 * License: GPL2
 * Text Domain: e20r-payment-warning-pmpro
 * Domain Path: /languages
 */

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

namespace E20R\Payment_Warning;

use Braintree\Util;
use E20R\Payment_Warning\Addon;
use E20R\Payment_Warning\Editor\Editor;
use E20R\Payment_Warning\Utilities\Cache;
use E20R\Payment_Warning\Utilities\Cron_Handler;
use E20R\Payment_Warning\Utilities\Utilities;
use E20R\Licensing\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die( __( "Cannot access", Payment_Warning::plugin_slug ) );
}

if ( ! defined( 'E20R_PW_VERSION' ) ) {
	define( 'E20R_PW_VERSION', '1.0' );
}

if ( !defined ( 'E20R_PW_DIR' ) ) {
    define( 'E20R_PW_DIR', plugin_basename( __FILE__ ) );
}

if ( !defined( 'E20R_WP_TEMPLATES' ) ) {
	define( 'E20R_WP_TEMPLATES', plugin_dir_path( __FILE__ ) . 'templates' );
}

if ( ! class_exists( 'E20R\Payment_Warning\Payment_Warning' ) ) {
	
	global $e20r_pw_addons;
	$e20r_pw_addons = array();
	
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
		
		private $addon_settings = array();
		
		private $settings_page_hook = null;
		
		private $settings_name = 'e20r_payment_warning';
		
		private $settings = array();
  
		protected $process_subscriptions = null;
		
		protected $process_charges = null;
		
		/**
		 * Payment_Warning constructor.
		 *
		 * @access private
		 * @since  1.0
		 */
		private function __construct() {
			
			global $e20rpw_db_version;
			$e20rpw_db_version = E20R_PW_VERSION;
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
				
				// First thing to do on activation (Required for this plugin)
				add_action( 'e20r_pw_addon_activating_core', 'E20R\Payment_Warning\User_Data::create_db_tables', -1 );
				add_action( 'e20r_pw_addon_activating_core', array( Cron_Handler::get_instance(), 'configure_cron_schedules' ), 10 );
				add_action( 'e20r_pw_addon_deactivating_core', array( Cron_Handler::get_instance(), 'remove_cron_jobs' ), 10 );
				
				add_action( 'wp_mail_failed', 'E20R\Payment_Warning\Utilities\Email_Message::email_error_handler', 10 );
				
				add_action( 'e20r_run_remote_data_update', array( Cron_Handler::get_instance(), 'fetch_gateway_payment_info') );
				
				add_action( 'e20r_send_payment_warning_emails', array( Cron_Handler::get_instance(), 'send_reminder_messages' ) );
				add_action( 'e20r_send_expiration_warning_emails', array( Cron_Handler::get_instance(), 'send_expiration_messages' ) );
				add_action( 'e20r_send_creditcard_warning_emails', array( Cron_Handler::get_instance(), 'send_cc_warning_messages' ) );
			}
			
			return self::$instance;
		}
		
		/**
		 * Configure actions & filters for this plugin
		 *
		 * @access public
		 * @since  1.0
		 */
		public function plugins_loaded() {
			
			$utils = Utilities::get_instance();
			
			if ( false !== $utils->get_variable( 'slm_action', false ) && false == preg_match( "/eighty20results.com/", Licensing::E20R_LICENSE_SERVER_URL ) ) {
				$utils->log( "Processing license server operation (self referential check???)" );
				
				return;
			}
   
			$this->load_addon_settings();
			
			add_filter( 'e20r-licensing-text-domain', array( $this, 'set_translation_domain' ), 10, 1 );
			
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_register_scripts' ), 9 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 20 );
			
			add_action( 'e20r_pw_cron_trigger_capture_data', array( self::$instance, 'load_active_subscriptions', ), 10, 2 );
			add_action( 'e20r_pw_cron_trigger_send_messages', array( self::$instance, 'send_recurring_payment_warnings', ), 10 );
			
			add_action( 'init', array( $this, 'load_translation' ) );
			
			add_action( 'admin_menu', array( $this, 'load_admin_settings_page' ), 10 );
			add_action( 'admin_menu', array( Editor::get_instance(), 'load_tools_menu_item' ) );
			
			add_action( 'admin_init', array( $this, 'check_license_warnings' ) );
			
			if ( ! empty ( $GLOBALS['pagenow'] )
			     && ( 'options-general.php' === $GLOBALS['pagenow']
			          || 'options.php' === $GLOBALS['pagenow']
			     )
			) {
				add_action( 'admin_init', array( $this, 'register_settings_page' ), 10 );
			}
			
			add_action( 'current_screen', array( $this, 'check_admin_screen' ), 10 );
			
			// TODO: Testing action:
			
			add_action( 'wp_ajax_test_get_remote_fetch', array( Fetch_User_Data::get_instance(), 'get_remote_data' ) );
            add_action( 'wp_ajax_test_run_record_check', array( Payment_Reminder::get_instance(), 'process_reminders') );
			add_action( 'wp_ajax_test_clear_cache', array( Fetch_User_Data::get_instance(), 'clear_member_cache') );
			add_action( 'wp_ajax_test_update_period', array( Cron_Handler::get_instance(), 'find_shortest_recurring_period' ) );
			add_action( 'wp_ajax_test_send_reminder', array( Cron_Handler::get_instance(), 'send_reminder_messages' ) );
			
			// Last thing to do on deactivation (Required for this plugin)
			add_action( 'e20r_pw_addon_deactivating_core', 'E20R\Payment_Warning\User_Data::delete_db_tables', 9999, 1 );
			add_action( 'e20r_pw_addon_deactivating_core', array( Editor::get_instance(), 'deactivate_plugin' ), 10, 1 );
			// add_action( 'e20r_pw_addon_activating_core', array( Cron_Handler::get_instance(), 'configure_cron_schedules'), 10, 0);
   
			add_action( 'wp_ajax_e20rpw_save_template', array( Editor::get_instance(), 'save_template' ) );
			add_action( 'wp_ajax_e20rpw_reset_template', array( Editor::get_instance(), 'reset_template' ) );
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
		 * Configure the default (global) settings for this add-on
		 * @return array
		 */
		private function default_settings() {
			
			return array(
				'deactivation_reset' => false,
                'upcoming_payment_reminders' => array(),
			);
		}
		
		/**
		 * Set the options for this plugin
		 */
		private function define_settings() {
			
			$this->settings = get_option( $this->settings_name, $this->default_settings() );
		}
		
		/**
		 * Validating the returned values from the Settings API page on save/submit
		 *
		 * @param array $input Changed values from the settings page
		 *
		 * @return array Validated array
		 *
		 * @since  1.0
		 * @access public
		 */
		public function validate_settings( $input ) {
			
			global $e20r_payment_gateways;
			
			$utils = Utilities::get_instance();
			
			$utils->log( "E20R Payment Warning input settings: " . print_r( $input, true ) );
			
			foreach ( $e20r_payment_gateways as $addon_name => $settings ) {
				
				$utils->log( "Trigger local toggle_addon action for {$addon_name}" );
				
				do_action( 'e20r_pw_addon_toggle_addon', $addon_name, isset( $input["is_{$addon_name}_active"] ) );
			}
			
			$defaults = $this->default_settings();
			
			foreach ( $defaults as $key => $value ) {
				
				if ( isset( $input[ $key ] ) ) {
					$this->settings[ $key ] = $input[ $key ];
				} else {
					$this->settings[ $key ] = $defaults[ $key ];
				}
			}
			
			// Validated & updated settings
			return $this->settings;
		}
		
		/**
		 * Load add-on classes & configure them
		 */
		private function load_addon_settings() {
			
			global $e20r_pw_addons;
			
			$utils = Utilities::get_instance();
			$addon_directory_list = apply_filters( 'e20r_pw_addon_directory_path', array( plugin_dir_path( __FILE__ ) . "class/add-on/" ) );
   
			// Search through all of the addon directories supplied
			foreach ( $addon_directory_list as $addon_directory ) {
				
				if ( false !== ( $files = scandir( $addon_directory ) ) ) {
					
				    $utils->log("Found files in {$addon_directory}");
				    
					foreach ( $files as $file ) {
						
						if ( '.' === $file || '..' === $file || 'e20r-pw-gateway-addon' === $file ) {
							$utils->log("Skipping file: {$file}");
							continue;
						}
						
						$parts      = explode( '.', $file );
						$class_name = $parts[ count( $parts ) - 2 ];
						$class_name = preg_replace( '/-/', '_', $class_name );
                        
                        $utils->log( "Searching for: {$class_name}" );
                        
						if ( is_array( $e20r_pw_addons ) ) {
							$setting_names = array_map( 'strtolower', array_keys( $e20r_pw_addons ) );
						} else {
							$setting_names = array();
						}
						
						$excluded = apply_filters( 'e20r_licensing_excluded', array(
							'e20r_default_license',
							'example_addon',
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
								
							    $utils->log("Loading source file for {$class_name}");
								require_once( $path );
								
								$class = $e20r_pw_addons[ $class_name ][ $var_name ];
								$class = "E20R\\Payment_Warning\\Addon\\{$class}";
								
                                $utils->log( "Checking if {$class} add-on is enabled?" );
								$enabled = $class::is_enabled( $class_name );
								
								if ( true == $enabled ) {
                                    $utils->log( "Triggering load of filters & hooks for {$class}" );
									$class::load_addon();
								}
							}
						} else {
						    $utils->log("Skipping {$class_name}");
                        }
					}
				}
			}
		}
		
		/**
		 * Load settings/options for the plugin
		 *
		 * @param $option_name
		 *
		 * @return bool|mixed
		 */
		public function load_options( $option_name ) {
			
			$this->settings = get_option( "{$this->settings_name}", $this->default_settings() );
			
			if ( isset( $this->settings[ $option_name ] ) && ! empty( $this->settings[ $option_name ] ) ) {
				
				return $this->settings[ $option_name ];
			}
			
			return false;
		}
		
		public function check_license_warnings() {
			
			global $e20r_pw_addons;
			$products = array_keys( $e20r_pw_addons );
			$utils    = Utilities::get_instance();
			
			foreach ( $products as $stub ) {
				
				switch ( Licensing::is_license_expiring( $stub ) ) {
					
					case true:
						$utils->add_message( sprintf( __( 'The license for %s will renew soon. As this is an automatic payment, you will not have to do anything. To modify <a href="%s" target="_blank">your license</a>, you will need to access <a href="%s" target="_blank">your account page</a>' ), $e20r_pw_addons[ $stub ]['label'], 'https://eighty20results.com/licenses/', 'https://eighty20results.com/account/' ), 'info', 'backend' );
						break;
					case - 1:
						$utils->add_message( sprintf( __( 'Your add-on license has expired. For continued use of the E20R Roles: %s add-on, you will need to <a href="%s" target="_blank">purchase and install a new license</a>.' ), $e20r_pw_addons[ $stub ]['label'], 'https://eighty20results.com/licenses/' ), 'error', 'backend' );
						break;
				}
			}
		}
		
		/**
		 * Generate the options page for this plugin
		 */
		public function load_admin_settings_page() {
			
		    $utils = Utilities::get_instance();
		    
		    $utils->log("Loading options page for Payment Warnings");
		    
			$this->settings_page_hook = add_options_page(
				__( "Payment Warnings for Paid Memberships Pro", Payment_Warning::plugin_slug ),
				__( "Payment Warnings", Payment_Warning::plugin_slug ),
				apply_filters( 'e20rpw_min_settings_capabilities', 'manage_options' ),
				'e20r-payment-warning-settings',
				array( $this, 'global_settings_page' )
			);
			
			Licensing::add_options_page();
		}
		
		/**
		 * Configure options page for the plugin and include any configured add-ons if needed.
		 */
		public function register_settings_page() {
			
			$utils = Utilities::get_instance();
			$utils->log("Register settings for Payment Warnings");
			
			// Configure our own settings
			register_setting( Payment_Warning::option_group, "{$this->settings_name}", array( $this, 'validate_settings' ) );
			
			$utils->log("Added Global Settings");
			add_settings_section(
				'e20r_pw_global',
				__( 'Global Settings: E20R Payment Warnings for PMPro', Payment_Warning::plugin_slug ),
				array( $this, 'render_global_settings_text', ),
				'e20r-payment-warning-settings'
			);
			
			add_settings_field(
				'e20r_pw_global_reset',
				__( "Reset data on deactivation", Payment_Warning::plugin_slug ),
				array( $this, 'render_checkbox' ),
				'e20r-payment-warning-settings',
				'e20r_pw_global',
				array( 'option_name' => 'deactivation_reset' )
			);
   
			$utils->log("Added Add-on Settings for Payment Warnings");
			add_settings_section(
				'e20r_pw_addons',
				__( 'Add-ons', Payment_Warning::plugin_slug ),
				array( $this, 'render_addon_header' ),
				'e20r-payment-warning-settings'
			);
			
			global $e20r_pw_addons;
			
			/*
			if ( WP_DEBUG ) {
			    error_log("Register Settings - List of add-ons: " . print_r( $e20r_payment_gateways, true ));
            }
			*/
			foreach ( $e20r_pw_addons as $addon => $settings ) {
				
				$utils->log("Adding settings for {$addon}: {$settings['label']}");
				
				add_settings_field(
					"e20r_pw_addons_{$addon}",
					$settings['label'],
					array( $this, 'render_addon_entry' ),
					'e20r-payment-warning-settings',
					'e20r_pw_addons',
					$settings
				);
			}
			
			// Load/Register settings for all active add-ons
			foreach ( $e20r_pw_addons as $name => $info ) {
				
				$utils->log( "Settings for {$name}...");
				
				if ( true == $info['is_active'] ) {
					
					$addon_fields = apply_filters( "e20r_pw_addon_options_{$info['class_name']}", array() );
					
					foreach ( $addon_fields as $type => $config ) {
						
						if ( 'setting' === $type ) {
							$utils->log( sprintf( "Loading: %s/{$config['option_name']}", Payment_Warning::option_group ) );
							register_setting( Payment_Warning::option_group, $config['option_name'], $config['validation_callback'] );
						}
						
						if ( 'section' === $type ) {
							
							$utils->log( "Processing " . count( $config ) . " sections" );
							
							// Iterate through the section(s)
							foreach ( $config as $section ) {
								
								$utils->log( "Loading: {$section['id']}/{$section['label']}" );
								add_settings_section( $section['id'], $section['label'], $section['render_callback'], 'e20r-payment-warning-settings' );
								
								$utils->log( "Processing " . count( $section['fields'] ) . " fields" );
								
								foreach ( $section['fields'] as $field ) {
									
									$utils->log( "Loading: {$field['id']}/{$field['label']}" );
									
									add_settings_field( $field['id'], $field['label'], $field['render_callback'], 'e20r-payment-warning-settings', $section['id'] );
								}
							}
						}
					}
				} else {
					$utils->log( "Addon settings are disabled for {$name}" );
				}
			}
			
			$utils->log("Configure licensing info for Payment Warning plugin");
			// Load settings for the Licensing code
			Licensing::register_settings();
		}
		
		/**
		 * Loads the text for the add-on list (to enable/disable add-ons)
		 */
		public function render_addon_header() {
			?>
            <p class="e20r-pw-addon-header-text">
			<?php _e( "Use checkbox to enable/disable the included add-ons", Payment_Warning::plugin_slug ); ?>
            </p><?php
		}
		
		/**
		 * Render the checkbox for the specific add-on (based on passed config)
		 *
		 * @param array $config
		 */
		public function render_addon_entry( $config ) {
			
			if ( ! empty( $config ) ) {
				$is_active  = $config['is_active'];
				$addon_name = strtolower( $config['class_name'] );
				?>
                <input id="<?php esc_attr_e( $addon_name ); ?>-checkbox" type="checkbox"
                       name="<?php echo $this->settings_name; ?>[<?php echo "is_{$addon_name}_active"; ?>]"
                       value="1" <?php checked( $is_active, true ); ?> />
				<?php
			}
		}
		
		/**
		 * Render description for the global plugin settings
		 */
		public function render_global_settings_text() {
			?>
            <p class="e20r-pw-global-settings-text">
				<?php _e( "Configure deactivation settings", Payment_Warning::plugin_slug ); ?>
            </p>
			<?php
		}
		
		
		/**
		 * Render description for the Reminder Schedule settings
		 */
		public function render_upcoming_payment_text() {
			?>
            <p class="e20r-pw-global-settings-text">
				<?php _e( "Reminder Schedule settings", Payment_Warning::plugin_slug ); ?>
            </p>
			<?php
		}
		/**
		 * Render a checkbox for the Settings page
		 *
		 * @param array $settings
		 */
		public function render_checkbox( $settings ) {
			
			$role_reset = $this->load_options( $settings['option_name'] );
			?>
            <input type="checkbox"
                   name="<?php esc_attr_e( $this->settings_name ); ?>[<?php esc_html_e( $settings['option_name'] ); ?>]"
                   value="1" <?php checked( 1, $role_reset ); ?> />
			<?php
		}
  
		/**
		 * Generates the Settings API compliant option page
		 */
		public function global_settings_page() {
			?>
            <div class="e20r-pw-settings">
                <div class="wrap">
                    <h2 class="e20r-pw-pmpro-settings"><?php _e( 'Settings: Eighty / 20 Results - Payment Warnings for Paid Memberships Pro', Payment_Warning::plugin_slug ); ?></h2>
                    <p class="e20r-pw-pmpro-settings">
						<?php _e( "Configure global 'E20R Payment Warnings for Paid Memberships Pro' settings", Payment_Warning::plugin_slug ); ?>
                    </p>
                    <form method="post" action="options.php">
						<?php settings_fields( Payment_Warning::option_group ); ?>
						<?php do_settings_sections( 'e20r-payment-warning-settings' ); ?>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ); ?>"/>
                        </p>
                    </form>

                </div>
            </div>
			<?php
		}
		
		/**
		 * Generates the PMPro Membership Level Settings section
		 */
		public function level_settings_page() {
			
			$active_addons = $this->get_active_addons();
			$level_id      = isset( $_REQUEST['edit'] ) ? intval( $_REQUEST['edit'] ) : ( isset( $_REQUEST['copy'] ) ? intval( $_REQUEST['copy'] ) : null );
			?>
            <div class="e20r-roles-for-pmpro-level-settings">
                <h3 class="topborder"><?php _e( 'Roles for Paid Memberships Pro (by Eighty/20 Results)', self::plugin_slug ); ?></h3>
                <hr style="width: 90%; border-bottom: 2px solid #c5c5c5;"/>
                <h4 class="e20r-roles-for-pmpro-section"><?php _e( 'Default user role settings', Payment_Warning::plugin_slug ); ?></h4>
				<?php do_action( 'e20r_roles_level_settings', $level_id, $active_addons ); ?>
            </div>
			<?php
		}
		
		/**
		 * Global save_level_settings function (calls add-on specific save code)
		 *
		 * @param $level_id
		 */
		public function save_level_settings( $level_id ) {
			
			$active_addons = $this->get_active_addons();
			
			do_action( 'e20r_roles_level_settings_save', $level_id, $active_addons );
		}
		
		/**
		 * Global delete membership level function (calls add-on specific save code)
		 *
		 * @param int $level_id
		 */
		public function delete_level_settings( $level_id ) {
			
			$active_addons = $this->get_active_addons();
			
			do_action( 'e20r_roles_level_settings_delete', $level_id, $active_addons );
		}
		
		/**
		 * Returns an array of add-ons that are active for this plugin
		 *
		 * @return array
		 */
		private function get_active_addons() {
			
			global $e20r_payment_gateways;
			
			if ( null === ( $active = Cache::get( 'e20r_pw_active_addons', Payment_Warning::cache_group ) ) ) {
				
				$active = array();
				
				foreach ( $e20r_payment_gateways as $addon => $settings ) {
					
					if ( true == $settings['status'] ) {
						$active[] = $addon;
					}
				}
				
				Cache::set( 'e20r_pw_active_addons', $active, ( 10 * MINUTE_IN_SECONDS ), Payment_Warning::cache_group );
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
			
			wp_enqueue_style( self::plugin_slug . '-admin', plugins_url( 'css/e20r-roles-for-pmpro-admin.css', __FILE__ ) );
			wp_register_script( self::plugin_slug . '-admin', plugins_url( 'javascript/e20r-roles-for-pmpro-admin.js', __FILE__ ) );
			
			$vars = array(
				'desc'    => __( 'Levels not matching up, or missing?', Payment_Warning::plugin_slug ),
				'repair'  => __( 'Repair', Payment_Warning::plugin_slug ),
				'working' => __( 'Working...', Payment_Warning::plugin_slug ),
				'done'    => __( 'Done!', Payment_Warning::plugin_slug ),
				'fixed'   => __( ' role connections were needed/repaired.', Payment_Warning::plugin_slug ),
				'failed'  => __( 'An error occurred while repairing roles.', Payment_Warning::plugin_slug ),
				// 'ajaxaction' => Payment_Warning::ajax_fix_action,
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'timeout' => intval( apply_filters( 'e20r_roles_for_pmpro_ajax_timeout_secs', 10 ) * 1000 ),
				// 'nonce'      => wp_create_nonce( Payment_Warning::ajax_fix_action ),
			);
			
			$key = self::plugin_prefix . 'vars';
			
			wp_localize_script( self::plugin_slug . '-admin', $key, $vars );
		}
		
		public function activate() {
		    
		    $util = Utilities::get_instance();
		    
		    if ( 0 > version_compare( PHP_VERSION, '5.4' ) ) {
		        
		        $util->log("Current PHP Version: " . PHP_VERSION );
		        $util->log( 'Plugin name: ' . plugin_basename( E20R_PW_DIR ) );
		        wp_die( __( "E20R Payment Warnings for Paid Memberships Pro requires a server configured with PHP version 5.4.0 or later. Please upgrade PHP on your server before attempting to activate this plugin.", Payment_Warning::plugin_slug ) );
		        
            } else {
		        
		        $util->log("Trigger activation action for add-ons");
			    do_action( 'e20r_pw_addon_activating_core' );
		    }
		}
        
        public function deactivate() {
		    
		    $clean_up = $this->load_options('deactivation_reset' );
		    $utils = Utilities::get_instance();
		    
		    $utils->log("Trigger deactivation action for add-ons");
		    do_action( 'e20r_pw_addon_deactivating_core', $clean_up );
        }
		/**
		 * Class auto-loader for this plugin
		 *
		 * @param string $class_name Name of the class to auto-load
		 *
		 * @since  1.0
		 * @access public
		 */
		public static function auto_loader( $class_name ) {
			
			if ( false === strpos( $class_name, 'E20R' ) ) {
				return;
			}
			
			error_log( "Loading: {$class_name}" );
			$parts = explode( '\\', $class_name );
			$name  = strtolower( preg_replace( '/_/', '-', $parts[ ( count( $parts ) - 1 ) ] ) );
			
			$base_path = plugin_dir_path( __FILE__ ) . 'class';
			$filename  = "class.{$name}.php";
			
			// For the E20R\Payment_Warning namespace
			if ( file_exists( "{$base_path}/{$filename}" ) ) {
				require_once( "{$base_path}/{$filename}" );
			}
			
			// For the E20R\Payment_Warning\Addon namespace
			if ( file_exists( "{$base_path}/add-on/{$filename}" ) ) {
				require_once( "{$base_path}/add-on/{$filename}" );
			}
			
			// For the E20R\Payment_Warning\Editor namespace
			if ( file_exists( "{$base_path}/editor/{$filename}" ) ) {
				require_once( "{$base_path}/editor/{$filename}" );
			}
			
			// For the E20R\Payment_Warning\Utilities namespace
			if ( file_exists( "{$base_path}/utilities/{$filename}" ) ) {
				require_once( "{$base_path}/utilities/{$filename}" );
			}
			
			// For the E20R\Licensing namespace
			if ( file_exists( "{$base_path}/licensing/{$filename}" ) ) {
				require_once( "{$base_path}/licensing/{$filename}" );
			}
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
		
	}
	
}

/**
 * Register the auto-loader and the activation/deactiviation hooks.
 */
spl_autoload_register( 'E20R\\Payment_Warning\\Payment_Warning::auto_loader' );

register_activation_hook( __FILE__, array( Payment_Warning::get_instance(), 'activate' ) );
register_deactivation_hook( __FILE__, array( Payment_Warning::get_instance(), 'deactivate' ) );

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