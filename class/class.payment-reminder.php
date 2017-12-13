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
use E20R\Utilities\Editor\Editor;
use E20R\Payment_Warning\Tools\Email_Message;
use E20R\Utilities\Utilities;
use DateTime;
use DateTimeZone;
use DateInterval;
use Exception;

class Payment_Reminder {
	
	private static $instance = null;
	
	private $schedule = array();
	
	private $settings = array();
	
	private $template_name = null;
	
	private $users = array();
	
	private $message_handler;
	
	/**
	 * Payment_Reminders constructor.
	 *
	 * @param $template_key
	 */
	public function __construct( $template_key = null ) {
		
		if ( ! empty( $template_key ) ) {
			
			$this->template_name = $template_key;
			$this->load_schedule();
		}
		
		add_filter( 'e20r-payment-warning-send-email', array( $this, 'should_send_reminder_message' ), -1, 4 );
	}
	
	public function load_hooks() {
	
	}
	
	/**
	 * Determine whether the user's payment date +/- the Interval value means the message should be sent
	 *
	 * @param bool   $send              Whether to send the payment reminder
	 * @param string $user_payment_date Date/Time string for next payment date
	 * @param int    $interval          The number of days before the payment date the message should be sent
	 *
	 * @return bool
	 */
	public function should_send_reminder_message( $send, $user_payment_date, $interval, $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Testing if {$user_payment_date} is within {$interval} days of " . date_i18n( 'Y-m-d', current_time( 'timestamp' ) ) );
		
		$negative = ( $interval < 0 ) ? true : false;
		
		try {
			$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
			$now      = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
			
			$user_payment = DateTime::createFromFormat( 'Y-m-d H:i:s', $user_payment_date, $timezone );
			
		} catch ( Exception $e ) {
			
			$util->log( "Error when creating DateTime Object for next User Payment info {$user_payment_date}: " . $e->getMessage() );
			
			return false;
		}
		
		try {
			$current_time = DateTime::createFromFormat( 'Y-m-d H:i:s', $now, $timezone );
		} catch ( Exception $e ) {
			
			$util->log( "Error when creating DateTime Object for Current time: " . $e->getMessage() );
			
			return false;
		}
		
		$interval = absint( $interval );
		
		try {
			
			$interval_string = ( $negative ? "{$interval} days" : "-{$interval} days" );
			$util->log( "Applying interval string: {$interval_string}" );
			
			$increment = DateInterval::createFromDateString( $interval_string );
			
			$user_payment->add( $increment );
			
		} catch ( Exception $e ) {
			
			$util->log( "Error when adding/subtracting the specified interval ({$interval}): " . $e->getMessage() );
			
			return false;
		}
		
		$util->log( "Next payment warning for interval {$interval}: " . $current_time->format( 'Y-m-d' ) . " vs " . $user_payment->format( 'Y-m-d' ) );
		
		return ( $user_payment->format( 'Y-m-d' ) == $current_time->format( 'Y-m-d' ) );
	}
	
	/**
	 * Task handler for Payment email reminders/notices
	 *
	 * @param string|null $type
	 */
	public function process_reminders( $type = null ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Received type request: {$type}" );
		
		// Set the default type to recurring if not received
		if ( empty( $type ) ) {
			$type = 'ccexpiration';
		}
		
		switch( $type ) {
			case 'expiration':
				$target_template = 'expiring';
				break;
			case 'recurring':
				$target_template = 'recurring';
				break;
			case 'ccexpiration':
				$target_template = 'ccexpiring';
				break;
		}
		
		$fetch           = Fetch_User_Data::get_instance();
		$main            = Payment_Warning::get_instance();
		$users           = $fetch->get_local_user_data( $type );
		$templates       = Reminder_Editor::get_templates_of_type( $type );
		$message_handler = $main->get_handler( 'messages' );
		
		$util->log( "Will process {$type} messages for " . count( $users ) . " members/users" );
		$this->set_users( $users );
		
		foreach ( $templates as $template_name ) {
			
			if ( false == preg_match( "/^{$target_template}_/", $template_name ) ) {
				$util->log("Template {$template_name} doesn't belong to {$target_template}/{$type}. Nothing to do.");
				continue;
			}
			
			$this->template_name = $template_name;
			
			foreach ( $users as $user_info ) {
				
				$message = new Email_Message( $user_info, $template_name );
				
				$util->log( "Adding user message for " . $message->get_user()->get_user_ID() );
				$message_handler->push_to_queue( $message );
			}
			
			$util->log( "Dispatching the possible send message operation for all users" );
			$message_handler->save()->dispatch();
		}
	}
	
	public function get_users() {
		
		if ( ! empty( $this->users ) ) {
			return $this->users;
		}
		
		return null;
	}
	
	public function set_users( $users ) {
		
		$this->users = $users;
	}
	
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Fetch / build the schedule of days and email templates to use for the payment warnings
	 *
	 * @return array|bool|mixed
	 * @access private
	 */
	private function load_schedule() {
		
		$this->settings = Reminder_Editor::get_template_by_name( $this->template_name, false );
		$this->schedule = $this->settings['schedule'];
		
		return $this->schedule;
	}
}