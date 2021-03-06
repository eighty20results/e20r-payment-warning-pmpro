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

namespace E20R\Payment_Warning\Tools;

use E20R\Payment_Warning\Addon\Check_Gateway_Addon;
use E20R\Payment_Warning\Addon\PayPal_Gateway_Addon;
use E20R\Payment_Warning\Addon\Stripe_Gateway_Addon;
use E20R\Payment_Warning\Fetch_User_Data;
use E20R\Payment_Warning\Payment_Reminder;
use E20R\Payment_Warning\Payment_Warning;
use E20R\Utilities\Utilities;
use E20R\Utilities\Cache;

class Cron_Handler {
	
	/**
	 * @var null|Cron_Handler
	 */
	private static $instance = null;
	
	/**
	 * Cron_Handler constructor.
	 */
	private function __construct() {
		
		self::$instance = $this;
		
		if ( function_exists( 'pmpro_getAllLevels' ) ) {
			
			// Clear the cache whenever a membership level definition is edited/saved
			add_action( 'pmpro_save_membership_level', array( $this, 'clear_cache' ), 9999, 0 );
			add_action( 'pmpro_save_discount_code', array( $this, 'clear_cache' ), 9999, 0 );
		}
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
		
		$not_first_run = get_option( 'e20r_pw_firstrun_cc_msg', false );
		
		if ( false === $not_first_run ) {
			$util->log( "Not running on startup!" );
			update_option( 'e20r_pw_firstrun_cc_msg', true, 'no' );
			
			return;
		}
		
		if ( true == Global_Settings::load_options( 'enable_cc_expiration_warnings' ) ) {
			$util->log( "Running send email handler (cron job) for credit card expiration warnings" );
			
			$reminders = Payment_Reminder::get_instance();
			$reminders->process_reminders( 'ccexpiration' );
			
			$util->log( "Triggered sending of Credit Card expiring type email messages" );
		}
		
	}
	
	/**
	 * Send Membership expiration warning message
	 */
	public function send_expiration_messages() {
		
		$util          = Utilities::get_instance();
		$not_first_run = get_option( 'e20r_pw_firstrun_exp_msg', false );
		
		if ( false === $not_first_run ) {
			$util->log( "Not running on startup!" );
			update_option( 'e20r_pw_firstrun_exp_msg', true, 'no' );
			
			return;
		}
		
		if ( true == Global_Settings::load_options( 'enable_expiration_warnings' ) ) {
			$util->log( "Running send email handler (cron job) for expiration warnings" );
			
			$reminders = Payment_Reminder::get_instance();
			$reminders->process_reminders( 'expiration' );
			
			$util->log( "Triggered sending of expiration type email messages" );
		}
	}
	
	/**
	 * Send Payment Reminder warning message(s)
	 */
	public function send_reminder_messages() {
		
		$util          = Utilities::get_instance();
		$not_first_run = get_option( 'e20r_pw_firstrun_reminder_msg', false );
		
		if ( false === $not_first_run ) {
			$util->log( "Not running on startup!" );
			update_option( 'e20r_pw_firstrun_reminder_msg', true, 'no' );
			
			return;
		}
		
		if ( true == Global_Settings::load_options( 'enable_payment_warnings' ) ) {
			
			$util->log( "Running send email handler (cron job) for recurring payment reminders" );
			
			$reminders = Payment_Reminder::get_instance();
			$reminders->process_reminders( 'recurring' );
			
			$util->log( "Triggered sending of reminder type email messages" );
		}
	}
	
