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

use E20R\Utilities\Utilities;
use DateTime;

class User_Data {
	
	/**
	 * @var null|\WP_User
	 */
	private $user = null;
	
	/**
	 * @var null|\MemberOrder
	 */
	private $last_order = null;
	
	private $user_info_table_name = null;
	private $cc_info_table_name = null;
	
	/**
	 * Is the currently active payment plan (subscription) ending
	 *
	 * @var bool
	 */
	private $subscription_ends = false;
	
	private $next_payment_amount = 0.00;
	
	private $tax_amount = 0.00;
	
	private $payment_currency = null;
	
	private $end_of_payment_period = null;
	
	private $next_payment_date = null;
	
	private $credit_card = array();
	
	private $has_active_membership = false;
	
	private $has_active_subscription = false;
	
	private $is_delinquent = false;
	
	private $user_gateway = null;
	
	private $user_gateway_type = 'sandbox';
	
	private $gateway_customer_id = null;
	
	// private $user_subscriptions = null;
	
	// private $user_charges = null;
	
	private $user_payment_status = 'stopped';
	
	private $reminder_type = null;
	
	private $end_of_membership_date = null;
	
	private $gateway_subscr_id = null;
	
	private $gateway_payment_id = null;
	
	private $has_local_recurring_membership = false;
	
	private $payment_amount = null;
	
	private $payment_date = null;
	
	private $is_payment_paid = null;
	
	private $failure_description = null;
	
	/**
	 * User_Data constructor.
	 *
	 * @param null|\WP_User|int|string $user A valid user object, user ID number, or their email address
	 * @param null|\MemberOrder        $order
	 * @param string                   $type
	 */
	public function __construct( $user = null, $order = null, $type = 'ccexpiring' ) {
		
		$util = Utilities::get_instance();
		
		if ( ! is_null( $user ) ) {
			
			if ( is_a( $user, 'WP_User' ) ) {
				$this->user = $user;
			} else if ( is_email( $user ) ) {
				$this->user = get_user_by( 'email', $user );
			} else if ( is_numeric( $user ) && is_int( (int) $user ) ) {
				$this->user = get_user_by( 'ID', $user );
			}
		}
		
		$this->reminder_type = $type;
		
		// Add the last order if the order object is present
		if ( ! is_null( $order ) && isset( $order->id ) ) {
			
			$util->log( "Adding order: {$order->id}" );
			$this->last_order        = $order;
			$this->user_gateway      = $order->gateway;
			$this->user_gateway_type = $order->gateway_environment;
			
			$util->log( "Saved gateway info: {$this->user_gateway} in a {$this->user_gateway_type} environment" );
		}
		
		global $wpdb;
		$this->user_info_table_name = apply_filters( 'e20r_pw_user_info_table_name', "{$wpdb->prefix}e20rpw_user_info" );
		$this->cc_info_table_name   = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
	}
	
	/**
	 * Save the amount for the payment and its currency
	 *
	 * @param float  $amount
	 * @param string $currency
	 */
	public function set_payment_amount( $amount, $currency = 'USD' ) {
		
		$this->payment_amount   = $amount;
		$this->payment_currency = $currency;
	}
	
	/**
	 * Load user info from the database if it exists
	 *
	 * @param null $user_id
	 * @param null $order_id
	 * @param null $level_id
	 * @param bool $skip
	 *
	 * @since 1.9.6 - BUG FIX: Would load credit cards in an unexpected format causing double-save operations
	 * @since 1.9.14 - ENHANCEMENT: Reduce debug logging levels
	 */
	public function maybe_load_from_db( $user_id = null, $order_id = null, $level_id = null, $skip = false ) {
		
		global $wpdb;
		
		$util = Utilities::get_instance();
		$util->log( "Looking for preexisting data from local DB: {$user_id}" );
		
		if ( true === $skip ) {
			$util->log( "Won't load the DB record for this user record..." );
			
			return;
		}
		
		if ( is_null( $user_id ) ) {
			$user_id = isset( $this->user->ID ) ? $this->user->ID : null;
		}
		
		if ( is_null( $order_id ) ) {
			$order_id = isset( $this->last_order->id ) ? $this->last_order->id : null;
		}
		
		if ( is_null( $level_id ) || ( isset( $this->user->current_membership_level->id ) && ! empty( $level_id ) && $level_id !== $this->user->current_membership_level->id ) ) {
			
			$util->log( "Having to (re)set the membership level info for the user: {$level_id}" );
			
			if ( ! is_null( $level_id ) ) {
				
				$util->log("(Re)Loading membership specific data for user...");
				$this->user = Fetch_User_Data::set_membership_info( $this->user );
			}
			// Configure the level ID
			$level_id = isset( $this->user->current_membership_level->id ) ? $this->user->current_membership_level->id : null;
		}
		
		if ( empty( $user_id ) || empty( $order_id ) || empty( $level_id ) ) {
			$util->log( "Can't load data from table for the (potential) user ID {$user_id}" );
			
			return;
		}
		
		$fetch_sql = $this->select_sql( $user_id, $level_id, $order_id );
		
		if ( ! empty( $fetch_sql ) ) {
			
			$result = $wpdb->get_results( $fetch_sql );
			
			$util->log( "Found " . count( $result ) . " record(s) for {$user_id}/{$order_id}/{$level_id}" );
			
			if ( ! empty( $result ) && count( $result ) == 1 ) {
				
				$util->log( "Found a single record for {$user_id}/{$order_id}/{$level_id}" );
				$data = $result[0];
				
				foreach ( $data as $field => $value ) {
					
					//$util->log( "Loading {$field} = {$value}" );
					$this->{$field} = $this->maybe_bool( $field, $value );
				}
			}
		} else {
			return;
		}
		
		// Load the credit card info for the specified user
		$cc_sql = $wpdb->prepare( "SELECT * FROM {$this->cc_info_table_name} WHERE user_id = %d AND exp_year >= %d",
			$user_id,
			date( 'Y', current_time( 'timestamp' ) )
		);
		
		// @since 1.9.6 - BUG FIX: Would load credit cards in an unexpected format causing double-save operations
		$credit_cards = $wpdb->get_results( $cc_sql, ARRAY_A );
		
		foreach( $credit_cards as $card ) {
			$this->add_card( $card['brand'], $card['last4'], $card['exp_month'], $card['exp_year'] );
		}
		
		$util->log( "Loaded " . count( $this->credit_card ) . " credit card(s) for {$user_id}" );
		
		if ( isset( $this->modified ) ) {
			$util->log( "Removing unused/unneeded `modified` column data" );
			unset( $this->modified );
		}
	}
	
