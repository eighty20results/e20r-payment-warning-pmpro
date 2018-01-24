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

use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\User_Data;
use E20R\Utilities\Utilities;
use E20R\Utilities\Email_Notice\Email_Notice;
use E20R\Utilities\Email_Notice\Email_Notice_View;

if ( ! defined( 'E20R_PW_EXPIRATION_REMINDER' ) ) {
	define( 'E20R_PW_EXPIRATION_REMINDER', 100 );
}
if ( ! defined( 'E20R_PW_RECURRING_REMINDER' ) ) {
	define( 'E20R_PW_RECURRING_REMINDER', 200 );
}
if ( ! defined( 'E20R_PW_CREDITCARD_REMINDER' ) ) {
	define( 'E20R_PW_CREDITCARD_REMINDER', 300 );
}

class Reminder_Editor extends Email_Notice {
	
	/**
	 * @var null|Reminder_Editor
	 */
	private static $instance = null;
	
	/**
	 * The name of the email message taxonomy term
	 *
	 * @var string $taxonomy_name
	 */
	protected $taxonomy_name = 'e20r-pw-notices';
	
	/**
	 * The label for the custom taxonomy term of this plugin.
	 *
	 * @var null|string $taxonomy_nicename
	 */
	protected $taxonomy_nicename = null;
	
	/**
	 * The description text for the custom taxonomy term of this plugin.
	 *
	 * @var null|string $taxonomy_description
	 */
	protected $taxonomy_description = null;
	
	/**
	 * The ID of the user who's message(s) we're processing
	 *
	 * @var int $user_id
	 */
	protected $user_id;
	
	/**
	 * Reminder_Editor constructor.
	 */
	public function __construct() {
		
		$this->taxonomy_nicename    = __( "E20R Payment Warning Notices", Payment_Warning::plugin_slug );
		$this->taxonomy_description = __( "Payment Warning Message types for PMPro Member notifications", Payment_Warning::plugin_slug );
	}
	
	/**
	 * Fetch instance of the Editor (child) class
	 *
	 * @return Reminder_Editor|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Loading all Notice Editor hooks and filters
	 */
	public function load_hooks() {
		
		$utils = Utilities::get_instance();
		
		parent::load_hooks();
		
		$utils->log( "Loading Payment Warning Notice email-notice functionality" );
		
		add_filter( 'e20r-email-notice-variable-help', array( $this, 'variable_help' ), 10, 2 );
		
		add_action( 'init', array( $this, 'install_taxonomy' ), 99 );
		
		add_action( 'wp_ajax_e20r_util_save_template', array( $this, 'save_template' ) );
		add_action( 'wp_ajax_e20r_util_reset_template', array( $this, 'reset_template' ) );
		
		/*
		add_action( 'e20r_sequence_module_deactivating_core', array( self::$instance, 'deactivate_plugin' ), 10, 1 );
		add_action( 'e20r_sequence_module_activating_core', array( self::$instance, 'activate_plugin' ), 10 );
		*/
		/*
		
		add_action( 'e20r-sequence-template-email-notice-email-entry', array( self::$instance, 'add_email_options' ), 10, 2 );
		*/
		
		add_filter( 'e20r-email-notice-loaded', '__return_true' );
		
		add_action( 'e20r-email-notice-load-message-meta', array( $this, 'load_message_metabox' ), 10, 1 );
		add_action( 'e20r-email-notice-load-message-meta', array(
			$this,
			'load_send_schedule_metabox',
		), 11, 1 );
		add_action( 'e20r-email-notice-load-message-meta', array( $this, 'load_template_help' ), 10, 1 );
		add_action( 'save_post', array( $this, 'save_message_meta' ), 10, 1 );
		
		add_filter( 'e20r-email-notice-data-variables', array( $this, 'default_data_variables' ), 10, 2 );
		add_filter( 'e20r-email-notice-message-types', array( $this, 'define_message_types' ), 10, 1 );
		add_filter( 'e20r-email-notice-content', array( $this, 'load_template_content' ), 10, 2 );
		add_filter( 'e20r-email-notice-template-contents', array(
			self::$instance,
			'load_template_content',
		), 10, 2 );
		
		add_filter( 'e20r-email-notice-custom-variable-filter', array( $this, 'load_filter_value' ), 10, 4 );
		
		add_filter( 'e20r-email-notice-membership-level-for-user', array( $this, 'get_member_level_for_user' ), 10, 3 );
		add_filter( 'e20r-email-notice-membership-page-for-user', array( $this, 'get_member_page_for_user' ), 10, 3 );
		
		add_filter( 'e20r-payment-warning-billing-info-page', array( $this, 'load_billing_page' ), 10, 1 );
	}
	
