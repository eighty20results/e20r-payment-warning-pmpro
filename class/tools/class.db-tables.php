<?php
/**
 * Copyright (c) 2018 - Eighty / 20 Results by Wicked Strong Chicks.
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

use E20R\Utilities\Utilities;

class DB_Tables {
	
	/**
	 * Plugin activation hook function to create required plugin data tables
	 */
	public static function create() {
		
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
				CREATE TABLE IF NOT EXISTS {$user_info_table} (
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
					gateway_module varchar(255) NULL,
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
				CREATE TABLE IF NOT EXISTS {$cc_table} (
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
				CREATE TABLE IF NOT EXISTS {$reminder_table} (
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
	
	/**
	 * Plugin deactivation hook function to remove plugin data tables
	 */
	public static function remove() {
		
		$deactivate = Global_Settings::load_options( 'deactivation_reset' );
		
		if ( true == $deactivate ) {
			
			global $wpdb;
			
			$tables = array( "{$wpdb->prefix}e20rpw_user_info", "{$wpdb->prefix}e20rpw_user_cc" );
			
			foreach ( $tables as $t ) {
				
				$sql = "DROP TABLE {$t}";
				
				$wpdb->query( $sql );
			}
		}
	}
}