	/**
	 * Cron job handler for Fetching upstream Payment Gateway data
	 *
	 * @since 1.9.4 - BUG FIX: Didn't update the e20r_pw_next_gateway_check option value
	 * @since 1.9.11 - REFACTOR: Moved monitoring for background data collection job to fetch_gateway_payment_info
	 *        action
	 */
	public function fetch_gateway_payment_info() {
		
		global $e20r_pw_addons;
		
		$util = Utilities::get_instance();
		$main = Payment_Warning::get_instance();
		
		$not_first_run     = get_option( 'e20r_pw_firstrun_gateway_check', false );
		$schedule_next_run = 0;
		
		if ( false == $not_first_run ) {
			$util->log( "Not running on startup!" );
			update_option( 'e20r_pw_firstrun_gateway_check', true, 'no' );
			
			return;
		}
		
		$util->log( "Running remote data update handler (cron job)" );
		$next_run = intval( get_option( 'e20r_pw_next_gateway_check', null ) );
		
		$util->log( "Next run value found in options? ({$next_run})" );
		
		if ( empty( $next_run ) ) {
			$util->log( "No next run value located in options. Checking scheduled cron jobs manually" );
			$schedule_next_run = $this->configure_cron_schedules();
			$next_run          = $schedule_next_run;
		}
		
		$util->log( "The next time we'll allow this job to trigger is: {$next_run}" );
		$override_schedule = apply_filters( 'e20r_payment_warning_schedule_override', false );
		
		/**
		 * @since 1.9.8 - ENHANCEMENT: Trigger run if there isn't a scheduled 'next' time and we're more than
		 *        24 hours away from the next calculated start time
		 */
		if ( empty( $next_run ) && ( $schedule_next_run > ( current_time( 'timestamp' ) + DAY_IN_SECONDS ) ) ) {
			$override_schedule = true;
			$next_run          = $schedule_next_run;
		}
		
		/**
		 * @since 1.9.11 REFACTOR: Moved monitoring for background data collection job to cron action
		 */
		if ( false === wp_next_scheduled( 'e20r_check_job_status' ) ) {
			
			$util->log( "Adding mutext/job monitoring" );
			wp_schedule_event( ( current_time( 'timestamp' ) + 90 ), 'hourly', 'e20r_check_job_status' );
		}
		
		$util->log( "Schedule override is: " . ( $override_schedule ? 'True' : 'False' ) );
		
		$now      = intval( current_time( 'timestamp' ) );
		$next_run = intval( $next_run );
		
		$util->log( "Next Run: {$next_run} < {$now}? " . ( $next_run < $now ? 'Yes' : 'No' ) );
		
		// After the required amount of time has passed?
		if ( ( $next_run < intval( $now ) ) || ( true === $override_schedule ) ) {
			
			$util->log( "Cron job running to trigger update of existing Payment Gateway data (may have been overridden)" );
			
			$fetch_data = Fetch_User_Data::get_instance();
			
			foreach ( $e20r_pw_addons as $addon_name => $addon_config  ) {
				
				$gateway_name = isset( $addon_config['class_name'] ) ? $addon_config['class_name'] : null;
				
				if ( empty( $gateway_name )) {
					continue;
				}
				
				$class_name = sprintf( 'E20R\Payment_Warning\Addon\%s', $gateway_name );
				
				/**
				 * @var PayPal_Gateway_Addon|Stripe_Gateway_Addon|Check_Gateway_Addon $class_name
				 */
				$class = $class_name::get_instance();
				
				if ( true === $class->is_active( $addon_name ) ) {
					
					// Trigger fetch of subscription data from Payment Gateways
					$util->log( "Triggering remote subscription fetch configuration with " . $addon_name );
					$fetch_data->configure_remote_subscription_data_fetch( strtolower( $addon_config['label'] ) );
					
					
					// Trigger fetch of one-time payment data from Payment Gateways
					$util->log( "Triggering remote payment (expiring memberships) fetch configuration with " . $addon_name );
					$fetch_data->configure_remote_payment_data_fetch( strtolower( $addon_config['label'] ) );
				} else {
					$util->log( "{$addon_name} is either inactive or we didn't find the gateway name (Gateway class: {$gateway_name})" );
				}
				
			}
			
			// Configure when to run this job the next time
			$default_data_collect_start_time = apply_filters( 'e20r_payment_warning_data_collect_time', '02:00:00' );
			$next_ts                         = ( $this->next_scheduled( $default_data_collect_start_time ) - ( 60 * MINUTE_IN_SECONDS ) );
			
			$util->log( "Calculating when to next run the gateway data fetch operation: {$next_ts}" );
			
			// @since 1.9.4 - BUG FIX: Didn't update the e20r_pw_next_gateway_check option value
			delete_option( 'e20r_pw_next_gateway_check' );
			update_option( 'e20r_pw_next_gateway_check', $next_ts, 'no' );
			
			$is_now = intval( get_option( 'e20r_pw_next_gateway_check' ) );
			
			if ( $next_ts != $is_now ) {
				$util->log( "ERROR: Couldn't update the timestamp for the Next Gateway check operation! (Expected: {$next_ts}, received: {$is_now} " );
			}
		} else {
			$util->log( "Not running. Cause: Not after the scheduled next-run time/date of {$next_run}" );
		}
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
		$default_data_collect_start_time = apply_filters( 'e20r_payment_warning_data_collect_time', '02:00:00' );
		$default_send_message_start_time = apply_filters( 'e20r_payment_warning_send_message_time', '05:30:00' );
		$timezone                        = get_option( 'timezone_string' );
		$next_scheduled_collect_run      = "{$default_data_collect_start_time} {$timezone}";
		$next_scheduled_message_run      = "{$default_send_message_start_time} {$timezone}";
		
		// Make sure the trigger time is minimally daily
		$delay_config = ( ! empty( $delay_config ) ? $delay_config : "1" );
		
		$now                  = current_time( 'timestamp' ) + 5;
		$collection_scheduled = wp_next_scheduled( 'e20r_run_remote_data_update' );
		$cc_scheduled         = wp_next_scheduled( 'e20r_send_creditcard_warning_emails' );
		$payment_scheduled    = wp_next_scheduled( 'e20r_send_payment_warning_emails' );
		$exp_scheduled        = wp_next_scheduled( 'e20r_send_expiration_warning_emails' );
		$collect_when         = $this->next_scheduled( $default_data_collect_start_time, false );
		
		// No previously scheduled job for the payment gateway
		if ( false === $collection_scheduled ) {
			
			$util->log( "Cron job for Payment Gateway processing isn't scheduled yet" );
			
			$collect_when = $this->next_scheduled( $default_data_collect_start_time, true );
			$util->log( "Scheduling data collection cron job to start on {$next_scheduled_collect_run}/{$collect_when}" );
			
			wp_schedule_event( $collect_when, 'daily', 'e20r_run_remote_data_update' );
			
			$util->log( "Configure next (allowed) run time for the cron job to be at {$collect_when}" );
			update_option( 'e20r_pw_next_gateway_check', $collect_when, 'no' );
			
		} else {
			$next_run = $collection_scheduled;
			$util->log( "The cron job exists and is scheduled to run at: {$next_run}" );
		}
		
		$util->log( "Scheduling next message transmissions: {$next_scheduled_message_run}" );
		
		$message_when = $this->next_scheduled( $default_send_message_start_time, true );
		
		$util->log( "Scheduling next message transmissions timestamp: {$message_when}" );
		
		if ( false === $cc_scheduled ) {
			
			$util->log( "Cron job for Credit Card Warning isn't scheduled yet. Will use {$message_when}" );
			wp_schedule_event( $message_when, 'daily', 'e20r_send_creditcard_warning_emails' );
		}
		
		if ( false === $payment_scheduled ) {
			$util->log( "Cron job for Next Payment Warning isn't scheduled yet. Will use {$message_when}" );
			wp_schedule_event( $message_when, 'daily', 'e20r_send_payment_warning_emails' );
		}
		
		if ( false === $exp_scheduled ) {
			$util->log( "Cron job for Membership Expiration Warning isn't scheduled yet. Will use {$message_when}" );
			wp_schedule_event( $message_when, 'daily', 'e20r_send_expiration_warning_emails' );
		}
		
		return $collect_when;
	}
	
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
	 * Calculate and return the next scheduled time (timestamp) for a Cron action
	 *
	 * @param string $default_time
	 * @param bool   $instant
	 *
	 * @return int
	 *
	 * @access private
	 */
	private function next_scheduled( $default_time, $instant = false ) {
		
		$util = Utilities::get_instance();
		
		$shortest_delay = $this->find_shortest_recurring_period();
		/**
		 * @since 1.9.17 - BUG FIX: Set default timezone if none is defined in the WordPress settings
		 */
		$timezone              = get_option( 'timezone_string', 'Europe/London' );
		$default_delay_divisor = apply_filters( 'e20r_payment_warning_period_divisor', 2 );
		$next_scheduled_run    = "{$default_time} {$timezone}";
		$delay_config          = ! empty( $shortest_delay ) ? array_shift( $shortest_delay ) : 9999;
		$delay_config          = ( ! empty( $delay_config ) ? $delay_config : "1" );
		$days                  = ceil( ( $delay_config / $default_delay_divisor ) );
		
		$util->log( "Timezone configured as: {$timezone}" );
		
		while ( $days > 7 ) {
			// Increment the divisor and retry the calculation (should minimally check the payment gateway once per week)
			$days = ceil( ( $delay_config / ( ++ $default_delay_divisor ) ) );
		}
		
		$util->log( "Delay config: {$days}" );
		
		try {
			
			// Calculate when the next interval is to happen
			if ( false === $instant ) {
				$util->log( "Configure next interval based on configured days: {$days}" );
				
				$time     = new \DateTime( $next_scheduled_run, new \DateTimeZone( $timezone ) );
				$interval = new \DateInterval( "P{$days}D" );
				$time->add( $interval );
			} else {
				$util->log( "Using next scheduled run value: {$next_scheduled_run}" );
				$time = new \DateTime( $next_scheduled_run, new \DateTimeZone( $timezone ) );
			}
			
		} catch ( \Exception $exception ) {
			$util->log( "DateTime Error: " . $exception->getMessage() );
			
			// Default to 'now' in the GMT timezone
			return current_time( 'timestamp', true );
		}
		
		$util->log( "Next scheduled (based on {$default_time} and whether or not to run without delay (" . ( $instant ? 'yes' : 'no' ) . "): " . $time->getTimestamp() );
		
		return $time->getTimestamp();
	}
	
