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
use E20R\Payment_Warning\Utilities\Utilities;

class Handle_Subscriptions extends E20R_Background_Process {
	
	private static $instance = null;
	
	/**
	 * Constructor for Handle_Subscriptions class
	 *
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Handle_Subscriptions class");
		
		self::$instance = $this;
		
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );
		$this->action = "hs_" . strtolower( $name[(count( $name ) - 1 )] );
		
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
	 */
	protected function task( $user_data ) {
		
		$util = Utilities::get_instance();
		
		$util->log("Trigger per-addon subscription fetch for user" );
		
		if ( ! is_bool( $user_data ) ) {
			
			$user_data = apply_filters( 'e20r_pw_addon_get_user_subscriptions', $user_data );
			
			if ( false !== $user_data && $user_data->has_active_subscription() && null !== $user_data->get_last_pmpro_order() ) {
				
				$util->log( "Fetched subscription data from payment gateway for " . $user_data->get_user_email() . ". Saving to DB..." );
				if ( true === $user_data->save_to_db() ) {
					
					$util->log( "Done processing data for " . $user_data->get_user_ID() . ". Removing the item from the queue" );
					
					return false;
				}
			}
			$util->log( "User record not saved/processed. May be a-ok..." );
		} else {
			$util->log("Incorrect format (bool) for received data");
		}
		
		return false;
	}
	
	/**
	 * Clear queue of entries for this handler
	 */
	public function clear_queue() {
		
		global $wpdb;
		
		$table  = $wpdb->options;
		$column = 'option_name';
		
		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}
		
		$key = $this->identifier . "_batch_%";
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} LIKE %s", $key ) );
	}
	
	/**
	 * Log & complete the Handle_Subscriptions background operation
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		
		parent::complete();
		
		$this->clear_queue();
		// Show notice to user or perform some other arbitrary task...
		$util = Utilities::get_instance();
		$util->log("Completed remote subscription data fetch for all active gateways");
		// $util->add_message( __("Fetched payment data for all active gateway add-ons", Payment_Warning::plugin_slug ), 'info', 'backend' );
	}
}