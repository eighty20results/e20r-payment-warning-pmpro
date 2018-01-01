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

namespace E20R\Utilities\Email_Notice;

use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;
use E20R\Sequences\Modules\Analytics\Google;

class EMail {
	
	/**
	 * @var int|null $user_id - The ID of the user receving this email message
	 */
	private $user_id;
	
	/**
	 * @var null|int[] $content_id_list = Post ID for any linked content
	 */
	private $content_id_list;
	
	/**
	 * Email address for recipient
	 *
	 * @var null|string
	 */
	private $to;
	
	/**
	 * Recipient's full name
	 *
	 * @var null|string
	 */
	private $toname;
	
	/**
	 * Sender email address
	 *
	 * @var null|string
	 */
	private $from;
	
	/**
	 * Sender's name
	 *
	 * @var null|string
	 */
	private $fromname;
	
	/**
	 * Subject text for email message
	 *
	 * @var null|string
	 */
	private $subject;
	
	/**
	 * Name of HTML template (<filename>.html) without the .html extension
	 *
	 * @var null|string
	 */
	private $template;
	
	/**
	 * Substitution variables (array)
	 *
	 * @var null|array
	 */
	private $variables;
	
	/**
	 * SMTP Headers for email message
	 *
	 * @var null|array
	 */
	private $headers;
	
	/**
	 * Body of email message
	 *
	 * @var null|string|array
	 */
	private $body;
	
	/**
	 * Attachments to email message (if applicable)
	 *
	 * @var array|null
	 */
	private $attachments;
	
	/**
	 * Format of any dates used (uses PHP date() formatting)
	 *
	 * @var null|string
	 */
	private $dateformat;
	
	public function __construct() {
		$this->user_id         = null;
		$this->content_id_list = array();
		$this->to              = null;
		$this->toname          = null;
		$this->from            = null;
		$this->fromname        = null;
		$this->subject         = null;
		$this->template        = null;
		$this->variables       = array();
		$this->headers         = array();
		$this->body            = null;
		$this->attachments     = array();
		$this->dateformat      = null;
		
		return $this;
	}
	
	/**
	 * Magic method to get/fetch class variable values
	 *
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __get( $property ) {
		
		if ( property_exists( $this, $property ) ) {
			
			return $this->{$property};
		}
		
		return null;
	}
	
	/**
	 * Magic method to set/save class variable values
	 *
	 * @param string $property
	 * @param mixed  $value
	 *
	 * @return eMail $this
	 */
	public function __set( $property, $value ) {
		$this->{$property} = $value;
		
		return $this;
	}
	