	/**
	 * Monitor background data collection job(s) and remove stale mutex options if they're done
	 *
	 * @since 1.9.9 - ENHANCEMENT: Clear mutex options (if they exists) once the background jobs are done/have ran
	 * @since 1.9.12 - ENHANCEMENT/FIX: Clear old temporary data/keys/values from options table
	 * @since 2.1 - ENHANCEMENT: Support processing multiple payment gateway at the same time
	 */
	public function clear_mutexes() {
		
		$utils = Utilities::get_instance();
		$main  = Payment_Warning::get_instance();
		
		$addons = $main->get_addons();
		
		$batch_scheduler = wp_next_scheduled( 'e20r_lhr_payments_cron' );
		
		foreach ( $addons as $addon ) {
			
			$payment_mutex       = "e20rpw_paym_fetch_mutex_{$addon}";
			$subscription_mutext = "e20rpw_subscr_fetch_mutex_{$addon}";
			
			/**
			 * @since 1.9.10 - ENHANCEMENT: Faster completion of scheduled job checks
			 */
			$subscr_job  = wp_next_scheduled( "e20r_hs_{$addon}_subscr_cron" );
			$payment_job = wp_next_scheduled( "e20r_hp_{$addon}_paym_cron" );
			
			if ( false === $payment_job && false === $batch_scheduler ) {
				$utils->log( "Removing the Payment Collection mutex: {$payment_mutex}" );
				delete_option( $payment_mutex );
			}
			
			if ( false === $subscr_job && false === $batch_scheduler ) {
				$utils->log( "Removing the Subscriptions Collection mutex: {$subscription_mutext}" );
				delete_option( $subscription_mutext );
			}
			
			if ( false === $payment_job && false === $subscr_job && false === $batch_scheduler ) {
				$utils->log( "None of the data fetch operations are running. Removing the monitoring job!" );
				wp_clear_scheduled_hook( 'e20r_check_job_status' );
				
				global $wpdb;
				
				$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
					'e20r_ar_hp%batch_%', 'e20r_ar_hs%batch_%', 'e20r_ar_lhr%_batch_%' );
				$wpdb->query( $sql );
				
				update_option( "e20r_hp_{$addon}_paym_batch_a", '', 'no' );
				update_option( "e20r_hs_{$addon}_subscr_batch_a", '', 'no' );
				update_option( 'e20r_lhr_subscriptions_batch_a', '', 'no' );
				update_option( 'e20r_lhr_payments_batch_a', '', 'no' );
				
				$utils->log( "Cleared options and temporary data" );
			} else {
				$utils->log( "One or more of the background data collection jobs are active" );
			}
		}
		
		$utils->log( "Done testing if mutexes are done..." );
	}
	
	/**
	 * Create a Cron schedule to run every 30 minutes (for mutex checking)
	 *
	 * @param array $schedules
	 *
	 * @return array
	 *
	 * @since 1.9.9 - ENHANCEMENT: Added Cron schedule for 30 minute repeating check of background data collection
	 *        status
	 */
	public function cron_schedules( $schedules ) {
		
		if ( ! isset( $schedules["30min"] ) ) {
			
			$schedules["30min"] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 30 minutes' ),
			);
		}
		
		return $schedules;
	}
	
	/**
	 * Clear all cache entries
	 */
	public function clear_cache() {
		
		$util = Utilities::get_instance();
		$util->log( "Clearing shortest_recurring_level cache entry" );
		Cache::delete( 'shortest_recurring_level', Payment_Warning::cache_group );
	}
	
}
