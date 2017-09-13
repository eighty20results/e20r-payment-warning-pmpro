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

class Handle_Payments extends E20R_Background_Process {
	
	private static $instance = null;
	
	protected $action = null;
	
	/**
	 * @var null|User_Data
	 */
	protected $user_info = null;
	/**
	 * Handle_Payments constructor.
	 *
	 * @param string $handle
	 */
	public function __construct( $handle ) {
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Handle_Payments class");
		
		self::$instance = $this;
		/*
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );
		*/
		$this->action = "hp_" . strtolower( $handle );
		$util->log("Set Action variable to {$this->action} for Handle_Payments");
		
		parent::__construct();
	}
	
	/**
	 * Task to fetch the most recent payment / charge information from the upstream gateway(s)
	 *
	 * @param User_Data $user_data
	 *
	 * @return bool
	 *
	 * @since 1.9.4 - BUG FIX: Didn't force the reminder type (expiration) for the user data when processing
	 * @since 1.9.4 - ENHANCEMENT: No longer need to specify type of record being saved
	 */
	protected function task( $user_data ) {
		
		$util = Utilities::get_instance();
		
		$util->log("Trigger per-addon payment/charge download for user" );
		
		if ( !is_bool( $user_data ) ) {
			
			$user_id = $user_data->get_user_ID();
			$user_data->set_reminder_type('expiration');
			
			$util->log( "Loading from DB (if record exists) for {$user_id}");
			$user_data->maybe_load_from_db( $user_id );
			
			$user_data = apply_filters( 'e20r_pw_addon_get_user_payments', $user_data );
			
			// @since 1.9.4 - ENHANCEMENT: No longer need to specify type of record being saved
			if ( false !== $user_data && true === $user_data->save_to_db() ) {
				
				$util->log( "Fetched payment data from gateway for " . $user_data->get_user_email() );
				$util->log( "Done processing payment data for {$user_id}. Removing the user from the queue" );
			}
			
			$util->log( "User payment record not saved/processed. May be a-ok..." );
			
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
	 * Clear queue of entries for this handler
	 */
	public function clear_queue() {
		
		$utils = Utilities::get_instance();
		
		global $wpdb;
		
		$table  = $wpdb->options;
		$column = 'option_name';
		
		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}
		
		$key = $this->identifier . "_batch_%";
		$utils->log("Attempting to manually clear the job queue for {$key}");
		
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} LIKE %s", $key ) ) ) {
			$utils->log("ERROR: Unable to clear the job queue for {$key}!");
		}
	}
	
	/**
	 * Log & complete the Handle_Payments background operation
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 1.9.4 - ENHANCEMENT: Remove Non-recurring payment data fetch lock w/error checking & messages to dashboard
	 */
	protected function complete() {
		parent::complete();
		
		$this->clear_queue();
		
		$util = Utilities::get_instance();
		if ( false === delete_option( 'e20rpw_paym_fetch_mutex' ) ) {
			$util->add_message( __( 'Unable to clear lock after loading Payment data', Payment_Warning::plugin_slug ), 'error', 'backend' );
		}
		
		$util->log("Completed remote payment/charge data fetch for all active gateways");
	}
}