	/**
	 * Send the email message to the defined recipient
	 *
	 * @param null|string $to
	 * @param null|string $from
	 * @param null|string $fromname
	 * @param null|string $subject
	 * @param string      $template
	 * @param array|null  $variables
	 *
	 * @return bool
	 */
	public function send( $to = null, $from = null, $fromname = null, $subject = null, $template = null, $variables = null ) {
		
		global $current_user;
		
		$utils    = Utilities::get_instance();
		$sequence = Controller::get_instance();
		
		// Set variables.
		if ( ! empty( $to ) ) {
			
			$this->to = sanitize_email( $to );
		}
		
		if ( ! empty( $from ) ) {
			
			$this->from = sanitize_email( $from );
		}
		
		if ( ! empty( $fromname ) ) {
			
			$this->fromname = sanitize_text_field( $fromname );
		}
		
		if ( ! empty( $subject ) ) {
			
			$this->subject = stripslashes( sanitize_text_field( $subject ) );
		}
		
		if ( ! empty( $template ) ) {
			
			$this->template = $template;
		}
		
		if ( ! empty( $data ) ) {
			
			$this->data = $data;
		}
		
		// Check if everything is configured.
		if ( empty( $this->to ) ) {
			
			$this->to = $current_user->user_email;
		}
		
		if ( empty( $this->from ) ) {
			
			$this->from = $sequence->get_option_by_name( 'replyto' );
		}
		
		if ( empty( $this->fromname ) ) {
			
			$this->fromname = $sequence->get_option_by_name( 'fromname' );
		}
		
		if ( empty( $this->subject ) ) {
			
			$this->subject = html_entity_decode( $sequence->get_option_by_name( 'subject' ), ENT_QUOTES, 'UTF-8' );
		}
		
		if ( empty( $this->template ) ) {
			
			$this->template = $sequence->get_option_by_name( 'noticeTemplate' );
		}
		
		if ( empty( $this->dateformat ) ) {
			
			$this->dateformat = $sequence->get_option_by_name( ' dateformat' );
		}
		
		$this->headers     = apply_filters( 'e20r-sequence-email-headers', array( "Content-Type: text/html" ) );
		$this->attachments = null;
		
		if ( is_string( $this->data ) ) {
			$this->data = array( 'body' => $this->data );
		}
		
		$utils->log( "Processing main content for email message" );
		
		$this->body      = $this->load_template( $this->template );
		$this->variables = apply_filters( 'e20r-sequences-email-data', $this->variables, $this );
		
		$filtered_email    = apply_filters( "e20r-sequence-email-filter", $this );        //allows filtering entire email at once
		$this->to          = apply_filters( "e20r-sequence-email-recipient", $filtered_email->to, $this );
		$this->from        = apply_filters( "e20r-sequence-email-sender", $filtered_email->from, $this );
		$this->fromname    = apply_filters( "e20r-sequence-email-sender_name", $filtered_email->fromname, $this );
		$this->subject     = apply_filters( "e20r-sequence-email-_subject", $filtered_email->subject, $this );
		$this->template    = apply_filters( "e20r-sequence-email-template", $filtered_email->template, $this );
		$this->body        = apply_filters( "e20r-sequence-email-body", $filtered_email->body, $this );
		$this->attachments = apply_filters( "e20r-sequence-email-attachments", $filtered_email->attachments, $this );
		
		$this->body = $this->process_body( $this->data, $this->body );
		
		$utils->log( "Sending email message..." );
		
		if ( wp_mail( $this->to, $this->subject, $this->body, $this->headers, $this->attachments ) ) {
			
			$utils->log( "Sent email to {$this->to} about {$this->subject}" );
			
			return true;
		}
		
		$utils->log( "Failed to send email to {$this->to} about {$this->subject}" );
		
		return false;
	}
	
