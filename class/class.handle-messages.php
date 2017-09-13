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


use E20R\Payment_Warning\Tools\E20R_Background_Process;
use E20R\Payment_Warning\Tools\Email_Message;
use E20R\Utilities\Utilities;

class Handle_Messages extends E20R_Background_Process {
	
	private static $instance = null;
	
	/**
	 * Constructor for Handle_Messages class
	 *
	 * @param string $handle
	 */
	public function __construct( $handle ) {
		
		$util = Utilities::get_instance();
		$util->log( "Instantiated Handle_Messages class" );
		
		self::$instance = $this;
		/*
		$av           = get_class( $calling_class );
		$name         = explode( '\\', $av );
		*/
		$this->action = "hm_" . strtolower( $handle );
		
		$util->log( "Set Action variable to {$this->action} for Handle_Messages" );
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * Process Background data retrieval task (fetching subscription data) for a specific user
	 *
	 * @param Email_Message $message
	 *
	 * @return bool
	 */
	protected function task( $message ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Trigger per user message operation for " . $message->get_user()->get_user_ID() );
		
		$template_type = $message->get_template_type();
		$schedule      = $message->get_schedule();
		$send          = false;
		$check_date    = null;
		
		if ( empty( $template_type ) ) {
			$util->log( sprintf( "Unable to process the '%s' messagefor %d", $message->get_template_type(), $message->get_user()->get_user_ID() ) );
			
			return false;
		}
		// $util->log( "Sending schedule for {$template_type} is: " . print_r( $schedule, true ) );
		
		foreach ( $schedule as $interval_day ) {
			
			$user_id = $message->get_user()->get_user_ID();
			$send    = false;
			$util->log( "Processing for warning day #{$interval_day}, user {$user_id}" );
			
			$uses_recurring = $message->get_user()->has_active_subscription();
			$next_payment   = $message->get_user()->get_next_payment();
			$has_enddate    = $message->get_user()->get_end_of_membership_date();
			
			if ( true === $uses_recurring && ! empty( $next_payment ) ) {
				
				$util->log( "Found Next payment info: {$next_payment} for recurring payment member {$user_id}" );
				$check_date = $next_payment;
			}
			
			if ( false === $uses_recurring && ! empty( $has_enddate ) ) {
				$check_date = $has_enddate;
			}
			
			// No date the check against, so returning false
			if ( ! empty( $check_date ) ) {
				$send = $message->should_send( $check_date, $interval_day, $template_type );
			}
			
			$util->log( "Should we send {$user_id} the {$template_type} email message? " . ( $send ? 'Yes' : 'No' ) );
			
			if ( true === $send ) {
				$util->log( "Preparing the message to {$user_id}" );
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
	 */
	protected function complete() {
		
		parent::complete();
		// Show notice to user or perform some other arbitrary task...
		
		$this->send_admin_notice( 'recurring' );
		$this->send_admin_notice( 'expiration' );
		// $this->send_admin_notice( 'creditcard' ); // TODO: Enable the admin notice for credit card expiration warnings
		
		$util = Utilities::get_instance();
		$now  = date_i18n( 'H:i:s (m-d)', strtotime( get_option( 'timezone_string' ) ) );
		$util->log( "Completed message transmission operations: {$now}" );
		
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
	 * @param $type
	 *
	 * @return bool
	 *
	 * @access private
	 *
	 * @since 1.9.4 - BUG FIX: Returned boolean value when looking for email address for recipients of message(s)
	 */
	private function send_admin_notice( $type ) {
		
		$users              = array();
		$today              = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
		$type_text          = null;
		$subject            = sprintf( __( "Completed processing the %s payment warning type", Payment_Warning::plugin_slug ), $type );
		$skip_admin_notices = apply_filters( 'e20r-payment-warning-skip-admin-message-if-none-sent', true );
		
		switch ( $type ) {
			case 'recurring':
				$users     = get_option( 'e20r_pw_sent_recurring', array() );
				$type_text = __( "upcoming recurring payment", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Recurring Payment Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'expiration':
				$users     = get_option( 'e20r_pw_sent_expiration', array() );
				$type_text = __( "pending membership expiration", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Expiration Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'creditcard':
				$users     = get_option( 'e20r_pw_sent_creditcard', array() );
				$type_text = __( "credit card expiration", Payment_Warning::plugin_slug );
				$count     = isset( $users[ $today ] ) ? count( $users[ $today ] ) : 0;
				$subject   = sprintf( __( "Credit Card Expiration Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
		}
		
		$admin_intro_text = apply_filters(
			"e20r-payment-warning-email-pre-list-text",
			sprintf(
				__( "A %s warning message has been sent to the following list of users/members:", Payment_Warning::plugin_slug ),
				$type_text
			)
		);
		
		// Don't send empty/unneeded admin notices?
		if ( empty( $users[ $today ] ) && true === $skip_admin_notices ) {
			
			$utils = Utilities::get_instance();
			$utils->log( "No need to send the {$type} admin summary. There were 0 notices sent" );
			
			return true;
		}
		
		$body      = sprintf( "<div>%s</div>", $admin_intro_text );
		$user_list = '<div style="font-size: 11pt; font-style: italic;">';
		
		if ( ! empty( $users[ $today ] ) ) {
			// @since 1.9.4 - BUG FIX: Returned boolean value when looking for email address for recipients of message(s)
			$user_list .= implode( '<br/>', array_keys( $users[ $today ] ) );
			
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
		
		return wp_mail( $admin_address, $subject, $body, $headers );
	}
}

