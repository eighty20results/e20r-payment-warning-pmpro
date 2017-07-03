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
 *
 * @version 1.3
 */

namespace E20R\Payment_Warning\Utilities;

// Disallow direct access to the class definition
use E20R\Payment_Warning\Payment_Warning;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die( __( "Cannot access file directly", Payment_Warning::plugin_slug ) );
}

if ( ! class_exists( 'E20R\Payment_Warning\Utilities\Utilities' ) ) {
	
	class Utilities {
		
		/**
		 * @var string Cache key
		 */
		private static $cache_key;
		
		/**
		 * @var null|string
		 */
		private static $plugin_slug = null;
		
		/**
		 * @var null|Utilities
		 */
		private static $instance = null;
		
		/**
		 * Array of error messages
		 *
		 * @var array $msg
		 */
		private $msg = array();
		
		/**
		 * Array of message types (notice, warning, error)
		 *
		 * @var array $msgt
		 */
		private $msgt = array();
		
		/**
		 * Array of message sources (unlimited)
		 * @var array $msg_source
		 */
		private $msg_source = array();
		
		private $blog_id = null;
		
		/**
		 * Utilities constructor.
		 */
		private function __construct() {
			
			if ( empty( self::$plugin_slug ) ) {
				self::$plugin_slug = apply_filters( 'e20r-licensing-text-domain', null );
			}
			
			$this->log( 'Plugin Slug: ' . self::$plugin_slug );
			
			$this->blog_id = get_current_blog_id();
			
			self::$cache_key = "e20r_pw_utils_{$this->blog_id}";
			
			if ( null !== ( $tmp = Cache::get( "err_info", self::$cache_key ) ) ) {
				
				$this->msg        = is_array( $tmp['msg'] ) ? $tmp['msg'] : array( $tmp['msg'] );
				$this->msgt       = is_array( $tmp['msgt'] ) ? $tmp['msgt'] : array( $tmp['msgt'] );
				$this->msg_source = is_array( $tmp['msg_source'] ) ? $tmp['msg_source'] : array( $tmp['msg_source'] );
			}
			
			if ( ! has_action( 'admin_notices', array( $this, 'display_messages' ) ) ) {
				add_action( 'admin_notices', array( $this, 'display_messages' ), 10 );
			}
			
			// Clear cache when updating discount codes or membership level definitions
			add_action( 'pmpro_save_discount_code', array( $this, 'clear_delay_cache' ), 9999, 1 );
			add_action( 'pmpro_save_membership_level', array( $this, 'clear_delay_cache' ), 9999, 1 );
			
		}
		
		/**
		 * Load and use L10N based text (if available)
		 */
		public function load_textdomain() {
			
			$this->log( "Processing load_textdomain" );
			
			if ( empty( self::$plugin_slug ) ) {
				$this->log( "Error attempting to load translation files!" );
				
				return;
			}
			
			$domain_name = self::$plugin_slug;
			
			$locale = apply_filters( "plugin_locale", get_locale(), $domain_name );
			
			$mofile        = "{$domain_name}-{$locale}.mo";
			$mofile_local  = plugin_dir_path( __FILE__ ) . "languages/" . $mofile;
			$mofile_global = WP_LANG_DIR . "/{$domain_name}/" . $mofile;
			
			load_textdomain( "{$domain_name}", $mofile_local );
			
			//Attempt to load the global translation first (if it exists)
			if ( file_exists( $mofile_global ) ) {
				load_textdomain( "{$domain_name}", $mofile_global );
			}
			
			//load local second
			load_textdomain( "{$domain_name}", $mofile_local );
			
			//load via plugin_textdomain/glotpress
			load_plugin_textdomain( "{$domain_name}", false, dirname( __FILE__ ) . "/../../languages/" );
		}
		
		/**
		 * Return last message of a specific type
		 *
		 * @param string $type
		 *
		 * @return string
		 */
		public function get_message( $type = 'notice' ) {
			
			// Grab from the cache (if it exists)
			if ( null !== ( $tmp = Cache::get( 'err_info', self::$cache_key ) ) ) {
				
				$this->msg        = $tmp['msg'];
				$this->msgt       = $tmp['msgt'];
				$this->msg_source = $tmp['msg_source'];
			}
			
			$return = array();
			foreach ( $this->msgt as $key => $mt ) {
				if ( $mt === $type ) {
					$return = $this->msg[ $key ];
				}
			}
			
			return $return;
		}
		
		/**
		 * Add error message to the list of messages to display on the back-end
		 *
		 * @param string $message    The message to save/add
		 * @param string $type       The type of message (notice, warning, error)
		 * @param string $msg_source The source of the error message
		 *
		 * @return bool
		 */
		public function add_message( $message, $type = 'notice', $msg_source = 'default' ) {
			
			// Grab from the cache (if it exists)
			if ( null !== ( $tmp = Cache::get( 'err_info', self::$cache_key ) ) ) {
				
				$this->msg        = $tmp['msg'];
				$this->msgt       = $tmp['msgt'];
				$this->msg_source = $tmp['msg_source'];
			}
			
			if ( is_array( $this->msg ) && ! in_array( $message, $this->msg ) ) {
				
				if ( WP_DEBUG ) {
					error_log( "Adding a message to the admin errors: {$message}" );
				}
				
				// Save the new message
				$this->msg[]        = $message;
				$this->msgt[]       = $type;
				$this->msg_source[] = $msg_source;
				
				$values = array(
					'msg'        => $this->msg,
					'msgt'       => $this->msgt,
					'msg_source' => $this->msg_source,
				);
				
				Cache::set( 'err_info', $values, DAY_IN_SECONDS, self::$cache_key );
			}
		}
		
		/**
		 * Display the error message as HTML when called
		 *
		 * @param string $source - The error source to show.
		 */
		public function display_messages( $source = 'default' ) {
			
			// Load any cached error message(s)
			if ( null !== ( $msgs = Cache::get( 'err_info', self::$cache_key ) ) ) {
				
				$this->msg        = array_merge( $this->msg, $msgs['msg'] );
				$this->msgt       = array_merge( $this->msgt, $msgs['msgt'] );
				$this->msg_source = array_merge( $this->msg_source, $msgs['msg_source'] );
			}
			
			if ( ! empty( $this->msg ) && ! empty( $this->msgt ) ) {
				
				if ( WP_DEBUG ) {
					error_log( "Have " . count( $this->msg ) . " admin message(s) to display" );
				}
				
				foreach ( $this->msg as $key => $notice ) { ?>
                    <div class="notice notice-<?php esc_html_e( $this->msgt[ $key ] ); ?> is-dismissible <?php esc_html_e( $this->msg_source[ $key ] ); ?>">
                        <p><?php echo $notice; ?></p>
                    </div>
					<?php
				}
			}
			
			// Clear the error message list
			$this->msg        = array();
			$this->msgt       = array();
			$this->msg_source = array();
			
			Cache::delete( 'err_info', self::$cache_key );
		}
		
		/**
         * Idenitfy the calling function (used in debug logger
         *
		 * @return array|string
         *
         * @access private
		 */
		private function _who_called_me() {
			
			$trace  = debug_backtrace();
			$caller = $trace[2];
			
			if ( isset( $caller['class'] ) ) {
				$trace = "{$caller['class']}::{$caller['function']}()";
			} else {
				$trace = "Called by {$caller['function']}()";
			}
			
			return $trace;
		}
		
		/**
         * Return all delay values for a membership payment start
         *
		 * @param $level
		 *
		 * @return array|bool|mixed|null
		 */
		public static function get_membership_start_delays( $level ) {
			
			$delays = array();
			self::$instance->log("Processing start delays for {$level->id}");
			
			if ( true === pmpro_isLevelRecurring( $level ) ) {
       
			    self::$instance->log("Level {$level->id} is a recurring payments level");
			    
				if ( null === ( $delays = Cache::get( "start_delay_{$level->id}", self::$cache_key ) ) ) {
					
					self::$instance->log("Invalid cache... Loading from scratch");
					
					// Calculate the trial period (may be smaller than a normal billing period
					if ( $level->cycle_number > 0 ) {
						
						self::$instance->log("Is a recurring billing level");
						
						$trial_cycles       = $level->trial_limit;
						$period_days        = self::convert_period( $level->cycle_period );
						$billing_cycle_days = 0;
						
						if ( null !== $period_days ) {
							$billing_cycle_days = $level->cycle_number * $period_days;
						}
						
						if ( ! empty( $trial_cycles ) ) {
							$delays['trial'] = $trial_cycles * $billing_cycle_days;
						}
      
						$delays[$level->id] = ( $billing_cycle_days * $level->cycle_number );
						self::$instance->log("Days used for delay value: {$delays[$level->id]} ");
					}
					
					// We have Subscription Delays add-on for PMPro installed and active
					if ( function_exists( 'pmprosd_getDelay' ) ) {
						
						self::$instance->log( "Processing Subscription Delay values for {$level->id}");
						
						//Get the default delay value (days)
						$date_or_num = pmprosd_getDelay( $level->id, null );
						self::$instance->log("Received default delay value: {$date_or_num}");
						
						if ( !empty( $date_or_num ) ) {
						    $val = ( is_numeric( $date_or_num ) ? $date_or_num : pmprosd_daysUntilDate( $date_or_num ) );
							
						    if ( !empty( $val ) ) {
								$delays['default'] = $val;
								self::$instance->log( "Configured default value {$delays[ 'default' ]}" );
							}
						} else {
						    self::$instance->log("No default value for level {$level->id} specified");
                        }
						
						// Fetch discount codes to locate delays for
						$active_codes = self::get_all_discount_codes();
						
						// Process active discount code delays
						if ( ! empty( $active_codes ) ) {
							
							foreach ( $active_codes as $code ) {
								
								// Get the delay value from the Subscription Delays plugin
								$d = pmprosd_getDelay( $level->id, $code->id );
								
								if ( ! empty( $d ) ) {
									
								    self::$instance->log("Processing {$d}");
									$val = ( is_numeric( $d ) ? $d : pmprosd_daysUntilDate( $d ) );
									
									if ( !empty( $val )) {
										$delays[ $code->code ] = $val;
										self::$instance->log( "Configured {$code->code} value {$delays[ $code->code ]}" );
									}
								}
							}
						}
					}
					
					// Update the cache.
					if ( ! empty( $delays ) ) {
						
						// Save to cache and have cached for up to 7 days
						Cache::set( "start_delay_{$level->id}", $delays, WEEK_IN_SECONDS, self::$cache_key );
					}
				}
            }
            
			return $delays;
		}
		
		/**
         * Convert a Cycle Period (from PMPro) string to an approximate day count
         *
		 * @param string $period
		 *
		 * @return int|null
		 */
		public static function convert_period( $period ) {
			
			$days = null;
			
			switch ( strtolower( $period ) ) {
				
				case 'day':
					$days = 1;
					break;
				
				case 'week':
					$days = 7;
					break;
				
				case 'month':
					$days = 30;
					break;
				
				case 'year':
					$days = 365;
					break;
			}
			
			return $days;
		}
		
		/**
         * Return all PMPro discount codes from the system
         *
		 * @return array|null|object
		 */
		public static function get_all_discount_codes() {
			
			global $wpdb;
			return $wpdb->get_results( "SELECT id, code FROM {$wpdb->pmpro_discount_codes}" );
		}
		
		/**
		 * Remove the start delay cache for the level
		 */
		public function clear_delay_cache( $level_id ) {
			
		    $this->log("Clearing delay cache for {$level_id}");
            Cache::delete( "start_delay_{$level_id}", self::$cache_key );
		}
		
		/**
         * Print a message to the WP_DEBUG logger if configured
         *
		 * @param $msg
		 */
		public function log( $msg ) {
			
			$tid = sprintf( "%08x", abs( crc32( $_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] ) ) );
			
			$from = $this->_who_called_me();
			
			if ( defined( "WP_DEBUG" ) && true == WP_DEBUG ) {
				error_log( "[{$tid}] {$from} - {$msg}" );
			}
		}
		
		/**
         * Case insensitive search/replace function (recursive)
         *
		 * @param string $search
		 * @param string $replacer
		 * @param string $input
		 *
		 * @return mixed
		 */
		public function nc_replace( $search, $replacer, $input ) {
			
			return preg_replace_callback( "/\b{$search}\b/i", function ( $matches ) use ( $replacer ) {
				return ctype_lower( $matches[0][0] ) ? strtolower( $replacer ) : $replacer;
			}, $input );
		}
		
		/**
		 * Process REQUEST variable: Check for presence and sanitize it before returning value or default
		 *
		 * @param string     $name    Name of the variable to return
		 * @param null|mixed $default The default value to return if the REQUEST variable doesn't exist or is empty.
		 *
		 * @return bool|float|int|null|string  Sanitized value from the front-end.
		 */
		public function get_variable( $name, $default = null ) {
			
			return isset( $_REQUEST[ $name ] ) && ! empty( $_REQUEST[ $name ] ) ? $this->_sanitize( $_REQUEST[ $name ] ) : $default;
		}
		
		/**
		 * Sanitizes the passed field/value.
		 *
		 * @param array|int|null|string|\stdClass $field The value to sanitize
		 *
		 * @return array|int|string     Sanitized value
		 */
		public function _sanitize( $field ) {
			
			if ( ! is_numeric( $field ) ) {
				
				if ( is_array( $field ) ) {
					
					foreach ( $field as $key => $val ) {
						$field[ $key ] = $this->_sanitize( $val );
					}
				}
				
				if ( is_object( $field ) ) {
					
					foreach ( $field as $key => $val ) {
						$field->{$key} = $this->_sanitize( $val );
					}
				}
				
				if ( ( ! is_array( $field ) ) && ctype_alpha( $field ) ||
				     ( ( ! is_array( $field ) ) && strtotime( $field ) ) ||
				     ( ( ! is_array( $field ) ) && is_string( $field ) )
				) {
					
					if ( strtolower( $field ) == 'yes' ) {
						$field = true;
					} else if ( strtolower( $field ) == 'no' ) {
						$field = false;
					} else if ( ! $this->is_html( $field ) ) {
						$field = sanitize_text_field( $field );
					} else {
						$field = wp_kses_post( $field );
					}
				}
				
				if ( is_array( $field ) ) {
					
					$field = array_map( 'sanitize_text_field', $field );
				}
				
			} else {
				
				if ( is_float( $field + 1 ) ) {
					
					$field = sanitize_text_field( $field );
				}
				
				if ( is_int( $field + 1 ) ) {
					
					$field = intval( $field );
				}
			}
			
			return $field;
		}
		
		/**
		 * Test whether string contains HTML
		 *
		 * @param $string
		 *
		 * @return bool
		 */
		final static function is_html( $string ) {
			return preg_match( "/<[^<]+>/", $string, $m ) != 0;
		}
		
		/**
		 * Test whether the value is an integer
		 *
		 * @param string $val
		 *
		 * @return bool|int
		 */
		final static function is_integer( $val ) {
			if ( ! is_scalar( $val ) || is_bool( $val ) ) {
				return false;
			}
			
			if ( is_float( $val + 0 ) && ( $val + 0 ) > PHP_INT_MAX ) {
				return false;
			}
			
			return is_float( $val ) ? false : preg_match( '~^((?:\+|-)?[0-9]+)$~', $val );
		}
		
		/**
		 * Test if the value is a floating point number
		 *
		 * @param string $val
		 *
		 * @return bool
		 */
		final static function is_float( $val ) {
			if ( ! is_scalar( $val ) ) {
				return false;
			}
			
			return is_float( $val + 0 );
		}
		
		/**
		 * The current instance of the Utilities class
		 *
		 * @return Utilities|null
		 */
		public static function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				self::$instance = new self;
			}
			
			return self::$instance;
		}
	}
}