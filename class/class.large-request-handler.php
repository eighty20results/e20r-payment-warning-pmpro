<?php
/**
 * Copyright (c) $today.year. - Eighty / 20 Results by Wicked Strong Chicks.
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


use E20R\Payment_Warning\Utilities\Utilities;
use E20R\Payment_Warning\Utilities\E20R_Background_Process;

class Large_Request_Handler extends E20R_Background_Process {
	
	private $type = null;
	
	private $handler = null;
	
	private $request_list = null;
	
	private static $instance = null;
	
	/**
	 * Large_Request_Handler constructor.
	 *
	 * @param Handle_Subscriptions|Handle_Payments $handler
	 * @param string $type
	 */
	public function __construct( $handler, $type ) {
		
		$this->type = $type;
		$this->handler = $handler;
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Large_Request_Handler class");
		
		self::$instance = $this;
		
		$av = get_class( $handler );
		$name = explode( '\\', $av );
		$this->action = "lrh_" . strtolower( $name[(count( $name ) - 1 )] );
		
		$util->log("Set Action variable to {$this->action} for this Large_Request_Handler");
		
		parent::__construct();
	}
	
	/**
	 * Handler for the remote data processing task
	 *
	 * @param array $list
	 *
	 * @return bool
	 */
	protected function task( $list ) {
		
		$util = Utilities::get_instance();
		$main = Payment_Warning::get_instance();
		
		$util->log("Trigger processing for " . count( $list ) . " in the background..." );
		
		foreach ( $list as $user_data ) {
			
			if ( 'enable_payment_warnings' === $this->type && true === $main->load_options( $this->type ) ) {
				
				$util->log( "Adding remote subscription data processing for " . $user_data->get_user_ID() );
				$this->handler->push_to_queue( $user_data );
				
				$util->log( "Dispatch the subscription fetch background job" );
				$this->handler->save()->dispatch();
			}
			
			if ( 'enable_expiration_warnings' === $this->type && true == $main->load_options( $this->type ) ) {
				
				$util->log( "Adding payment handling to queue for User ID: " . $user_data->get_user_ID() );
				$this->handler->push_to_queue( $user_data );
				
				$util->log( "Dispatch the background job for the payment data" );
				$this->handler->save()->dispatch();
			}
		}
		
		return false;
	}
	
	protected function complete() {
		
		parent::complete();
		
		$util = Utilities::get_instance();
		$util->log("Completed handling large request instance processing");
		
	}
}