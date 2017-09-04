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


use E20R\Payment_Warning\Utilities\E20R_Background_Process;
use E20R\Payment_Warning\Utilities\Email_Message;
use E20R\Payment_Warning\Utilities\Utilities;

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
		
		if ( empty( $template_type ) ) {
			$util->log( sprintf( "Unable to process %s Message based on %s for ", $message->get_template_type(), $message->get_user()->get_user_ID() ) );
			
			return false;
		}
		// $util->log( "Sending schedule for {$template_type} is: " . print_r( $schedule, true ) );
		
		foreach ( $schedule as $interval_day ) {
			
			$user_id = $message->get_user()->get_user_ID();
			$send    = false;
			$util->log( "Processing for warning day #{$interval_day}, user {$user_id}" );
			
			$next_payment = $message->get_user()->get_next_payment();
			$util->log( "Found Next payment info: {$next_payment} for {$user_id}" );
			
			if ( ! empty( $next_payment ) ) {
				
				$send = $message->should_send( $next_payment, $interval_day, $template_type );
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
		$this->send_admin_notice( 'creditcard' );
		
		$util = Utilities::get_instance();
		$util->log( "Completed message transmission operations" );
		// $util->add_message( __("Fetched user subscription data for all active gateway add-ons", Payment_Warning::plugin_slug ), 'info', 'backend' );
	}
	
	/**
	 * Send email message notifying administrator user(s) of completion
	 *
	 * @param $type
	 *
	 * @return bool
	 *
	 * @access private
	 */
	private function send_admin_notice( $type ) {
		
		$users     = array();
		$today     = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
		$type_text = null;
		$subject   = sprintf( __( "Completed processing the %s payment warning type", Payment_Warning::plugin_slug ), $type );
		
		switch ( $type ) {
			case 'recurring':
				$users     = get_option( 'e20r_pw_sent_recurring', array() );
				$type_text = __( "upcoming recurring payment", Payment_Warning::plugin_slug );
				$count = isset( $users[$today] ) ? count( $users[$today] ) : 0;
				$subject   = sprintf( __( "Recurring Payment Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'expiration':
				$users     = get_option( 'e20r_pw_sent_expiration', array() );
				$type_text = __( "pending membership expiration", Payment_Warning::plugin_slug );
				$count = isset( $users[$today] ) ? count( $users[$today] ) : 0;
				$subject   = sprintf( __( "Expiration Warning sent to %d users on %s", Payment_Warning::plugin_slug ), $count, $today );
				
				break;
			
			case 'creditcard':
				$users     = get_option( 'e20r_pw_sent_creditcard', array() );
				$type_text = __( "credit card expiration", Payment_Warning::plugin_slug );
				$count = isset( $users[$today] ) ? count( $users[$today] ) : 0;
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
		
		$body      = sprintf( "<div>%s</div>", $admin_intro_text );
		$user_list = '<div style="font-size: 11pt; font-style: italic;">';
		
		if ( ! empty( $users[ $today ] ) ) {
			
			$user_list .= implode( '<br/>', $users[$today] );
			
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