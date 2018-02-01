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

namespace E20R\Payment_Warning;


use E20R\Payment_Warning\Editor\Reminder_Editor;
use E20R\Utilities\E20R_Background_Process;
use E20R\Payment_Warning\Tools\Email_Message;
use E20R\Utilities\Utilities;

class Handle_Messages extends E20R_Background_Process {
	
	/**
	 * Instance of this class (Handle_Messages class)
	 *
	 * @var Handle_Messages|null
	 */
	private static $instance = null;
	
	/**
	 * Set the action handler type (unique name)
	 * @var string $type
	 */
	private $type;
	
	/**
	 * Constructor for Handle_Messages class
	 *
	 * @param string $type
	 *
	 * @since 2.1 - BUG FIX: Didn't always process all types of warning messages
	 */
	public function __construct( $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Instantiated Handle_Messages class for {$type}" );
		
		self::$instance = $this;
		$this->type     = strtolower( $type );
		$this->action   = "email_{$this->type}";
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * Set the type of message (for the Message Handler action(s))
	 *
	 * @param string $type - recurring, expiring, ccexpiration
	 *
	 * @since 2.1 - ENHANCEMENT: Adding ability to change the action name for the background process
	 */
	public function set_message_type( $type ) {
		
		if ( 1 !== preg_match( "/{$type}/i", $this->action ) ) {
			$this->type   = strtolower( $type );
			$this->action = "email_{$this->type}";
		}
	}
	
	/**
	 * Process Background data retrieval task (fetching subscription data) for a specific user
	 *
	 * @param Email_Message $message
	 *
	 * @return bool
	 *
	 * @since 2.1 - BUG FIX: Didn't load Credit Card user data correctly
	 */
	protected function task( $message ) {
		
		$util            = Utilities::get_instance();
		$reminder_notice = Reminder_Editor::get_instance();
		
		$util->log( "Using Action variable {$this->action} for Handle_Messages" );
		$util->log( "Trigger per user message operation for " . $message->get_user()->get_user_ID() );
		
		$template_type = $message->get_template_type();
		$template_name = $message->get_template_name();
		$schedule      = $message->get_schedule();
		$check_date    = null;
		
		if ( empty( $template_type ) ) {
			$util->log( sprintf( "Unable to process the '%s' message for %d", $message->get_template_type(), $message->get_user()->get_user_ID() ) );
			
			return false;
		}
		
		// $util->log( "Sending schedule for {$template_type} is: " . print_r( $schedule, true ) );
		
		foreach ( $schedule as $interval_day ) {
			
			$user_id = $message->get_user()->get_user_ID();
			$send    = false;
			
			$util->log( "Processing for {$template_type}/{$template_name} on warning day #{$interval_day}, user {$user_id}" );
			
			$uses_recurring = $message->get_user()->has_active_subscription();
			$next_payment   = $message->get_user()->get_next_payment();
			$has_enddate    = $message->get_user()->get_end_of_membership_date();
			$type           = $reminder_notice->get_type_from_string( 'ccexpiring' );
			$is_creditcard  = ( $template_type == $type );
			
			$util->log( "Processing for a credit card template ({$template_type} vs {$type})? " . ( $is_creditcard ? 'Yes' : 'No' ) );
			
			if ( true === $is_creditcard ) {
				$util->log( "Credit Card expiration warning" );
				$check_date = $reminder_notice->end_of_month() . " 23:59:59";
			}
			
			if ( true === $uses_recurring && ! empty( $next_payment ) && false === $is_creditcard ) {
				
				$util->log( "Found Next payment info: {$next_payment} for recurring payment member {$user_id}" );
				$check_date = $next_payment;
			}
			
			if ( false === $uses_recurring && ! empty( $has_enddate ) && false === $is_creditcard ) {
				$check_date = $has_enddate;
			}
			
			// No date the check against, so returning false
			if ( ! empty( $check_date ) ) {
				$send = $message->should_send( $check_date, $interval_day, $template_type );
			}
			
			$util->log( "Should we send {$user_id} the {$template_type} email message for the {$interval_day} interval? " . ( $send ? 'Yes' : 'No' ) );
			
			if ( true === $send ) {
				$util->log( "Preparing the {$template_type}/{$template_name} message to {$user_id}" );
				$message->send_message( $template_type );
			}
		}
		
		return false;
	}
	
	
	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 2.1 - ENHANCEMENT: Add CreditCard Expiration warning admin message (when applicable)
	 * @since 3.3 - BUG FIX: Sent too many admin notices to admin
	 */
	protected function complete() {
		
		parent::complete();
		
		$this->send_admin_notice();
		
		$util = Utilities::get_instance();
		$now  = date_i18n( 'H:i:s (m/d)', strtotime( get_option( 'timezone_string' ) ) );
		$util->log( "Completed message transmission operations: {$now} for {$this->type}" );
		
		$this->clear_queue();
		
		if ( true === apply_filters( 'e20rpw_show_completion_info_banner', false ) ) {
			$util->add_message(
				sprintf(
					__( "Sending warning messages to active members complete: %s", Payment_Warning::plugin_slug ),
					$now
				),
				'info',
				'backend'
			);
		}
	}
	
	/**
	 * Send email message notifying administrator user(s) of completion
	 *
	 * @return bool
	 *
	 * @access private
	 *
	 * @since  1.9.4 - BUG FIX: Returned boolean value when looking for email address for recipients of message(s)
	 * @since  2.1 - ENHANCEMENT/FIX: Use correct message types for new email notice infrastructure
	 * @since  3.3 - BUG FIX: Sent too many admin notices to admin
	 */
	private function send_admin_notice() {
		
		$utils = Utilities::get_instance();
		
		/**
		 * @var array[string] $users
		 */
		$today        = date( 'Y-m-d', current_time( 'timestamp' ) );
		$already_sent = get_option( '_e20r_pw_admin_notices', array() );
		
		if ( isset( $already_sent[ $this->type ] ) && $today === $already_sent[ $this->type ] ) {
			$utils->log( "Sent the admin notice for message type {$this->type}" );
			
			return true;
		}
		
		$type_text          = null;
		$skip_admin_notices = apply_filters( 'e20r-payment-warning-skip-admin-message-if-none-sent', true );
		$reminder_msg       = Reminder_Editor::get_instance();
		$type_const         = $reminder_msg->get_type_from_string( $this->type );
		
		// The requested message type doesn't exist???
		if ( is_null( $type_const ) ) {
			
			$utils = Utilities::get_instance();
			$utils->log( "Error: Unable to map to the appropriate value for the requested type: {$this->type}" );
			
			return false;
		}
		
		switch ( $this->type ) {
			case 'recurring':
				$users     = get_option( "e20r_pw_sent_{$type_const}", array( $today => array() ) );
				$type_text = __( "Upcoming recurring payment", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Recurring Payment Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'expiring':
				$users     = get_option( "e20r_pw_sent_{$type_const}", array( $today => array() ) );
				$type_text = __( "Pending membership expiration", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Expiration Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'ccexpiring':
				$users     = get_option( "e20r_pw_sent_{$type_const}", array( $today => array() ) );
				$type_text = __( "Credit card expiration", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Credit Card Expiration Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			default:
				$utils->log( "Using default handler for Admin Message type ({$this->type})" );
				$users     = get_option( "e20r_pw_sent_{$type_const}", array( $today => array() ) );
				$type_text = sprintf( __( "Payment Warning Message (%s)", Payment_Warning::plugin_slug ), $this->type );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( 'Payment Warning message (%1$s) sent to %2$d users on %3$s', Payment_Warning::plugin_slug ), $this->type, $count, $today );
			
		}
		
		$admin_intro_text = apply_filters(
			"e20r-payment-warning-email-pre-list-text",
			sprintf(
				__( "%s warning message(s) has been sent to the following list of users/members:", Payment_Warning::plugin_slug ),
				$type_text
			)
		);
		
		// Don't send empty/unneeded admin notices?
		if ( empty( $users[ $today ] ) && true === $skip_admin_notices ) {
			
			$utils->log( "No need to send the {$this->type} admin summary. There were 0 notices sent" );
			
			return true;
		}
		
		$body      = sprintf( "<div>%s</div>", $admin_intro_text );
		$user_list = sprintf( '<div style="font-size: 11pt; font-style: italic;">' );
		
		if ( ! empty( $users[ $today ] ) ) {
			
			foreach ( $users[ $today ] as $user_email => $list ) {
				$user_list .= sprintf( '%s (messages sent: %d)%s', $user_email, count( $list ), '<br/>' );
			}
		} else {
			$user_list .= sprintf( __( "No %s warning emails sent/recorded for %s", Payment_Warning::plugin_slug ), $type_text, $today );
		}
		
		$user_list .= '</div>';
		$body      .= $user_list;
		
		$admin_address = get_option( 'admin_email' );
		$headers       = array();
		$cc_list       = apply_filters( 'e20r_payment_warning_admin_cc_list', array() );
		$bcc_list      = apply_filters( 'e20r_payment_warning_admin_bcc_list', array() );
		
		if ( ! empty( $cc_list ) ) {
			
			foreach ( $cc_list as $cc_to ) {
				$headers[] = "Cc: {$cc_to}";
			}
		}
		
		if ( ! empty( $bcc_list ) ) {
			foreach ( $bcc_list as $bcc_to ) {
				$headers[] = "Bcc: {$bcc_to}";
			}
		}
		
		if ( true === wp_mail( $admin_address, $subject, $body, $headers ) ) {
			
			$already_sent[ $this->type ] = $today;
			$log_length = intval( apply_filters( 'e20r-payment-warning-admin-notice-log-days', 30 ) );
			
			if ( $log_length < 0 ) {
				$utils->log("Filter provided invalid log length value: [{$log_length}]!");
				$log_length = 30;
			}
			
			// Maintain user send info for message type option
			if ( count( $users ) >= $log_length ) {
				sort( $users );
				
				$preserve = array_slice( $users, -$log_length, null, true );
				$utils->log( "Preserving " . count( $preserve ) . " days worth of send logs" );
				
				// Save the log
				$users = $preserve;
				update_option( "e20r_pw_sent_{$type_const}", $users, 'no' );
			}
		}
		
		update_option( '_e20r_pw_admin_notices', $already_sent, 'no' );
		
		return true;
	}

}

