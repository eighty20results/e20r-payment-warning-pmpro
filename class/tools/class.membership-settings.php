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

use E20R\Utilities\Cache;
use E20R\Utilities\Utilities;
use E20R\Payment_Warning\Payment_Warning;

class Membership_Settings {
	
	/**
	 * Instance of this class (Membership_Settings)
	 *
	 * @var Membership_Settings|null $instance
	 *
	 * @access private
	 * @since  1.0
	 */
	static private $instance = null;
	
	/**
	 * Membership_Settings constructor.
	 *
	 * @access private
	 * @since  1.0
	 */
	private function __construct() {}
	
	/**
	 * Returns the instance of this class (singleton pattern)
	 *
	 * @return Membership_Settings
	 *
	 * @access public
	 * @since  1.0
	 */
	static public function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Clear the level delay cache info on membership level save operation(s)
	 *
	 * @param $level_id
	 */
	public function updated_membership_level( $level_id ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Dropping the cache for delay & cron schedules due to a membership level being updated" );
		// Clear cached values when discount code(s) get updated
		Cache::delete( "start_delay_{$level_id}", Utilities::get_util_cache_key() );
		Cache::delete( "shortest_recurring_level", Payment_Warning::cache_group );
		update_option( 'e20r_pw_next_gateway_check', null, 'no' );
	}
	
	/**
	 * Force calculation of next cron scheduled run whenever saving/updating a Discount Code
	 *
	 * @param int $discount_code_id
	 * @param int $level_id
	 */
	public function updated_discount_codes( $discount_code_id, $level_id ) {
		
		$util = Utilities::get_instance();
		
		// Clear cached values when discount code(s) get updated
		Cache::delete( "start_delay_{$level_id}", Utilities::get_util_cache_key() );
		Cache::delete( "shortest_recurring_level", Payment_Warning::cache_group );
		update_option( 'e20r_pw_next_gateway_check', null, 'no' );
		
		$util->log( "Dropping the cache for delay & cron schedules due to Discount Code being updated" );
	}
	
	/**
	 * Generates the PMPro Membership Level Settings section
	 */
	public function level_settings_page() {
		
		$controller = Payment_Warning::get_instance();
		$active_addons = $controller->get_active_addons();
		
		$level_id      = isset( $_REQUEST['edit'] ) ? intval( $_REQUEST['edit'] ) : ( isset( $_REQUEST['copy'] ) ? intval( $_REQUEST['copy'] ) : null );
		?>
		<div class="e20r-pw-for-pmpro-level-settings">
			<h3 class="topborder"><?php _e( 'Custom Payment Reminders for Paid Memberships Pro (by Eighty/20 Results)', self::plugin_slug ); ?></h3>
			<hr style="width: 90%; border-bottom: 2px solid #c5c5c5;"/>
			<h4 class="e20r-pw-for-pmpro-section"><?php _e( 'Default gateway settings', Payment_Warning::plugin_slug ); ?></h4>
			<?php do_action( 'e20r_pw_level_settings', $level_id, $active_addons ); ?>
		</div>
		<?php
	}
	
	/**
	 * Global save_level_settings function (calls add-on specific save code)
	 *
	 * @param $level_id
	 */
	public function save_level_settings( $level_id ) {
		
		$controller = Payment_Warning::get_instance();
		
		$active_addons = $controller->get_active_addons();
		
		do_action( 'e20r_pw_level_settings_save', $level_id, $active_addons );
	}
	
	/**
	 * Global delete membership level function (calls add-on specific save code)
	 *
	 * @param int $level_id
	 */
	public function delete_level_settings( $level_id ) {
		
		$controller = Payment_Warning::get_instance();
		$active_addons = $controller->get_active_addons();
		
		do_action( 'e20r_pw_level_settings_delete', $level_id, $active_addons );
	}
}
