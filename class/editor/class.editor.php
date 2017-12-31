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
 *
 * @version 1.7
 */

namespace E20R\Utilities\Editor;

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
	 * The taxonomy
	 */
	const taxonomy = 'e20r_email_type';
	
	/**
	 * The child class taxonomy name to use (TODO: Override $taxonomy_name in child class!)
	 *
	 * @var null|string $taxonomy_name
	 */
	protected $taxonomy_name = null;
	
	/**
	 * The child class taxonomy nicename to use (TODO: Override $taxonomy_nicename in child class!)
	 *
	 * @var null|string $taxonomy_nicename
	 */
	protected $taxonomy_nicename = null;
	
	/**
	 * The child class taxonomy description to use (TODO: Override $taxonomy_description in child class!)
	 *
	 * @var null|string $taxonomy_description
	 */
	protected $taxonomy_description = null;
	
	/**
	 * Version number for the edition of the Email Editor we're loading
	 */
	const version = '1.4';
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
		
		// $utils = Utilities::get_instance();
		// $utils->log( "Loading editor hooks in constructor" );
		
		// $this->load_hooks();
	}
	
	/**
	 * Return all templates of the specified template type
	 *
	 * @param $type
	 *
	 * @return array
	 */
	public static function get_templates_of_type( $type ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Loading templates for type: {$type}" );
		
		if ( empty( self::$instance ) ) {
			$utils->log( "Error attempting to load myself!" );
			
			return array();
		}
		
		$template_keys = array();
		$templates     = self::$instance->load_template_settings( 'all', false );
		
		foreach ( $templates as $template_name => $template ) {
			
			if ( ! empty( $template['type'] ) && $template['type'] == $type && true == $template['active'] ) {
				$utils->log( "Adding template {$template_name} to the list" );
				$template_keys[] = $template_name;
			}
		}
		
		$utils->log( "Returning " . count( $template_keys ) . " templates of type: {$type}" );
		$utils->log( "Returned: " . print_r( $template_keys, true ) );
		
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
	
	public function display_message_metabox() {
		
		$types = apply_filters( 'e20r-email-editor-message-types', array() );
		Template_Editor_View::add_type_metabox( $types );
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
	
	/**
	 * Help text for supported message type specific substitution variables
	 *
	 * @param array $variables - List of variables to provide help text for
	 * @param mixed $type - The message type we're providing help for
	 *
	 * @return array
	 *
	 * @since 1.9.6 - ENHANCEMENT: Added e20r-email-editor-variable-help filter to result of default_variable_help()
	 */
	public function default_variable_help( $variables, $type ) {
		
		if ( $type == $this->processing_this_term( $type ) ) {
			return apply_filters( 'e20r-email-editor-variable-help', $variables, $type );
		}
		
		return $variables;
	}
	
	/**
	 * Load all hooks & filters for Editor Class
	 */
	public function load_hooks() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading Hooks and Filters" );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		
		// Load Custom Post Type for Email Body message
		add_action( 'init', array( $this, 'register_template_entry' ) );
		// add_action( 'admin_menu', array( $this, 'load_tools_menu_item' ), 10 );
		// add_action( 'admin_menu', array( self::$instance, 'load_custom_css_input' ), 10 );
		
		add_action( 'e20r-editor-load-message-meta', array( $this, 'load_default_metaboxes' ), 10, 1 );
		
		$save_action = "save_post_" . Editor::cpt_type;
		
		add_action( $save_action, array( $this, 'save_metadata' ), 10, 1 );
		
		add_action( 'e20r-editor-load-message-meta', array( $this, 'load_custom_css_input' ), 10, 0 );
	}
	
	
	public function load_default_metaboxes( $term_type ) {
		
		if ( $term_type !== 'default' ) {
			return;
		}
		
		add_meta_box(
			'e20r-editor-settings',
			__( 'Email Notice Type', Editor::plugin_slug ),
			Template_Editor_View::add_default_metabox(),
			Editor::cpt_type,
			'side',
			'high'
		);
	}
	
	/**
	 * Save any custom (message specific) CSS for the email notice
	 *
	 * @param int $post_id
	 *
	 * @return bool|int
	 */
	public function save_metadata( $post_id ) {
		
		// post_meta key: _e20r_editor_custom_css
		
		global $post;
		
		$utils = Utilities::get_instance();
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $post_id ) ) {
			
			$utils->log( 'No message ID supplied...' );
			
			return false;
		}
		
		if ( wp_is_post_revision( $post_id ) ) {
			return $post_id;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		
		if ( ! isset( $post->post_type ) || ( Editor::cpt_type != $post->post_type ) ) {
			return $post_id;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return $post_id;
		}
		
		if ( !isset( $_REQUEST['e20r-editor-custom-css'] ) ) {
			return $post_id;
		}
		
		$message_css = isset( $_REQUEST['e20r-editor-custom-css'] ) ? trim( wp_filter_nohtml_kses( wp_strip_all_tags( $_REQUEST['e20r-editor-custom-css'] ) ) ) : null;
		
		if ( ! empty( $message_css ) ) {
			update_post_meta( $post_id, '_e20r_editor_custom_css', $message_css );
		}
	}
	
	/**
	 * Sanitize URLs used in CSS properties
	 *
	 * @param string $check_url
	 * @param string $property
	 *
	 * @return string
	 */
	public function sanitize_urls_in_css_properties( $check_url, $property ) {
		
		$allowed_props = array( 'background', 'background-image', 'border-image', 'content', 'cursor', 'list-style', 'list-style-image' );
		$allowed_proto  = array( 'http', 'https' );
		
		// Clean up the string
		$check_url = trim( $check_url, "' \" \r \n" );
		
		// Check against whitelist for properties allowed to have URL values
		if ( ! in_array( trim( $property ), $allowed_props, true ) ) {
			// trim() is because multiple properties with the same name are stored with
			// additional trailing whitespace so they don't overwrite each other in the hash.
			return '';
		}
		
		$check_url = wp_kses_bad_protocol_once( $check_url, $allowed_proto );
		
		if ( empty( $check_url ) ) {
			return '';
		}
		
		return "url('" . str_replace( "'", "\\'", $check_url ) . "')";
	}
	
	/**
	 * Add Custom CSS input box on Editor edit page
	 */
	public function load_custom_css_input() {
		
		$utils = Utilities::get_instance();
		$utils->log("Loading message-specific CSS textarea");
		
		add_meta_box(
			'e20r_message_css',
			__( 'Message Specific CSS', Editor::plugin_slug ),
			array( $this, "display_custom_css_input" ),
			Editor::cpt_type,
			'normal',
			'high'
		);
	}
	
	/**
	 * Load the Metabox for the Email Message specific Custom CSS
	 */
	public function display_custom_css_input() {
		
		Template_Editor_View::add_css_metabox();
	}
	
	/**
	 * Install custom Taxonomy for child Editor class
	 *
	 * @param string $taxonomy_name
	 * @param string $taxonomy_nicename
	 * @param string $taxonomy_description
	 */
	public function install_taxonomy( $taxonomy_name, $taxonomy_nicename, $taxonomy_description ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Testing custom taxonomy {$taxonomy_name} for {$taxonomy_nicename} email notices: " . Editor::taxonomy );
		
		if ( ! term_exists( $taxonomy_name, Editor::taxonomy ) ) {
			$utils->log( "Adding {$taxonomy_nicename} taxonomy" );
			
			$new_term = wp_insert_term(
				$taxonomy_nicename,
				Editor::taxonomy,
				array(
					'slug'        => $taxonomy_name,
					'description' => $taxonomy_description,
				)
			);
			
			if ( is_wp_error( $new_term ) ) {
				$utils->log( "Error creating new taxonomy term: " . $new_term->get_error_message() );
			}
		}
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
	 * Load scripts and styles (front and back-end as needed)
	 */
	public function enqueue() {
		
		$utils = Utilities::get_instance();
		
		// In backend
		/*
		if ( is_admin() && isset( $_REQUEST['page'] ) && 'e20r-email-editor-templates' === $_REQUEST['page'] ) {
			
			global $post;
			
			wp_enqueue_editor();
			
			if ( ! empty( $post->ID ) ) {
				wp_enqueue_media( $post->ID );
			}
			
			$utils->log( "Loading style(s) for Email Editor plugin" );
			
			wp_enqueue_style( 'e20r-email-editor-admin', plugins_url( 'css/e20r-email-editor-admin.css', __FILE__ ), null, Editor::version );
			
			wp_enqueue_script( 'e20r-email-editor-admin', plugins_url( 'javascript/e20r-email-editor-admin.js', __FILE__ ), array(
				'jquery',
				'editor',
			), Editor::version, true );
			
			$this->i18n_script();
		}
		*/
		global $post;
		
		if ( is_admin() && isset( $post->post_type ) && Editor::cpt_type == $post->post_type ) {
			
			$utils->log("Loading scripts for Editor CPT page");
			
			wp_enqueue_style(
				'e20r-email-editor-admin',
				plugins_url( 'css/e20r-email-editor-admin.css', __FILE__ ),
				null,
				Editor::version
			);
			
			if ( file_exists( plugin_dir_path('javascript/e20r-email-editor-admin.js' ) ) ) {
				wp_enqueue_script( 'e20r-email-editor-admin', plugins_url( 'javascript/e20r-email-editor-admin.js', __FILE__ ), array( 'jquery' ), Editor::version, true );
			}
			
			wp_register_script( 'e20r-email-editor', plugins_url( 'javascript/e20r-email-editor.js', __FILE__ ), array(
				'jquery',
				'editor',
			), Editor::version, true );
			
			$new_row_settings = $this->default_template_settings( 'new' );
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
		
		global $post;
		global $post_ID;
		
		$current_post_id = null;
		
		// Find the post ID (numeric)
		if ( empty( $post ) && empty( $post_ID ) && ! empty( $_REQUEST['post'] ) ) {
			$current_post_id = intval( $_REQUEST['post'] );
		} else if ( ! empty( $post_ID ) ) {
			$current_post_id = $post_ID;
		} else if ( ! empty( $post->ID ) ) {
			$current_post_id = $post->ID;
		}
		
		if ( empty( $current_post_id ) ) {
			return false;
		}
		
		$terms = wp_get_post_terms( $current_post_id, Editor::taxonomy );
		
		if ( empty( $terms ) ) {
			$terms = array( 'slug' => 'default' );
		}
		
		foreach ( $terms as $term ) {
			do_action( 'e20r-editor-load-message-meta', $term->slug );
		}
	}
	
	
	/**
	 * Load the template editor page (html)
	 */
	public function load_message_template_page() {
		
		$all_settings = $this->load_template_settings( 'all', true );
		
		Template_Editor_View::editor( $all_settings );
	}
	
	/**
	 * Return the
	 *
	 * @param string $template_name
	 *
	 * @return string
	 */
	public function load_default_template_body( $template_name ) {
		
		$utils = Utilities::get_instance();
		
		$default_templ = $this->default_templates();
		$location      = apply_filters( 'e20r-email-editor-template-location', plugin_dir_path( __FILE__ ) );
		$content_body  = null;
		$file_name     = false;
		
		$utils->log( "Path? " . "{$default_templ[$template_name]['file_path']}/{$default_templ[ $template_name ]['file_name']}" );
		
		// Load the template to use
		if ( ! empty( $default_templ[ $template_name ]['body'] ) ) {
			$utils->log( "Loading from default template body" );
			$content_body = $default_templ[ $template_name ]['body'];
			
		} else if ( file_exists( get_stylesheet_directory() . "/{$location}-templates/{$default_templ[ $template_name ]['file_name']}" ) ) {
			$utils->log( "Loading child theme override template" );
			$file_name = get_stylesheet_directory() . "/{$location}-templates/{$default_templ[ $template_name ]['file_name']}";
			
		} else if ( file_exists( get_template_directory() . "/{$location}-templates/{$default_templ[ $template_name ]['file_name']}" ) ) {
			$utils->log( "Loading theme override template" );
			$file_name = get_template_directory() . "/{$location}-templates/{$default_templ[ $template_name ]['file_name']}";
			
		} else if ( file_exists( "{$default_templ[$template_name]['file_path']}/{$default_templ[ $template_name ]['file_name']}" ) ) {
			$utils->log( "Loading from plugin" );
			$file_name = "{$default_templ[$template_name]['file_path']}/{$default_templ[ $template_name ]['file_name']}";
		}
		
		$utils->log( "Loading body of template from {$file_name}" );
		if ( ! empty( $file_name ) && empty( $content_body ) ) {
			
			ob_start();
			require_once( $file_name );
			$content_body = ob_get_clean();
		}
		
		return $content_body;
	}
	
	/**
	 * AJAX request handle for 'save template' action
	 */
	public function save_template() {
		
		$utils       = Utilities::get_instance();
		$description = null;
		$reload      = false;
		$is_new      = false;
		
		check_ajax_referer( Editor::plugin_prefix, 'message_template' );
		
		$template_name = $utils->get_variable( 'e20r_message_template-key', null );
		$utils->log( "Nonce is OK for template: {$template_name}" );
		
		if ( ! empty( $template_name ) && 'new' === $template_name ) {
			
			$is_new   = true;
			$type     = $utils->get_variable( 'e20r_message_template-type', null );
			$schedule = $utils->get_variable( 'e20r_message_template-schedule', array() );
			
			if ( ! empty( $type ) && ! empty( $schedule ) ) {
				
				$schedule      = implode( '_', $schedule );
				$template_name = "{$type}_{$schedule}";
				$utils->log( "New template name: {$template_name}" );
			}
		}
		
		if ( ! empty( $template_name ) ) {
			
			$template_settings = $this->load_template_settings( 'all' );
			
			// Skip and use the HTML formatted message/template
			unset( $_REQUEST["e20r_message_template-body_{$template_name}"] );
			
			// Get status for template (active/inactive)
			$is_active = $utils->get_variable( 'e20r_message_template-active', 0 );
			
			if ( 0 == $is_active ) {
				$template_settings[ $template_name ]['active'] = $is_active;
			}
			
			foreach ( $_REQUEST as $key => $value ) {
				
				if ( false !== strpos( $key, 'e20r_message_template' ) ) {
					
					$tmp = explode( '-', $key );
					
					if ( $key !== 'e20r_message_template-key' ) {
						
						$value    = $utils->get_variable( $key, null );
						$var_name = $tmp[ ( count( $tmp ) - 1 ) ];
						
						if ( $var_name == 'description' ) {
							$description = $value;
						}
						
						if ( $var_name === 'active' && $value === 'true' ) {
							$utils->log( "Setting active to 'no'" );
							$value = 0;
						} else if ( $var_name === 'active' ) {
							$utils->log( "Setting active to 'yes'" );
							$value = 1;
						}
						
						if ( $value == '-1' ) {
							$value = null;
						}
						
						if ( $var_name == 'schedule' && is_array( $value ) && false === $this->is_sorted( $value ) ) {
							
							$utils->log( "Need to sort schedule array" );
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
						
						$utils->log( "Saving to {$var_name}: " . print_r( $value, true ) );
						$template_settings[ $template_name ][ $var_name ] = $value;
					}
				}
			}
			
			if ( true === $is_new ) {
				$reload = true;
			}
			
			update_option( $this->option_name, $template_settings, 'no' );
			
			$utils->log( "Saving template settings" );
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
		$utils     = Utilities::get_instance();
		
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
		$utils = Utilities::get_instance();
		
		$template_name = $utils->get_variable( 'message_template', null );
		
		if ( ! empty( $template_name ) ) {
			
			
			wp_send_json_success();
			wp_die();
		}
		
		wp_send_json_error( array( 'message' => sprintf( __( 'Could not locate the "%s" template.', Editor::plugin_slug ), $template_name ) ) );
		wp_die();
	}
	
	/**
	 * Register email template content as Custom Post Type (Use Taxonomy)
	 */
	public function register_template_entry() {
		
		$default_slug = apply_filters( 'e20r-email-editor-cpt-slug', get_option( 'e20r_email_editor_slug', Editor::plugin_slug ) );
		$post_type    = Editor::cpt_type;
		$utils        = Utilities::get_instance();
		
		$this->create_email_taxonomy( $post_type, $default_slug );
		
		$labels = array(
			'name'               => __( 'Email Notices', Editor::plugin_slug ),
			'singular_name'      => __( 'Email Notice', Editor::plugin_slug ),
			'slug'               => Editor::plugin_slug,
			'add_new'            => __( 'New Email Notice', Editor::plugin_slug ),
			'add_new_item'       => __( 'New Email Notice', Editor::plugin_slug ),
			'edit'               => __( 'Edit Email Notice', Editor::plugin_slug ),
			'edit_item'          => __( 'Edit Email Notice', Editor::plugin_slug ),
			'new_item'           => __( 'Add New', Editor::plugin_slug ),
			'view'               => __( 'View Email Notice', Editor::plugin_slug ),
			'view_item'          => __( 'View This Email Notice', Editor::plugin_slug ),
			'search_items'       => __( 'Search Email Notices', Editor::plugin_slug ),
			'not_found'          => __( 'No Email Notice Found', Editor::plugin_slug ),
			'not_found_in_trash' => __( 'No Email Notice Found In Trash', Editor::plugin_slug ),
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
				'has_archive'        => apply_filters( 'e20r-email-editor-cpt-archive-slug', Editor::plugin_slug . "-archive" ),
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
	 * Create Taxonomy for E20R Editor
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
		
		register_taxonomy( Editor::taxonomy, $post_type, array(
				'hierarchical'      => true,
				'label'             => __( 'Email Type', $slug ),
				'labels'            => $taxonomy_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'         => str_replace( '_', '-', Editor::taxonomy ),
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
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
	
	/**
	 * Return arguments to use for Taxonomy/Term searches
	 *
	 * @param string $term_slug
	 *
	 * @return array
	 */
	protected function get_term_args( $term_slug ) {
		
		return array(
			'fields' => 'all',
			'slug' => $term_slug,
			'hide_empty' => false,
			'orderby' => 'slug',
			'taxonomy' => self::taxonomy
		);
	}
	
	/**
	 * Save the message specific metadata (from child class)
	 *
	 * @param int $post_id
	 */
	abstract public function save_message_meta( $post_id );
	
	/**
	 * Verify if the child function is processing this term
	 *
	 * @param null|int|string $type
	 *
	 * @return bool
	 */
	abstract public function processing_this_term( $type );
	
	/**
	 * Return the current settings for the specified message template
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 *
	 * @access public
	 */
	abstract public function load_template_settings( $template_name, $load_body = false );
	
	/**
	 * Define the substitution variables for the email messages and how/where to find their data
	 *
	 * @param array $variable_list
	 * @param mixed $type
	 *
	 * @return array
	 */
	abstract public function default_data_variables( $variable_list, $type );
	
	/**
	 * Return the default templates with per template settings
	 *
	 * @return array
	 */
	abstract public function default_templates();
	
	/**
	 * Return the Message templates for the specified type
	 *
	 * @param string $type - The type of template to return
	 *
	 * @return array
	 */
	abstract public function configure_cpt_templates( $type );
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	abstract public function default_template_settings( $template_name );
	
	/**
	 * Filter handler to load the Editor Email Notice content
	 *
	 * @filter 'e20r-email-editor-notice-content'
	 *
	 * @param string $content
	 * @param string $template_slug
	 *
	 * @return string|null
	 */
	abstract public function load_template_content( $content, $template_slug );
	
	/**
	 * Load the child metabox to set message type ( For the _e20r_sequence_message_type post meta )
	 */
	abstract public function load_message_metabox( $term_type );
}
