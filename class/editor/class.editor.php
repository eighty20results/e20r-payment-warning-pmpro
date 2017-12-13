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

namespace E20R\Utilities\Editor;


use E20R\Utilities\Licensing\Licensing;
use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\Tools\Email_Message;
use E20R\Utilities\Utilities;

abstract class Editor {
	
	/**
	 * The email editor 'slug' (language/translation)
	 */
	const plugin_slug = 'e20r-email-editor';
	
	/**
	 * The Email Editor 'prefix' (for actions/nonces, etc).
	 */
	const plugin_prefix = 'e20r_ee_';
	
	/**
	 * The Custom Post Type (name)
	 */
	const cpt_type = 'e20r_email_message';
	
	/**
	 * Version number for the edition of the Email Editor we're loading
	 */
	const version = '1.0';
	/**
	 * @var null|Editor
	 */
	private static $instance = null;
	
	/**
	 * @var string
	 */
	protected $option_name = 'e20r_email_message_templates';
	
	/**
	 * Editor constructor.
	 *
	 * @access private
	 */
	public function __construct() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = $this;
		}
		
		$util = Utilities::get_instance();
		$util->log( "Loading editor hooks in constructor" );
		
		$this->load_hooks();
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
	 * Return all templates of the specified template type
	 *
	 * @param $type
	 *
	 * @return array
	 */
	public static function get_templates_of_type( $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Loading templates for type: {$type}" );
		
		if ( empty( self::$instance ) ) {
			$util->log( "Error attempting to load myself!" );
			
			return array();
		}
		
		$template_keys = array();
		$templates     = self::$instance->load_template_settings( 'all', false );
		
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
	 * Return the current settings for the specified message template
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 *
	 * @access private
	 */
	abstract public function load_template_settings( $template_name, $load_body = false );
	
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
	 * Class instance
	 *
	 * @return Editor|null
	 */
	public static function get_instance() {
		
		if ( ! is_null( self::$instance ) ) {
			
			return self::$instance;
		}
		
		wp_die( __( "Cannot use the Editor class directly. Your developer must extend from it!", Editor::plugin_slug ) );
	}
	
	abstract public function convert_messages();
	
	/**
	 * Add the WP_User content to the email object
	 *
	 * @param \WP_User $user
	 */
	public function set_user( \WP_User $user ) {
		$this->user_data = $user;
	}
	
	/**
	 * Load scripts and styles (front and back-end as needed)
	 */
	public function enqueue() {
		
		$util = Utilities::get_instance();
		
		// In backend
		if ( is_admin() && isset( $_REQUEST['page'] ) && 'e20r-email-editor-templates' === $_REQUEST['page'] ) {
			
			global $post;
			
			wp_enqueue_editor();
			
			if ( ! empty( $post->ID ) ) {
				wp_enqueue_media( $post->ID );
			}
			
			$util->log( "Loading style(s) for Email Editor plugin" );
			
			wp_enqueue_style( 'e20r-email-editor-admin', plugins_url( 'css/e20r-email-editor-admin.css', __FILE__ ), null, Editor::version );
			
			wp_enqueue_script( 'e20r-email-editor-admin', plugins_url( 'javascript/e20r-email-editor-admin.js', __FILE__ ), array(
				'jquery',
				'editor',
			), Editor::version, true );
			
			wp_register_script( 'e20r-email-editor', plugins_url( 'javascript/e20r-email-editor.js', __FILE__ ), array(
				'jquery',
				'editor',
			), Editor::version, true );
			
			$new_row_settings = self::default_template_settings( 'new' );
			$new_rows         = Template_Editor_View::add_template_entry( 'new', $new_row_settings, true );
			
			wp_localize_script( 'e20r-email-editor', 'e20r_email_editor',
				array(
					'lang'   => array(
						'period_label'           => __( 'days before event', Editor::plugin_slug ),
						'period_btn_label'       => __( 'Add new schedule entry', Editor::plugin_slug ),
						'no_schedule'            => __( 'No schedule needed/defined', Editor::plugin_slug ),
						'invalid_schedule_error' => __( "Invalid data in send schedule", Editor::plugin_slug ),
					),
					'config' => array(
						'save_url'     => admin_url( 'admin-ajax.php' ),
						'ajax_timeout' => apply_filters( 'e20r_email_editor_ajax_timeout', 15000 ),
					),
					'data'   => array(
						'new_template' => $new_rows,
					),
				)
			);
			
			wp_enqueue_script( 'e20r-email-editor' );
		}
	}
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	abstract function default_template_settings( $template_name );
	
	/**
	 * Set/select the default reminder schedule based on the type of reminder
	 *
	 * @param array  $schedule
	 * @param string $type
	 * @param string $slug
	 *
	 * @return array
	 */
	public function default_schedule( $schedule, $type = 'recurring', $slug = Editor::plugin_slug ) {
		
		$schedule = apply_filters( 'e20r-email-editor-default-schedules', $schedule, $type, $slug );
		
		return $schedule;
	}
	
	/**
	 * Add Edit function to WordPress Tools menu
	 */
	public function load_tools_menu_item() {
		
		add_management_page(
			__( "Email Message Templates", Editor::plugin_slug ),
			__( "E20R Templates", Editor::plugin_slug ),
			apply_filters( 'e20ree_min_settings_capabilities', 'manage_options' ),
			'e20r-email-message-templates',
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
	 * Return the
	 *
	 * @param string $template_name
	 *
	 * @return string
	 */
	public function load_default_template_body( $template_name ) {
		
		$util = Utilities::get_instance();
		
		$default_templates = $this->default_templates();
		$location          = apply_filters( 'e20r-email-editor-template-location', 'e20r-payment-warning' );
		$body              = "";
		$file              = false;
		
		$util->log( "Path? " . "{$default_templates[$template_name]['file_path']}/{$default_templates[ $template_name ]['file_name']}" );
		
		// Load the template to use
		if ( ! empty( $default_templates[ $template_name ]['body'] ) ) {
			$util->log( "Loading from default template body" );
			$body = $default_templates[ $template_name ]['body'];
			
		} else if ( file_exists( get_stylesheet_directory() . "/{$location}-templates/{$default_templates[ $template_name ]['file_name']}" ) ) {
			$util->log( "Loading child theme override template" );
			$file = get_stylesheet_directory() . "/{$location}-templates/{$default_templates[ $template_name ]['file_name']}";
			
		} else if ( file_exists( get_template_directory() . "/{$location}-templates/{$default_templates[ $template_name ]['file_name']}" ) ) {
			$util->log( "Loading theme override template" );
			$file = get_template_directory() . "/{$location}-templates/{$default_templates[ $template_name ]['file_name']}";
			
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
	 * Return the default templates with per template settings
	 *
	 * @return array
	 */
	abstract public function default_templates();
	
	/**
	 * AJAX request handle for 'save template' action
	 */
	public function save_template() {
		
		$util        = Utilities::get_instance();
		$description = null;
		$reload      = false;
		$is_new      = false;
		
		check_ajax_referer( Editor::plugin_prefix, 'message_template' );
		
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
							
							$t_type = ! empty( $template_settings[ $template_name ]['type'] ) ? $template_settings[ $template_name ]['type'] : __( 'Custom', Editor::plugin_slug );
							
							$t_daylist = ! empty( $template_settings[ $template_name ]['schedule'] ) ? $template_settings[ $template_name ]['schedule'] : null;
							
							$value = sprintf( __( "Custom %s Template", Editor::plugin_slug ), ucwords( $t_type ) );
							
							if ( ! empty( $t_daylist ) ) {
								$value .= sprintf( __( " for days %s", Editor::plugin_slug ), implode( ', ', $t_daylist ) );
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
			
			update_option( $this->option_name, $template_settings, 'no' );
			
			$util->log( "Saving template settings" );
			wp_send_json_success( array(
				'message' => sprintf( __( '%s message template saved', Editor::plugin_slug ), $description ),
				'reload'  => $reload,
			) );
			wp_die();
		}
		
		wp_send_json_error( array( 'message' => __( "Invalid attempt at saving the Message Template", Editor::plugin_slug ) ) );
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
	 * Reset to the default template for the specified message_template
	 */
	public function reset_template() {
		
		check_ajax_referer( Editor::plugin_prefix, 'message_template' );
		$util = Utilities::get_instance();
		
		$template_name = $util->get_variable( 'message_template', null );
		
		if ( ! empty( $template_name ) ) {
			
			
			wp_send_json_success();
			wp_die();
		}
		
		wp_send_json_error( array( 'message' => sprintf( __( 'Could not locate the "%s" template.', Editor::plugin_slug ), $template_name ) ) );
		wp_die();
	}
	
	/**
	 * TODO: Register email template content as Custom Post Type (Use Taxonomy)
	 */
	public function register_template_entry() {
		
		$default_slug = apply_filters( 'e20r-email-editor-cpt-slug', get_option( 'e20r_email_editor_slug', Editor::plugin_slug ) );
		$post_type    = Editor::cpt_type;
		$utils        = Utilities::get_instance();
		
		$this->create_email_taxonomy( $post_type, $default_slug );
		
		$labels = array(
			'name'               => __( 'Email Messages', Editor::plugin_slug ),
			'singular_name'      => __( 'Email Message', Editor::plugin_slug ),
			'slug'               => Editor::plugin_slug,
			'add_new'            => __( 'New Email Message', Editor::plugin_slug ),
			'add_new_item'       => __( 'New Email Message', Editor::plugin_slug ),
			'edit'               => __( 'Edit Email Message', Editor::plugin_slug ),
			'edit_item'          => __( 'Edit Email Message', Editor::plugin_slug ),
			'new_item'           => __( 'Add New', Editor::plugin_slug ),
			'view'               => __( 'View Email Message', Editor::plugin_slug ),
			'view_item'          => __( 'View This Email Message', Editor::plugin_slug ),
			'search_items'       => __( 'Search Email Messages', Editor::plugin_slug ),
			'not_found'          => __( 'No Email Message Found', Editor::plugin_slug ),
			'not_found_in_trash' => __( 'No Email Message Found In Trash', Editor::plugin_slug ),
		);
		
		$error = register_post_type( $post_type,
			array(
				'labels'             => apply_filters( 'e20r-email-editor-cpt-labels', $labels ),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'publicly_queryable' => true,
				'hierarchical'       => true,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'author', 'excerpt' ),
				'can_export'         => true,
				'show_in_nav_menus'  => true,
				'rewrite'            => array(
					'slug'       => $default_slug,
					'with_front' => false,
				),
				'has_archive'        => apply_filters( 'e20r-email-editor-cpt-archive-slug', 'sequences' ),
			)
		);
		
		if ( ! is_wp_error( $error ) ) {
			return true;
		} else {
			$utils->log( 'Error creating post type: ' . $error->get_error_message() );
			wp_die( $error->get_error_message() );
			
			return false;
		}
	}
	
	/**
	 * Create Taxonomy for E20R Sequences
	 *
	 * @param string $post_type
	 * @param string $slug
	 */
	private function create_email_taxonomy( $post_type, $slug ) {
		
		$taxonomy_labels = array(
			'name'              => __( 'Email Type', $slug ),
			'singular_name'     => __( 'Email Type', $slug ),
			'menu_name'         => _x( 'Email Types', 'Admin menu name', $slug ),
			'search_items'      => __( 'Search Email Type', $slug ),
			'all_items'         => __( 'All Email Types', $slug ),
			'parent_item'       => __( 'Parent Email Type', $slug ),
			'parent_item_colon' => __( 'Parent Email Type:', $slug ),
			'edit_item'         => __( 'Edit Email Type', $slug ),
			'update_item'       => __( 'Update Email Type', $slug ),
			'add_new_item'      => __( 'Add New Email Type', $slug ),
			'new_item_name'     => __( 'New Email Type Name', $slug ),
		);
		
		register_taxonomy( 'e20r_email_type', $post_type, array(
				'hierarchical'      => true,
				'label'             => __( 'Email Type', $slug ),
				'labels'            => $taxonomy_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'         => 'e20r-email-type',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}
	
	/**
	 * @param \PMProEmail $email_obj
	 */
	public function replace_email_data( \PMProEmail $email_obj ) {
		
		$defaults = $this->default_templates();
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