	/**
	 * Get and print the message type (string) for the email notice column
	 *
	 * @param string $column
	 * @param int $post_id
	 */
	public function custom_post_column( $column, $post_id ) {
		
		$msg_types = $this->define_message_types( array() );
		
		if ( $column === 'message_type' ) {
			
			$warning_type = get_post_meta( $post_id, '_e20r_pw_message_type', true );
			$terms = wp_get_object_terms( $post_id, 'e20r_email_type', array( 'fields' => 'slugs') );
			
			if ( empty( $warning_type ) ) {
				$warning_type = -1;
			}
			
			if ( !empty( $warning_type ) && in_array( 'e20r-pw-notices', $terms ) ) {
				esc_html_e( $msg_types[ $warning_type ]['label'] );
			}
		}
		
	}
	
	/**
	 * Return the billing page ID (post ID) for the PMPro membership plugin
	 *
	 * @param int|null $page_id
	 *
	 * @return int
	 */
	public function load_billing_page( $page_id ) {
		
		global $pmpro_pages;
		
		if ( isset( $pmpro_pages['billing'] ) ) {
			$page_id = $pmpro_pages['billing'];
		}
		
		return $page_id;
	}
	
	/**
	 * Filter handler for the e20r-email-notice-membership-page-for-user filter
	 *
	 * @filter e20r-email-notice-membership-page-for-user - Return the page URL for the specified membership page
	 *         (specified by the variable name)
	 *
	 * @param string $page_name
	 * @param int    $user_id
	 * @param string $variable_name
	 *
	 * @return string
	 */
	public function get_member_page_for_user( $page_name, $user_id, $variable_name ) {
		
		if ( function_exists( 'pmpro_url' ) ) {
			
			$var_info  = explode( '_', $variable_name );
			$page_name = array_shift( $var_info );
			
			$url = pmpro_url( $page_name );
			
			if ( ! empty( $url ) ) {
				return $url;
			}
		}
		
		return get_permalink();
	}
	
	/**
	 * Return the PMPro specific membership level definition for the specified User ID
	 *
	 * @param \stdClass $level
	 * @param int       $user_id
	 * @param bool      $force
	 *
	 * @return \stdClass|null
	 */
	public function get_member_level_for_user( $level, $user_id, $force ) {
		
		$utils = Utilities::get_instance();
		
		if ( $utils->plugin_is_active( null, 'pmpro_getOption' ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id, $force );
		}
		
		return $level;
	}
	
	/**
	 * Add send schedule information to metabox (if needed)
	 */
	public function load_send_schedule_metabox( $term_type ) {
		
		$utils = Utilities::get_instance();
		
		if ( false == $this->processing_this_term( $term_type ) ) {
			return;
		}
		
		add_meta_box(
			'e20r-pw-send-schedule',
			__( "Message Settings", Payment_Warning::plugin_slug ),
			array( self::$instance, 'display_schedule_meta' ),
			Email_Notice::cpt_type,
			'side',
			'high'
		);
	}
	
	/**
	 * Verify if the child function is processing this term
	 *
	 * @param null|int|string $type
	 *
	 * @return bool
	 */
	public function processing_this_term( $type = null ) {
		
		global $post;
		global $post_ID;
		
		$utils           = Utilities::get_instance();
		$current_post_id = null;
		$slug_list       = array();
		
		// Find the post ID (numeric)
		if ( empty( $post ) && empty( $post_ID ) && ! empty( $_REQUEST['post'] ) ) {
			$current_post_id = intval( $_REQUEST['post'] );
		} else if ( ! empty( $post_ID ) ) {
			$current_post_id = $post_ID;
		} else if ( ! empty( $post->ID ) ) {
			$current_post_id = $post->ID;
		}
		
		$utils->log( "Called by: " . $utils->_who_called_me() );
		
		if ( empty( $current_post_id ) ) {
			$utils->log( "No post ID found, exiting!" );
			
			return false;
		}
		
		$terms = wp_get_post_terms( $current_post_id, Email_Notice::taxonomy, parent::get_term_args( $this->taxonomy_name ) );
		
		foreach ( $terms as $term ) {
			$slug_list[] = $term->slug;
		}
		
		$type_list = $this->define_message_types( array() );
		unset( $type_list[ - 1 ] );
		
		$is_the_type = array_key_exists( $type, $type_list );
		$is_in_terms = in_array( $type, $slug_list );
		
		$utils->log( "Found {$type} in {$current_post_id}? " . ( $is_the_type || $is_in_terms ? 'Yes' : 'No' ) );
		
		if ( ( false === $is_in_terms && false === $is_in_terms ) && ( $type === $this->taxonomy_name && empty( $post ) && empty( $post_ID ) ) ) {
			$utils->log( "Processing our own type: {$type} vs {$this->taxonomy_name}. Returning success" );
			
			return true;
		}
		
		return ( $is_in_terms || $is_the_type );
	}
	
