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


use E20R\Utilities\Cache;
use E20R\Utilities\Utilities;

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
		 *
		 * @param string $addon_name - Name of gateway/addon we'll fetch data for
		 *
		 * @return bool
		 *
		 * @since 1.9.1 - BUG FIX: Didn't use the default method - get_all_user_records() - when loading member/user data
		 * @since 1.9.4 - ENHANCEMENT: Added error checking in get_remote_subscription_data() for get_all_user_records() return values
		 * @since 1.9.4 - ENHANCEMENT: Preventing get_remote_subscription_data() from running more than once at a time
		 * @since 1.9.6 - ENHANCEMENT: Renamed get_remote_subscription_data() to configure_remote_subscription_data_fetch()
		 * @since 1.9.9 - ENHANCEMENT: Add monitoring for background data collection job
		 * @since 1.9.10 - ENHANCEMENT: Also add monitoring if the mutex is set
		 * @since 1.9.10 - BUG FIX: Delay first execution of monitoring action
		 * @since 1.9.11 - BUG FIX: Moved monitoring cron job scheduler to Cron_Handler class
		 * @since 2.1 - ENHANCEMENT: Process for multiple payment gateways (add-ons) at the same time
		 */
		public function configure_remote_subscription_data_fetch( $addon_name ) {
			
			$util  = Utilities::get_instance();
			$main  = Payment_Warning::get_instance();
			$mutex = intval( get_option( "e20rpw_subscr_fetch_mutex_{$addon_name}", 0 ) );
			
			if ( 1 === $mutex ) {
				
				$util->log( "Error: Remote subscription data fetch is already active. Refusing to run!" );
				
				return false;
			}
			
			if ( false == $main->load_options( 'enable_gateway_fetch' ) ) {
				
				$util->log( "User has not enabled subscription download!" );
				
				return false;
			}
			
			$util->log( "Trigger load of the active add-on gateway for {$addon_name}" );
			$loaded = apply_filters( 'e20r_pw_addon_load_gateway', $addon_name );
			
			/**
			 * @since 2.1 - ENHANCEMENT: Manage processing for multiple gateway(s) at once
			 */
			if ( $addon_name !== $loaded ) {
				$util->log("Not processing the same add-on as we're trying to load!");
				return false;
			}
			
			$util->log( "Grab all active PMPro Members" );
			$this->active_members = array();
			
			/**
			 * @since 1.9.4 - ENHANCEMENT: Added error checking in get_remote_subscription_data() for get_all_user_records() return values
			 */
			if ( false === $this->get_all_user_records( 'recurring' ) ) {
				$util->log( "No records found for the remote payment data search" );
				$util->add_message( __( "No local records for expiring memberships found!", Payment_Warning::plugin_slug ), 'warning', 'backend' );
				
				return false;
			}
			
			$data_count = count( $this->active_members );
			
			$util->log( "Process subscription data for {$data_count} active members" );
			
			$handler = $main->get_handler( 'lhr_subscriptions' );
			
			if ( $data_count > $this->per_request_count ) {
				
				$util->log( "Using large request handler for subscription requests for {$addon_name}" );
				
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
						'task_handler' => $main->get_handler( 'subscription', $addon_name ),
						'type'         => 'enable_gateway_fetch',
					);
					
					$handler->push_to_queue( $data );
					$util->log( "Added data set # {$i} to subscription request dispatcher: " . $data['task_handler']->get_action() );
				}
				
				$util->log( "Save and dispatch the request handler for a large number of subscriptions" );
				$handler->save()->dispatch();
				update_option( "e20rpw_subscr_fetch_mutex_{$addon_name}", 1, 'no' );
				
			} else {
				
				$run_gateway_fetch = $main->load_options( 'enable_gateway_fetch' );
				
				$run_gateway_fetch = ( ! empty( $run_gateway_fetch ) ? true : false );
				$util->log( "Is enable_gateway_fetch enabled for subscription data download? " . ( $run_gateway_fetch ? 'Yes' : 'No' ) );
				
				if ( true === $run_gateway_fetch ) {
					
					$util->log( "No need to split the data set to queue for processing for type: {$addon_name}!" );
					$sub_handler = $main->get_handler( 'subscription', $addon_name );
					
					if ( empty( $sub_handler ) ) {
						$util->log("No handler returned for the {$addon_name} gateway");
						return false;
					}
					
					foreach ( $this->active_members as $user_data ) {
						
						$util->log( "Adding subscription handling to queue for User ID: " . $user_data->get_user_ID() );
						$sub_handler->push_to_queue( $user_data );
					}
					$util->log( "Saved the data to process to the subscription handler & dispatching it" );
					$sub_handler->save()->dispatch();
					update_option( "e20rpw_subscr_fetch_mutex_{$addon_name}", 1, 'no' );
				}
			}
		}
		
		/**
		 * Handler for remote data fetch operation for payments (non-recurring payments in background operation)
		 *
		 * @param string $addon_name - Name of gateway/addon we'll fetch data for
		 *
		 * @since 1.9.1 - BUG FIX: Didn't use the default method - get_all_user_records() - when loading member/user data
		 * @since 1.9.4 - ENHANCEMENT: Added error checking in get_remote_payment_data() for get_all_user_records() return values
		 * @since 1.9.4 - ENHANCEMENT: Preventing get_remote_payment_data() from running more than once at a time
		 * @since 1.9.6 - ENHANCEMENT: Renamed get_remote_payment_data() to configure_remote_payment_data_fetch()
		 * @since 2.1 - ENHANCEMENT: Process for multiple payment gateways (add-ons) at the same time
		 */
		public function configure_remote_payment_data_fetch( $addon_name ) {
			
			$util = Utilities::get_instance();
			$main = Payment_Warning::get_instance();
			
			$mutex = intval( get_option( 'e20rpw_paym_fetch_mutex', 0 ) );
			
			if ( 1 === $mutex ) {
				
				$util->log( "Error: Remote payment data fetch is already active. Stopping!" );
				
				return;
			}
			
			if ( false == $main->load_options( 'enable_gateway_fetch' ) ) {
				
				$util->log( "User has not enabled payment download!" );
				
				return;
			}
			
			$util->log( "Trigger load of the active add-on gateway(s)" );
			$loaded = apply_filters( 'e20r_pw_addon_load_gateway', $addon_name );
			
			if ( $addon_name !== $loaded ) {
				$util->log("Not processing the same add-on as we're trying to load!");
				return;
			}
			
			$util->log( "Grab all active Members WITHOUT a subscription plans" );
			$this->active_members = array();
			
			/**
			 * @since 1.9.4 - ENHANCEMENT: Added error checking in get_remote_payment_data() for get_all_user_records() return values
			 */
			if ( false === $this->get_all_user_records( 'expiration' ) ) {
				$util->log( "No records found for the remote payment data search" );
				$util->add_message( __( "No local records for expiring memberships found!", Payment_Warning::plugin_slug ), 'warning', 'backend' );
				
				return;
			}
			
			$data_count = count( $this->active_members );
			$util->log( "Process payment data for {$data_count} active members" );
			
			$handler = $main->get_handler( 'lhr_payments' );
			
			if ( $data_count > $this->per_request_count ) {
				
				$util->log( "Using large request handler for payment request for {$addon_name}" );
				
				$no_chunks = ceil( $data_count / $this->per_request_count );
				$util->log( "Splitting data into {$no_chunks} chunks" );
				
				for ( $i = 1; $i <= $no_chunks; $i ++ ) {
					
					$offset = ( $i === 1 ? 0 : ( ( $i - 1 ) * $this->per_request_count ) );
					$util->log( "Processing {$i} of {$no_chunks}: Offset: {$offset}" );
					
					$to_process = array_slice( $this->active_members, $offset, $this->per_request_count );
					
					$util->log( "Asking to process payment retrieval (if needed)" );
					
					$data = array(
						'dataset'      => $to_process,
						'task_handler' => $main->get_handler( "payment", $addon_name ),
						'type'         => 'enable_gateway_fetch',
					);
					
					$handler->push_to_queue( $data );
					
					$util->log( "Added data set # {$i} to payment request dispatcher: " . $data['task_handler']->get_action() );
				}
				
				$util->log( "Saving and dispatching large number of payment workstreams in separate requests" );
				$handler->save()->dispatch();
				
				update_option( "e20rpw_paym_fetch_mutex_{$addon_name}", 1, 'no' );
				
			} else {
				
				$run_gateway_fetch = $main->load_options( 'enable_gateway_fetch' );
				
				$run_gateway_fetch = ( ! empty( $run_gateway_fetch ) ? true : false );
				$util->log( "Is enable_gateway_fetch enabled for payment info download? " . ( $run_gateway_fetch ? 'Yes' : 'No' ) );
				
				if ( true === $run_gateway_fetch ) {
					
					$p_handler = $main->get_handler( "payment", $addon_name );
					$p_handler->clear_queue();
					
					if ( empty( $this->active_members ) ) {
						return;
					}
					
					foreach ( $this->active_members as $user_data ) {
						
						$util->log( "Adding payment/charge handling to queue for User ID: " . $user_data->get_user_ID() );
						$p_handler->push_to_queue( $user_data );
					}
					
					$util->log( "Dispatch the background job for the payment data with {$addon_name}" );
					$p_handler->save()->dispatch();
					
					update_option( "e20rpw_paym_fetch_mutex_{$addon_name}", 1, 'no' );
				}
			}
		}
		
		/**
		 * Fetch all active members with non-recurring membership plans, plus their last order from the local DB (or cache)
		 *
		 * @return User_Data[]|bool
		 *
		 * @since v1.9.5 - BUG FIX: Only load active and non-recurring billing members
		 * @since v1.9.6 - ENHANCEMENT: Set cache timeout for active nonrecurring subscription data to 12 hours
		 * @since v1.9.14 - ENHANCEMENT: Simplified config of status (always active based on what PMPro believes)
		 * @since v1.9.14 - BUG FIX: Didn't load previously recurring membership records that are now expiring
		 */
		public function set_active_non_subscription_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			
			$utils->log( "Attempting to load active non-recurring payment members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'active_norecurr_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				/**
				 * Locate active membership records that will expire
				 *
				 * @since v1.9.5 - BUG FIX: Only load active and non-recurring billing members
				 * @since v1.9.14 - BUG FIX: Didn't load previously recurring membership records that are now expiring
				 */
				$active_sql = $wpdb->prepare( "
						SELECT DISTINCT *
						 	FROM {$wpdb->pmpro_memberships_users} AS mu
						 	WHERE mu.status = 'active'
						 	AND (
						 		mu.enddate IS NOT NULL
						 		AND mu.enddate != '0000-00-00 00:00:00'
						 		AND mu.enddate >= %s
					        )
					        ORDER by mu.user_id ASC",
					date( 'Y-m-d 00:00:00' ) // Midnight today
				);
				
				$member_list = $wpdb->get_results( $active_sql );
				$environment = pmpro_getOption( 'gateway_environment' );
				
				$utils->log( "Found " . count( $member_list ) . " active member records for non-subscribers" );
				
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
						
						$record = new User_Data( $user, $last_order, 'expiration' );
						$record->set_recurring_membership_status( false );
						
						$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}. Is recurring? " . ( false === $record->get_recurring_membership_status() ? 'No' : 'Yes' ) );
					} else {
						$record = new User_Data( $user, null, 'expiration' );
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					/**
					 * @since v1.9.14 - ENHANCEMENT: Simplified config of status (always active based on what PMPro believes)
					 */
					$utils->log( "Setting member status: {$member->status}" );
					$record->set_membership_status( true );
					
					$cust_id = apply_filters( 'e20r_pw_addon_get_user_customer_id', null, $last_order->gateway, $record );
					
					$utils->log( "User ID for {$last_order->gateway} ({$last_order->gateway_environment}): {$cust_id}/" . $record->get_user_ID() );
					
					// Add record if it's no longer recurring
					if ( ! empty( $cust_id ) && false === $record->get_recurring_membership_status() ) {
						
						$record->set_gateway_customer_id( $cust_id );
						
						$this->active_members[] = $record;
					} else if ( empty( $cust_id ) ) {
						
						$utils->log( "Couldn't locate the upstream customer ID for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						continue;
						
					} else {
						
						$utils->log( "Uses recurring payment for the '{$last_order->gateway}' gateway ({$member->user_id})" );
						continue;
					}
				}
				
				// Save to cache
				if ( ! empty( $this->active_members ) ) {
					$utils->log( "Saving active member list to cache" );
					Cache::set( 'active_norecurr_users', $this->active_members, 12 * 3600, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
			
			return $this->active_members;
		}
		
		/**
		 * Fetch all active members with recurring payment subscriptions and their last order info from local DB (or cache)
		 *
		 * @return User_Data[]|bool
		 *
		 * @since v1.9.4 - BUG FIX: Return record list from set_active_subscription_members()
		 * @since v1.9.5 - BUG FIX: Load all recurring payment records that are active (and w/o enddate) or have an enddate in the future
		 * @since v1.9.6 - ENHANCEMENT: Set cache duration to 12 hours for all_active_users
		 * @since v1.9.14 - BUG FIX: Didn't always load active recurring payment member data
		 * @since v1.9.14 - ENHANCEMENT: Simplified config of status (always active based on what PMPro believes)
		 * @since v1.9.14 - BUG FIX: Should always set status to 'recurring' in set_active_subscription_members()
		 */
		public function set_active_subscription_members() {
			
			$this->active_members = array();
			$utils                = Utilities::get_instance();
			$environment          = pmpro_getOption( 'gateway_environment' );
			
			$utils->log( "Attempting to load active members from cache" );
			
			if ( null === ( $this->active_members = Cache::get( 'active_subscr_users', Payment_Warning::cache_group ) ) ) {
				
				global $wpdb;
				
				$utils->log( "Have to refresh since cache is invalid/empty" );
				
				/**
				 * @since v1.9.5 - BUG FIX: Load all recurring payment records that are active (and w/o enddate) or have an enddate in the future
				 * @since v1.9.14 - BUG FIX: Didn't always load active recurring payment member data
				 */
				$active_sql = "
						SELECT DISTINCT *
							FROM {$wpdb->pmpro_memberships_users} AS mu
							WHERE mu.status = 'active'
							AND mu.billing_amount != '0.00'
							AND ( mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00')
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
					
					$is_recurring = true;
					
					if ( ! empty( $last_order->code ) ) {
						$record = new User_Data( $user, $last_order, 'recurring' );
					} else {
						$record = new User_Data( $user, null, 'recurring' );
						$utils->log( "No pre-existing active order for {$user->ID}" );
					}
					
					/**
					 * @since v1.9.14 - BUG FIX: Should always set status to 'recurring' in set_active_subscription_members()
					 */
					$record->set_recurring_membership_status( true );
					$utils->log( "Found existing order object for {$user->ID}? ({$last_order->code}). Is recurring? " . ( true == $record->get_recurring_membership_status() ? 'Yes' : 'No' ) );
					
					
					/**
					 * @since v1.9.14 - ENHANCEMENT: Simplified config of status (always active based on what PMPro believes)
					 */
					$utils->log( "Setting member status: {$member->status}" );
					$record->set_membership_status( true );
					
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
					Cache::set( 'active_subscr_users', $this->active_members, 12 * 3600, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
			
			// @since v1.9.4 - BUG FIX: Return record list from set_active_subscription_members()
			return $this->active_members;
		}
		
		/**
		 * Load all (PMPro) members who are currently active (on the local system)
		 *
		 * @return User_Data[]|bool
		 *
		 * @since v1.9.4 - BUG FIX: Return record list from set_all_active_members()
		 * @since v1.9.6 - ENHANCEMENT: Set cache duration to last 12 hours
		 */
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
					Cache::set( 'all_active_users', $this->active_members, 12 * 3600, Payment_Warning::cache_group );
				}
			}
			
			$record     = null;
			$last_order = null;
			
			// @since v1.9.4 - BUG FIX: Return record list from set_all_active_members()
			return $this->active_members;
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
		 * @return User_Data[]|bool
		 *
		 * @since 1.9.2 BUG FIX: Didn't always load the required user records
		 * @since 1.9.4 - BUG FIX: Would return whatever records were previously loaded if incorrect type was given!
		 * @since v1.9.6 - ENHANCEMENT: Set cache duration to last 4 hours
		 */
		public function get_all_user_records( $type = 'ccexpiration' ) {
			
			$util = Utilities::get_instance();
			
			$this->active_members = array();
			
			if ( null === ( $this->active_members = Cache::get( "current_{$type}", Payment_Warning::cache_group ) ) ) {
				
				$util->log( "Loading {$type} records from Membership data" );
				
				switch ( $type ) {
					case 'recurring':
						$records = $this->set_active_subscription_members();
						break;
					case 'expiration':
						$records = $this->set_active_non_subscription_members();
						break;
					case 'ccexpiration':
						$records = $this->set_all_active_members();
						break;
					default:
						// @since 1.9.4 - BUG FIX: Would return whatever records were previously loaded if incorrect type was given!
						$util->log( "ERROR: Incorrect record type requested ({$type})!" );
						$this->active_members = array();
						
						return false;
				}
				
				if ( ! empty( $records ) ) {
					
					foreach ( $records as $key => $user_data ) {
						
						$order         = $user_data->get_last_pmpro_order();
						$user_id       = $user_data->get_user_ID();
						$user_level_id = $user_data->get_membership_level_ID();
						
						if ( ! empty( $order ) && ! empty( $order->id ) ) {
							
							$util->log( "Found order ID: {$order->id} for {$user_id}" );
							$order_id = $order->id;
							
							$user_data->maybe_load_from_db( $user_id, $order_id, $user_level_id );
							$user_data->set_reminder_type( $type );
							
							$next_payment = $user_data->get_next_payment();
							$is_active    = pmpro_hasMembershipLevel( $user_level_id, $user_id );
							
							// Include record if we're processing recurring payments & the user is active & has an upcoming payment
							// Or if we're processing credit card data/expiration data & the user is an active member
							// @since 1.9.2 BUG FIX: Didn't always load the required user records
							if (
								( ( 'recurring' === $type ) && ! empty( $next_payment ) && true === $is_active ) ||
								( in_array( $type, array( 'expiration', 'ccexpiration' ) ) && true === $is_active )
							) {
								
								$util->log( "Including record for {$user_id}" );
								$this->active_members[] = $user_data;
							} else {
								$util->log( "Will skip record for {$user_id} (Not considered an 'active' member for {$type} data)..." );
							}
						}
					}
				}
				
				if ( ! empty( $this->active_members ) ) {
					Cache::set( "current_{$type}", $this->active_members, 4 * 3600, Payment_Warning::cache_group );
				}
			}
			
			return $this->active_members;
		}
		
		/**
		 * @param string $type
		 *
		 * @return bool|User_Data[]
		 *
		 * @since 1.9.6 - Add local fetch of user records
		 */
		public function get_local_user_data( $type = 'ccexpiration' ) {
			global $wpdb;
			
			$utils     = Utilities::get_instance();
			$user_list = array();
			
			$user_info_table = apply_filters( 'e20r_pw_user_info_table_name', "{$wpdb->prefix}e20rpw_user_info" );
			$user_cc_table   = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
			$environment     = pmpro_getOption( 'gateway_environment' );
			
			$last_day      = apply_filters( 'e20rpw_ccexpiration_last_day_of_month', date( 't', current_time( 'timestamp' ) ) );
			$current_month = apply_filters( 'e20rpw_ccexpiration_month', date( 'm', current_time( 'timestamp' ) ) );
			$current_year = apply_filters( 'e20rpw_ccexpiration_year', date( 'Y', current_time( 'timestamp' ) ) );
			
			if ( $current_month == 12 ) {
				$next_month = 01;
			} else {
				$next_month = $current_month + 1;
			}
			
			$utils->log( "Loading {$type} records from Membership data" );
			
			/**
			 * @since 2.1 - BUG FIX: Used localized date
			 * @since 2.1 - ENHANCEMENT: Add data fetch for Credit Card Expiration warnings
			 */
			switch ( $type ) {
				case 'recurring':
					$sql = $wpdb->prepare( "
							SELECT DISTINCT user_id, level_id, last_order_id
								FROM {$user_info_table}
								WHERE (user_payment_status = %s OR user_payment_status = %s) AND
								reminder_type = %s AND
								end_of_payment_period >= %s",
						'active',
						'success',
						$type,
						date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) )
					);
					
					break;
				case 'expiration':
					$sql = $wpdb->prepare( "
							SELECT DISTINCT user_id, level_id, last_order_id
								FROM {$user_info_table}
								WHERE (user_payment_status = %s OR user_payment_status = %s) AND
								reminder_type = %s AND
								end_of_membership_date >= %s",
						'active',
						'success',
						$type,
						date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) )
					);
					break;
				case 'ccexpiration':
					
					$sql = $wpdb->prepare( "
						SELECT UI.user_id AS user_id, UI.level_id AS level_id, UI.last_order_id AS last_order_id
						FROM {$user_cc_table} AS CC
						INNER JOIN {$user_info_table} AS UI
							ON (
								CC.user_id = UI.user_id
								AND UI.next_payment_date >= %s
								AND UI.reminder_type = %s
							)
						WHERE CC.exp_month = %d AND
							CC.exp_month <= %d AND
							CC.exp_year = %d
							AND UI.user_payment_status = %s",
						"{$current_year}-{$current_month}-{$last_day} 23:59:59",
						'recurring',
						( $current_month == 12 ? $next_month : $current_month ), // Handle end of year
						( $current_month == 12 ? $next_month + 1 : $next_month ), // Handle end of year
						( $next_month != 1 ? $current_year : $current_year + 1 ), // Handle end of year
						'active'
					);
					
					$utils->log("Using SQL for Credit Card Expirations: {$sql}");
					break;
				default:
					
					$utils->log( "ERROR: Incorrect record type requested ({$type})!" );
					$this->active_members = array();
					
					return false;
			}
			
			$members = $wpdb->get_results( $sql );
			
			$utils->log( "Processing local user data for {$type}" );
			
			foreach ( $members as $member ) {
				
				$user       = new \WP_User( $member->user_id );
				$user       = self::set_membership_info( $user );
				$last_order = new \MemberOrder();
				
				$last_order->getLastMemberOrder( $member->user_id, 'success', $member->level_id );
				
				if ( empty( $last_order->gateway_environment ) || ( isset( $last_order->gateway_environment ) && $environment !== $last_order->gateway_environment ) ) {
					$utils->log( "Last order found used a different environment from the currently configured payment gateway environment ({$environment})" );
					continue;
				}
				
				if ( ! empty( $last_order->code ) ) {
					$record = new User_Data( $user, $last_order, $type );
					$record->maybe_load_from_db( $user->ID, $last_order->id, $member->level_id );
					$utils->log( "Found existing order object for {$user->ID}: {$last_order->code}. Is recurring? " . ( true == $record->get_recurring_membership_status() ? 'Yes' : 'No' ) );
					
				} else {
					$record = new User_Data( $user, null, $type );
					$record->maybe_load_from_db( $user->ID, null, $member->level_id );
					$utils->log( "No pre-existing active order for {$user->ID}" );
				}
				
				$user_list[] = $record;
			}
			
			$utils->log( "Will have " . count( $user_list ) . " records to process for {$type} reminders" );
			
			return $user_list;
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
