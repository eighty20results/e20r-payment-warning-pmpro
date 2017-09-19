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
use E20R\Utilities\Utilities;

class Handle_Subscriptions extends E20R_Background_Process {
	
	private static $instance = null;
	
	/**
	 * Constructor for Handle_Subscriptions class
	 *
	 * @param string $handle
	 */
	public function __construct( $handle ) {
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Handle_Subscriptions class");
		
		self::$instance = $this;
		/*
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );*/
		$this->action = "hs_" . strtolower( $handle );
		
		$util->log("Set Action variable to {$this->action} for Handle_Subscriptions");
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * Process Background data retrieval task (fetching subscription data) for a specific user
	 *
	 * @param User_Data $user_data
	 *
	 * @return bool
	 *
	 * @since 1.9.4 - BUG FIX: Didn't force the reminder type (recurring) for the user data when processing
	 * @since 1.9.4 - ENHANCEMENT: No longer need to specify type of record being saved
	 */
	protected function task( $user_data ) {
		
		$util = Utilities::get_instance();
		
		$util->log("Trigger per-addon subscription download for user" );
		
		if ( ! is_bool( $user_data ) ) {
			
			$user_id = $user_data->get_user_ID();
			$user_data->set_reminder_type('recurring');
			
			$util->log( "Loading from DB (if record exists) for {$user_id}" );
			$user_data->maybe_load_from_db();
			
			$user_data = apply_filters( 'e20r_pw_addon_get_user_subscriptions', $user_data );
			
			if ( false !== $user_data && true === $user_data->save_to_db() ) {
				
				$util->log( "Done processing subscription data for {$user_id}. Removing the user from the queue" );
				return false;
			}
			
			$util->log( "User subscription record not saved/processed. May be a-ok..." );
		} else {
			$util->log("Incorrect format for user data record (boolean received!)");
		}
		
		return false;
	}
	
	/**
	 * Return the action name for this Background Process
	 *
	 * @return string
	 */
	public function get_action() {
		return $this->action;
	}
	
	/**
	 * Log & complete the Handle_Subscriptions background operation
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 1.9.4 - ENHANCEMENT: Remove subscription data fetch lock w/error checking & messages to dashboard
	 */
	protected function complete() {
		
		parent::complete();
		
		$this->clear_queue();
		
		$util = Utilities::get_instance();
		
		// @since 1.9.4 - ENHANCEMENT: Remove subscription data fetch lock w/error checking & messages to dashboard
		if ( false === delete_option( 'e20rpw_subscr_fetch_mutex' ) ) {
			$util->add_message( __( 'Unable to clear lock after loading Subscription data', Payment_Warning::plugin_slug ), 'error', 'backend' );
		}
		
		$util->log("Completed remote subscription data fetch for all active gateways");
		// $util->add_message( __("Fetched payment data for all active gateway add-ons", Payment_Warning::plugin_slug ), 'info', 'backend' );
	}
}