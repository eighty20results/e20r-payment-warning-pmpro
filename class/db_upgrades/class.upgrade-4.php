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

namespace E20R\Payment_Warning\Upgrades;

use E20R\Utilities\Utilities;

class Upgrade_4 {
	
	public function __construct() {
		
		global $e20rpw_db_version;
		add_action( 'e20rpw_trigger_database_upgrade_4', array( $this, 'load_upgrade'), $e20rpw_db_version );
	}
	
	public function load_upgrade( $current_version ) {
		
		global $wpdb;
		global $e20rpw_db_version;
		
		$utils = Utilities::get_instance();
		
		$charset_collate = $wpdb->get_charset_collate();
		$user_info_table = "{$wpdb->prefix}e20rpw_user_info";
		$success = true;
		
		$sql = array();
		$sql[] = $wpdb->prepare( "SET @sql = (
		SELECT IF(
			(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND table_schema = %s AND column_name = %s) > 0,
			\"SELECT 0\",
			\"ALTER TABLE {$user_info_table} ADD COLUMN gateway_module varchar(255) NULL AFTER reminder_type;\"
		))",
			$user_info_table,
			DB_NAME,
			'gateway_module'
		);
		$sql[] = "PREPARE stmt FROM @sql";
		$sql[] = "EXECUTE stmt";
		$sql[] = "DEALLOCATE PREPARE stmt";
		$sql[] = $wpdb->prepare( "SET @sql = (
		SELECT IF(
			(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s) > 0,
			\"SELECT 0\",
			\"ALTER TABLE {$user_info_table} ADD INDEX gateway_module USING BTREE ( gateway_module )\"
			))",
			DB_NAME,
		$user_info_table,
		'gateway_module'
			);
		$sql[] = "PREPARE stmt FROM @sql";
		$sql[] = "EXECUTE stmt";
		$sql[] = "DEALLOCATE PREPARE stmt";
		
		if ( intval($current_version ) < $e20rpw_db_version ) {
			
			foreach( $sql as $update ) {
				
				$result = $wpdb->query( $update );
				
				if ( false === $result ) {
					$success = false;
				}
				
				$utils->log("Update to v{$e20rpw_db_version} of the database tables - result: " . print_r( $result, true ));
			}

			if ( true == $success ) {
				$utils->log("Database upgraded to version 4");
				update_option( "e20rpw_db_version", 4, 'no' );
			}
		}
	}
}
