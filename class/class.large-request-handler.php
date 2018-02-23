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

use E20R\Payment_Warning\Tools\Global_Settings;
use E20R\Utilities\E20R_Background_Process;
use E20R\Utilities\Utilities;

/**
 * Class Large_Request_Handler
 *
 * @package E20R\Payment_Warning
 * @since 2.1 - ENHANCEMENT: Added documentation for functions/variables
 */
class Large_Request_Handler extends E20R_Background_Process {
	
	/**
	 * The action (string) we're processing (used to set the WP_Cron action name)
	 *
	 * @var null|string
	 */
	protected $action = null;
	
	/**
	 * The Background process handler this large request handler will fire off
	 *
	 * @var null|Handle_Subscriptions|Handle_Payments|Handle_Messages
	 */
	private $sub_handler = null;
	
	/**
	 * Large_Request_Handler constructor.
	 *
	 * @param string $handle
	 */
	public function __construct( $handle ) {
		
		$this->action = "lhr_{$handle}";
		
		parent::__construct();
	}
	
	/**
	 * Set the type of handler to use for this (large) task
	 *
	 * @param Handle_Subscriptions|Handle_Payments|Handle_Messages $handler
	 */
	public function set_task_handler( $handler ) {
		$this->sub_handler = $handler;
	}
	
	/**
	 * Handler for the remote data processing task
	 *
	 * @param array $data
	 *
	 * @return bool
	 */
	protected function task( $data ) {
		
		$util = Utilities::get_instance();
		$main = Payment_Warning::get_instance();
		
		$util->log( "Handler class: " . get_class( $data['task_handler'] ) );
		$util->log( "Trigger processing for " . count( $data['dataset'] ) . " records in the background..." );
		
		if ( !isset( $data['task_handler'] ) ) {
			$util->log("Error: No task handler found for this data!!! " );
		}
		
		/**
		 * @var User_Data $user_data
		 */
		foreach ( $data['dataset'] as $user_data ) {
			
			$util->log( "Check if we need to process for {$data['type']}" );
			$is_active = Global_Settings::load_options( $data['type'] );
			
			$util->log( "Is {$data['type']} enabled? " . ( $is_active ? 'Yes' : 'No' ) );
			if ( true == $is_active ) {
				
				$util->log( "Adding remote data processing for " . $user_data->get_user_ID() );
				$data['task_handler']->push_to_queue( $user_data );
			}
		}
		
		$util->log( "Dispatch the data processing background job" );
		$data['task_handler']->save()->dispatch();
		
		return false;
	}
	
	/**
	 * Background task complete() function handler
	 */
	protected function complete() {
		
		parent::complete();
		
		$util = Utilities::get_instance();
		$util->log( "Completed handling large request instance processing" );
		
	}
}