	/**
	 * Convert to valid Boolean value if applicable for variable
	 *
	 * @param string $variable
	 * @param mixed  $value
	 *
	 * @return bool|mixed
	 */
	private function maybe_bool( $variable, $value ) {
		
		$boolean_vars = apply_filters( 'e20r_payment_warning_boolean_db_fields', array(
			'has_active_subscription',
			'is_delinquent',
		) );
		
		if ( in_array( $variable, $boolean_vars ) ) {
			
			if ( ! is_string( $value ) ) {
				return (bool) $value;
			}
			
			switch ( strtolower( $value ) ) {
				case '1':
				case 'true':
				case 'on':
				case 'yes':
				case 'y':
					return true;
				default:
					return false;
			}
		} else {
			return $value;
		}
	}
	
	/**
	 * Select the appropriate SQL statement for the supplied variables
	 *
	 * @param $user_id
	 * @param $level_id
	 * @param $order_id
	 *
	 * @return string|null
	 */
	private function select_sql( $user_id, $level_id, $order_id ) {
		
		global $wpdb;
		
		$utils = Utilities::get_instance();
		$sql   = null;
		
		$this->user_info_table_name = apply_filters( 'e20r_pw_user_info_table_name', "{$wpdb->prefix}e20rpw_user_info" );
		$this->cc_info_table_name   = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
		
		$utils->log( "Loading data for {$user_id}, {$level_id}, {$order_id} and reminder type: {$this->reminder_type}" );
		
		if ( ! empty( $order_id ) ) {
			
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->user_info_table_name} WHERE user_id = %d AND level_id = %d AND last_order_id = %d AND reminder_type = %s ORDER BY ID DESC LIMIT 1",
				$user_id,
				$level_id,
				$order_id,
				$this->reminder_type
			);
		}
		
		if ( empty( $order_id ) ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->user_info_table_name} WHERE user_id = %d AND level_id = %d AND reminder_type = %s ORDER BY ID DESC LIMIT 1",
				$user_id,
				$level_id,
				$this->reminder_type
			);
			
		}
		
		return $sql;
	}
	
	/**
	 * Save the existing user data to the respective database table(s)
	 *
	 * @return bool
	 *
	 * @since v1.9.1 ENHANCEMENT: Remove any instance of the charge or subscription record data
	 * @since v1.9.4 ENHANCEMENT: No longer declaring the type of data to save (recurring/payment)
	 */
	public function save_to_db() {
		
		global $wpdb;
		$util      = Utilities::get_instance();
		$result    = false;
		$cc_info   = array();
		$user_data = array();
		$where     = array();
		
		$util->log( "Saving user data for {$this->user->ID}" );
		
		if ( ! empty( $this->payment_currency ) && ( ! empty( $this->gateway_subscr_id ) || ! empty( $this->gateway_payment_id ) ) ) {
			
			$util->log( "Have what we think is valid data for {$this->user->ID}" );
			
			$user_data = array(
				'user_id'                        => $this->user->ID,
				'level_id'                       => isset( $this->user->current_membership_level->id ) ? $this->user->current_membership_level->id : null,
				'last_order_id'                  => $this->last_order->id,
				'gateway_subscr_id'              => $this->gateway_subscr_id,
				'gateway_payment_id'             => $this->gateway_payment_id,
				'is_delinquent'                  => $this->is_delinquent,
				'has_active_subscription'        => $this->has_active_subscription,
				'has_local_recurring_membership' => $this->is_local_membership_recurring( $this->user->current_membership_level ),
				'payment_currency'               => $this->payment_currency,
				'payment_amount'                 => $this->payment_amount,
				'next_payment_amount'            => $this->next_payment_amount,
				'tax_amount'                     => $this->tax_amount,
				'user_payment_status'            => $this->user_payment_status,
				'payment_date'                   => $this->payment_date,
				'is_payment_paid'                => $this->is_payment_paid,
				'failure_description'            => $this->failure_description,
				'next_payment_date'              => $this->next_payment_date,
				'end_of_payment_period'          => $this->end_of_payment_period,
				'end_of_membership_date'         => $this->end_of_membership_date,
				'reminder_type'                  => $this->reminder_type,
				'modified'                       => current_time( 'mysql' ),
			);
			
			$data_format = array(
				'%d', // user_id
				'%d', // level_id
				'%d', // last_order_id
				'%s', // gateway_subscr_id
				'%s', // gateway_payment_id
				'%d', // is_delinquent
				'%d', // has_active_subscription
				'%d', // has_local_recurring_membership
				'%s', // payment_currency
				'%s', // payment_amount
				'%s', // next_payment_amount (should be float, but doesn't format well)
				'%s', // tax_amount
				'%s', // user_payment_status
				'%s', // payment_date
				'%d', // is_payment_paid
				'%s', // failure_description
				'%s', // next_payment_date
				'%s', // end_of_payment_period
				'%s', // end_of_membership_date
				'%s', // reminder_type
				'%s', // modified
			);
			
			$where = array(
				'user_id'  => $this->user->ID,
				'level_id' => $this->last_order->membership_id,
			);
			
			if ( ! empty( $this->gateway_subscr_id ) ) {
				$where['gateway_subscr_id'] = $this->gateway_subscr_id;
			} else {
				if ( ! empty( $this->gateway_payment_id ) ) {
					$where['gateway_payment_id'] = $this->gateway_payment_id;
				}
			}

//			$util->log( "Record: " . print_r( $user_data, true ) );
//			$util->log( "Where: " . print_r( $where, true ) );
			
			// No valid membership level defined!
			if ( empty( $user_data['level_id'] ) ) {
				$util->log( "User doesn't have a valid/current membership level on this system. Exiting the save operation!" );
				
				return false;
			}
			
			$where_format = array( '%d', '%d', '%s' );
			
			if ( ! empty( $this->gateway_subscr_id ) ) {
				$check_sql = $wpdb->prepare(
					"SELECT ID FROM {$this->user_info_table_name} WHERE user_id = %d AND level_id = %d AND gateway_subscr_id = %s ORDER BY ID DESC LIMIT 1",
					$this->user->ID,
					$this->last_order->membership_id,
					$this->gateway_subscr_id
				);
				
			} else {
				if ( ! empty( $this->gateway_payment_id ) ) {
					$check_sql = $wpdb->prepare(
						"SELECT ID FROM {$this->user_info_table_name} WHERE user_id = %d AND level_id = %d AND gateway_payment_id = %s ORDER BY ID DESC LIMIT 1",
						$this->user->ID,
						$this->last_order->membership_id,
						$this->gateway_payment_id
					);
				}
			}
			
			$exists = $wpdb->get_var( $check_sql );
			
			if ( empty( $exists ) ) {
				$util->log( "No previous record exists for this combination of user information" );
				$result = $wpdb->insert( $this->user_info_table_name, $user_data, $data_format );
			} else {
				$util->log( "Need to update as a previous record exists for this combination of user information" );
				$result = $wpdb->update( $this->user_info_table_name, $user_data, $where, $data_format, $where_format );
			}
		}
		
		if ( false === $result ) {
			
			$util->log( "No record added/updated for {$this->user->ID} to {$this->user_info_table_name}. Maybe missing payment/subscription record?" );
			
			// $util->add_message( sprintf( __( "Error inserting/updating data for %s", Payment_Warning::plugin_slug ), $this->user->user_email ), 'error', 'backend' );
			
			return false;
		} else {
			$util->log( "We " . ( empty( $exists ) ? 'inserted' : 'updated' ) . " the record for {$this->user->ID}." );
		}
		
		$util->log( "Attempt to save payment source info for {$this->user->ID}? " . ( empty($this->credit_card) ? 'No' : 'Yes') );
		
		// Save credit card info for user
		foreach ( $this->credit_card as $card_id => $card_data ) {
			
			if ( is_a( $card_data, 'stdClass' ) ) {
				
				$util->log( "Processing card for (" . $this->get_user_ID() . ") - stdClass given" );
				$last4 = isset( $card_data->last4 ) ? $card_data->last4 : $card_data->card_id;
				
				$cc_info = array(
					'user_id'   => $this->user->ID,
					'last4'     => $last4,
					'exp_month' => $card_data->exp_month,
					'exp_year'  => $card_data->exp_year,
					'brand'     => $card_data->brand,
				);
				
				$where = array(
					'user_id' => $this->user->ID,
					'last4'   => $last4,
				);
				
			}
			
			if ( is_array( $card_data ) ) {
				
				$util->log( "Processing card for (" . $this->get_user_ID() . ") - array given " );
				$last4 = isset( $card_data['last4'] ) ? $card_data['last4'] : $card_data['card_id'];
				
				$cc_info = array(
					'user_id'   => $this->user->ID,
					'last4'     => $last4,
					'exp_month' => $card_data['exp_month'],
					'exp_year'  => $card_data['exp_year'],
					'brand'     => $card_data['brand'],
				);
				
				$where = array(
					'user_id' => $this->user->ID,
					'last4'   => $last4,
				);
			}
			
			$format = array( '%d', '%s', '%s', '%s', '%s' );
			
			$where_format = array( '%s', '%s' );
			$exists       = self::find_card_info( $cc_info );
			
			$util->log( "Does card info exist? " . print_r( $exists, true ) );
			
			if ( ! empty( $exists ) ) {
				$where['ID'] = $exists;
				unset( $where['last4'] );
				$where_format = array( '%s', '%s' );
			}
			
			$result = self::save_credit_card( $cc_info, ! empty( $exists ) );
			
			if ( false === $result ) {
				$util->log( "Error: Failed to update Credit Card info for {$this->user->ID}/{$last4}: {$wpdb->last_error}" );
				
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Save/Update the Credit Card data
	 *
	 * @param array $card
	 * @param bool  $exists
	 *
	 * @return false|int
	 */
	public static function save_credit_card( $card, $exists = false ) {
		
		global $wpdb;
		$util = Utilities::get_instance();
		
		$cc_info = array(
			'user_id'   => $card['user_id'],
			'last4'     => $card['last4'],
			'exp_month' => $card['exp_month'],
			'exp_year'  => $card['exp_year'],
			'brand'     => $card['brand'],
		);
		$format  = array( '%d', '%s', '%s', '%s', '%s' );
		
		$where        = array(
			'user_id' => $card['user_id'],
			'last4'   => $card['last4'],
		);
		$where_format = array( '%d', '%s' );
		
		// Using the recorded ID of the local Card record
		if ( ! empty( $card['ID'] ) ) {
			$where['ID'] = $card['ID'];
			unset( $where['last4'] );
			$where_format = array( '%s', '%s' );
		}
		
		// Get the table name
		$table_name = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
		
		// Check
		if ( true === $exists ) {
			
			$util->log( "Previous CC record exists for {$card['last4']}/{$card['user_id']}. Updating" );
			$result = $wpdb->update(
				$table_name,
				$cc_info,
				$where,
				$format,
				$where_format
			);
		} else {
			$util->log( "No previous CC record exists for {$card['last4']}/{$card['user_id']}. Inserting" );
			
			$result = $wpdb->insert(
				$table_name,
				$cc_info,
				$format
			);
		}
		
		return $result;
	}
	
	/**
	 * Delete a specific card from the local DB
	 *
	 * @param int $card_id
	 *
	 * @return false|int
	 */
	public static function delete_card( $card_id ) {
		
		$util = Utilities::get_instance();
		global $wpdb;
		
		// Query parameters
		$where        = array( 'ID' => $card_id );
		$where_format = array( '%d' );
		$table_name   = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
		
		$util->log( "Attempting to delete card with record ID {$card_id} from {$table_name} " );
		
		return $wpdb->delete( $table_name, $where, $where_format );
	}
	
	/**
	 * Deleting Subscription record from local DB
	 *
	 * @param int    $user_id         Local User ID
	 * @param string $subscription_id Remote gateway Subscription ID
	 *
	 * @return bool
	 */
	public static function delete_subscription_record( $user_id, $subscription_id ) {
		
		global $wpdb;
		
		$util         = Utilities::get_instance();
		$where        = array( 'user_id' => $user_id, 'gateway_subscr_id' => $subscription_id );
		$where_format = array( '%d', '%s' );
		$table_name   = apply_filters( 'e20r_pw_user_info_table_name', "{$wpdb->prefix}e20rpw_user_info" );
		
		$util->log( "Deleting record from {$table_name} using: " . print_r( $where, true ) );
		
		$result = $wpdb->delete( $table_name, $where, $where_format );
		if ( false !== $result ) {
			
			$util->log( "Records deleted: {$result}" );
			
			return true;
		}
		
		$util->log( "Error deleting record for {$user_id}/{$subscription_id}" );
		
		return false;
	}
	
	/**
	 * Let's us know if the user ID/subscription ID combination exists in local DB
	 *
	 * @param $subscription_id
	 *
	 * @return bool
	 */
	public function has_subscription_id( $subscription_id ) {
		
		global $wpdb;
		
		$sql = $wpdb->prepare(
			"SELECT gateway_subscr_id FROM {$this->user_info_table_name} WHERE user_id = %d AND gateway_subscr_id = %s",
			$this->user->ID,
			$subscription_id
		);
		
		$result = $wpdb->get_var( $sql );
		
		return ( ! empty( $result ) );
	}
	
	public function set_gw_subscription_id( $id ) {
		$this->gateway_subscr_id = $id;
	}
	
	public function get_gw_subscription_id() {
		
		if ( ! empty( $this->gateway_subscr_id ) ) {
			return $this->gateway_subscr_id;
		}
		
		return null;
	}
	
	/**
	 * Configure the date when the membership access terminates for this user
	 *
	 * @param string $date A valid strtotime() date
	 *
	 * @since 1.9.4 - BUG FIX: Prevented from saving end of membership date due to typo in variable name
	 */
	public function set_end_of_membership_date( $date = null ) {
		
		$util = Utilities::get_instance();
		
		// Fetch the enddate for the user data, if it's configured.
		if ( is_null( $date ) ) {
			
			global $wpdb;
			
			$sql = $wpdb->prepare(
				"SELECT mu.enddate
							FROM {$wpdb->pmpro_memberships_users} AS mu
							WHERE mu.user_id = %d AND
								mu.status = %s AND
								mu.membership_id = %d",
				$this->get_user_ID(),
				'active',
				$this->get_membership_level_ID()
			);
			
			$date = $wpdb->get_var( $sql );
		}
		
		// Test if $date is a valid date/time
		// @since 1.9.4 - BUG FIX: Prevented from saving end of membership date due to typo in variable name
		if ( !empty($date) && false !== strtotime( $date, current_time( 'timestamp' ) ) ) {
			
			$util->log("Adding {$date} as the end-date for this membership");
			$this->end_of_membership_date = $date;
		} else {
			$util->log( "Unable to add {$date} as the 'end of membership date' value" );
		}
	}
	
	/**
	 * Whether the user has an active subscription plan on the payment gateway
	 *
	 * @return bool
	 */
	public function has_active_subscription() {
		return $this->has_active_subscription;
	}
	
	/**
	 * Return the date when the membership access terminates for this user
	 *
	 * @return null|string
	 */
	public function get_end_of_membership_date() {
		
		$utils = Utilities::get_instance();
		$utils->log("Using {$this->end_of_membership_date} as the end of membership date for {$this->user->ID}");
		
		return $this->end_of_membership_date;
	}
	
	/**
	 * Set the reminder type for this record
	 *
	 * @param string $type
	 */
	public function set_reminder_type( $type ) {
		$this->reminder_type = $type;
	}
	
	/**
	 * Return the type of reminder this record is for
	 *
	 * @return null|string|int
	 */
	public function get_reminder_type() {
		
		$value = null;
		
		if ( ! empty( $this->reminder_type ) && is_string( $this->reminder_type )) {
			
			switch( $this->reminder_type ) {
				case 'recurring':
					$value = E20R_PW_RECURRING_REMINDER;
					break;
				case 'expiring':
					$value = E20R_PW_EXPIRATION_REMINDER;
					break;
				case 'ccexpiration':
					$value = E20R_PW_CREDITCARD_REMINDER;
					break;
			}
			
		} else if ( !empty( $this->reminder_type ) ) {
			$value = $this->reminder_type;
		}
		
		return $value;
	}
	
	/**
	 * Adds the tax amount for the upcoming transaction/invoice/payment
	 *
	 * @param float|string $amount
	 */
	public function set_tax_amount( $amount ) {
		
		$this->tax_amount = $amount;
	}
	
	/**
	 * Returns the tax amount
	 *
	 * @return float|string|null
	 */
	public function get_tax_amount() {
		
		if ( ! empty( $this->tax_amount ) ) {
			return $this->tax_amount;
		}
		
		return null;
	}
	
	/**
	 * Return the gateway environment (live|sandbox) where the last transaction was performed
	 *
	 * @return null|string
	 */
	public function get_user_gateway_type() {
		
		if ( ! empty( $this->user_gateway_type ) ) {
			return $this->user_gateway_type;
		}
		
		return null;
	}
	
	/**
	 * Activate "subscription payments end" setting
	 */
	public function set_subscription_end() {
		$this->subscription_ends = true;
	}
	
	/**
	 * Is the subscription (payment plan) going to end after this payment period?
	 *
	 * @return bool|null
	 */
	public function get_end_of_subscr_status() {
		
		return $this->subscription_ends;
	}
	
	/**
	 * @param string $currency - 3 character identifier (used by $pmpro_currencies global)
	 */
	public function set_payment_currency( $currency ) {
		
		$this->payment_currency = $currency;
	}
	
	/**
	 * Set the amount to use for the next payment
	 *
	 * @param float $amount
	 */
	public function set_next_payment_amount( $amount ) {
		$this->next_payment_amount = $amount;
	}
	
	/**
	 * Next payment's amount (charge)
	 *
	 * @return float
	 */
	public function get_next_payment_amount() {
		return $this->next_payment_amount;
	}
	
	/**
	 * Set the end of the current payment period
	 *
	 * @param string $date_time - YYYY-MM-DD HH:MM:SS
	 */
	public function set_end_of_paymentperiod( $date_time ) {
		$this->end_of_payment_period = $date_time;
	}
	
	/**
	 * Set date for when we'll be charged the next payment
	 *
	 * @param string $date_time - YYYY-MM-DD HH:MM:SS
	 */
	public function set_next_payment( $date_time ) {
		
		$this->next_payment_date = $date_time;
	}
	
	/**
	 * Next payment date (for subscriptions)
	 *
	 * @return null|string Date/Time: YYYY-MM-DD HH:MM:SS)
	 *
	 * @since 2.2 - BUG FIX: Didn't use the supplied subscription ID
	 */
	public function get_next_payment( $subscription_id = null ) {
		
		$util = Utilities::get_instance();
		
		if ( ! empty( $subscription_id ) && empty( $this->next_payment_date ) ) {
			
			$util->log( "Have to attempt to fetch the next payment date from the DB..." );
			global $wpdb;
			
			$sql = $wpdb->prepare( "SELECT next_payment_date FROM {$this->user_info_table_name} WHERE user_id = %d AND gateway_subscr_id = %s", $this->user->ID, $subscription_id );
			
			$date = $wpdb->get_var( $sql );
			$util->log( "Received date value: {$date}" );
			
			if ( ! empty( $date ) ) {
				$this->next_payment_date = $date;
			}
		}
		
		$util->log( "Using: {$this->next_payment_date} for subscription" );
		
		return $this->next_payment_date;
	}
	
	/**
	 * Return the name for the Credit Card database table
	 *
	 * @return string|null
	 */
	public function get_cc_table_name() {
		
		return $this->cc_info_table_name;
	}
	
	/**
	 * Search for, and return card data in database
	 *
	 * @param $card
	 *
	 * @return null|string
	 */
	public static function find_card_info( $card ) {
		
		global $wpdb;
		$util = Utilities::get_instance();
		
		$table_name = apply_filters( 'e20r_pw_user_cc_table_name', "{$wpdb->prefix}e20rpw_user_cc" );
		
		$cc_sql = $wpdb->prepare(
			"SELECT ID FROM {$table_name} WHERE user_id = %d AND last4 = %s AND brand = %s",
			$card['user_id'],
			$card['last4'],
			$card['brand']
		);
		
		$result = $wpdb->get_var( $cc_sql );
		
		$util->log( "Looking up card from {$table_name} for {$card['user_id']} and {$card['last4']}/{$card['brand']}: " . print_r( $result, true ) );
		
		return $result;
	}
	
	/**
	 * The default format for Credit Card data stored (PCI Compliant)
	 *
	 * @return array
	 */
	public static function default_card_format() {
		
		return array(
			'user_id'   => null,
			'brand'     => null,
			'last4'     => null,
			'exp_month' => null,
			'exp_year'  => null,
		);
	}
	
	/**
	 * Set the card specific expiration date
	 *
	 * @param string $brand
	 * @param string $last4
	 * @param string $month
	 * @param int    $year
	 */
	public function add_card( $brand, $last4, $month, $year ) {
		
		$util = Utilities::get_instance();
		$key  = preg_replace( '/\s/', '', $brand );
		$util->log( "Saving {$key}_{$last4} info" );
		
		if ( !in_array( $key, $this->credit_card ) ) {
			$this->credit_card["{$key}_{$last4}"] = array(
				'brand'     => $brand,
				'last4'     => $last4,
				'exp_month' => $month,
				'exp_year'  => $year,
			);
		}
	}
	
	/**
	 * Get the expiration info for the specified card ID (last 4 digits)
	 *
	 * @param string $card_id
	 *
	 * @return array|null
	 */
	public function get_card_expiry( $card_id ) {
		
		$exp_data = array( 'month' => null, 'year' => null );
		
		if ( ! isset( $this->credit_card[ $card_id ]['exp_year'] ) && ! isset( $this->credit_card[ $card_id ]['exp_month'] ) ) {
			$exp_data = null;
		} else {
			
			$exp_data['month'] = $this->credit_card[ $card_id ]['exp_month'];
			$exp_data['year']  = $this->credit_card[ $card_id ]['exp_year'];
		}
		
		return $exp_data;
	}
	
	/**
	 * Set the delinquency status for the user's payment
	 *
	 * @param bool $status
	 */
	public function set_delinquency_status( $status = false ) {
		
		$this->is_delinquent = $status;
	}
	
	/**
	 * Fetch the delinquency status for the user's payment
	 *
	 * @return bool
	 */
	public function get_delinquency_status() {
		
		return $this->is_delinquent;
	}
	
	/**
	 * Get the user's WordPress ID
	 *
	 * @return bool|int
	 */
	public function get_user_ID() {
		
		if ( isset( $this->user->ID ) ) {
			return $this->user->ID;
		}
		
		return false;
	}
	
	/**
	 * Serialize and save a list of subscriptions for the user
	 *
	 * @param array $data Unserialized list of Subscription objects
	 */
	public function add_subscription_list( $data ) {
		
		$this->user_subscriptions = maybe_serialize( $data );
	}
	
	/**
	 * Load Charge/Payment object list if saved
	 *
	 * @return null|array
	 */
	public function get_serialized_charges() {
		
		if ( ! empty( $this->user_charges ) ) {
			return $this->user_charges;
		}
		
		return null;
		
	}
	
	/**
	 * Load Subscription list if saved
	 *
	 * @return null|array
	 */
	public function get_serialized_subscriptions() {
		
		if ( ! empty( $this->user_subscriptions ) ) {
			return $this->user_subscriptions;
		}
		
		return null;
		
	}
	
	/**
	 * Serialize and save a list of charges for the user
	 *
	 * @param array $data Unserialized list of charge/payment objects
	 */
	public function add_charge_list( $data ) {
		
		$this->user_charges = maybe_serialize( $data );
	}
	
	/**
	 * Return an unserialized list of charge/payment object(s)
	 *
	 * @return array|array
	 */
	public function get_charge_list() {
		
		if ( ! empty( $this->user_charges ) ) {
			return maybe_unserialize( $this->user_charges );
		}
		
		return null;
	}
	
	/**
	 * Return an unserialized list of subscription object(s)
	 *
	 * @return array|array
	 */
	public function get_subscription_list() {
		
		if ( ! empty( $this->user_subscriptions ) ) {
			return maybe_unserialize( $this->user_subscriptions );
		}
		
		return null;
	}
	
	/**
	 * Set (local) recurring membership status based on user's membership level
	 *
	 * @param bool $is_recurring
	 *
	 * @since 1.9.2 ENHANCEMENT: Force the reminder_type based on whether the membership (for the user) is recurring
	 * @since 1.9.4 - BUG FIX: Typo in reminder_type supplied
	 * @since 1.9.14 - BUG FIX: Handle situations where user has a recurring level and we have to auto-detect
	 */
	public function set_recurring_membership_status( $is_recurring = null ) {
		
		if ( null === $is_recurring ) {
			$this->has_local_recurring_membership = pmpro_isLevelRecurring( $this->user->current_membership_level );
		} else {
			$this->has_local_recurring_membership = $is_recurring;
		}
		
		if ( true === $this->has_local_recurring_membership ) {
			$this->reminder_type = "recurring";
		}
		
		// @since 1.9.4 - BUG FIX: Typo in reminder type supplied
		if ( false === $this->has_local_recurring_membership ) {
			$this->reminder_type = 'expiration';
		}
	}
	
	/**
	 * Return recurring membership status as recorded locally
	 *
	 * @return bool
	 */
	public function get_recurring_membership_status() {
		return $this->has_local_recurring_membership;
	}
	
	/**
	 * Get the User's email address (identifier)
	 *
	 * @return bool|string
	 */
	public function get_user_email() {
		
		if ( isset( $this->user->user_email ) ) {
			return $this->user->user_email;
		}
		
		return false;
	}
	
	/**
	 * Get the user's Display Name
	 *
	 * @return bool|string
	 */
	public function get_user_name() {
		
		if ( isset( $this->user->display_name ) ) {
			return $this->user->display_name;
		}
		
		return false;
	}
	
	/**
	 * Return the user's login name
	 *
	 * @return null|string
	 */
	public function get_user_login() {
		
		if ( isset( $this->user->user_login ) ) {
			return $this->user->user_login;
		}
		
		return null;
	}
	
	/**
	 * Return the membership Level ID
	 *
	 * @return null|int
	 */
	public function get_membership_level_ID() {
		
		$util = Utilities::get_instance();
		
		if ( isset( $this->user->current_membership_level->id ) ) {
			
			return $this->user->current_membership_level->id;
			
		} else {
			
			$util->log( "Loading membership level info for {$this->user->ID}" );
			$this->user = Fetch_User_Data::set_membership_info( $this->user );
			
			$util->log( "Loaded most recent successful/active membership order for user ({$this->user->ID}) and membership level: " . ! empty( $this->user->current_membership_level->id ) ? $this->user->current_membership_level->id : "N/A" );
			
			if ( isset( $this->user->current_membership_level->id ) ) {
				return $this->user->current_membership_level->id;
			}
		}
		
		return null;
	}
	
	/**
	 * Return the membership level name (string) for the user
	 *
	 * @return string|null
	 */
	public function get_level_name() {
		
		if ( isset( $this->user->current_membership_level->name ) ) {
			return $this->user->current_membership_level->name;
		}
		
		return null;
	}
	
	/**
	 * Returns all recorded payment (credit cards) for this user
	 *
	 * @return array
	 */
	public function get_cards() {
		
		return array_keys( $this->credit_card );
	}
	
	/**
	 * Return the credit card info for the current record
	 *
	 * @return array|null
	 */
	public function get_all_payment_info() {
		
		if ( ! empty( $this->credit_card ) ) {
			return $this->credit_card;
		}
		
		return null;
	}
	
	/**
	 * Configure whether user has an active Gateway Subscription plan
	 *
	 * @param bool $status
	 */
	public function set_active_subscription( $status ) {
		
		$this->has_active_subscription = $status;
	}
	
	/**
	 * Test whether the specified card (last 4) number expires sometime between now and the $interval_days value
	 *
	 * @param string $card
	 * @param int    $interval_days
	 *
	 * @return bool
	 */
	public function cc_is_expiring_soon( $card, $interval_days ) {
		
		$now = date_i18n( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
		
		if ( ! isset( $this->credit_card[ $card ] ) ) {
			return false;
		}
		
		$credit_card_exp_date  = "{$this->credit_card[$card]['exp_month']}-{$this->credit_card[$card]['exp_year']}";
		$last_day_of_exp_month = date_i18n( 't', strtotime( "{$credit_card_exp_date}-01", current_time( 'timestamp' ) ) );
		$warning_date          = date_i18n( 'Y-m-d 23:59:59', strtotime( "{$credit_card_exp_date}-{$last_day_of_exp_month} -{$interval_days} day", current_time( 'timestamp' ) ) );
		
		if ( $warning_date <= $now ) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Set the payment plan status for the user ('trialing', 'active', etc)
	 *
	 * @param $status
	 */
	public function set_payment_status( $status ) {
		$this->user_payment_status = $status;
	}
	
	/**
	 * Return the current payment status for the user
	 * @return null
	 */
	public function get_payment_status() {
		return $this->user_payment_status;
	}
	
	/**
	 * Most recent successful order data from local DB
	 *
	 * @return \MemberOrder|null
	 */
	public function get_last_pmpro_order() {
		
		$util     = Utilities::get_instance();
		$user_id  = $this->get_user_ID();
		$level_id = $this->get_membership_level_ID();
		
		if ( empty( $this->last_order ) || empty( $this->last_order->id ) ) {
			
			$order = new \MemberOrder();
			$order->getLastMemberOrder( $user_id, 'success', $level_id );
			
			$util->log( "Attempted to load the most recent successful Order record" );
			
			if ( isset( $order->code ) && ! empty( $order->code ) ) {
				$util->log( "Found order {$order->code} for {$user_id}/{$level_id}" );
				$this->last_order = $order;
			} else {
				$util->log( "After lookup, no active order object found for {$user_id}/{$level_id}!" );
				$this->last_order = null;
			}
		} else if ( ! empty( $this->last_order->code ) ) {
			
			$util->log( "Order found for {$user_id}: {$this->last_order->code} " );
			
		} else {
			$util->log( "No order found for {$user_id}, but user has upstream subscription? " . ( $this->has_active_subscription() ? 'Yes' : 'No' ) );
			$this->last_order = null;
		}
		
		return $this->last_order;
	}
	
	/**
	 * Set the membership status (active/inactive)
	 *
	 * @param bool $status
	 */
	public function set_membership_status( $status ) {
		$this->has_active_membership = $status;
	}
	
	/**
	 * Return the membership status (active/inactive)
	 *
	 * @return bool
	 */
	public function get_membership_status() {
		return $this->has_active_membership;
	}
	
	/**
	 * Return the payment gateway name for the last transaction
	 *
	 * @return null|string
	 */
	public function get_gateway_name() {
		return $this->user_gateway;
	}
	
	/**
	 * Assign the Payment Gateway specific customer ID for this record.
	 *
	 * @param $id_string
	 */
	public function set_gateway_customer_id( $id_string ) {
		
		$this->gateway_customer_id = $id_string;
	}
	
	/**
	 * Get the customer ID as used by the Payment Gateway
	 *
	 * @return null
	 */
	public function get_gateway_customer_id() {
		
		if ( ! empty( $this->gateway_customer_id ) ) {
			return $this->gateway_customer_id;
		}
		
		return null;
	}
	
	/**
	 * Return the User object
	 *
	 * @return null|\WP_User
	 */
	public function get_user() {
		return $this->user;
	}
	
	/**
	 * Plugin deactivation hook function to remove user data tables
	 */
	public static function delete_db_tables() {
		
		$plugin     = Payment_Warning::get_instance();
		$deactivate = $plugin->load_options( 'deactivation_reset' );
		
		if ( true == $deactivate ) {
			
			global $wpdb;
			
			$tables = array( "{$wpdb->prefix}e20rpw_user_info", "{$wpdb->prefix}e20rpw_user_cc" );
			
			foreach ( $tables as $t ) {
				
				$sql = "DROP TABLE {$t}";
				
				$wpdb->query( $sql );
			}
		}
	}
	
	/**
	 * Delete all local credit card info  for a specific user (last 4 + expiration date info)
	 *
	 * @param int $user_id
	 */
	public function clear_card_info( $user_id ) {
		
		global $wpdb;
		
		$util = Utilities::get_instance();
		$util->log( "Clearing all credit card entries for {$user_id}" );
		$wpdb->delete( $this->cc_info_table_name, array( 'user_id' => $user_id ), array( '%d' ) );
	}
	
	/**
	 * Test whether the supplied membership level is a recurring payment membership
	 *
	 * @param \stdClass $level
	 *
	 * @return bool
	 */
	public function is_local_membership_recurring( $level ) {
		
		if ( function_exists( 'pmpro_isLevelRecurring' ) ) {
			return pmpro_isLevelRecurring( $level );
		}
		
		return false;
	}
	
	/**
	 * Record payment status data for charges / non-recurring membership payment(s)
	 *
	 * @param bool   $status      Payment status
	 * @param string $description failure description (if it exists)
	 */
	public function is_payment_paid( $status, $description ) {
		
		if ( true === $status ) {
			
			$this->is_payment_paid     = true;
			$this->is_delinquent       = false;
			$this->user_payment_status = 'active';
			$this->failure_description = null;
		} else {
			$this->is_payment_paid     = false;
			$this->is_delinquent       = true;
			$this->user_payment_status = 'failed';
			$this->failure_description = $description;
		}
	}
	
	/**
	 * Set the date info (use upstream data, and calculate the enddate for the for the payment/membership)
	 *
	 * @param int    $timestamp
	 * @param string $timezone
	 */
	public function set_payment_date( $timestamp, $timezone ) {
		
		$util = Utilities::get_instance();
		
		if ( function_exists( 'date_i18n' ) ) {
			$this->payment_date = date_i18n( 'Y-m-d H:i:s', $timestamp );
		} else {
			$this->payment_date = date( 'Y-m-d H:i:s', $timestamp );
		}
		
		$util->log( "Using payment date value: {$this->payment_date}" );
		
		if ( ! empty( $this->user->current_membership_level->enddate ) ) {
			
			$util->log( "Membership end-date in level definition..." );
			$this->end_of_membership_date = date_i18n( 'Y-m-d H:i:s', $this->user->current_membership_level->enddate );
			
		} else if ( empty( $this->user->current_membership_level->enddate ) ) {
			
			$util->log( "Membership end-date calculated from the default membership level info..." );
			
			// Calculate the end-date based on the membership level this user has (cycle info).
			$level = pmpro_getMembershipLevelForUser( $this->user->ID );
			$util->log( "Member info for {$this->user->ID}: " . print_r( $level, true ) );
			
			if ( ! empty( $level->cycle_number ) ) {
				
				$interval   = "+{$level->cycle_number} {$level->cycle_period}";
				$timestring = "{$this->payment_date} {$timezone} {$interval}";
				
				$util->log( "Using interval setting: {$interval} and date string: {$timestring}" );
				
				$approx_enddate               = strtotime( $timestring, current_time( 'timestamp' ) );
				$this->end_of_membership_date = date_i18n( 'Y-m-d H:i:s', $approx_enddate );
				
				$util->log( "Using a calculated enddate for this member..." );
			} else {
				$util->log( "Membership level for {$this->user->ID} is not recurring!" );
			}
			
		} else {
			
			$util->log( "No membership level cycle info for:" . $this->get_membership_level_ID() );
			update_user_meta( $this->user->ID, 'e20r_pw_error_enddate_error', true );
			
		}
		
		$util->log( "Using membership expiration/end date for ({$this->user->ID}): {$this->end_of_membership_date}" );
	}
	
	/**
	 * Add the Payment Gateway's charge/payment Identifier
	 *
	 * @param $id
	 */
	public function set_charge_info( $id ) {
		$this->gateway_payment_id = $id;
	}
	
	/**
	 * Plugin activation hook function to create required User data tables
	 */
	public static function create_db_tables() {
		
		global $wpdb;
		global $e20rpw_db_version;
		
		if ( $e20rpw_db_version < 1 ) {
			$e20rpw_db_version = 1;
		}
		
		$charset_collate = $wpdb->get_charset_collate();
		$user_info_table = "{$wpdb->prefix}e20rpw_user_info";
		$cc_table        = "{$wpdb->prefix}e20rpw_user_cc";
		$reminder_table  = "{$wpdb->prefix}e20rpw_emails";
		
		$user_info_sql = "
				CREATE TABLE {$user_info_table} (
					ID mediumint(9) NOT NULL AUTO_INCREMENT,
					user_id mediumint(9) NOT NULL,
					level_id mediumint(9) NOT NULL DEFAULT 0,
					last_order_id mediumint(9) NULL,
					gateway_subscr_id varchar(50) NULL,
					gateway_payment_id varchar(50) NULL,
					is_delinquent tinyint(1) DEFAULT 0,
					has_active_subscription tinyint(1) DEFAULT 0,
					has_local_recurring_membership tinyint(1) DEFAULT 0,
					payment_currency varchar(4) NOT NULL DEFAULT 'USD',
					payment_amount varchar(9) NULL,
					next_payment_amount varchar(9) NULL,
					tax_amount varchar(9) NULL,
					user_payment_status varchar(7) NOT NULL DEFAULT 'stopped',
					payment_date datetime NULL,
					is_payment_paid tinyint(1) NULL DEFAULT 0,
					failure_description varchar(255) NULL,
					next_payment_date datetime NULL,
					end_of_payment_period datetime NULL,
					end_of_membership_date datetime NULL,
					reminder_type enum('recurring', 'expiration', 'ccexpiration' ) NOT NULL DEFAULT 'recurring',
					modified DATETIME  NOT NULL,
					PRIMARY KEY (ID),
					INDEX next_payment USING BTREE (next_payment_date),
					INDEX end_of_period USING BTREE (end_of_payment_period),
					INDEX delinquency (is_delinquent),
					INDEX level_ids (level_id),
					INDEX types( reminder_type ),
					INDEX user_levels (user_id, level_id),
					INDEX end_of_payments (user_id, has_active_subscription, end_of_payment_period),
					INDEX modified ( modified )
				) {$charset_collate};";
		
		$cc_table_sql = "
				CREATE TABLE {$cc_table} (
					ID mediumint(9) NOT NULL AUTO_INCREMENT,
					user_id mediumint(9) NOT NULL,
					last4 varchar(4) NOT NULL,
					exp_month int(2) NOT NULL,
					exp_year int(4) NOT NULL,
					brand varchar(18) NOT NULL,
					PRIMARY KEY (ID),
					INDEX last4 ( last4 ),
					INDEX month_year USING BTREE ( exp_month, exp_year ),
					INDEX user_month ( user_id, last4, exp_month ),
					INDEX user_year ( user_id, last4, exp_year ),
					INDEX cc_brands ( brand )
				) {$charset_collate};";
		
		/*
		$notices_sql = "
				CREATE TABLE {$reminder_table} (
					ID mediumint(9) NOT NULL AUTO_INCREMENT,
					user_id mediumint(9) NOT NULL,
					user_info_id mediumint(9) NOT NULL,
					reminder_type varchar(5) NOT NULL,
					reminder_template_key varchar(25) NOT NULL,
					reminder_sent_date DATETIME NULL,
					schedule_value_used int(4) NOT NULL,
					
				)
		";
		*/
		
		$utils = Utilities::get_instance();
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		$update_result = dbDelta( $user_info_sql );
		$utils->log( "Create {$user_info_table} for Payment_Warning - result: " . print_r( $update_result, true ) );
		
		$update_result = dbDelta( $cc_table_sql );
		$utils->log( "Create {$cc_table} for Payment_Warning - result: " . print_r( $update_result, true ) );
		
		/*
		$update_result = dbDelta( $notices_sql );
		$utils->log( "Create {$cc_table} for Payment_Warning - result: " .  print_r( $update_result, true ) );
		*/
		update_option( 'e20rpw_db_version', 1, 'no' );
	}
	
}
