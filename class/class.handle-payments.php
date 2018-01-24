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
	 */
	protected function complete() {
		parent::complete();
		
		$this->clear_queue();
		
		$util = Utilities::get_instance();
		if ( false === delete_option( "e20rpw_paym_fetch_mutex_{$this->type}" ) ) {
			$util->add_message( sprintf( __( 'Unable to clear lock after loading Payment data for %s', Payment_Warning::plugin_slug ), $this->type ), 'error', 'backend' );
		}
		
		$util->log( "Completed remote payment/charge data fetch for all active gateways" );
	}
}
