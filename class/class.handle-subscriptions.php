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


use E20R\Utilities\E20R_Background_Process;
use E20R\Utilities\Utilities;

class Handle_Subscriptions extends E20R_Background_Process {
	
	/**
	 * Instance for this class
	 *
	 * @var Handle_Subscriptions|null
	 */
	private static $instance = null;
	
	/**
	 * The action name (handler)
	 *
	 * @var null|string
	 */
	protected $action = null;
	
	/**
	 * The type (add-on module) of the handler
	 *
	 * @var null|string
	 */
	private $type = null;
	
	/**
	 * Constructor for Handle_Subscriptions class
	 *
	 * @param string $type
	 */
	public function __construct( $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Instantiated {$type} Handle_Subscriptions class" );
		
		self::$instance = $this;
		$this->type     = strtolower( $type );
		$this->action   = "hs_{$this->type}_subscr";
		
		$util->log( "Set Action variable to {$this->action} for Handle_Subscriptions" );
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * Return the type name (i.e. the add-on name) for this background process
	 *
	 * @return null|string
	 */
	public function get_type() {
		return $this->type;
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
		$main   = Payment_Warning::get_instance();
		
		$util->log( "Trigger per-addon subscription download for user" );
		
		if ( !empty( $user_data ) && ! is_bool( $user_data )) {
			
			$user_id = $user_data->get_user_ID();
			$user_data->set_reminder_type( 'recurring' );
			
			$util->log( "Loading from DB (if record exists) for {$user_id}" );
			$user_data->maybe_load_from_db();
			
			/**
			 * @since 2.1 - Allow processing for multiple active payment gateways
			 */
			$user_data = apply_filters( 'e20r_pw_addon_get_user_subscriptions', $user_data, $this->type );
			
			if ( !empty( $user_data )  && true === $user_data->save_to_db() ) {
				
				$util->log( "Done processing subscription data for {$user_id}. Removing the user from the queue" );
				
				return false;
			}
			
			$util->log( "User subscription record not saved/processed. May be a-ok..." );
		} else {
			$util->log( "Incorrect format for user data record (boolean received!)" );
		}
		
		return false;
	}
	
	/**
	 * Log & complete the Handle_Subscriptions background operation
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 1.9.4 - ENHANCEMENT: Remove subscription data fetch lock w/error checking & messages to dashboard
	 * @since 3.7 - ENHANCEMENT: Only remove records if we're configured to do so
	 */
	protected function complete() {
		
		parent::complete();
		
		$this->clear_queue();
		
		$util = Utilities::get_instance();
		
		// @since 1.9.4 - ENHANCEMENT: Remove subscription data fetch lock w/error checking & messages to dashboard
		if ( false === delete_option( "e20rpw_subscr_fetch_mutex_{$this->type}" ) ) {
			$util->add_message( sprintf( __( 'Unable to clear lock after loading Subscription data for %s', Payment_Warning::plugin_slug ), $this->type ), 'error', 'backend' );
		}
		
		$util->log( "Remove old and stale recurring billing user data for Payment Warnings plugin?" );
		if ( true === apply_filters( 'e20r-payment-warning-clear-old-records', false ) ) {
			
			$util->log( "Yes, we're wanting to remove the records");
			$this->clear_old_subscr_records();
		}
		
		$util->log( "Completed remote subscription data fetch for all active gateways" );
		// $util->add_message( __("Fetched payment data for all active gateway add-ons", Payment_Warning::plugin_slug ), 'info', 'backend' );
	}
	
	/**
	 * Remove stale subscription/user data from the database table
	 */
	private function clear_old_subscr_records() {
		
		global $wpdb;
		$utils = Utilities::get_instance();
		
		$sql = $wpdb->prepare(
			"SELECT DISTINCT UI.user_id
					FROM {$wpdb->prefix}e20rpw_user_info AS UI
					WHERE UI.reminder_type = 'recurring' AND
					(
						( UI.user_payment_status = 'stopped' AND UI.modified < %s ) OR
						( UI.user_payment_status = 'active' AND
							(
					        	( UI.end_of_membership_date IS NULL AND UI.next_payment_date < %s )
					          	OR UI.end_of_membership_date < %s
				            )
			            )
					)",
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) ),
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) ),
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) )
		);
		
		$delete_sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}e20rpw_user_info AS UI
					WHERE UI.reminder_type = 'recurring' AND
					(
						( UI.user_payment_status = 'stopped' AND UI.modified < %s ) OR
						( UI.user_payment_status = 'active' AND
							(
					        	( UI.end_of_membership_date IS NULL AND UI.next_payment_date < %s )
					          	OR UI.end_of_membership_date < %s
				            )
			            )
					)",
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) ),
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) ),
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) )
		);
		
		$user_ids_to_clear = $wpdb->get_col( $sql );
		
		if ( ! empty( $user_ids_to_clear ) ) {
			
			$utils->log("Found " . count( $user_ids_to_clear ) . ' records to clear from DB');
			
			$id_list = implode( "','", array_map( 'absint', $user_ids_to_clear ) );
			$cc_sql = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}e20rpw_user_cc WHERE user_id IN( %s )", $id_list );
			
			$utils->log("Credit Card SQL to use: " . $cc_sql );
			
			if ( false === $wpdb->query( $cc_sql ) ) {
				$utils->log("Error clearing Credit Card info from local cache!");
			}
			
			if ( false === $wpdb->query( $delete_sql ) ) {
				$utils->log("Error clearing recurring payment records from local cache");
			}
		} else {
			$utils->log("No recurring payment user data to purge");
		}
	}
}
