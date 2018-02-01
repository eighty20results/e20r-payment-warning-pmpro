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

class Handle_Payments extends E20R_Background_Process {
	
	/**
	 * Instance of this class
	 *
	 * @var Handle_Payments|null
	 */
	private static $instance = null;
	
	/**
	 * The action name (handler)
	 *
	 * @var null|string
	 */
	protected $action = null;
	
	/**
	 * @var null|User_Data
	 */
	protected $user_info = null;
	
	/**
	 * The type (add-on module) of handler
	 *
	 * @var null|string
	 */
	private $type = null;
	
	/**
	 * Handle_Payments constructor.
	 *
	 * @param string $type
	 */
	public function __construct( $type ) {
		
		$util = Utilities::get_instance();
		$util->log( "Instantiated Handle_Payments for {$type} class" );
		
		self::$instance = $this;
		$this->type     = $type;
		$this->action   = "hp_{$this->type}_paym";
		$util->log( "Set Action variable to {$this->action} for Handle_Payments" );
		
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
		$pw   = Payment_Warning::get_instance();
		
		$util->log( "Trigger per-addon payment/charge download for user" );
		
		if ( ! is_bool( $user_data ) ) {
			
			$user_id = $user_data->get_user_ID();
			$user_data->set_reminder_type( 'expiration' );
			
			$util->log( "Loading from DB (if record exists) for {$user_id}" );
			$user_data->maybe_load_from_db( $user_id );
			
			/**
			 * @since 2.1 - Allow processing for multiple payment gateways
			 */
			$user_data = apply_filters( 'e20r_pw_addon_get_user_payments', $user_data, $this->type );
			
			// @since 1.9.4 - ENHANCEMENT: No longer need to specify type of record being saved
			if ( false !== $user_data && true === $user_data->save_to_db() ) {
				
				$util->log( "Fetched payment data from gateway for " . $user_data->get_user_email() );
				$util->log( "Done processing payment data for {$user_id}. Removing the user from the queue" );
				
				return false;
			}
			
			$util->log( "User payment record (for gateway: {$this->type}) not saved/processed. May be a-ok..." );
			
		} else {
			$util->log( "Incorrect format for user data record (boolean received!)" );
		}
		
		return false;
		
	}
	
	/**
	 * Log & complete the Handle_Payments background operation
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @since 1.9.4 - ENHANCEMENT: Remove Non-recurring payment data fetch lock w/error checking & messages to dashboard
	 * @since 3.7 - ENHANCEMENT: Only remove records if we're configured to do so
	 */
	protected function complete() {
		parent::complete();
		
		$this->clear_queue();
		
		$util = Utilities::get_instance();
		if ( false === delete_option( "e20rpw_paym_fetch_mutex_{$this->type}" ) ) {
			$util->add_message( sprintf( __( 'Unable to clear lock after loading Payment data for %s', Payment_Warning::plugin_slug ), $this->type ), 'error', 'backend' );
		}
		
		$util->log( "Remove old and stale payment user data for Payment Warnings plugin?" );
		if ( true === apply_filters( 'e20r-payment-warning-clear-old-records', false ) ) {
			
			$util->log( "Yes, we're wanting to remove the records");
			$this->clear_old_payment_records();
		}
		
		$util->log( "Completed remote payment/charge data fetch for all active gateways" );
	}
	
	/**
	 * Remove stale subscription/user data from the database table
	 */
	private function clear_old_payment_records() {
		
		global $wpdb;
		$utils = Utilities::get_instance();
		
		$sql = $wpdb->prepare(
			"SELECT DISTINCT UI.user_id
					FROM {$wpdb->prefix}e20rpw_user_info AS UI
						WHERE UI.reminder_type = 'expiration' AND
							UI.end_of_membership_date < %s AND
							UI.modified < %s",
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) ),
			date( 'Y-m-d 00:00:00', current_time('timestamp' ) )
		);
		
		$delete_sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}e20rpw_user_info AS UI
					WHERE UI.reminder_type = 'expiration' AND
						UI.end_of_membership_date < %s AND
						UI.modified < %s",
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