	/**
	 * Load email body from specified template (file or editor)
	 *
	 * @param string $template_file - file name
	 *
	 * @return mixed|null|string   - Body value for template
	 */
	private function load_template( $template_file ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Load template for file {$template_file}" );
		
		/**
		 * @filter e20r-sequence-template-editor-loaded - Determines whether the template editor is loaded & active
		 */
		$use_editor = apply_filters( 'e20r-sequence-template-editor-loaded', false );
		
		if ( true === $use_editor && true === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			
			/**
			 * @filter e20r-sequence-template-editor-contents - Loads the contents of the specific template_file from the email editor add-on.
			 */
			$this->body = apply_filters( 'e20r-email-notice-template-contents', null, $template_file );
			
		} else {
			
			// Haven't got the plus license, using file system saved template(s)
			if ( file_exists( get_stylesheet_directory() . "/sequence-email-alert/" . $template_file ) ) {
				
				$this->body = file_get_contents( get_stylesheet_directory() . "/sequence-email-alert/" . $template_file );        //email template folder in child theme
			} else if ( file_exists( get_stylesheet_directory() . "/sequences-email-alerts/" . $template_file ) ) {
				
				$this->body = file_get_contents( get_stylesheet_directory() . "/sequences-email-alerts/" . $template_file );    //typo in path for email template folder in child theme
			} else if ( file_exists( get_template_directory() . "/sequences-email-alerts/" . $template_file ) ) {
				
				$this->body = file_get_contents( get_template_directory() . "/sequences-email-alerts/" . $template_file );        //email folder in parent theme
			} else if ( file_exists( get_template_directory() . "/sequence-email-alerts/" . $template_file ) ) {
				
				$this->body = file_get_contents( get_template_directory() . "/sequence-email-alerts/" . $template_file );        //typo in path for email folder in parent theme
			} else if ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "/email/" . $template_file ) ) {
				
				$this->body = file_get_contents( E20R_SEQUENCE_PLUGIN_DIR . "/email/" . $template_file );                        //default template in plugin
			}
		}
		
		return $this->body;
	}
	
	/**
	 * Process the body & complete variable substitution if/when needed
	 *
	 * @param array $data_array
	 * @param null  $body
	 *
	 * @return array|null|string
	 */
	private function process_body( $data_array = array(), $body = null ) {
		
		$utils = Utilities::get_instance();
		
		if ( is_null( $body ) ) {
			
			if ( ! empty( $data_array['template'] ) ) {
				
				$this->load_template( $data_array['template'] );
			}
			
			if ( empty( $body ) ) {
				$utils->log( "No body to substitute in. Returning empty string" );
				$this->body = null;
			}
		}
		
		if ( ! is_array( $data_array ) && empty( $body ) ) {
			
			$utils->log( "Not a valid substitution array: " . print_r( $data_array, true ) );
			$this->body = null;
		}
		
		if ( is_array( $data_array ) && ! empty( $data_array ) && ! empty( $body ) ) {
			
			$utils->log( "Substituting variables in body of email" );
			$this->body = $body;
			
			foreach ( $data_array as $subst_key => $value ) {
				
				$this->body = str_ireplace( "!!{$subst_key}!!", $value, $this->body );
			}
		}
		
		return $this->body;
	}
	
	public function add_header() {
	
	}
	
	public function add_footer() {
	
	}
	
	/**
	 * Prepare the (soon-to-be) PHPMailer() object to send
	 *
	 * @param \WP_Post $post     - Post Object
	 * @param \WP_User $user     - User Object
	 * @param          $template - Template name (string)
	 *
	 * @return eMail - Mail object to process
	 */
	public function prepare_mail_obj( $post, $user, $template ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$user_started = ( $controller->get_user_startdate( $user->ID ) - DAY_IN_SECONDS ) + ( $controller->normalize_delay( $post->delay ) * DAY_IN_SECONDS );
		
		$this->from            = $controller->get_option_by_name( 'replyto' );
		$this->template        = $template;
		$this->fromname        = $controller->get_option_by_name( 'fromname' );
		$this->to              = $user->user_email;
		$this->subject         = sprintf( '%s: %s (%s)', $controller->get_option_by_name( 'subject' ), $post->title, strftime( "%x", $user_started ) );
		$this->dateformat      = $controller->get_option_by_name( 'dateformat' );
		$this->user_id         = $user->ID;
		$this->content_id_list = array( $post->id );
		$this->body            = $this->load_template( $this->template );
		
		$this->process_body();
		
		return $this;
		
	}
	
	/**
	 * Substitute the included variables for the appropriate text
	 *
	 * @param mixed $email_type
	 *
	 */
	public function replace_variable_data( $email_type ) {
		
		$editor    = Editor::get_instance();
		$variables = $editor->default_data_variables( array(), $email_type );
		
		foreach ( $variables as $var_name => $settings ) {
			
			if ( in_array( $settings['type'], array( 'link', 'wp_post' ) ) ) {
				$settings['post_id'] = $this->content_id_list;
			}
			
			$var_value = $this->get_value_for_variable( $this->user_id, $settings );
			
			$this->variables[ $var_name ] = $var_value;
		}
		
		$this->body = apply_filters( 'e20r-email-notice-set-data-variables', $this->body, $this->variables );
	}
	
	/**
	 * @param int   $user_id
	 * @param array $settings
	 *
	 * @return mixed
	 */
	public static function get_value_for_variable( $user_id, $settings ) {
		
		$value = null;
		
		switch ( $settings['type'] ) {
			case 'wp_user':
				$user = get_user_by( 'ID', $user_id );
				
				if ( ! empty( $user ) ) {
					$value = $user->{$settings['variable']};
				}
				
				unset( $user );
				break;
			
			case 'user_meta':
				$value = get_user_meta( $user_id, $settings['variable'], true );
				break;
			
			case 'wp_options':
				$value = get_option( $settings['variable'], null );
				break;
			
			case 'link':
				switch ( $settings['variable'] ) {
					case 'wp_login';
						$value = wp_login_url();
						break;
					
					case 'post':
						$value = array();
						
						foreach ( $settings['post_id'] as $post_id ) {
							$value[ $post_id ] = get_permalink( $post_id );
						}
						break;
				}
				break;
			
			case 'encoded_link':
				switch ( $settings['variable'] ) {
					case 'post':
						$value = array();
						
						foreach ( $settings['post_id'] as $post_id ) {
							$value[ $post_id ] = urlencode_deep( get_permalink( $post_id ) );
						}
						break;
				}
			case 'membership':
				
				$level = apply_filters( 'e20r-sequence-membership-level-for-user', null, $user_id, true );
				
				switch ( $settings['variable'] ) {
					case 'membership_id':
						$value = $level->id;
						break;
					case 'membership_level_name':
						$value = $level->name;
						break;
				}
				break;
			
			case 'wp_post':
				
				$content = get_posts( array( 'include' => $settings['post_id'] ) );
				
				foreach ( $content as $content_post ) {
					$value[ $content_post->ID ] = $content_post->{$settings['variable']};
				}
				
				wp_reset_postdata();
				break;
			default:
			
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Found setting (type: {$settings['type']} value for user (ID: {$user_id}): {$value}" );
		
		return $value;
	}
	
	private function inline_html() {
		?>
		<!doctype html>
		<html>
		<head>
			<meta name="viewport" content="width=device-width">
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
			<title>Simple Transactional Email</title>
			<style>
				/* -------------------------------------
					INLINED WITH htmlemail.io/inline
				------------------------------------- */
				/* -------------------------------------
					RESPONSIVE AND MOBILE FRIENDLY STYLES
				------------------------------------- */
				@media only screen and (max-width: 620px) {
					table[class=body] h1 {
						font-size: 28px !important;
						margin-bottom: 10px !important;
					}
					
					table[class=body] p,
					table[class=body] ul,
					table[class=body] ol,
					table[class=body] td,
					table[class=body] span,
					table[class=body] a {
						font-size: 16px !important;
					}
					
					table[class=body] .wrapper,
					table[class=body] .article {
						padding: 10px !important;
					}
					
					table[class=body] .content {
						padding: 0 !important;
					}
					
					table[class=body] .container {
						padding: 0 !important;
						width: 100% !important;
					}
					
					table[class=body] .main {
						border-left-width: 0 !important;
						border-radius: 0 !important;
						border-right-width: 0 !important;
					}
					
					table[class=body] .btn table {
						width: 100% !important;
					}
					
					table[class=body] .btn a {
						width: 100% !important;
					}
					
					table[class=body] .img-responsive {
						height: auto !important;
						max-width: 100% !important;
						width: auto !important;
					}
				}
				
				/* -------------------------------------
					PRESERVE THESE STYLES IN THE HEAD
				------------------------------------- */
				@media all {
					.ExternalClass {
						width: 100%;
					}
					
					.ExternalClass,
					.ExternalClass p,
					.ExternalClass span,
					.ExternalClass font,
					.ExternalClass td,
					.ExternalClass div {
						line-height: 100%;
					}
					
					.apple-link a {
						color: inherit !important;
						font-family: inherit !important;
						font-size: inherit !important;
						font-weight: inherit !important;
						line-height: inherit !important;
						text-decoration: none !important;
					}
					
					.btn-primary table td:hover {
						background-color: #34495e !important;
					}
					
					.btn-primary a:hover {
						background-color: #34495e !important;
						border-color: #34495e !important;
					}
				}
			</style>
		</head>
		<body class=""
		      style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
		<table border="0" cellpadding="0" cellspacing="0" class="body"
		       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
			<tr>
				<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
				<td class="container"
				    style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
					<div class="content"
					     style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
						
						<!-- START CENTERED WHITE CONTAINER -->
						<span class="preheader"
						      style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">This is preheader text. Some clients will show this text as a preview.</span>
						<table class="main"
						       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
							
							<!-- START MAIN CONTENT AREA -->
							<tr>
								<td class="wrapper"
								    style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
									<table border="0" cellpadding="0" cellspacing="0"
									       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
										<tr>
											<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Hi there,</p>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Sometimes you just want to send a simple HTML email with a simple
													design and clear call to action. This is it.</p>
												<table border="0" cellpadding="0" cellspacing="0"
												       class="btn btn-primary"
												       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; box-sizing: border-box;">
													<tbody>
													<tr>
														<td align="left"
														    style="font-family: sans-serif; font-size: 14px; vertical-align: top; padding-bottom: 15px;">
															<table border="0" cellpadding="0" cellspacing="0"
															       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: auto;">
																<tbody>
																<tr>
																	<td style="font-family: sans-serif; font-size: 14px; vertical-align: top; background-color: #3498db; border-radius: 5px; text-align: center;">
																		<a href="http://htmlemail.io" target="_blank"
																		   style="display: inline-block; color: #ffffff; background-color: #3498db; border: solid 1px #3498db; border-radius: 5px; box-sizing: border-box; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: bold; margin: 0; padding: 12px 25px; text-transform: capitalize; border-color: #3498db;">Call
																			To Action</a></td>
																</tr>
																</tbody>
															</table>
														</td>
													</tr>
													</tbody>
												</table>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													This is a really simple email template. Its sole purpose is to get
													the recipient to click the button with no distractions.</p>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Good luck! Hope it works.</p>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							
							<!-- END MAIN CONTENT AREA -->
						</table>
						
						<!-- START FOOTER -->
						<div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
							<table border="0" cellpadding="0" cellspacing="0"
							       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
								<tr>
									<td class="content-block"
									    style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
										<span class="apple-link"
										      style="color: #999999; font-size: 12px; text-align: center;">Company Inc, 3 Abbey Road, San Francisco CA 94102</span>
										<br> Don't like these emails? <a href="http://i.imgur.com/CScmqnj.gif"
										                                 style="text-decoration: underline; color: #999999; font-size: 12px; text-align: center;">Unsubscribe</a>.
									</td>
								</tr>
								<tr>
									<td class="content-block powered-by"
									    style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
										Powered by <a href="http://htmlemail.io"
										              style="color: #999999; font-size: 12px; text-align: center; text-decoration: none;">HTMLemail</a>.
									</td>
								</tr>
							</table>
						</div>
						<!-- END FOOTER -->
						
						<!-- END CENTERED WHITE CONTAINER -->
					</div>
				</td>
				<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
			</tr>
		</table>
		</body>
		</html><?php
	}
	
	/**
	 * Check the theme/child-theme/Sequence plugin directory for the specified notice template.
	 *
	 * @return null|string -- Path to the selected template for the email alert notice.
	 */
	private function email_template_path() {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		if ( file_exists( get_stylesheet_directory() . "/sequence-email-alerts/" . $controller->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = get_stylesheet_directory() . "/sequence-email-alerts/" . $controller->get_option_by_name( 'noticeTemplate' );
			
		} else if ( file_exists( get_template_directory() . "/sequence-email-alerts/" . $controller->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = get_template_directory() . "/sequence-email-alerts/" . $controller->get_option_by_name( 'noticeTemplate' );
		} else if ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "email/" . $controller->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = E20R_SEQUENCE_PLUGIN_DIR . "email/" . $controller->get_option_by_name( 'noticeTemplate' );
		} else {
			$template_path = null;
		}
		
		$utils->log( "email_template_path() - Using path: {$template_path}" );
		
		return $template_path;
	}
}
