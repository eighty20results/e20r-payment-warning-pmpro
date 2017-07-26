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

class Large_Request_Handler extends E20R_Background_Process {
	
	protected $action = null;
	
	/**
	 * Large_Request_Handler constructor.
	 *
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$util = Utilities::get_instance();
		
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );
		$this->action = "lhr_" . strtolower( $name[(count( $name ) - 1 )] );
		
		$util->log( "Set Action variable to {$this->action} for this Large_Request_Handler" );
		
		parent::__construct();
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
		
		$util->log( "Handler class: " . get_class( $data['handler'] ) );
		$util->log( "Trigger processing for " . count( $data['dataset'] ) . " in the background..." );
		
		foreach ( $data['dataset'] as $user_data ) {
			
			$util->log( "Check if we need to process for {$data['type']}" );
			$is_active = $main->load_options( $data['type'] );
			
			$util->log( "Is {$data['type']} enabled? " . ( $is_active ? 'Yes' : 'No' ) );
			if ( true == $is_active ) {
				
				$util->log( "Adding remote data processing for " . $user_data->get_user_ID() );
				$data['handler']->push_to_queue( $user_data );
			}
		}
		
		$util->log( "Dispatch the data processing background job" );
		$data['handler']->save()->dispatch();
		
		return false;
	}
	
	protected function complete() {
		
		parent::complete();
		
		$util = Utilities::get_instance();
		$util->log( "Completed handling large request instance processing" );
		
	}
}