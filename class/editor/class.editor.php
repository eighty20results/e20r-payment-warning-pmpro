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

namespace E20R\Payment_Warning\Editor;


use E20R\Licensing\Licensing;
use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\Utilities\Email_Message;
use E20R\Payment_Warning\Utilities\Utilities;

class Editor {
	
	/**
	 * @var string
	 */
	private $option_name = 'e20r_pw_templates';
	
	/**
	 * @var null|Editor
	 */
	private static $instance = null;
	
	/**
	 * Editor constructor.
	 */
	public function __construct() {
		
		$util = Utilities::get_instance();
	}
	
	/**
	 * Class instance
	 *
	 * @return Editor|null
	 */
	public static function get_instance() {
		
		$util = Utilities::get_instance();
		
		if ( is_null( self::$instance ) ) {
			
			if ( true === Licensing::is_licensed( Payment_Warning::plugin_slug ) ) {
				$util->log( "The Payment Warnings product is licensed so enabling editor." );
				
				self::$instance = new self;
				self::$instance->load_hooks();
				
			}
		}
		
		return self::$instance;
	}
	
	/**
	 * Add the WP_User content to the email object
	 *
	 * @param \WP_User $user
	 */
	public function set_user( \WP_User $user ) {
		$this->user_data = $user;
	}
	
	/**
	 * Load all hooks & filters for Editor Class
	 */
	public function load_hooks() {
		
		$util = Utilities::get_instance();
		
		$util->log( "Loading Hooks and Filters" );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		
		// Load Custom Post Type for Email Body message
		add_action( 'init', array( $this, 'register_template_entry' ) );
	}
	
	/**
	 * Load scripts and styles (front and back-end as needed)
	 */
	public function enqueue() {
		
		$util = Utilities::get_instance();
		
		// In backend
		if ( is_admin() && isset( $_REQUEST['page'] ) && 'e20r-payment-warning-templates' === $_REQUEST['page'] ) {
			
			global $post;
			
			wp_enqueue_editor();
			wp_enqueue_media( $post->ID );
			
			$util->log("Loading style(s) for Payment Warning plugin");
			
			wp_enqueue_style( 'e20r-payment-warning-pmpro-admin', plugins_url( 'css/e20r-payment-warning-pmpro-admin.css', E20R_PW_DIR ), null, Payment_Warning::version );
			
			wp_enqueue_script( 'e20r-payment-warning-pmpro-admin', plugins_url( 'javascript/e20r-payment-warning-pmpro-admin.js', E20R_PW_DIR ), array( 'jquery', 'editor' ), Payment_Warning::version, true );
			
			wp_register_script( 'e20r-payment-warning-editor', plugins_url( 'javascript/e20r-payment-warning-editor.js', E20R_PW_DIR ), array( 'jquery', 'editor' ), Payment_Warning::version, true );
			
			$new_row_settings = self::default_template_settings( 'new' );
			$new_rows         = Template_Editor_View::add_template_entry( 'new', $new_row_settings, true );
			
			wp_localize_script( 'e20r-payment-warning-editor', 'e20r_pw_editor',
				array(
					'lang'   => array(
						'period_label'           => __( 'days before event', Payment_Warning::plugin_slug ),
						'period_btn_label'       => __( 'Add new schedule entry', Payment_Warning::plugin_slug ),
						'no_schedule'            => __( 'No schedule needed/defined', Payment_Warning::plugin_slug ),
						'invalid_schedule_error' => __( "Invalid data in send schedule", Payment_Warning::plugin_slug ),
					),
					'config' => array(
						'save_url'     => admin_url( 'admin-ajax.php' ),
						'ajax_timeout' => apply_filters( 'e20r_payment_warming_ajax_timeout', 15000 ),
					),
					'data'   => array(
						'new_template' => $new_rows,
					),
				)
			);
			
			wp_enqueue_script( 'e20r-payment-warning-editor' );
		}
	}
	
	/**
	 * Add Edit function to WordPress Tools menu
	 */
	public function load_tools_menu_item() {
		
		add_management_page(
			__( "Payment Warning Message Templates", Payment_Warning::plugin_slug ),
			__( "E20R Templates", Payment_Warning::plugin_slug ),
			apply_filters( 'e20rpw_min_settings_capabilities', 'manage_options' ),
			'e20r-payment-warning-templates',
			array( $this, 'load_message_template_page' )
		);
	}
	
