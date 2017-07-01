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

namespace E20R\Payment_Warning\Utilities;

use E20R\Payment_Warning\Fetch_User_Data;
use E20R\Payment_Warning\Payment_Reminder;
use E20R\Payment_Warning\Payment_Warning;

class Cron_Handler {
	
	/**
	 * @var null|Cron_Handler
	 */
	private static $instance = null;
	
	/**
	 * Identify the shortest billing cycle for the membership levels on this system
	 *
	 * @return array|null
	 */
	public function find_shortest_recurring_period() {
		
		$util = Utilities::get_instance();
		
		if ( null === ( $shortest_period = Cache::get( 'shortest_recurring_level', Payment_Warning::cache_group ) ) ) {
			
			if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
				$util->log( "PMPro not found. Returning error!" );
				
				return null;
			}
			
			$level_list = pmpro_getAllLevels( true, true );
			$shortest   = 9999;
			
			foreach ( $level_list as $level ) {
				
				$all_delays = Utilities::get_membership_start_delays( $level );
				
				if ( empty( $all_delays ) ) {
					$util->log( "Skipping subscription delay handling for {$level->id}" );
					continue;
				}
				
				// Iterate through all delay values for this membership level, and select the shortest one
				foreach ( $all_delays as $type => $days ) {
					
					if ( $shortest > $days ) {
						
						$util->log( "Delay of {$days} is shorter than {$shortest} so we'll select level {$level->id} as the current level with the shortest delay" );
						
						$shortest        = $days;
						$shortest_period = array( $level->id => $shortest );
					}
				}
			}
			
			if ( ! empty( $level_id ) ) {
				Cache::set( 'shortest_recurring_level', $shortest_period, WEEK_IN_SECONDS, Payment_Warning::cache_group );
			}
		}
		
		$util->log( "Returning as the shortest recurring level on this server: " . print_r( $shortest_period, true ) );
		
