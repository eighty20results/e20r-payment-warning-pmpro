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

namespace E20R\Payment_Warning\Editor;


use E20R\Payment_Warning\Payment_Warning;
use E20R\Utilities\Editor\Editor;
use E20R\Utilities\Utilities;

class Reminder_Editor extends Editor {
	
	/**
	 * @var null|Reminder_Editor
	 */
	private static $instance = null;
	
	/**
	 * Reminder_Editor constructor.
	 */
	public function __construct() {
		parent::__construct();
	}
	
	/**
	 * Fetch instance of the Editor (child) class
	 *
	 * @return Reminder_Editor|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			parent::get_instance();
		}
		
		
		
		return self::$instance;
	}
	
	/**
	 * Convert existing templates & message files to the E20R Editor template(s)
	 */
	public function convert_messages() {
		
		$utils = Utilities::get_instance();
		
		if ( ! term_exists( 'e20r_pw_emails', Editor::plugin_slug ) ) {
			
			$new_term = wp_insert_term(
				'e20r_pw_emails',
				Editor::plugin_slug,
				array(
					'slug'        => 'e20r-payment-warnings',
					'description' => __( "Payment Warnings for PMPro", Payment_Warning::plugin_slug )
				)
			);
			
			if ( is_wp_error( $new_term ) ) {
				$utils->log( "Error creating new taxonomy term: " . $new_term->get_error_message() );
				return false;
			}
		}
		
		//TODO: Get all existing pw_emails, check the slug against the
	}
	
	public function default_templates() {
		
		$templates = array(
			'messageheader'      => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'schedule'       => array(),
				'data_variables' => array(),
				'description'    => __( 'Standard Header for Messages', Editor::plugin_slug ),
				'file_name'      => 'messageheader.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'messagefooter'      => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => array(),
				'description'    => __( 'Standard Footer for Messages', Editor::plugin_slug ),
				'file_name'      => 'messagefooter.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'recurring_default'  => array(
				'subject'        => sprintf( __( "Your upcoming recurring membership payment for %s", Editor::plugin_slug ), '!!sitename!!' ),
				'active'         => true,
				'type'           => 'recurring',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(), 'recurring', Payment_Warning::plugin_slug ),
				'description'    => __( 'Recurring Payment Reminder', Editor::plugin_slug ),
				'file_name'      => 'recurring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'expiring_default'   => array(
				'subject'        => sprintf( __( "Your membership at %s will end soon", Editor::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => 'expiration',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(),  'expiration', Payment_Warning::plugin_slug ),
				'description'    => __( 'Membership Expiration Warning', Editor::plugin_slug ),
				'file_name'      => 'expiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
			'ccexpiring_default' => array(
				'subject'        => sprintf( __( "Credit Card on file at %s expiring soon", Editor::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => 'ccexpiration',
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => $this->load_schedule( array(), 'expiration', Payment_Warning::plugin_slug ),
				'description'    => __( 'Credit Card Expiration', Editor::plugin_slug ),
				'file_name'      => 'ccexpiring.html',
				'file_path'      => apply_filters( 'e20r-payment-warning-template-path', E20R_WP_TEMPLATES ),
			),
		);
		
		return apply_filters( 'e20r_pw_default_email_templates', $templates );
	}
	
	/**
	 * Return the current settings for the specified message template
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 *
	 * @access private
	 */
	public function load_template_settings( $template_name, $load_body = false ) {
		
		$util = Utilities::get_instance();
		
		$util->log( "Loading Message templates for {$template_name}" );
		
		// TODO: Load existing PMPro templates that apply for this editor
		$pmpro_email_templates = apply_filters( 'pmproet_templates', array() );
		
		$template_info = get_option( $this->option_name, $this->default_templates() );
		
		if ( 'all' === $template_name ) {
			
			if ( true === $load_body ) {
				
				foreach ( $template_info as $name => $settings ) {
					
					if ( empty( $name ) ) {
						unset( $template_info[ $name ] );
						continue;
					}
					
					$util->log( "Loading body for {$name}" );
					
					if ( empty( $settings['body'] ) ) {
						
						$util->log( "Body has no content, so loading from default template: {$name}" );
						$settings['body']       = $this->load_default_template_body( $name );
						$template_info[ $name ] = $settings;
					}
				}
			}
			
			return $template_info;
		}
		
		// Specified template settings not found so have to return the default settings
		if ( $template_name !== 'all' && ! isset( $template_info[ $template_name ] ) ) {
			
			$template_info[ $template_name ] = $this->default_template_settings( $template_name );
			
			// Save the new template info
			update_option( $this->option_name, $template_info, 'no' );
		}
		
		if ( $template_name !== 'all' && true === $load_body && empty( $template_info[ $template_name ]['body'] ) ) {
			
			$template_info[ $template_name ]['body'] = $this->load_default_template_body( $template_name );
		}
		
		return $template_info[ $template_name ];
	}
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	public function default_template_settings( $template_name ) {
		
		return array(
			'subject'        => null,
			'active'         => false,
			'type'           => 'recurring',
			'body'           => null,
			'data_variables' => array(),
			'schedule'       => $this->default_schedule(array(), 'recurring', Payment_Warning::plugin_slug ),
			'description'    => null,
			'file_name'      => "{$template_name}.html",
			'file_path'      => dirname( E20R_PW_DIR ) . '/templates',
		);
	}
	
	/**
	 * Set/select the default reminder schedule based on the type of reminder
	 *
	 * @param array $schedule
	 * @param string $type
	 * @param string $slug
	 *
	 * @return array
	 */
	public function load_schedule( $schedule, $type = 'recurring', $slug ) {
		
		if ( $slug !== Payment_Warning::plugin_slug ) {
			return $schedule;
		}
		
		switch ( $type ) {
			case 'recurring':
				
				$pw_schedule = array_keys( apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
					7 => 'membership_recurring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-recurring-reminder-schedule', $pw_schedule );
				break;
			
			case 'expiring':
				$pw_schedule = array_keys( apply_filters( 'pmproeewe_email_frequency_and_templates', array(
					30 => 'membership_expiring',
					60 => 'membership_expiring',
					90 => 'membership_expiring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-expiration-schedule', $pw_schedule );
				break;
			
			default:
				$pw_schedule = array( 7, 15, 30 );
		}
		
		return array_merge( $pw_schedule, $schedule );
	}
}