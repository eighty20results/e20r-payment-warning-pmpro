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
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Handle_Messages class");
		
		self::$instance = $this;
		
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );
		$this->action = "hm_" . strtolower( $name[(count( $name ) - 1 )] );
		
		$util->log("Set Action variable to {$this->action} for Handle_Messages");
		
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
		
		$util->log("Trigger per user message operation for " . $message->get_user()->get_user_ID() );
		
		$template_type = $message->get_template_type();
		$schedule = $message->get_schedule();
		
		if ( empty( $template_type ) ) {
			$util->log(sprintf( "Unable to process %s Message based on %s for ", $message->get_template_type(), $message->get_user()->get_user_ID() ) );
			return false;
		}
		// $util->log( "Sending schedule for {$template_type} is: " . print_r( $schedule, true ) );
		
		foreach( $schedule as $interval_day ) {
			
			$user_id = $message->get_user()->get_user_ID();
			$send = false;
			$util->log("Processing for warning day #{$interval_day}, user {$user_id}" );
			
			$next_payment = $message->get_user()->get_next_payment();
			$util->log("Found Next payment info: {$next_payment} for {$user_id}" );
			
			if ( !empty( $next_payment ) ) {
				
				$send = $message->should_send( $next_payment, $interval_day, $template_type );
			}
			
			$util->log("Should we send {$user_id} the {$template_type} email message? " . ( $send ? 'Yes' : 'No') );
			
			if ( true === $send ) {
				$util->log("Preparing the message to {$user_id}");
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
		$util = Utilities::get_instance();
		$util->log("Completed message transmission operations");
		// $util->add_message( __("Fetched user subscription data for all active gateway add-ons", Payment_Warning::plugin_slug ), 'info', 'backend' );
	}
}