	/**
	 * The list of message types used by this plugin
	 *
	 * @filter
	 *
	 * @param array $types
	 *
	 * @return array
	 */
	public function define_message_types( $types ) {
		
		global $post_ID;
		
		$meta_key      = '_e20r_pw_message_type';
		$current_value = - 1;
		
		if ( ! empty( $post_ID ) ) {
			$current_value = get_post_meta( $post_ID, $meta_key, true );
		}
		
		$new_types = array(
			- 1                         => array(
				'label'    => __( 'Not selected', Payment_Warning::plugin_slug ),
				'value'    => - 1,
				'meta_key' => $meta_key,
				'selected' => selected( $current_value, null, false ),
			),
			E20R_PW_EXPIRATION_REMINDER => array(
				'label'      => __( 'Membership Expiration', Payment_Warning::plugin_slug ),
				'value'      => E20R_PW_EXPIRATION_REMINDER,
				'meta_key'   => $meta_key,
				'text_value' => 'expiring',
				'selected'   => selected( $current_value, E20R_PW_EXPIRATION_REMINDER, false ),
			),
			E20R_PW_RECURRING_REMINDER  => array(
				'label'      => __( 'Recurring Membership Payment', Payment_Warning::plugin_slug ),
				'value'      => E20R_PW_RECURRING_REMINDER,
				'meta_key'   => $meta_key,
				'text_value' => 'recurring',
				'selected'   => selected( $current_value, E20R_PW_RECURRING_REMINDER, false ),
			),
			E20R_PW_CREDITCARD_REMINDER => array(
				'label'      => __( 'Credit Card Expiration', Payment_Warning::plugin_slug ),
				'value'      => E20R_PW_CREDITCARD_REMINDER,
				'meta_key'   => $meta_key,
				'text_value' => 'ccexpiring',
				'selected'   => selected( $current_value, E20R_PW_CREDITCARD_REMINDER, false ),
			),
		);
		
		$types = $types + $new_types;
		
		return $types;
	}
	
	/**
	 * Converts recurring|expiring|creditcard into the integer key value for the type of message
	 *
	 * @param $string_type
	 *
	 * @return int|string
	 */
	public function get_type_from_string( $string_type ) {
		
		$types = $this->define_message_types( array() );
		
		foreach ( $types as $type ) {
			if ( isset( $type['text_value'] ) && $type['text_value'] === $string_type ) {
				return $type['value'];
			}
		}
		
		return null;
	}
	
	/**
	 * Generate the MySQL friendly date string for the end of the current month
	 *
	 * @return null|string
	 */
	public function end_of_month() {
		
		$end_of_month = null;
		
		$last_day      = apply_filters( 'e20rpw_ccexpiration_last_day_of_month', date( 't', current_time( 'timestamp' ) ) );
		$current_month = apply_filters( 'e20rpw_ccexpiration_month', date( 'm', current_time( 'timestamp' ) ) );
		$current_year  = apply_filters( 'e20rpw_ccexpiration_year', date( 'Y', current_time( 'timestamp' ) ) );
		
		$end_of_month = "{$current_year}-{$current_month}-{$last_day}";
		
		return $end_of_month;
	}
	
	/**
	 * Set the message type for the Email Notices (as used by Sequences)
	 *
	 * @param string $term_type
	 */
	public function load_message_metabox( $term_type ) {
		
		if ( false == $this->processing_this_term( $term_type ) ) {
			return;
		}
		
		add_meta_box(
			'e20r-email-notice-settings',
			__( 'Reminder Type', Payment_Warning::plugin_slug ),
			array( self::$instance, 'display_message_metabox', ),
			Email_Notice::cpt_type,
			'side',
			'high'
		);
	}
	
	/**
	 * Load the Substitution variable help text on the Email Notices Editor page
	 *
	 * @param int|string|null $term_type
	 */
	public function load_template_help( $term_type ) {
		
		if ( false == $this->processing_this_term( $term_type ) ) {
			return;
		}
		
		add_meta_box(
			'e20r_message_help',
			__( 'Substitution Variables', Payment_Warning::plugin_slug ),
			array( self::$instance, "show_template_help", ),
			Email_Notice::cpt_type,
			'normal',
			'high'
		);
	}
	