		return $shortest_period;
	}
	
	/**
	 * Configure the cron schedule for the remote data fetch (by gateway)
	 *
	 * @return false|int
	 */
	public function configure_cron_schedules() {
		
		$util = Utilities::get_instance();
		
		$util->log( "Determine shortest recurring payment period on system" );
		
		$shortest                        = $this->find_shortest_recurring_period();
		$delay_config                    = ! empty( $shortest ) ? array_shift( $shortest ) : 9999;
		$default_delay_divisor           = apply_filters( 'e20r_payment_warning_period_divisor', 2 );
		$default_data_collect_start_time = apply_filters( 'e20r_payment_warning_data_collect_time', '02:00:00' );
		$default_send_message_start_time = apply_filters( 'e20r_payment_warning_send_message_time', '05:30:00' );
		$todays_date                     = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
		$timezone                        = get_option( 'timezone_string' );
		
		$now                = current_time( 'timestamp' );
		$scheduled          = strtotime( date( $default_data_collect_start_time, current_time( 'timestamp' ) ) );
		$next_scheduled_collect_run = ( $now >= $scheduled ) ? "{$default_data_collect_start_time} {$timezone} today" : "{$default_data_collect_start_time} {$timezone} tomorrow";
		$next_scheduled_message_run = ( $now >= $scheduled ) ? "{$default_send_message_start_time} {$timezone} today" : "{$default_send_message_start_time} {$timezone} tomorrow";
		
		$util->log( "Delay config: {$delay_config}" );
		
		// Make sure the trigger time is minimally daily
		$delay_config = ( ! empty( $delay_config ) ? $delay_config : "1" );
		
		$now               = current_time( 'timestamp' ) + 5;
		$is_scheduled      = wp_next_scheduled( 'e20r_run_remote_data_update' );
		$cc_scheduled      = wp_next_scheduled( 'e20r_send_creditcard_warning_emails' );
		$payment_scheduled = wp_next_scheduled( 'e20r_send_payment_warning_emails' );
		$exp_scheduled     = wp_next_scheduled( 'e20r_send_expiration_warning_emails' );
		$days              = ceil( ( $delay_config / $default_delay_divisor ) );
		
		while ( $days > 7 ) {
			// Increment the divisor and retry the calculation (should minimally check the payment gateway once per week)
			$days = ceil( ( $delay_config / ( ++ $default_delay_divisor ) ) );
		}
		
		// No previously scheduled job for the payment gateway
		if ( false == $is_scheduled ) {
			
			$util->log( "Cron job for Payment Gateway processing isn't scheduled yet" );
			
			$util->log( "Using day value of {$days} after a start of {$todays_date} {$default_data_collect_start_time}" );
			$next_run = strtotime( "{$todays_date} {$default_data_collect_start_time} {$timezone} +{$days} days", $now );
			wp_schedule_event( $next_scheduled_collect_run, 'daily', 'e20r_run_remote_data_update' );
			
			$util->log( "Configure next (allowed) run time for the cron job to be at {$next_run}" );
			add_option( 'e20r_pw_next_gateway_check', $next_run );
			
		} else {
			$next_run = $is_scheduled;
			$util->log( "The cron job exists and is scheduled to run at: {$next_run}" );
		}
		
		if ( false === $cc_scheduled ) {
			$util->log( "Cron job for Credit Card Warning isn't scheduled yet" );
			wp_schedule_event( strtotime( $next_scheduled_message_run, current_time( 'timestamp' ) ), 'daily', 'e20r_send_creditcard_warning_emails' );
		}
		
		if ( false === $payment_scheduled ) {
			wp_schedule_event( strtotime( $next_scheduled_message_run, current_time( 'timestamp' ) ), 'daily', 'e20r_send_payment_warning_emails' );
		}
		
		if ( false === $exp_scheduled ) {
			wp_schedule_event( strtotime( $next_scheduled_message_run, current_time( 'timestamp' ) ), 'daily', 'e20r_send_expiration_warning_emails' );
		}
		
		return $next_run;
	}
	
	/**
	 * Clear scheduled cron jobs for this plugin
	 */
	public function remove_cron_jobs() {
		
		$util = Utilities::get_instance();
		
		$util->log( "Attempting to remove the remote data update cron job" );
		
		// Clear all running background jobs
		wp_clear_scheduled_hook( 'e20r_ar_hs_fetch_user_data_cron' );
		wp_clear_scheduled_hook( 'e20r_ar_hp_fetch_user_data_cron' );
		wp_clear_scheduled_hook( 'e20r_ar_hm_payment_reminder_cron' );
		
		// Clear scheduled (regular) work that triggers background jobs
		wp_clear_scheduled_hook( 'e20r_run_remote_data_update' );
		wp_clear_scheduled_hook( 'e20r_send_payment_warning_emails' );
		wp_clear_scheduled_hook( 'e20r_send_expiration_warning_emails' );
		wp_clear_scheduled_hook( 'e20r_send_creditcard_warning_emails' );
	}
	
	/**
	 * Send Warning messages about expiring credit cards
	 */
	public function send_cc_warning_messages() {
		
		$util = Utilities::get_instance();
		$util->log( "Running send email handler (cron job) for credit card expiration warnings" );
		
		$override_schedule = apply_filters( 'e20r_payment_warming_schedule_override', true );
		$util->log( "Email schedule override is: " . ( $override_schedule ? 'True' : 'False' ) );
		
		$reminders = Payment_Reminder::get_instance();
		$reminders->process_reminders( 'ccexpiration' );
		
		$util->log( "Triggered sending of Credit Card expiring type email messages" );
		
	}
	
	/**
	 * Send Membership expiration warning message
	 */
	public function send_expiration_messages() {
		
		$util = Utilities::get_instance();
		$util->log( "Running send email handler (cron job) for expiration warnings" );
		
		$override_schedule = apply_filters( 'e20r_payment_warming_schedule_override', true );
		$util->log( "Email schedule override is: " . ( $override_schedule ? 'True' : 'False' ) );
		
		$reminders = Payment_Reminder::get_instance();
		$reminders->process_reminders( 'expiration' );
		
		$util->log( "Triggered sending of expiration type email messages" );
		
	}
	
	/**
	 * Send Payment Reminder warning message(s)
	 */
	public function send_reminder_messages() {
		
		$util = Utilities::get_instance();
		$util->log( "Running send email handler (cron job)" );
		
		$override_schedule = apply_filters( 'e20r_payment_warming_schedule_override', true );
		$util->log( "Email schedule override is: " . ( $override_schedule ? 'True' : 'False' ) );
		
		$reminders = Payment_Reminder::get_instance();
		$reminders->process_reminders( 'recurring' );
		
		$util->log( "Triggered sending of reminder type email messages" );
	}
	
	/**
	 * Cron job handler for Fetching upstream Payment Gateway data
	 */
	public function fetch_gateway_payment_info() {
		
		$util = Utilities::get_instance();
		$util->log( "Running remote data update handler (cron job)" );
		$next_run = get_option( 'e20r_pw_next_gateway_check', null );
		
		if ( is_null( $next_run ) ) {
			$util->log( "No next run value located in options. Checking scheduled cron jobs manually" );
			$next_run = $this->configure_cron_schedules();
		}
		
		$util->log( "The next time we'll allow this job to trigger is: {$next_run}" );
		$override_schedule = apply_filters( 'e20r_payment_warming_schedule_override', true );
		
		$util->log( "Schedule override is: " . ( $override_schedule ? 'True' : 'False' ) );
		
		// After the required amount of time has passed?
		if ( current_time( 'timestamp' ) >= $next_run || true === $override_schedule ) {
			
			$util->log( "Cron job running to trigger update of existing Payment Gateway data" );
			$fetch_data = Fetch_User_Data::get_instance();
			$fetch_data->get_remote_data();
			$util->log( "Triggered remote data fetch configuration" );
		} else {
			$util->log( "Not after the scheduled next-run time/date of {$next_run}" );
		}
	}
	
	/**
	 * Clear all cache entries
	 */
	public function clear_cache() {
		
		$util = Utilities::get_instance();
		$util->log( "Clearing shortest_recurring_level cache entry" );
		Cache::delete( 'shortest_recurring_level', Payment_Warning::cache_group );
	}
	
	/**
	 * Returns the current instance of the class
	 *
	 * @return Cron_Handler|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Cron_Handler constructor.
	 */
	private function __construct() {
		
		self::$instance = $this;
		
		if ( function_exists( 'pmpro_getAllLevels' ) ) {
			
			// Clear the cache whenever a membership level definition is edited/saved
			add_action( 'pmpro_save_membership_level', array( self::$instance, 'clear_cache' ), 9999, 0 );
			add_action( 'pmpro_save_discount_code', array( self::$instance, 'clear_cache' ), 9999, 0 );
		}
	}
	
}