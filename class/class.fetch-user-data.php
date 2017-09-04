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


use E20R\Payment_Warning\Utilities\Cache;
use E20R\Payment_Warning\Utilities\Utilities;

if ( ! class_exists( 'E20R\Payment_Warning\Fetch_User_Data' ) ) {
	
	class Fetch_User_Data {
		
		/**
		 * @var null|Fetch_User_Data
		 */
		private static $instance = null;
		
		/**
		 * @var array|User_Data[]
		 */
		private $active_members;
		
		/**
		 * Number of records to handle per Large_Request_Handler request (memory is limiting factor)
		 *
		 * @var null|int
		 */
		private $per_request_count = null;
		
		/**
		 * Handler for remote data fetch operation for subscriptions (background operation)
		 */
		public function get_remote_subscription_data() {
			
			$util = Utilities::get_instance();
			$main = Payment_Warning::get_instance();
			
			if ( false == $main->load_options( 'enable_payment_warnings' ) ) {
				
				$util->log( "User has not configured payment download to execute!" );
				
				return;
			}
			
			$util->log( "Trigger load of the active add-on gateway(s)" );
			do_action( 'e20r_pw_addon_load_gateway' );
			
			$util->log( "Grab all active PMPro Members" );
			$this->active_members = null;
			$this->set_active_subscription_members();
			
			$data_count = count( $this->active_members );
			
			$util->log( "Process subscription data for {$data_count} active members" );
			
			$handler = $main->get_handler( 'lhr_subscriptions' );
			
			if ( $data_count > $this->per_request_count ) {
				
				$util->log( "Using large request handler for subscription requests" );
				
				$no_chunks = ceil( $data_count / $this->per_request_count );
				$util->log( "Splitting data into {$no_chunks} chunks" );
				
				for ( $i = 1; $i <= $no_chunks; $i ++ ) {
					
					$offset = ( $i === 1 ? 0 : ( ( $i - 1 ) * $this->per_request_count ) );
					$util->log( "Processing {$i} of {$no_chunks}: Offset: {$offset}" );
					
					$to_process = array_slice( $this->active_members, $offset, $this->per_request_count );
					
					$util->log( "Asking to process subscription data for " . count( $to_process ) . " users" );
					$util->log( "Adding subscription retrieval to own queue" );
					
					$data = array(
						'dataset'      => $to_process,
						'task_handler' => $main->get_handler( 'subscriptions' ),
						'type'         => 'enable_gateway_fetch',
					);
					
					$handler->push_to_queue( $data );
					$util->log( "Added data set # {$i} to subscription request dispatcher: " . $data['task_handler']->get_action()  );
				}
				
				$util->log( "Save and dispatch the request handler for a large number of subscriptions" );
				$handler->save()->dispatch();
				
			} else {
				
				$run_gateway_fetch = $main->load_options( 'enable_gateway_fetch' );
				
				$run_gateway_fetch = ( !empty( $run_gateway_fetch ) ? true : false );
				$util->log( "Is enable_gateway_fetch enabled for subscription data download? " . ( $run_gateway_fetch ? 'Yes' : 'No' ) );

				if ( true === $run_gateway_fetch ) {
					
					$util->log( "No need to split the data set to queue for processing!" );
					$sub_handler = $main->get_handler( 'subscriptions' );
					
					foreach ( $this->active_members as $user_data ) {
						
						$util->log( "Adding subscription handling to queue for User ID: " . $user_data->get_user_ID() );
						$sub_handler->push_to_queue( $user_data );
					}
					$util->log( "Saved the data to process to the subscription handler & dispatching it" );
					$sub_handler->save()->dispatch();
				}
			}
		}
		
		/**
		 * Handler for remote data fetch operation for payments (non-recurring payments in background operation)
		 */
		public function get_remote_payment_data() {
			
			$util = Utilities::get_instance();
			$main = Payment_Warning::get_instance();
			
			if ( false == $main->load_options( 'enable_expiration_warnings' ) ) {
				
				$util->log( "User has not configured payment download to execute!" );
				
				return;
			}
			
			$util->log( "Trigger load of the active add-on gateway(s)" );
			do_action( 'e20r_pw_addon_load_gateway' );
			
			$util->log( "Grab all active PMPro Members without subscription plans" );
			$this->active_members = null;
			$this->set_active_non_subscription_members();
			
			$data_count = count( $this->active_members );
			$util->log( "Process payment data for {$data_count} active members" );
			
			$handler = $main->get_handler( 'lhr_payments' );
			// $handler->set_task_handler( $main->get_handler( 'payments' ) );
			
			if ( $data_count > $this->per_request_count ) {
				
				$util->log( "Using large request handler for payment request" );
				
				$no_chunks = ceil( $data_count / $this->per_request_count );
				$util->log( "Splitting data into {$no_chunks} chunks" );
				
				for ( $i = 1; $i <= $no_chunks; $i ++ ) {
					
					$offset = ( $i === 1 ? 0 : ( ( $i - 1 ) * $this->per_request_count ) );
					$util->log( "Processing {$i} of {$no_chunks}: Offset: {$offset}" );
					
					$to_process = array_slice( $this->active_members, $offset, $this->per_request_count );
					
					$util->log( "Asking to process payment retrieval (if needed)" );
					
					$data = array(
						'dataset'      => $to_process,
						'task_handler' => $main->get_handler( 'payments' ),
						'type'         => 'enable_gateway_fetch',
					);
					
					$handler->push_to_queue( $data );
					
					$util->log( "Added data set # {$i} to payment request dispatcher: " . $data['task_handler']->get_action() );
				}
				
				$util->log( "Saving and dispatching large number of payment workstreams in separate requests" );
				$handler->save()->dispatch();
				
			} else {
				
				$run_gateway_fetch = $main->load_options( 'enable_gateway_fetch' );
				
				$run_gateway_fetch = ( !empty( $run_gateway_fetch ) ? true : false );
				$util->log( "Is enable_gateway_fetch enabled for payment info download? " . ( $run_gateway_fetch ? 'Yes' : 'No' ) );
				
				if ( true === $run_gateway_fetch ) {
					
					$p_handler = $main->get_handler( 'payments' );
					
					foreach ( $this->active_members as $user_data ) {
						
						$util->log( "Adding payment/charge handling to queue for User ID: " . $user_data->get_user_ID() );
						$p_handler->push_to_queue( $user_data );
					}
					
					$util->log( "Dispatch the background job for the payment data" );
					$p_handler->save()->dispatch();
				}
			}
		}
		
		/**
		 * Fetch all active members with non-recurring membership plans, plus their last order from the local DB (or cache)
		 *
		 * @return User_Data[]|bool
		 */
		public function set_active_non_subscription_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			
			$utils->log( "Attempting to load active non-recurring payment members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'active_norecurr_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				// Locate active member records without recurring payment configured (cycle_number is 0 or NULL).
				$active_sql = "
						SELECT DISTINCT *
						FROM {$wpdb->pmpro_memberships_users} AS mu
						WHERE mu.status = 'active'
						AND ( mu.cycle_number = 0 OR mu.cycle_number IS NULL )
						ORDER BY mu.user_id ASC";
				
				$member_list = $wpdb->get_results( $active_sql );
				$environment = pmpro_getOption( 'gateway_environment' );
				
				$utils->log( "Found " . count( $member_list ) . "active member records" );
				
				foreach ( $member_list as $member ) {
					
					$user       = new \WP_User( $member->user_id );
					$user       = self::set_membership_info( $user );
					$last_order = new \MemberOrder();
					
					$last_order->getLastMemberOrder( $member->user_id, 'success', $member->membership_id );
					
					if ( empty( $last_order->gateway_environment ) || ( isset( $last_order->gateway_environment ) && $environment !== $last_order->gateway_environment ) ) {
						$utils->log( "Last order found used a different environment from the currently configured payment gateway environment ({$environment})" );
						continue;
					}
					
					if ( ! empty( $last_order->code ) ) {
						
						$record = new User_Data( $user, $last_order );
						$record->set_recurring_membership_status( false );
						$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}. Is recurring? " . ( false === $record->get_recurring_membership_status() ? 'No' : 'Yes' ) );
					} else {
						$record = new User_Data( $user, null );
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					$utils->log( "Setting member status: {$member->status}" );
					
					if ( 'active' === $member->status ) {
						$record->set_membership_status( true );
					} else {
						$record->set_membership_status( false );
					}
					
					$cust_id = apply_filters( 'e20r_pw_addon_get_user_customer_id', null, $last_order->gateway, $record );
					
					$utils->log( "User ID for {$last_order->gateway} ({$last_order->gateway_environment}): {$cust_id}/" . $record->get_user_ID() );
					
					if ( ! empty( $cust_id ) && false === $record->get_recurring_membership_status() ) {
						
						$record->set_gateway_customer_id( $cust_id );
						
						$this->active_members[] = $record;
					} else if ( empty( $cust_id ) ) {
						
						$utils->log( "Couldn't locate the upstream customer ID for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						continue;
						
					} else {
						
						$utils->log( "Is using a recurring payment for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						continue;
					}
				}
				
				// Save to cache
				if ( ! empty( $this->active_members ) ) {
					$utils->log( "Saving active member list to cache" );
					Cache::set( 'active_norecurr_users', $this->active_members, HOUR_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
			
			return $this->active_members;
		}
		
		/**
		 * Fetch all active members with recurring payment subscriptions and their last order info from local DB (or cache)
		 *
		 */
		public function set_active_subscription_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			$environment          = pmpro_getOption( 'gateway_environment' );
			
			$utils->log( "Attempting to load active members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'active_subscr_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				// Only load records where the user's cycle_number is greater or equal to 1 (has a recurring payment membership)
				$active_sql = "
						SELECT DISTINCT *
						FROM {$wpdb->pmpro_memberships_users} AS mu
						WHERE mu.status = 'active'
						AND mu.cycle_number >= 1
						ORDER BY mu.user_id ASC";
				
				$member_list = $wpdb->get_results( $active_sql );
				
				$utils->log( "Found " . count( $member_list ) . " active member records with subscriptions (we think)" );
				
				foreach ( $member_list as $member ) {
					
					$user       = new \WP_User( $member->user_id );
					$user       = self::set_membership_info( $user );
					$last_order = new \MemberOrder();
					
					$last_order->getLastMemberOrder( $member->user_id, 'success', $member->membership_id );
					
					if ( empty( $last_order->gateway_environment ) || ( isset( $last_order->gateway_environment ) && $environment !== $last_order->gateway_environment ) ) {
						$utils->log( "Last order found used a different environment from the currently configured payment gateway environment ({$environment})" );
						continue;
					}
					
					if ( ! empty( $last_order->code ) ) {
						$record = new User_Data( $user, $last_order );
						$record->set_recurring_membership_status();
						$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}. Is recurring? " . ( true == $record->get_recurring_membership_status() ? 'Yes' : 'No' ) );
						
					} else {
						$record = new User_Data( $user, null );
						$record->set_recurring_membership_status();
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					$utils->log( "Setting member status: {$member->status}" );
					
					if ( $member->status == 'active' ) {
						$record->set_membership_status( true );
					} else {
						$record->set_membership_status( false );
					}
					
					$cust_id = apply_filters( 'e20r_pw_addon_get_user_customer_id', null, $last_order->gateway, $record );
					
					$utils->log( "Got User ID for {$last_order->gateway}: {$cust_id}/" . $record->get_user_ID() );
					
					if ( ! empty( $cust_id ) && true === $record->get_recurring_membership_status() ) {
						
						$record->set_gateway_customer_id( $cust_id );
						
						$this->active_members[] = $record;
						$record                 = null;
						$last_order             = null;
					} else {
						
						$utils->log( "Isn't on a recurring membership or couldn't locate the upstream customer ID for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						// $utils->add_message( sprintf( __( "No Gateway Customer ID found for user with WordPress ID %d", Payment_Warning::plugin_slug ), $member->user_id ), 'error', 'backend' );
						continue;
					}
				}
				
				// Save to cache
				if ( ! empty( $this->active_members ) ) {
					$utils->log( "Saving active member subscribers list to cache" );
					Cache::set( 'active_subscr_users', $this->active_members, HOUR_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
		}
		
		public function set_all_active_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			$environment          = pmpro_getOption( 'gateway_environment' );
			
			$utils->log( "Attempting to load all active members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'all_active_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				// Only load records where the user's cycle_number is greater or equal to 1 (has a recurring payment membership)
				$active_sql = "
						SELECT DISTINCT *
						FROM {$wpdb->pmpro_memberships_users} AS mu
						WHERE mu.status = 'active'
						ORDER BY mu.user_id ASC";
				
				$member_list = $wpdb->get_results( $active_sql );
				
				$utils->log( "Found " . count( $member_list ) . " active member records" );
				
				foreach ( $member_list as $member ) {
					
					$user       = new \WP_User( $member->user_id );
					$user       = self::set_membership_info( $user );
					$last_order = new \MemberOrder();
					
					$last_order->getLastMemberOrder( $member->user_id, 'success', $member->membership_id );
					
					if ( empty( $last_order->gateway_environment ) || ( isset( $last_order->gateway_environment ) && $environment !== $last_order->gateway_environment ) ) {
						$utils->log( "Last order found used a different environment from the currently configured payment gateway environment ({$environment})" );
						continue;
					}
					
					if ( ! empty( $last_order->code ) ) {
						$record = new User_Data( $user, $last_order );
						$record->set_recurring_membership_status();
						$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}. Is recurring? " . ( true == $record->get_recurring_membership_status() ? 'Yes' : 'No' ) );
						
					} else {
						$record = new User_Data( $user, null );
						$record->set_recurring_membership_status();
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					$utils->log( "Setting member status: {$member->status}" );
					
					if ( $member->status == 'active' ) {
						$record->set_membership_status( true );
					} else {
						$record->set_membership_status( false );
					}
					
					$cust_id = apply_filters( 'e20r_pw_addon_get_user_customer_id', null, $last_order->gateway, $record );
					
					$utils->log( "Got User ID for {$last_order->gateway}: {$cust_id}/" . $record->get_user_ID() );
					
					if ( ! empty( $cust_id ) && true === $record->get_recurring_membership_status() ) {
						
						$record->set_gateway_customer_id( $cust_id );
						
						$this->active_members[] = $record;
						$record                 = null;
						$last_order             = null;
					}
				}
				
				// Save to cache
				if ( ! empty( $this->active_members ) ) {
					$utils->log( "Saving active member subscribers list to cache" );
					Cache::set( 'all_active_users', $this->active_members, HOUR_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
		}
		
		/**
		 * Load PMPro Membership info for the specific user object
		 *
		 * @param \WP_User $user
		 *
		 * @return \WP_User
		 */
		public static function set_membership_info( \WP_User $user ) {
			
			$util = Utilities::get_instance();
			
			if ( ! is_null( $user ) ) {
				
				$util->log( "Loading membership level info for {$user->ID}" );
				$user->current_membership_level = pmpro_getMembershipLevelForUser( $user->ID );
				
				if ( ! empty( $user->current_membership_level->id ) ) {
					$user->current_membership_level->categories = pmpro_getMembershipCategories( $user->current_membership_level->ID );
				}
				
				$user->membership_levels = pmpro_getMembershipLevelsForUser( $user->ID );
			}
			
			return $user;
		}
		
		/**
		 * Load User Data from the local DB based on user ID, level ID and the type of payment warning
		 *
		 * @param int    $user_id
		 * @param int    $level_id
		 * @param string $type Payment Warning type ( expiration | recurring )
		 *
		 * @return null|User_Data
		 */
		public function get_user_info_from_db( $user_id, $level_id, $type ) {
			
			$user_record = null;
			
			if ( null === ( $user_record = Cache::get( "user_info_{$user_id}_{$level_id}_{$type}", Payment_Warning::cache_group ) ) ) {
				
				$user_record = new User_Data( $user_id, null, $type );
				$user_record->maybe_load_from_db();
				$level_id = $user_record->get_membership_level_ID();
				
				if ( ! empty( $level_id ) ) {
					Cache::set( "user_info_{$user_id}_{$level_id}_{$type}", $user_record, 5 * MINUTE_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			return $user_record;
		}
		
		/**
		 * Return all user records for a specific Message Template type
		 *
		 * @param string $type
		 *
		 * @return User_Data[]
		 */
		public function get_all_user_records( $type = 'recurring' ) {
			
			$util = Utilities::get_instance();
			
			if ( null === ( $this->active_members = Cache::get( "current_{$type}", Payment_Warning::cache_group ) ) ) {
				
				$util->log( "Loading {$type} records from DB" );
				
				$class = self::get_instance();
				
				switch( $type ) {
					case 'recurring':
						$class->set_active_subscription_members();
						break;
					case 'expiration':
						$class->set_active_non_subscription_members();
						break;
					case 'ccexpiration':
						$class->set_all_active_members();
						break;
				}
				
				$records              = $this->active_members;
				$this->active_members = array();
				
				if ( ! empty( $records ) ) {
					
					foreach ( $records as $key => $user_data ) {
						
						$user_data->set_reminder_type( $type );
						$order = $user_data->get_last_pmpro_order();
						
						if ( ! empty( $order ) && ! empty( $order->id ) ) {
							
							$util->log( "Found order ID: {$order->id} for " . $user_data->get_user_ID() );
							$order_id = $order->id;
							
							$user_data->maybe_load_from_db( $user_data->get_user_ID(), $order_id, $user_data->get_membership_level_ID() );
							
							$next_payment = $user_data->get_next_payment();
							
							// Only include this user record if the user/member has a pre-existing payment date in the
							if ( ! empty( $next_payment ) || false !== $user_data->get_end_of_subscr_status() ) {
								
								$util->log( "Including record for " . $user_data->get_user_ID() );
								$this->active_members[] = $user_data;
							}
							
						}
						
					}
				}
				
				if ( ! empty( $this->active_members ) ) {
					Cache::set( "current_{$type}", $this->active_members, 5 * MINUTE_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			return $this->active_members;
		}
		
		/**
		 * Clear/reset active member cache
		 */
		public function clear_member_cache() {
			
			$util = Utilities::get_instance();
			$util->log( "Clearing user cache for Payment Warnings add-on" );
			
			Cache::delete( 'active_subscr_users', Payment_Warning::cache_group );
			Cache::delete( 'all_active_users', Payment_Warning::cache_group );
			Cache::delete( 'active_norecurr_users', Payment_Warning::cache_group );
			Cache::delete( "current_reminder", Payment_Warning::cache_group );
			Cache::delete( "current_expiring", Payment_Warning::cache_group );
			
			$util->add_message( "Cleared cached user data for Payment Warnings add-on", 'info', 'backend' );
			
		}
		
		/**
		 * Return or instantiate the class instance
		 *
		 * @return Fetch_User_Data|null
		 */
		public static function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				
				self::$instance = new self;
				
				self::$instance->load_hooks();
			}
			
			return self::$instance;
		}
		
		/**
		 * Load Hooks and Filters
		 */
		public function load_hooks() {
			
			$util = Utilities::get_instance();
			$util->log( "Loading the action hooks" );
			
			if ( function_exists( 'pmpro_getAllLevels' ) ) {
				
				$util->log( "Loading the action hooks for PMPro" );
				
				// Clear the active member cache after checkout and membership expiration
				add_action( 'pmpro_after_checkout', array( $this, 'clear_member_cache' ), 99 );
				add_action( 'pmpro_membership_post_membership_expiry', array( $this, 'clear_member_cache', 99 ) );
			}
			
			$this->per_request_count = apply_filters( 'e20r_pw_max_records_per_request', 250 );
		}
	}
}