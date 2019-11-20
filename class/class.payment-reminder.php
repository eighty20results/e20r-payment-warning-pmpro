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


use E20R\Payment_Warning\Editor\Reminder_Editor;
use E20R\Payment_Warning\Tools\Email_Message;
use E20R\Utilities\E20R_Background_Process;
use E20R\Utilities\Message;
use E20R\Utilities\Utilities;
use DateTime;
use DateTimeZone;
use DateInterval;
use Exception;

class Payment_Reminder {
	
	/**
	 * Instance of the Payment_Reminder class
	 *
	 * @var null|Payment_Reminder $instance
	 */
	private static $instance = null;
	
	/**
	 * The schedule for the payment reminder message
	 *
	 * @var array $schedule
	 */
	private $schedule = array();
	
	/**
	 * Configuration for the payment reminder message
	 *
	 * @var array $settings
	 */
	private $settings = array();
	
	/**
	 * Template to use (slug of Email_Message CPT)
	 *
	 * @var null|string $template_name
	 */
	private $template_name = null;
	
	/**
	 * List of users this payment reminder message belongs to
	 *
	 * @var array $users
	 */
	private $users = array();
	
	/**
	 * Background process handler class
	 *
	 * @var E20R_Background_Process $message_handler
	 */
	private $message_handler;
	
	/**
	 * Payment_Reminders constructor.
	 *
	 * @param $template_key
	 */
	public function __construct( $template_key = null ) {
		
		if ( ! empty( $template_key ) ) {
			
			$this->template_name = $template_key;
			$this->load_schedule();
		}
	}
	
	/**
	 * Fetch / build the schedule of days and email templates to use for the payment warnings
	 *
	 * @return array|bool|mixed
	 * @access private
	 *
	 * @since  2.1 - ENHANCEMENT: Return the send schedule for the active template
	 */
	private function load_schedule() {
		
		$reminder = Reminder_Editor::get_instance();
		
		$this->settings = $reminder->get_template_by_name( $this->template_name, false );
		$this->schedule = $this->settings['schedule'];
		
		return $this->schedule;
	}
	
	/**
	 * Return the instance of this Payment_Reminder() class
	 *
	 * @return Payment_Reminder|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Load action and filter hooks for Payment_Reminder() class
	 *
	 * @since v4.3 - BUG FIX: Didn't trigger the 'e20r-payment-warning-send-email' filter which triggered date check of
	 *        message(s)
	 */
	public function load_hooks() {
		
		add_filter( 'e20r-payment-warning-send-email', array( $this, 'should_send_reminder_message' ), 1, 4 );
	}
	