	/**
	 * Save the message type as post meta for the current post/message
	 *
	 * @param int $post_id
	 *
	 * @return bool|int
	 *
	 * @since 2.1 - ENHANCEMENT: Sort the delay values so they'll display in longest first order
	 */
	public function save_message_meta( $post_id ) {
		
		global $post;
		
		$utils = Utilities::get_instance();
		$utils->log( "Attempting to save metadata settings for {$post_id}" );
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $post_id ) ) {
			
			$utils->log( 'No post ID supplied...' );
			
			return false;
		}
		
		if ( wp_is_post_revision( $post_id ) ) {
			return $post_id;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		
		if ( ! isset( $post->post_type ) || ( Email_Notice::cpt_type != $post->post_type ) ) {
			return $post_id;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return $post_id;
		}
		
		$types = $this->define_message_types( array() );
		
		$type_list = array();
		foreach ( $types as $msg_type => $msg_settings ) {
			if ( '_e20r_pw_message_type' == $msg_settings['meta_key'] && - 1 !== $msg_type ) {
				$type_list[] = $msg_type;
			}
		}
		
		if ( ! isset( $_REQUEST['e20r-email-notice-type'] ) ||
		     ( isset( $_REQUEST['e20r-email-notice-type'] ) &&
		       ! in_array( $_REQUEST['e20r-email-notice-type'], $type_list ) ) ) {
			return $post_id;
		}
		
		if ( ! isset( $_REQUEST['e20r_message_template-schedule'] ) ) {
			return $post_id;
		}
		
		$message_type     = $utils->get_variable( 'e20r-email-notice-type', null );
		$message_schedule = $utils->get_variable( 'e20r_message_template-schedule', array() );
		
		$utils->log( "Saving settings for Payment Warnings: " . print_r( $message_schedule, true ) );
		
		if ( ! empty( $message_type ) ) {
			update_post_meta( $post_id, '_e20r_pw_message_type', $message_type );
		}
		
		/**
		 * @since 2.1 - ENHANCEMENT: Sort the delay values so they'll display in longest first order
		 */
		if ( ! empty( $message_schedule ) ) {
			
			rsort( $message_schedule ); // Sort in reverse order (longest first)
			update_post_meta( $post_id, '_e20r_pw_message_schedule', $message_schedule );
		}
	}
	
	/**
	 * Display Substitution variable help text on the Email Notices Editor page
	 */
	public function show_template_help() {
		
		global $post;
		global $post_ID;
		
		$notice_type = false;
		
		if ( ! empty( $post_ID ) ) {
			$notice_type = get_post_meta( $post_ID, '_e20r_pw_message_type', true );
		}
		
		if ( empty( $notice_type ) ) {
			$notice_type = $this->taxonomy_name;
		}
		?>
		<div id="e20r-message-editor-variable-info">
			<div class="e20r-message-template-col">
				<label for="variable_references"><?php _e( 'Reference', Email_Notice::plugin_slug ); ?>:</label>
			</div>
			<div>
				<div class="template_reference"
				     style="background: #FAFAFA; border: 1px solid #CCC; color: #666; padding: 5px;">
					<p>
						<em><?php _e( 'Use these variables in the email-notice window above.', Email_Notice::plugin_slug ); ?></em>
					</p>
					<?php Email_Notice_View::add_placeholder_variables( $notice_type ); ?>
				</div>
				<p class="e20r-message-template-help">
					<?php printf( __( "%sSuggestion%s: Type in a message title, select the Reminder Type and save this notice. It will give you access to even more substitution variables.", Payment_Warning::plugin_slug ), '<strong>', '</strong>' ); ?>
				</p>
			
			</div>
		</div>
		<?php
	}
	
	/**
	 * Add metabox for notice send schedule options
	 */
	public function display_schedule_meta() {
		
		global $post_ID;
		global $post;
		
		$utils = Utilities::get_instance();
		
		$template      = $this->load_template_settings( "{$post->post_name}", false );
		$template_type = get_post_meta( $post_ID, '_e20r_pw_message_type', true );
		$schedule      = get_post_meta( $post_ID, '_e20r_pw_message_schedule', true );
		
		if ( empty( $template_type ) && ! empty( $template['type'] ) ) {
			$template_type = $template['type'];
		}
		
		if ( empty( $schedule ) ) {
			$utils->log( "Loading the default schedule for {$template_type}" );
			$schedule = $this->load_schedule( array(), intval( $template_type ), Payment_Warning::plugin_slug );
		} else if ( empty( $schedule ) && ! empty( $template['schedule'] ) ) {
			$schedule = $template['schedule'];
			$utils->log( "Loading the template array schedule for {$template_type}" );
		} ?>
		<div class="submitbox" id="e20r-editor-postmeta">
			<div id="minor-publishing">
				<div id="e20r-pw-schedule-settings">
					<div class="e20r-message-template e20r-message-schedule-info">
						<th scope="row" valign="top" class="e20r-message-template-col">
							<label for="e20r-message-schedule">
								<?php _e( 'Send on day #', Payment_Warning::plugin_slug ); ?>
							</label>
						</th>
						<td class="e20r-message-template-col">
							<?php
							if ( ! empty( $schedule ) ) {
								foreach ( $schedule as $days ) { ?>
									<div class="e20r-schedule-entry">
										<input name="e20r_message_template-schedule[]" type="number"
										       value="<?php esc_attr_e( $days ); ?>" class="e20r-message-schedule"/>&nbsp;
										<span class="e20r-message-schedule-remove">
											<input type="button"
											       value="<?php _e( "Remove", Payment_Warning::plugin_slug ); ?>"
											       class="e20r-delete-schedule-entry button-secondary"/>
                                        </span>
									</div>
								<?php }
								?>
								<button
									class="button-secondary e20r-add-new-schedule"><?php _e( "Add new", Payment_Warning::plugin_slug ); ?></button>
								<p>
									<small>
										<?php printf( __( '%3$sHint%4$s: Positive numbers sends the message %1$sbefore%2$s the event, negative numbers sends it %1$safter%2$s the event', Payment_Warning::plugin_slug ), '<em>', '</em>', '<strong>', '</strong>' ); ?>
									</small>
								</p>
								<?php
							} ?>
						</td>
					</div>
				</div>
			</div>
		</div>
		<?php
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
	public function load_template_settings( $template_name, $load_body = false ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Loading Message templates for {$template_name}" );
		
		// TODO: Load existing PMPro templates that apply for this email-notice
		$pmpro_email_templates = apply_filters( 'pmproet_templates', array() );
		
		/*
		$old_template_info = get_option( $this->option_name, $this->default_templates() );
		
		if ( 'all' === $template_name ) {
			
			if ( true === $load_body ) {
				
				foreach ( $old_template_info as $name => $settings ) {
					
					$util->log( "Loading body for {$name}" );

					if ( empty( $name ) || 1 === preg_match( '/-/', $name ) ) {
						unset( $old_template_info[ $name ] );
						continue;
					}
					
					if ( empty( $settings['body'] ) ) {
						
						$util->log( "Body has no content, so loading from default template: {$name}" );
						$settings['body']           = $this->load_default_template_body( $name );
						$old_template_info[ $name ] = $settings;
					}
				}
			}
			return $old_template_info;
			
		}
		
		// Specified template settings not found so have to return the default settings
		if ( $template_name !== 'all' && ! isset( $template_info[ $template_name ] ) ) {
			
			$template_info[ $template_name ] = $this->default_template_settings( $template_name );
			
			// Save the new template info
			update_option( $this->option_name, $template_info, 'no' );
		}
		
		if ( $template_name !== 'all' && true === $load_body && empty( $template_info[ $template_name ]['body'] ) ) {
			
			$template_info[ $template_name ]['body'] = $this->load_default_template_body( $template_name );
		}
		*/
		
		return array();
		// return $template_info[ $template_name ];
	}
	
	/**
	 * Set/select the default reminder schedule based on the type of reminder
	 *
	 * @param array      $schedule
	 * @param int|string $type
	 * @param string     $slug
	 *
	 * @return array
	 */
	public function load_schedule( $schedule, $type = E20R_PW_RECURRING_REMINDER, $slug ) {
		
		if ( $slug !== Payment_Warning::plugin_slug ) {
			return $schedule;
		}
		
		switch ( $type ) {
			case E20R_PW_RECURRING_REMINDER:
				
				$pw_schedule = array_keys( apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
					7 => 'membership_recurring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-recurring-reminder-schedule', $pw_schedule );
				break;
			
			case E20R_PW_EXPIRATION_REMINDER:
				$pw_schedule = array_keys( apply_filters( 'pmproeewe_email_frequency_and_templates', array(
					30 => 'membership_expiring',
					60 => 'membership_expiring',
					90 => 'membership_expiring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-expiration-schedule', $pw_schedule );
				break;
			case E20R_PW_CREDITCARD_REMINDER:
				$pw_schedule = array( 7, 14, 21, 28 );
				$pw_schedule = apply_filters( 'e20r-payment-warning-creditcard-expiration-schedule', $pw_schedule );
				break;
			default:
				$pw_schedule = array( 7, 15, 30 );
		}
		
		return array_merge( $pw_schedule, $schedule );
	}
	
	/**
	 * Return the default payment warning message template settings
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
				'description'    => __( 'Standard Header for Messages', Email_Notice::plugin_slug ),
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
				'description'    => __( 'Standard Footer for Messages', Email_Notice::plugin_slug ),
				'file_name'      => 'messagefooter.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'recurring_default'  => array(
				'subject'        => sprintf( __( "Your upcoming recurring membership payment for %s", Email_Notice::plugin_slug ), '!!sitename!!' ),
				'active'         => true,
				'type'           => E20R_PW_RECURRING_REMINDER,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(), E20R_PW_RECURRING_REMINDER, Payment_Warning::plugin_slug ),
				'description'    => __( 'Recurring Payment Reminder', Email_Notice::plugin_slug ),
				'file_name'      => 'recurring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'expiring_default'   => array(
				'subject'        => sprintf( __( "Your membership at %s will end soon", Email_Notice::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => E20R_PW_EXPIRATION_REMINDER,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(), E20R_PW_EXPIRATION_REMINDER, Payment_Warning::plugin_slug ),
				'description'    => __( 'Membership Expiration Warning', Email_Notice::plugin_slug ),
				'file_name'      => 'expiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'ccexpiring_default' => array(
				'subject'        => sprintf( __( "Credit Card on file at %s expiring soon", Email_Notice::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => E20R_PW_CREDITCARD_REMINDER,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(), E20R_PW_CREDITCARD_REMINDER, Payment_Warning::plugin_slug ),
				'description'    => __( 'Credit Card Expiration', Email_Notice::plugin_slug ),
				'file_name'      => 'ccexpiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
		);
		
		return apply_filters( 'e20r_pw_default_email_templates', $templates );
	}
	
	/**
	 * Help info for the Substitution variables available for the Editor page/post
	 *
	 * @param array  $variables
	 * @param string $type
	 *
	 * @return array
	 */
	public function variable_help( $variables, $type ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Processing for type: {$type} by " . $utils->_who_called_me() );
		
		if ( true === $this->processing_this_term( $type ) ) {
			
			// Default (always available) variables
			$variables = array(
				'display_name'          => __( 'Display Name (User Profile setting) for the user receiving this message', Payment_Warning::plugin_slug ),
				'first_name'            => __( "The first name for the user receiving this message", Payment_Warning::plugin_slug ),
				'last_name'             => __( "The last/surname for the user receiving this message", Payment_Warning::plugin_slug ),
				'user_login'            => __( 'Login / username for the user receiving this message', Payment_Warning::plugin_slug ),
				'user_email'            => __( 'The email address of the user receiving this message', Payment_Warning::plugin_slug ),
				'sitename'              => __( 'The blog/site name (see General Settings)', Payment_Warning::plugin_slug ),
				'siteemail'             => __( "The email address used as the 'From' email when sending this message to the user", Payment_Warning::plugin_slug ),
				'membership_id'         => __( 'The ID of the membership level for the user receiving this message', Payment_Warning::plugin_slug ),
				'membership_level_name' => __( "The active Membership Level name for the user receiving this message  (from the Membership Level settings page)", Payment_Warning::plugin_slug ),
				'login_link'            => __( "A link to the login page for this site. Can be used to send the user to the content after they've logged in/authenticated. Specify the link as HTML: `<a href=\"!!login_link!!?redirect_to=!!encoded_content_link!!\">Access the content</a>`", Payment_Warning::plugin_slug ),
				'account_page_link'     => __( "Link to the member account information page", Payment_Warning::plugin_slug ),
				'account_page_login'    => __( "Link to the member account information page, forced via the WordPress login page.", Payment_Warning::plugin_slug ),
			);
			
			switch ( $type ) {
				case E20R_PW_EXPIRATION_REMINDER:
					$variables['membership_ends'] = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
					break;
				
				case E20R_PW_RECURRING_REMINDER:
					$variables['cancel_link']         = __( 'A link to the Membership Cancellation page', Payment_Warning::plugin_slug );
					$variables['billing_address']        = __( 'The stored PMPro billing address (formatted)', Payment_Warning::plugin_slug );
					$variables['saved_cc_info']       = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
					$variables['next_payment_amount'] = __( "The amount of the upcoming recurring payment for the user who's receving this message", Payment_Warning::plugin_slug );
					$variables['payment_date']        = __( "The date when the recurring payment will be charged to the user's payment method", Payment_Warning::plugin_slug );
					$variables['membership_ends']     = __( "If there is a termination date saved for the recipient's membership, it will be formatted per the 'Settings' => 'General' date settings.", Payment_Warning::plugin_slug );
					break;
				
				case E20R_PW_CREDITCARD_REMINDER:
					$variables['billing_address']       = __( 'The stored PMPro billing address (formatted)', Payment_Warning::plugin_slug );
					$variables['saved_cc_info']      = __( "The stored Credit Card info for the payment method used when paying for the membership by the user receiving this message. The data is stored in a PCI-DSS compliant manner (the last 4 digits of the card, the type of card, and its expiration date)", Payment_Warning::plugin_slug );
					$variables['billing_page_link']  = __( "Link to the Membership billing information page (used to change credit card info)", Payment_Warning::plugin_slug );
					$variables['billing_page_login'] = __( "Link to the Membership billing information page, via the WordPress login process. (used to change credit card info)", Payment_Warning::plugin_slug );
					break;
				
				default:
					$utils->log( "Type of reminder not found??? {$type}" );
			}
		}
		
		return $variables;
	}
	
	/**
	 * Defining list of data (substitution) variables and fields/locations
	 *
	 * @param array  $variable_list
	 * @param string $type
	 *
	 * @return array
	 */
	public function default_data_variables( $variable_list, $type ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Looking up variable definitions for {$type} / {$this->taxonomy_name}" );
		
		// if ( $type == $this->processing_this_term( $type ) ) {
		$variable_list = array(
			'display_name'          => array( 'type' => 'wp_user', 'variable' => 'display_name' ),
			'first_name'            => array( 'type' => 'wp_user', 'variable' => 'first_name' ),
			'last_name'             => array( 'type' => 'wp_user', 'variable' => 'last_name' ),
			'user_login'            => array( 'type' => 'wp_user', 'variable' => 'user_login' ),
			'user_email'            => array( 'type' => 'wp_user', 'variable' => 'user_email' ),
			// 'random_user_meta'   => array( 'type' => 'user_meta', 'variable' => 'meta_key' ),
			'sitename'              => array( 'type' => 'wp_options', 'variable' => 'blogname' ),
			'siteemail'             => array( 'type' => 'wp_options', 'variable' => 'admin_email' ),
			'membership_id'         => array( 'type' => 'membership', 'variable' => 'membership_id' ),
			'membership_level_name' => array( 'type' => 'membership', 'variable' => 'membership_level_name' ),
			'login_link'            => array( 'type' => 'link', 'variable' => 'wp_login' ),
			'next_payment_amount'   => array( 'type' => 'membership', 'variable' => 'billing_amount' ),
			'payment_date'          => array( 'type' => 'membership', 'variable' => 'payment_date' ),
			'currency'              => array( 'type' => 'wp_options', 'variable' => 'pmpro_currency' ),
			'billing_address'          => array( 'type' => null, 'variable' => null ),
			'saved_cc_info'         => array( 'type' => null, 'variable' => null ),
			'billing_page_link'     => array( 'type' => 'link', 'variable' => 'billing_page' ),
			'billing_page_login'    => array( 'type' => 'encoded_link', 'variable' => 'billing_page' ),
			'account_page_link'     => array( 'type' => 'link', 'variable' => 'account_page' ),
			'account_page_login'    => array( 'type' => 'encoded_link', 'variable' => 'account_page' ),
			'membership_ends'       => array( 'type' => 'membership', 'variable' => 'enddate' ),
			'enddate'               => array( 'type' => 'membership', 'variable' => 'enddate' ),
		);
		
		// }
		
		return $variable_list;
	}
	
	/**
	 * Process custom variables that require extra care (filters, plugin specific variables, etc).
	 *
	 * @param mixed  $value
	 * @param string $var_name
	 * @param int    $user_id
	 * @param array  $settings
	 *
	 * @return mixed
	 */
	public function load_filter_value( $value, $var_name, $user_id, $settings ) {
		
		global $wpdb;
		
		$utils = Utilities::get_instance();
		$sql   = null;
		
		// Make sure we load this from the
		if ( 'next_payment_amount' === $var_name && 'billing_amount' === $settings['variable'] ) {
			
			$utils->log( "Provide the billing amount from the upstream payment gateway!" );
			$sql = $wpdb->prepare( "SELECT next_payment_amount FROM {$wpdb->prefix}e20rpw_user_info WHERE user_id = %d ORDER BY ID DESC LIMIT 1", $user_id );
		}
		
		if ( 'payment_date' === $var_name ) {
			$utils->log( "Load the next expected payment date for {$user_id} from the upstream payment gateway" );
			
			$sql = $wpdb->prepare( "SELECT next_payment_date FROM {$wpdb->prefix}e20rpw_user_info WHERE user_id = %d ORDER BY ID DESC LIMIT 1", $user_id );
			
			$upstream_value = $wpdb->get_var( $sql );
			
			// Only update the default value (local user level info) if there's data from the gateway
			if ( ! empty( $upstream_value ) ) {
				$value = date( get_option( 'date_format' ), strtotime( $upstream_value, current_time( 'timestamp' ) ) );
				$sql   = null;
			}
			
		}
		
		// Load the data when we're filtering for something that creates a custom SQL statement
		if ( ! empty( $sql ) ) {
			
			$upstream_value = $wpdb->get_var( $sql );
			
			// Only update the default value (local user level info) if there's data from the gateway
			if ( ! empty( $upstream_value ) ) {
				$value = $upstream_value;
			}
		}
		
		return $value;
	}
	
	/**
	 * Generate list of templates to include in Payment Warning "Template" settings drop-down
	 *
	 * @param string $selected - Name of selected/current template being used
	 * @param int    $type     - Type of template to add/include
	 *
	 */
	public function add_email_options( $selected, $type = E20R_PW_RECURRING_REMINDER ) {
		
		$templates = $this->configure_cpt_templates( $type );
		
		foreach ( $templates as $template ) {
			printf(
				'<option label="%1$s" value="%2$s" %3$s>%2$s</option>',
				esc_attr( $template['description'] ),
				sanitize_file_name( $template['file_name'] ),
				selected( $selected, sanitize_file_name( $template['file_name'] ), false )
			);
		}
	}
	
	/**
	 * Create and return all relevant notice templates for Reminder Editor
	 *
	 * @param mixed $alert_type E20R_PW_EXPIRATION_REMINDER|E20R_PW_RECURRING_REMINDER|E20R_PW_CREDITCARD_REMINDER
	 *
	 * @return array
	 */
	public function configure_cpt_templates( $alert_type = null ) {
		
		$query_args = array(
			'posts_per_page' => - 1,
			'post_type'      => Email_Notice::cpt_type,
			'post_status'    => 'publish',
			'tax_query'      => array(
				'relation' => 'AND',
				array(
					'taxonomy'         => Email_Notice::taxonomy,
					'field'            => 'slug',
					'operator'         => 'IN',
					'include_children' => false,
					'terms'            => array( $this->taxonomy_name ),
				),
			),
		);
		
		$emails = new \WP_Query( $query_args );
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Number of payment warning templates found: " . $emails->found_posts );
		$templates = $this->load_template_settings( 'all' );
		
		foreach ( $templates as $key => $settings ) {
			if ( $settings['type'] != $alert_type ) {
				$utils->log( "Skipping template of type {$settings['typs']}" );
				unset( $templates[ $key ] );
			}
		}
		
		foreach ( $emails->get_posts() as $msg ) {
			
			$msg_type     = get_post_meta( $msg->ID, '_e20r_pw_message_type', true );
			$msg_schedule = get_post_meta( $msg->ID, '_e20r_pw_message_schedule', true );
			
			$utils->log( "Processing {$msg->ID}/{$msg->post_name} with type {$msg_type}" );
			
			if ( is_null( $alert_type ) || $msg_type == $alert_type || $msg->post_name === $alert_type ) {
				
				$utils->log( "Adding message {$msg->ID} to template list" );
				
				$templates[ $msg->ID ]                   = $this->default_template_settings( $msg->ID );
				$templates[ $msg->ID ]['subject']        = $msg->post_title;
				$templates[ $msg->ID ]['active']         = true;
				$templates[ $msg->ID ]['type']           = ! empty( $msg_type ) ? $msg_type : E20R_PW_RECURRING_REMINDER;
				$templates[ $msg->ID ]['body']           = $msg->post_content;
				$templates[ $msg->ID ]['data_variables'] = apply_filters( 'e20r-email-notice-data-variables', array(), $this->taxonomy_name );
				$templates[ $msg->ID ]['description']    = $msg->post_excerpt;
				$templates[ $msg->ID ]['file_name']      = "{$msg->ID}.html";
				$templates[ $msg->ID ]['file_path']      = E20R_WP_TEMPLATES;
				$templates[ $msg->ID ]['schedule']       = empty( $msg_schedule ) ? $this->load_schedule( array(), $msg_type, Payment_Warning::plugin_slug ) : $msg_schedule;
				
			}
		}
		
		// $utils->log( "Templates: " . print_r( $templates, true ) );
		
		wp_reset_postdata();
		
		return $templates;
	}
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	public function default_template_settings( $template_name ) {
		
		return array(
			'subject'        => null,
			'active'         => false,
			'type'           => E20R_PW_RECURRING_REMINDER,
			'body'           => null,
			'data_variables' => array(),
			'schedule'       => $this->default_schedule( array(), E20R_PW_RECURRING_REMINDER, Payment_Warning::plugin_slug ),
			'description'    => null,
			'file_name'      => "{$template_name}.html",
			'file_path'      => dirname( E20R_PW_DIR ) . '/templates',
		);
	}
	
	/**
	 * Filter handler to load the Reminder Editor notice content
	 *
	 * @filter 'e20r-email-email-notice-notice-content'
	 *
	 * @param string $content
	 * @param mixed  $template_slug
	 *
	 * @return string|null
	 */
	public function load_template_content( $content, $template_slug ) {
		
		$utils = Utilities::get_instance();
		
		if ( 1 === preg_match( '/\.html/i', $template_slug ) ) {
			$utils->log( "Removing trailing .html (added by option for compatibility reasons)" );
			$template_slug = preg_replace( '/\.html/i', '', $template_slug );
		}
		
		$utils->log( "Searching for: {$template_slug}" );
		
		if ( is_numeric( $template_slug ) ) {
			$utils->log( "Given a numeric value, so looking it up by ID" );
			$notice = new \WP_Query( array(
				'post_type' => Email_Notice::cpt_type,
				'p'         => $template_slug,
				'tax_query' => array(
					'relation' => 'AND',
					array(
						'taxonomy'         => Email_Notice::taxonomy,
						'field'            => 'slug',
						'operator'         => 'IN',
						'terms'            => array( $this->taxonomy_name ),
						'include_children' => true,
					),
				),
			) );
		} else {
			
			$notice = new \WP_Query( array(
				'post_type' => Email_Notice::cpt_type,
				'name'      => $template_slug,
				'tax_query' => array(
					'relation' => 'AND',
					array(
						'taxonomy'         => Email_Notice::taxonomy,
						'field'            => 'slug',
						'operator'         => 'IN',
						'terms'            => array( $this->taxonomy_name ),
						'include_children' => true,
					),
				),
			) );
		}
		
		if ( ! empty( $notice ) ) {
			
			$notices = $notice->get_posts();
			
			$utils->log( "Found {$notice->found_posts} templates for {$template_slug}");
			
			if ( count( $notices ) > 1 ) {
				$utils->log( "Found more than a single email notice/template for {$template_slug}!!!" );
			} else if ( count( $notices ) === 1 ) {
				$utils->log( "Found a single email template to use for {$template_slug}" );
			}
			
			/**
			 * @var \WP_Post $email_notice
			 */
			$email_notice = array_pop( $notices );
			$content      = wpautop( do_shortcode( wp_unslash( $email_notice->post_content ) ) );
		} else {
			$content = $this->load_default_template_body( $template_slug );
		}
		
		return $content;
	}
	
	/**
	 * Install custom taxonomies for the Reminder Editor child class ('e20r-pw-notices' for Editor class)
	 *
	 * @param null|string $name
	 * @param null|string $nicename
	 * @param null|string $descr
	 */
	public function install_taxonomy( $name = null, $nicename = null, $descr = null ) {
		
		parent::install_taxonomy( $this->taxonomy_name, $this->taxonomy_nicename, $this->taxonomy_description );
	}
}