	/**
	 * Load the template editor page (html)
	 */
	public function load_message_template_page() {
		
		$all_template_settings = $this->load_template_settings( 'all', true );
		
		Template_Editor_View::editor( $all_template_settings );
	}
	
	/**
	 * Set/select the default reminder schedule based on the type of reminder
	 *
	 * @param string $type
	 *
	 * @return array
	 */
	public function default_schedule( $type = 'recurring' ) {
		
		$schedule = array();
		
		switch ( $type ) {
			case 'recurring':
				
				$schedule = array_keys( apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
					7  => 'membership_recurring'
				) ) );
				$schedule = apply_filters( 'e20r-payment-warning-recurring-reminder-schedule', $schedule );
				break;
			
			case 'expiring':
				$schedule = array_keys( apply_filters( 'pmproeewe_email_frequency_and_templates', array(
					30 => 'membership_expiring',
					60 => 'membership_expiring',
					90 => 'membership_expiring',
				) ) );
				$schedule = apply_filters( 'e20r-payment-warning-expiration-schedule', $schedule );
				break;
			
			default:
				$schedule = array( 7, 15, 30 );
		}
		
		return $schedule;
	}
	
	/**
	 * Return the current settings for the specified message template
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 *
	 * @access private
	 */
	private function load_template_settings( $template_name, $load_body = false ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Loading Message templates for {$template_name}:" );
		
		// TODO: Load existing PMPro templates that apply for this editor
		$pmpro_email_templates = apply_filters( 'pmproet_templates', array() );
		
		$template_info = get_option( $this->option_name, $this->default_templates() );
		
		if ( 'all' === $template_name ) {
			
			if ( true === $load_body ) {
				
				foreach ( $template_info as $name => $settings ) {
					
					$util->log( "Loading body for {$name}" );
					
					if ( empty( $settings['body'] ) ) {
						
						$util->log( "Body has no content, so loading from default template: {$name}" );
						$settings['body']       = $this->load_default_template_body( $name );
						$template_info[ $name ] = $settings;
					}
				}
			}
			
			return $template_info;
		}
		
		// Specified template settings not found so have to return the default settings
		if ( $template_name !== 'all' && ! isset( $template_info[ $template_name ] ) ) {
			
			$template_info[ $template_name ] = $this->default_template_settings( $template_name );
			
			// Save the new template info
			update_option( $this->option_name, $template_info, false );
		}
		
		if ( $template_name !== 'all' && true === $load_body && empty( $template_info[ $template_name ]['body'] ) ) {
			
			$template_info[ $template_name ]['body'] = $this->load_default_template_body( $template_name );
		}
		
		return $template_info[ $template_name ];
	}
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	private function default_template_settings( $template_name ) {
		
		return array(
			'subject'        => null,
			'active'         => false,
			'type'           => 'recurring',
			'body'           => null,
			'data_variables' => array(),
			'schedule'       => $this->default_schedule( 'recurring' ),
			'description'    => null,
			'file_name'      => "{$template_name}.html",
			'file_path'      => dirname( E20R_PW_DIR ) . '/templates',
		);
	}
	
	/**
	 * Return all templates of the specified template type
	 *
	 * @param $type
	 *
	 * @return array
	 */
	public static function get_templates_of_type( $type ) {
		
		$class = self::get_instance();
		$util  = Utilities::get_instance();
		$util->log( "Loading templates for type: {$type}" );
		
		$template_keys = array();
		$templates     = $class->load_template_settings( 'all', false );
		
		foreach ( $templates as $template_name => $template ) {
			
			if ( ! empty( $template['type'] ) && $template['type'] == $type && true == $template['active'] ) {
				$util->log( "Adding template {$template_name} to the list" );
				$template_keys[] = $template_name;
			}
		}
		
		$util->log( "Returning " . count( $template_keys ) . " templates of type: {$type}" );
		$util->log( "Returned: " . print_r( $template_keys, true ) );
		
		return $template_keys;
	}
	
	/**
	 * Return the specified template (by name)
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 */
	public static function get_template_by_name( $template_name, $load_body = false ) {
		
		$class    = self::get_instance();
		$template = $class->load_template_settings( $template_name, $load_body );
		
		if ( $template['active'] == true && ! empty( $template['type'] ) ) {
			return $template;
		}
		
		return null;
	}
	
	/**
	 * Return the default templates with per template settings
	 *
	 * @return array
	 */
	public function default_templates() {
		
		$templates = array(
			'messageheader'      => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'schedule'       => array(),
				'data_variables' => array(),
				'description'    => __( 'Standard Header for Messages', Payment_Warning::plugin_slug ),
				'file_name'      => 'messageheader.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'messagefooter'      => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => array(),
				'description'    => __( 'Standard Footer for Messages', Payment_Warning::plugin_slug ),
				'file_name'      => 'messagefooter.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'recurring_default'  => array(
				'subject'        => sprintf( __( "Your upcoming recurring membership payment for %s", Payment_Warning::plugin_slug ), '!!sitename!!' ),
				'active'         => true,
				'type'           => 'recurring',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->default_schedule( 'recurring' ),
				'description'    => __( 'Recurring Payment Reminder', Payment_Warning::plugin_slug ),
				'file_name'      => 'recurring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'expiring_default'   => array(
				'subject'        => sprintf( __( "Your membership at %s will end soon", Payment_Warning::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => 'expiration',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->default_schedule( 'expiration' ),
				'description'    => __( 'Membership Expiration Warning', Payment_Warning::plugin_slug ),
				'file_name'      => 'expiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'ccexpiring_default' => array(
				'subject'        => sprintf( __( "Credit Card on file at %s expiring soon", Payment_Warning::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => 'expiration',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->default_schedule( 'expiration' ),
				'description'    => __( 'Credit Card Expiration', Payment_Warning::plugin_slug ),
				'file_name'      => 'ccexpiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
		);
		
		return apply_filters( 'e20r_pw_default_email_templates', $templates );
	}
	
	/**
	 * AJAX request handle for 'save template' action
	 */
	public function save_template() {
		
		$util        = Utilities::get_instance();
		$description = null;
		$reload      = false;
		$is_new      = false;
		
		check_ajax_referer( Payment_Warning::plugin_prefix, 'message_template' );
		
		$template_name = $util->get_variable( 'e20r_message_template-key', null );
		$util->log( "Nonce is OK for template: {$template_name}" );
		
		if ( ! empty( $template_name ) && 'new' === $template_name ) {
			
			$is_new   = true;
			$type     = $util->get_variable( 'e20r_message_template-type', null );
			$schedule = $util->get_variable( 'e20r_message_template-schedule', array() );
			
			if ( ! empty( $type ) && ! empty( $schedule ) ) {
				
				$schedule      = implode( '_', $schedule );
				$template_name = "{$type}_{$schedule}";
				$util->log( "New template name: {$template_name}" );
			}
		}
		
		if ( ! empty( $template_name ) ) {
			
			$template_settings = $this->load_template_settings( 'all' );
			
			// Skip and use the HTML formatted message/template
			unset( $_REQUEST["e20r_message_template-body_{$template_name}"] );
			
			// Get status for template (active/inactive)
			$is_active = $util->get_variable( 'e20r_message_template-active', 0 );
			
			if ( 0 == $is_active ) {
				$template_settings[ $template_name ]['active'] = $is_active;
			}
			
			foreach ( $_REQUEST as $key => $value ) {
				
				if ( false !== strpos( $key, 'e20r_message_template' ) ) {
					
					$tmp = explode( '-', $key );
					
					if ( $key !== 'e20r_message_template-key' ) {
						
						$value    = $util->get_variable( $key, null );
						$var_name = $tmp[ ( count( $tmp ) - 1 ) ];
						
						if ( $var_name == 'description' ) {
							$description = $value;
						}
						
						if ( $var_name === 'active' && $value === 'true' ) {
							$util->log( "Setting active to 'no'" );
							$value = 0;
						} else if ( $var_name === 'active' ) {
							$util->log( "Setting active to 'yes'" );
							$value = 1;
						}
						
						if ( $value == '-1' ) {
							$value = null;
						}
						
						if ( $var_name == 'schedule' && is_array( $value ) && false === $this->is_sorted( $value ) ) {
							
							$util->log( "Need to sort schedule array" );
							$reload = true;
							sort( $value );
						}
						
						if ( $var_name == 'file_name' && true === $is_new ) {
							$value = "{$template_name}.html";
						}
						
						if ( $var_name == 'description' && true === $is_new ) {
							
							$t_type = !empty( $template_settings[$template_name]['type'] ) ? $template_settings[$template_name]['type'] : __( 'Custom', Payment_Warning::plugin_slug );
							
							$t_daylist = !empty( $template_settings[$template_name]['schedule'] ) ?  $template_settings[$template_name]['schedule'] : null;
							
							$value = sprintf( __( "Custom %s Template", Payment_Warning::plugin_slug ), ucwords( $t_type ) );
							
							if ( !empty( $t_daylist ) ) {
								$value .= sprintf( __( " for days %s", Payment_Warning::plugin_slug ), implode( ', ', $t_daylist ) );
							}
						}
						
						$util->log( "Saving to {$var_name}: " . print_r( $value, true ) );
						$template_settings[ $template_name ][ $var_name ] = $value;
					}
				}
			}
			
			if ( true === $is_new ) {
				$reload = true;
			}
			
			update_option( $this->option_name, $template_settings, false );
			
			$util->log( "Saving template settings" );
			wp_send_json_success( array(
				'message' => sprintf( __( '%s message template saved', Payment_Warning::plugin_slug ), $description ),
				'reload'  => $reload,
			) );
			wp_die();
		}
		
		wp_send_json_error( array( 'message' => __( "Invalid attempt at saving the Message Template", Payment_Warning::plugin_slug ) ) );
		wp_die();
	}
	
	/**
	 * Test whether a single-dimensioned array is sorted already
	 *
	 * @param array $array
	 *
	 * @return bool
	 */
	private function is_sorted( $array ) {
		
		$is_sorted = true;
		$util      = Utilities::get_instance();
		
		$first  = array_shift( $array );
		$second = array_shift( $array );
		
		while ( $is_sorted ) {
			
			if ( empty( $first ) && empty( $seconc ) ) {
				break;
			}
			
			if ( $first > $second && ! empty( $second ) ) {
				
				$is_sorted = false;
				break;
			}
			
			$first  = $second;
			$second = array_shift( $array );
		}
		
		return $is_sorted;
	}
	
	/**
	 *
	 */
	public function reset_template() {
		
		check_ajax_referer( Payment_Warning::plugin_prefix, 'message_template' );
		$util = Utilities::get_instance();
		
		$template_name = $util->get_variable( 'e20r_template_to_reset', null );
		
		if ( ! empty( $template_name ) ) {
		
		}
		
		wp_send_json_error( array( 'message' => __( 'Unable to locate the message template to reset!', Payment_Warning::plugin_slug ) ) );
		wp_die();
	}
	
	/**
	 * TODO: Register email template content as Custom Post Type (Use Taxonomy)
	 */
	public function register_template_entry() {
	
	}
	
	/**
	 * @param \PMProEmail $email_obj
	 */
	public function replace_email_data( \PMProEmail $email_obj ) {
		
		$defaults = $this->default_templates();
	}
	
	/**
	 * Return the
	 *
	 * @param string $template_name
	 *
	 * @return string
	 */
	public function load_default_template_body( $template_name ) {
		
		$util = Utilities::get_instance();
		
		$default_templates = $this->default_templates();
		
		$body = "";
		$file = false;
		
		$util->log( "Path? " . "{$default_templates[$template_name]['file_path']}/{$default_templates[ $template_name ]['file_name']}" );
		
		// Load the template to use
		if ( ! empty( $default_templates[ $template_name ]['body'] ) ) {
			$util->log( "Loading from default template body" );
			$body = $default_templates[ $template_name ]['body'];
			
		} else if ( file_exists( get_stylesheet_directory() . "/e20r-payment-warning-templates/{$default_templates[ $template_name ]['file_name']}" ) ) {
			$util->log( "Loading child theme override template" );
			$file = get_stylesheet_directory() . "/e20r-payment-warning-templates/{$default_templates[ $template_name ]['file_name']}";
			
		} else if ( file_exists( get_template_directory() . "/e20r-payment-warning-templates/{$default_templates[ $template_name ]['file_name']}" ) ) {
			$util->log( "Loading theme override template" );
			$file = get_template_directory() . "/e20r-payment-warning-templates/{$default_templates[ $template_name ]['file_name']}";
			
		} else if ( file_exists( "{$default_templates[$template_name]['file_path']}/{$default_templates[ $template_name ]['file_name']}" ) ) {
			$util->log( "Loading from plugin" );
			$file = "{$default_templates[$template_name]['file_path']}/{$default_templates[ $template_name ]['file_name']}";
		}
		
		$util->log( "Loading body of template from {$file}" );
		if ( ! empty( $file ) && empty( $body ) ) {
			
			ob_start();
			require_once( $file );
			$body = ob_get_clean();
		}
		
		return $body;
	}
	
	/**
	 * Trigger as part of plugin deactivation
	 *
	 * @param bool $clear_options
	 */
	public function deactivate_plugin( $clear_options = false ) {
		
		if ( true === $clear_options ) {
			delete_option( $this->option_name );
		}
	}
}