	/**
	 * Override send status when running in E20R_DEBUG_OVERRIDE mode
	 *
	 * @param true   $send
	 * @param string $comparison_date
	 * @param int    $interval
	 * @param string $type
	 *
	 * @return bool
	 */
	public function send_reminder_override( $send, $comparison_date, $interval, $type ) {
		
		if ( true === $send ) {
			return $send;
		}
		
		if ( date( 'Y-m-d', strtotime( $comparison_date, current_time( 'timestamp' ) ) ) >= date( 'Y-m-d', current_time( 'timestamp' ) ) ) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Determine whether the user's payment date +/- the Interval value means the message should be sent
	 *
	 * @param bool   $send              Whether to send the payment reminder
	 * @param string $user_payment_date Date/Time string for next payment date
	 * @param int    $interval          The number of days before the payment date the message should be sent
	 * @param string $type              The type of reminder being processed
	 *
	 * @return bool
	 *
	 * @since v4.3 - BUG FIX: Didn't always trigger Payment_Reminder::should_send_reminder_message()
	 */
	public function should_send_reminder_message( $send, $user_payment_date, $interval, $type ) {
		
		$util = Utilities::get_instance();
		
		/**
		 * @since v4.3 - ENHANCEMENT: No need to recalculate if the decision had already been made to send the message
		 */
		if ( true === $send ) {
			
			$util->log( "Already decided to send the message, so we're sending it!" );
			
			return $send;
		}
		
		$util->log( "Testing if {$user_payment_date} is within {$interval} days of " . date( 'Y-m-d', current_time( 'timestamp' ) ) );
		
		$negative = ( $interval < 0 ) ? true : false;
		
		try {
			$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
			$now      = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
			
			$user_payment = DateTime::createFromFormat( 'Y-m-d H:i:s', $user_payment_date, $timezone );
			
		} catch ( Exception $e ) {
			
			$util->log( "Error when creating DateTime Object for next User Payment info {$user_payment_date}: " . $e->getMessage() );
			
			return false;
		}
		
		try {
			$current_time = DateTime::createFromFormat( 'Y-m-d H:i:s', $now, $timezone );
		} catch ( Exception $e ) {
			
			$util->log( "Error when creating DateTime Object for Current time: " . $e->getMessage() );
			
			return false;
		}
		
		$interval = absint( $interval );
		
		try {
			
			$interval_string = ( $negative ? "{$interval} days" : "-{$interval} days" );
			$util->log( "Applying interval string: {$interval_string}" );
			
			$increment = DateInterval::createFromDateString( $interval_string );
			
			$user_payment->add( $increment );
			
		} catch ( Exception $e ) {
			
			$util->log( "Error when adding/subtracting the specified interval ({$interval}): " . $e->getMessage() );
			
			return false;
		}
		
		$util->log( "Next payment warning for interval {$interval}: " . $current_time->format( 'Y-m-d' ) . " vs " . $user_payment->format( 'Y-m-d' ) );
		
		return ( $user_payment->format( 'Y-m-d' ) == $current_time->format( 'Y-m-d' ) );
	}
	
	/**
	 * Task handler for Payment email reminders/notices
	 *
	 * @param string|null $type
	 *
	 * @since 2.1 - BUG FIX: Didn't always process all payment warning message types
	 * @since 2.1 - BUG FIX: Didn't dispatch the queue properly
	 */
	public function process_reminders( $type = null ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Received type request: {$type}" );
		
		// Set the default type to recurring if not received
		if ( empty( $type ) ) {
			$type = 'recurring';
		}
		
		switch ( $type ) {
			case 'expiration':
				$target_template = 'expiring';
				$template_type   = E20R_PW_EXPIRATION_REMINDER;
				break;
			case 'recurring':
				$target_template = 'recurring';
				$template_type   = E20R_PW_RECURRING_REMINDER;
				break;
			case 'ccexpiration':
				$target_template = 'ccexpiring';
				$template_type   = E20R_PW_CREDITCARD_REMINDER;
				break;
			default:
				$target_template = null;
				$template_type   = null;
		}
		
		// Unexpected, but something we need to handle!
		if ( empty( $target_template ) || empty( $template_type ) ) {
			$util->log( "Error: No target template or template type was configured!" );
			
			return;
		}
		
		$fetch   = Fetch_User_Data::get_instance();
		$main    = Payment_Warning::get_instance();
		$notices = Reminder_Editor::get_instance();
		
		$users           = $fetch->get_local_user_data( $type );
		$templates       = $notices->configure_cpt_templates( $template_type );
		$message_handler = $main->get_handler( $target_template );
		
		$util->log( "Will process {$type} messages for " . count( $users ) . " members/users and " . count( $templates ) . " email notice templates" );
		$this->set_users( $users );
		
		foreach ( $templates as $template_name => $settings ) {
			
			$this->template_name = $template_name;
			
			/**
			 * @var User_Data $user_info
			 */
			foreach ( $this->users as $user_info ) {
				
				$message = new Email_Message( $user_info, $this->template_name, $user_info->get_reminder_type(), $settings );
				
				$util->log( "Adding user message for " . $message->get_user()->get_user_ID() . " and template: {$this->template_name}" );
				$message_handler->push_to_queue( $message );
			}
		}
		/**
		 * @since 2.1 - BUG FIX: Didn't always save the queue correctly
		 */
		$util->log( "Dispatching the possible send message operation for all users" );
		$message_handler->save()->dispatch();
	}
	
	/**
	 * Handler for the 'e20r-email-notice-send-test-message' action
	 *
	 * @param string $recipient   - Email address of test message recipient
	 * @param int    $notice_type - The Type of notice to use
	 * @param string $notice_slug - The slug/name of the Email Notice to use
	 *
	 * @return bool
	 * @uses 'e20r-email-notice-send-test-message', $recipient, $notice_id, $notice_type
	 *
	 */
	public function send_test_notice( $recipient, $notice_type, $notice_slug ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Trigger Payment Reminder test message for {$recipient}/{$notice_slug}" );
		
		$recipient_user = get_user_by( 'email', $recipient );
		
		// Does the recipient user record exist?
		if ( empty( $recipient_user ) ) {
			$utils->add_message(
				sprintf(
					__( 'Error: %s does not belong to a current user on this system. Can\'t send the test message...', Payment_Warning::plugin_slug ),
					$recipient
				),
				'error',
				'backend'
			);
			
			return false;
		}
		
		// Do we have a test invoice we can use?
		$test_invoice_id = get_option( 'e20rpw_test_invoice_id', null );
		
		// Make sure there is a real order saved for PMPro/this user
		if ( ! empty( $test_invoice_id ) ) {
			$test_invoice = new \MemberOrder( $test_invoice_id );
			if ( empty( $test_invoice->getMemberOrderByID( $test_invoice_id ) ) ) {
				$test_invoice_id = null;
			}
		}
		
		// Insert new test/demo records for use by the test notices
		if ( empty( $test_invoice_id ) ) {
			
			$utils->log( "No test invoice found for {$recipient}. Creating new order/invoice" );
			
			try {
				
				$test_invoice_id = $this->create_test_user_invoice( $recipient_user, $notice_type );
				update_option( 'e20rpw_test_invoice_id', $test_invoice_id, 'no' );
				
				if ( false === $this->insert_example_data( $test_invoice_id, $recipient_user ) ) {
					$utils->log( "Error adding example data to DB!" );
					
					return false;
				}
				
			} catch ( \Exception $e ) {
				$utils->log( "Unable to generate test data: " . $e->getMessage() );
				$utils->add_message(
					sprintf(
						__( 'Error adding user test data: %s', Payment_Warning::plugin_slug ),
						$e->getMessage()
					),
					'error',
					'backend'
				);
				
				return false;
			}
		}
		
		// We should have inserted new demo data!
		if ( ! $test_invoice_id ) {
			$utils->log( "No test invoice info found?!?!" );
			
			return false;
		}
		
		$utils->log( "Test User's Invoice ID: " . print_r( $test_invoice_id, true ) );
		
		// Load the user specific data for this service
		try {
			$test_user_data = $this->get_test_user_info( $test_invoice_id, $recipient_user, $notice_type );
		} catch ( \Exception $exception ) {
			$utils->add_message(
				sprintf(
					__( 'Test user data error: %s', Payment_Warning::plugin_slug ),
					$exception->getMessage()
				),
				'error', '
				backend'
			);
			
			return false;
		}
		
		// Prepare the test email message
		$message = new Email_Message( $test_user_data, $notice_slug, $notice_type );
		
		// Send it and return the status
		return $message->send_message( $notice_type );
	}
	
	/**
	 * Create test user info for the specific Email Notice type
	 *
	 * @param \WP_User $user
	 * @param string   $notice_type
	 *
	 * @return \MemberOrder|bool
	 *
	 * @throws \Exception
	 */
	private function create_test_user_invoice( $user, $notice_type ) {
		
		if ( ! class_exists( '\MemberOrder' ) ) {
			throw new \Exception(
				__( 'Error: The Paid Memberships Pro plugin is not active on this site!', Payment_Warning::plugin_slug )
			);
		}
		
		$utils = Utilities::get_instance();
		
		global $wpdb;
		
		$data       = get_userdata( $user->ID );
		$all_levels = pmpro_getAllLevels( true, true );
		$test_level = null;
		
		# Find a level w/actual payment info
		foreach ( $all_levels as $test_level ) {
			if ( ! pmpro_isLevelFree( $test_level ) && pmpro_isLevelRecurring( $test_level ) ) {
				break;
			}
		}
		
		if ( empty( $test_level ) ) {
			$test_level                    = new \stdClass();
			$test_level->expiration_number = 1;
			$test_level->id                = 1;
			$test_level->expiration_period = 'Month';
			$test_level->initial_payment   = '20.00';
			$test_level->billing_amount    = '20.00';
			$test_level->cycle_number      = 1;
			$test_level->cycle_period      = 'Month';
		}
		
		unset( $all_levels );
		
		$order = new \MemberOrder();
		$order->getEmptyMemberOrder();
		
		$order->user_id          = $user->ID;
		$order->billing          = new \stdClass();
		$order->billing->street  = '123 Test Street';
		$order->billing->city    = 'Testcity';
		$order->billing->zip     = '01234';
		$order->billing->state   = 'Alabama';
		$order->billing->country = 'US';
		$order->billing->phone   = '+1234567890';
		$order->billing->name    = "{$user->first_name} {$user->last_name}";
		
		// Add card info (mock card)
		$order->payment_type                = '';
		$order->session_id                  = null;
		$order->affiliate_id                = null;
		$order->affiliate_subid             = null;
		$order->status                      = '';
		$order->gateway                     = pmpro_getOption( 'gateway' );
		$order->gateway_environment         = pmpro_getOption( 'gateway_environment' );
		$order->payment_transaction_id      = null;
		$order->subscription_transaction_id = null;
		$order->paypal_token                = null;
		$order->certificate_id              = null;
		$order->certificate_amount          = pmpro_formatPrice( 0 );
		$order->cardtype                    = 'Visa';
		$order->accountnumber               = 'XXXXXXXXXXXX4242';
		$order->timestamp                   = current_time( 'timestamp' );
		$order->expirationmonth             = date( 'm', $order->timestamp );
		$order->expirationyear              = date( 'Y', strtotime( '+1 year', $order->timestamp ) );
		$order->datetime                    = date( 'Y-m-d H:i:s', $order->timestamp );
		
		// Set membership expiration info
		if ( function_exists( 'pmpro_isLevelExpiring' ) &&
		     ! pmpro_isLevelRecurring( $test_level ) &&
		     isset( $test_level->expiration_number ) && ! empty( $test_level->expiration_number )
		) {
			
			$expiration_ts          = strtotime(
				"+{$test_level->expiration_number} {$test_level->expiration_period}",
				$order->timestamp
			);
			$order->expirationmonth = date( 'm', $expiration_ts );
			$order->expirationyear  = date( 'y', $expiration_ts );
		}
		
		$order->notes = __(
			"Test order from Custom Member Payment Notices for PMPro.",
			Payment_Warning::plugin_slug
		);
		
		$order->code           = $order->getRandomCode();
		$order->InitialPayment = $test_level->initial_payment;
		$order->subtotal       = (float) $test_level->initial_payment + (float) $test_level->billing_amount;
		$order->tax            = $order->getTax( true );
		$order->total          = $order->subtotal + $order->tax;
		
		// Set the checkout ID
		$max_checkout_id = 0;
		
		$found_max_id = $wpdb->get_var( "SELECT MAX(checkout_id) FROM $wpdb->pmpro_membership_orders" );
		
		if ( ! empty( $found_max_id ) ) {
			$max_checkout_id = $found_max_id;
		}
		
		$order->checkout_id                 = intval( $max_checkout_id ) + 1;
		$order->payment_type                = __( 'Example payment', Payment_Warning::plugin_slug );
		$order->status                      = 'success';
		$order->payment_transaction_id      = 'tx_1234567890abc';
		$order->subscription_transaction_id = 'sub_1234567890abc';
		
		if ( ! isset( $user->membership_level->id ) ) {
			$user->membership_level = $test_level;
		}
		
		$order->membership_id = $user->membership_level->id;
		
		# TODO: Make sure we don't have more invoice data to set/update before we save this order
		$saved_order_id = $order->saveOrder();
		
		$utils->log( "Saved order info: " . print_r( $saved_order_id, true ) );
		
		if ( ! $saved_order_id ) {
			throw new \Exception(
				sprintf(
					__( 'Error saving test order for %s', Payment_Warning::plugin_slug ),
					$user->user_email
				)
			);
		}
		
		return $saved_order_id;
	}
	
	/**
	 * Load sample data to the PW DB tables
	 *
	 * @param int      $test_invoice_id
	 * @param \WP_User $recipient_user
	 *
	 * @return bool
	 */
	private function insert_example_data( $test_invoice_id, $recipient_user ) {
		
		global $wpdb;
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading PMPro order ID {$test_invoice_id}" );
		$pmpro_invoice = new \MemberOrder();
		$pmpro_invoice->getMemberOrderByID( $test_invoice_id );
		
		if ( empty( $pmpro_invoice ) ) {
			$utils->log( "No PMPro order found for {$recipient_user->user_email}" );
			
			return false;
		}
		
		$next_payment_info = $this->test_next_payment( $recipient_user, $pmpro_invoice );
		
		if ( false === $next_payment_info) {
			$utils->log("Invalid payment date returned!");
			$next_payment_info = strtotime( '+1 month', $pmpro_invoice->timestamp );
		}
		
		$next_payment_date = date( 'Y-m-d H:i:s', $next_payment_info );
		
		$utils->log( "Next payment date for {$recipient_user->ID}/order: {$test_invoice_id} is {$next_payment_date}" );
		$recurring_row = array(
			'user_id'                        => $recipient_user->ID,
			'level_id'                       => $recipient_user->membership_level->id,
			'last_order_id'                  => $test_invoice_id,
			'gateway_subscr_id'              => $pmpro_invoice->subscription_transaction_id,
			'gateway_payment_id'             => $pmpro_invoice->payment_transaction_id,
			'is_delinquent'                  => false,
			'has_active_subscription'        => true,
			'has_local_recurring_membership' => true,
			'payment_currency'               => pmpro_getOption( "currency" ),
			'payment_amount'                 => $pmpro_invoice->total,
			'next_payment_amount'            => $pmpro_invoice->total,
			'tax_amount'                     => $pmpro_invoice->tax,
			'user_payment_status'            => $pmpro_invoice->status,
			'payment_date'                   => date( 'Y-m-d H:i:s', $pmpro_invoice->timestamp ),
			'is_payment_paid'                => null,
			'failure_description'            => null,
			'next_payment_date'              => $next_payment_date,
			'end_of_payment_period'          => date( 'Y-m-d 23:59:59', $next_payment_info ),
			'end_of_membership_date'         => null,
			'reminder_type'                  => 'recurring',
			'gateway_module'                 => $pmpro_invoice->gateway,
		);
		
		$expiring_row = array(
			'user_id'                        => $recipient_user->ID,
			'level_id'                       => $recipient_user->membership_level->id,
			'last_order_id'                  => $test_invoice_id,
			'gateway_subscr_id'              => null,
			'gateway_payment_id'             => $pmpro_invoice->payment_transaction_id,
			'is_delinquent'                  => false,
			'has_active_subscription'        => true,
			'has_local_recurring_membership' => true,
			'payment_currency'               => pmpro_getOption( "currency" ),
			'payment_amount'                 => $pmpro_invoice->total,
			'next_payment_amount'            => $pmpro_invoice->total,
			'tax_amount'                     => $pmpro_invoice->tax,
			'user_payment_status'            => $pmpro_invoice->status,
			'payment_date'                   => date( 'Y-m-d H:i:s', $pmpro_invoice->timestamp ),
			'is_payment_paid'                => true,
			'failure_description'            => null,
			'next_payment_date'              => null,
			'end_of_payment_period'          => null,
			'end_of_membership_date'         => date( 'Y-m-d 23:59:59', $next_payment_info ),
			'reminder_type'                  => 'expiration',
			'gateway_module'                 => $pmpro_invoice->gateway,
		);
		
		$cc_sql = $wpdb->prepare(
			"SELECT COUNT(*)
						FROM {$wpdb->prefix}e20rpw_user_cc
						WHERE user_id = %d AND exp_year > %d",
			$recipient_user->ID,
			date( 'Y', current_time( 'timestamp' ) )
		);
		
		$has_cc = $wpdb->get_var( $cc_sql );
		
		if ( $has_cc <= 0 ) {
			$cc_row = array(
				'user_id'   => $recipient_user->ID,
				'last4'     => isset( $pmpro_invoice->accountnumber ) && ! empty( $pmpro_invoice->accountnumber ) ?
					preg_replace( '/X/', '', $pmpro_invoice->accountnumber ) :
					'4242',
				'exp_month' => $pmpro_invoice->expirationmonth,
				'exp_year'  => $pmpro_invoice->expirationyear,
				'brand'     => 'Visa',
			);
			
			$utils->log( "Adding Credit Card info (fake) for {$recipient_user->ID}" );
			
			if ( false === $wpdb->insert( "{$wpdb->prefix}e20rpw_user_cc", $cc_row ) ) {
				$utils->add_message(
					__(
						"Unable to add fake Credit Card info to local database for the test email messages",
						Payment_Warning::plugin_slug
					),
					'warning',
					'backend'
				);
				
				return false;
			}
			
			$utils->log( "Adding Recurring payment info (fake) for {$recipient_user->ID}" );
			if ( false === $wpdb->insert( "{$wpdb->prefix}e20rpw_user_info", $recurring_row ) ) {
				$utils->add_message(
					__(
						"Unable to add fake recurring payment record for the test email messages",
						Payment_Warning::plugin_slug
					),
					'warning',
					'backend'
				);
				
				return false;
			}
			
			$utils->log( "Adding One-time (expiring) payment info (fake) for {$recipient_user->ID}" );
			if ( false === $wpdb->insert( "{$wpdb->prefix}e20rpw_user_info", $expiring_row ) ) {
				$utils->add_message(
					__(
						"Unable to add fake expiring payment record for the test email messages",
						Payment_Warning::plugin_slug
					),
					'warning',
					'backend'
				);
				
				return false;
			}
			
			$utils->log( "Added recurring, expiring and CC test data" );
			
			return true;
		}
	}
	
	/**
	 * Return the timestamp for the next payment date for an order
	 *
	 * @param \WP_User     $user
	 * @param \MemberOrder $order
	 *
	 * @return string | bool
	 */
	private function test_next_payment( $user, $order ) {
		
		$utils = Utilities::get_instance();
		$next_payment_date = false;
		
		if ( ! isset( $user->membership_level->id ) ) {
			$utils->log("No membership ID fround for user!");
			return $next_payment_date;
		}
		
		if ( ! is_object( $order ) && is_a( $order, '\MemberOrder' ) ) {
			$utils->log("Not a valid MemberOrder object!");
			return $next_payment_date;
		}
		
		// Calculate the timestamp for the next payment date (for this order)
		if ( ! empty( $order ) && ! empty( $order->id ) && ! empty( $user->membership_level->id ) && ! empty( $user->membership_level->cycle_number ) ) {
			
			$utils->log("Calculating the next payment date...");
			
			$str               = sprintf( '+%1$s %2%s', $user->membership_level->cycle_number, $user->membership_level->cycle_period );
			$next_payment_date = strtotime( $str, $order->timestamp );
		}
		
		return $next_payment_date;
	}
	
	/**
	 * @param $invoice_id
	 * @param $user
	 * @param $notice_type
	 *
	 * @return User_Data
	 */
	private function get_test_user_info( $invoice_id, $user, $notice_type ) {
		
		$order = new \MemberOrder();
		$order->getMemberOrderByID( $invoice_id );
		
		
		$test_user_info = new User_Data( $user, $order, $notice_type );
		
		return $test_user_info;
	}
	
	/**
	 * Return the current list of user IDs
	 *
	 * @return array|null
	 */
	public function get_users() {
		
		if ( ! empty( $this->users ) ) {
			return $this->users;
		}
		
		return null;
	}
	
	/**
	 * Update the list of user IDs
	 *
	 * @param array $users
	 */
	public function set_users( $users ) {
		
		$this->users = $users;
	}
	
	/**
	 * Generate a random alphanumeric string of a specified length
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	private function generateRandomString( $length ) {
		$characters       = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen( $characters );
		$randomString     = '';
		for ( $i = 0; $i < $length; $i ++ ) {
			$randomString .= $characters[ rand( 0, $charactersLength - 1 ) ];
		}
		
		return $randomString;
	}
	
	/**
	 * Create an order for the user (test order)
	 *
	 * @param \WP_User $user
	 * @param array    $data
	 *
	 * @throws Exception
	 */
	private function create_test_order( $user, $data ) {
		
		global $current_user, $pmpro_currency_symbol, $wpdb;
		
		if ( ! empty( $data ) && ! empty( $data['user_login'] ) ) {
			$user = get_user_by( 'login', $data['user_login'] );
		}
		
		if ( empty( $user ) ) {
			$user = $current_user;
		}
		
		$pmpro_user_meta =
			$wpdb->get_row(
				$wpdb->prepare(
					"SELECT *
							FROM {$wpdb->pmpro_memberships_users} AS pmpu
							WHERE pmpu.user_id = %d AND pmpu.status= %s",
					$user->ID,
					'active'
				)
			);
		
		// Verify that we've got data
		if ( ! is_array( $data ) ) {
			$data =
				array(
					'',
				);
			
		}
		
		// Site specific data
		$new_data['sitename']  = get_option( "blogname" );
		$new_data['siteemail'] = pmpro_getOption( "from_email" );
		
		// Add the login link (unless configured)
		if ( empty( $new_data['login_link'] ) ) {
			$new_data['login_link'] = wp_login_url();
		}
		
		// Get the levels page link
		$new_data['levels_link'] = pmpro_url( "levels" );
		
		// Test user data
		if ( isset( $user->ID ) && ! empty( $user->ID ) ) {
			$new_data['name']         = $user->display_name;
			$new_data['user_login']   = $user->user_login;
			$new_data['display_name'] = $user->display_name;
			$new_data['user_email']   = $user->user_email;
		} else {
			
			throw new \Exception( __( 'Test user information is missing', Payment_Warning::plugin_slug ) );
		}
		
		// Grab membership data for the user (if it exists)
		if ( ! empty( $user->membership_level ) &&
		     isset( $user->membership_level->enddate ) &&
		     ! empty( $user->membership_level->enddate )
		) {
			$new_data['enddate'] = date( 'Y-m-d H:i:s', $user->membership_level->enddate );
		} else {
			
			// Pick a date in the future (+1 year from today)
			$new_data['enddate'] = date(
				'Y-m-d H:i:s',
				strtotime( '+1 year', current_time( 'timestamp' ) )
			);
		}
		
		// Invoice specific data
		if ( ! empty( $data['invoice_id'] ) ) {
			
			$invoice = new \MemberOrder( $data['invoice_id'] );
			
			if ( ! empty( $invoice ) && ! empty( $invoice->code ) ) {
				$new_data['billing_name']    = $invoice->billing->name;
				$new_data['billing_street']  = $invoice->billing->street;
				$new_data['billing_city']    = $invoice->billing->city;
				$new_data['billing_state']   = $invoice->billing->state;
				$new_data['billing_zip']     = $invoice->billing->zip;
				$new_data['billing_country'] = $invoice->billing->country;
				$new_data['billing_phone']   = $invoice->billing->phone;
				$new_data['cardtype']        = $invoice->cardtype;
				$new_data['accountnumber']   = hideCardNumber( $invoice->accountnumber );
				$new_data['expirationmonth'] = $invoice->expirationmonth;
				$new_data['expirationyear']  = $invoice->expirationyear;
				$new_data['instructions']    = wpautop( pmpro_getOption( 'instructions' ) );
				$new_data['invoice_id']      = $invoice->code;
				$new_data['invoice_total']   = $pmpro_currency_symbol . number_format( $invoice->total, 2 );
				$new_data['invoice_link']    = pmpro_url( 'invoice', '?invoice=' . $invoice->code );
				
				//billing address
				$new_data["billing_address"] = pmpro_formatAddress( $invoice->billing->name,
					$invoice->billing->street,
					"", //address 2
					$invoice->billing->city,
					$invoice->billing->state,
					$invoice->billing->zip,
					$invoice->billing->country,
					$invoice->billing->phone );
			}
		}
		
		//membership change
		if ( ! empty( $user->membership_level ) && ! empty( $user->membership_level->ID ) ) {
			$new_data["membership_change"] = sprintf( __( "The new level is %s.", "pmproet" ), $user->membership_level->name );
		} else {
			$new_data["membership_change"] = __( "Your membership has been cancelled", "pmproet" );
		}
		if ( ! empty( $user->membership_level ) && ! empty( $user->membership_level->enddate ) ) {
			$new_data["membership_change"] .= ". " . sprintf( __( "This membership will expire on %s", "pmproet" ), date( get_option( 'date_format' ), $user->membership_level->enddate ) );
		} else if ( ! empty( $user->expiration_changed ) ) { // FIXME: Should be using the email test
			$new_data["membership_change"] .= ". " . __( "This membership does not expire", "pmproet" );
		}
		//membership expiration
		$new_data['membership_expiration'] = '';
		if ( ! empty( $pmpro_user_meta->enddate ) ) {
			$new_data['membership_expiration'] = "<p>" . sprintf( __( "This membership will expire on %s.", "pmproet" ), $pmpro_user_meta->enddate . "</p>\n" );
		}
		//if others are used in the email look in usermeta
		$et_body            = pmpro_getOption( 'email_' . $email->template . '_body' );
		$templates_in_email = preg_match_all( "/!!([^!]+)!!/", $et_body, $matches );
		if ( ! empty( $templates_in_email ) ) {
			$matches = $matches[1];
			foreach ( $matches as $match ) {
				if ( empty( $new_data[ $match ] ) ) {
					$usermeta = get_user_meta( $user->ID, $match, true );
					if ( ! empty( $usermeta ) ) {
						if ( is_array( $usermeta ) && ! empty( $usermeta['fullurl'] ) ) {
							$new_data[ $match ] = $usermeta['fullurl'];
						} else if ( is_array( $usermeta ) ) {
							$new_data[ $match ] = implode( ", ", $usermeta );
						} else {
							$new_data[ $match ] = $usermeta;
						}
					}
				}
			}
		}
		//now replace any new_data not already in data
		foreach ( $new_data as $key => $value ) {
			if ( ! isset( $data[ $key ] ) ) {
				$data[ $key ] = $value;
			}
		}
	}
}
