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
		 * @var null|Handle_Payments
		 */
		protected $process_payments = null;
		
		/**
		 * @var null|Handle_Subscriptions
		 */
		protected $process_subscriptions = null;
		
		/**
		 * Handler for remote data fetch operation (background operation)
		 */
		public function get_remote_data() {
			
			$utils = Utilities::get_instance();
			
			$utils->log( "Trigger load of the active add-on gateway(s)" );
			do_action( 'e20r_pw_addon_load_gateway' );
			
			$utils->log( "Grab all active PMPro Members" );
			$this->get_active_members();
			
			$utils->log( "Process payment info for " . count( $this->active_members ) . " active members" );
			
			foreach ( $this->active_members as $user_data ) {
				
				$utils->log( "Adding subscription handling to queue for User ID: " . $user_data->get_user_ID() );
				$this->process_subscriptions->push_to_queue( $user_data );
				
				$utils->log("Adding payment handling to queue for User ID: " . $user_data->get_user_ID() );
				$this->process_payments->push_to_queue( $user_data );
			}
			
			$utils->log( "Dispatch the background job for the subscription data" );
			$this->process_subscriptions->save()->dispatch();
			
			$utils->log("Dispatch the background job for the payment data" );
			$this->process_payments->save()->dispatch();
			
		}
		
		/**
		 * Fetch all active members and their last order info from local DB (or cache)
		 *
		 * @return User_Data[]
		 */
		public function get_active_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			
			$utils->log( "Attempting to load active members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'active_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				$active_sql = "
						SELECT DISTINCT *
						FROM {$wpdb->pmpro_memberships_users} AS mu
						WHERE mu.status = 'active'";
				
				$member_list = $wpdb->get_results( $active_sql );
				
				$utils->log( "Found " . count( $member_list ) . " active member records" );
				
				foreach ( $member_list as $member ) {
					
					$user       = new \WP_User( $member->user_id );
					$last_order = new \MemberOrder();
					
					$last_order->getLastMemberOrder( $member->user_id, 'success', $member->membership_id );
					
					if ( ! empty( $last_order->code ) ) {
						$record = new User_Data( $user, $last_order );
						$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}" );
					} else {
						$record = new User_Data( $user, null );
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					$utils->log( "Setting member status: {$member->status}" );
					
					$record->set_membership_status( ( $member->status == 'active' ? true : false ) );
					
					$cust_id = apply_filters( 'e20r_pw_addon_get_user_customer_id', null, $last_order->gateway, $record );
					
					$utils->log( "Got User ID for {$last_order->gateway}: {$cust_id}/" . $record->get_user_ID() );
					if ( ! empty( $cust_id ) ) {
						
						$record->set_gateway_customer_id( $cust_id );
						
						$this->active_members[] = $record;
						$record                 = null;
						$last_order             = null;
					} else {
						
						$utils->log( "Couldn't locate the upstream customer ID for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						// $utils->add_message( sprintf( __( "No Gateway Customer ID found for user with WordPress ID %d", Payment_Warning::plugin_slug ), $member->user_id ), 'error', 'backend' );
						continue;
					}
				}
				
				// Save to cache
				if ( ! empty( $this->active_members ) ) {
					$utils->log( "Saving active member list to cache" );
					Cache::set( 'active_users', $this->active_members, HOUR_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			return $this->active_members;
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
				$level_id    = $user_record->get_membership_level_ID();
				
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
			
			if ( null === ( $records = Cache::get( "current_{$type}", Payment_Warning::cache_group ) ) ) {
				
				$util->log( "Loading {$type} records from DB" );
				
				$records = array();
				$class   = self::get_instance();
				
				$active = $class->get_active_members();
				
				if ( ! empty( $active ) ) {
					
					foreach ( $active as $key => $user_data ) {
						
						$user_data->set_reminder_type( $type );
						$user_data->maybe_load_from_db( $user_data->get_user_ID(), $user_data->get_last_pmpro_order()->id, $user_data->get_membership_level_ID() );
						
						$next_payment = $user_data->get_next_payment();
						
						// Only include this user record if the user/member has a pre-existing payment date in the
						if ( ! empty( $next_payment ) || false !== $user_data->get_end_of_subscr_status() ) {
							
							$util->log( "Including record for " . $user_data->get_user_ID() );
							$records[] = $user_data;
						}
					}
				}
				
				if ( ! empty( $records ) ) {
					Cache::set( "current_{$type}", $records, 5 * MINUTE_IN_SECONDS, Payment_Warning::cache_group );
				}
			}
			
			return $records;
		}
		
		/**
		 * Clear/reset active member cache
		 */
		public function clear_member_cache() {
			
			$util = Utilities::get_instance();
			$util->log( "Clearing user cache for Payment Warnings add-on" );
			
			Cache::delete( 'active_users', Payment_Warning::cache_group );
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
			
			$this->process_payments      = new Handle_Payments( $this );
			$this->process_subscriptions = new Handle_Subscriptions( $this );
		}
	}
}