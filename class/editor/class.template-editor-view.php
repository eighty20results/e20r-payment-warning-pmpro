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

namespace E20R\Payment_Warning\Editor;

use E20R\Payment_Warning\Payment_Warning;
use E20R\Payment_Warning\Utilities\Utilities;

class Template_Editor_View {
	
	public static function editor( $template_settings ) {
		
		if ( ! current_user_can( apply_filters( 'e20rpw_min_settings_capabilities', 'manage_options' ) ) ) {
			wp_die( __( "You do not have sufficient permissions to access this page", Payment_Warning::plugin_slug ) );
		}
		
		$util = Utilities::get_instance();
		?>
        <h2><?php _e( 'Edit: Payment Warning Message Templates', Payment_Warning::plugin_slug ); ?></h2>
        <table class="form-table">
            <thead>
            <tr class="status e20r-start-hidden">
                <th scope="row" valign="top"></th>
                <td>
                    <div id="message">
                        <p class="e20r-status-message"></p>
                    </div>
                </td>
            </tr>
            </thead>
            <tbody>
            <tr>
                <th scope="row" valign="top">
                    <label for="e20r-message-templates"><?php _e( "Message Template", Payment_Warning::plugin_slug ); ?></label>
                </th>
                <td>
                    <select name="e20r-message-templates" id="e20r-message-templates">
                        <option value="-1" selected="selected">
							<?php printf( '--- %s ---', __( 'Select a Message template to Edit', Payment_Warning::plugin_slug ) ); ?>
                        </option>
						<?php foreach ( $template_settings as $template_name => $template ) { ?>
                            <option value="<?php esc_attr_e( $template_name ); ?>">
								<?php esc_html_e( $template['description'] ); ?>
                            </option>
						<?php } ?>
                        <option value="new">
							<?php printf( '*** %s ***', __( 'Add new Message template', Payment_Warning::plugin_slug ) ); ?>
                        </option>
                    </select>
                    <img src="<?php echo admin_url( 'images/wpspin_light.gif' ); ?>" id="e20r-busy-image"
                         class="e20r-start-hidden"/>
                    <hr/>
                </td>
            </tr>
            <tr>
                <th scope="row" valign="top"></th>
                <td class="e20r-hidden-template-settings">
                    <table class="e20r-message-settings">
                        <tbody>
                        <form action="" method="post" enctype="multipart/form-data"
                              id="e20r-message-templates-form_<?php esc_attr_e( $template_name ); ?>">
							<?php wp_nonce_field( Payment_Warning::plugin_prefix, 'message_template' ); ?>
							<?php
							foreach ( $template_settings as $template_name => $template ) {
								self::add_template_entry( $template_name, $template );
							} ?>
                        </form>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
            <tfoot>
            <tr class="controls e20r-start-hidden">
                <th scope="row" valign="top"></th>
                <td>
                    <p class="submit">
                        <input id="save-template-data" name="save-message-template" type="button" class="button-primary"
                               value="<?php _e( 'Save Template Settings', Payment_Warning::plugin_slug ); ?>"/>
                        <input id="reset-template-data" name="reset-message-template" type="button" class="button"
                               value="<?php _e( 'Reset', Payment_Warning::plugin_slug ); ?>"/>
                        <!--
                        <input id="add-new-template" name="add-message-template" type="button" class="button"
                               value="<?php _e( 'Add New Template', Payment_Warning::plugin_slug ); ?>"/>
                        -->
                    </p>

                </td>
            </tr>
            </tfoot>
        </table>
		<?php
	}
	
	public static function add_template_entry( $template_name, $template, $return = false ) {
		
		if ( true === $return ) {
			ob_start();
		} ?>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?>">
            <input type="hidden" name="e20r_message_template-file_name"
                   value="<?php esc_attr_e( $template['file_name'] ); ?>">
            <input type="hidden" name="e20r_message_template-key" value="<?php esc_attr_e( $template_name ); ?>">
            <input type="hidden" name="e20r_message_template-file_path"
                   value="<?php esc_attr_e( $template['file_path'] ); ?>">
            <input type="hidden" name="e20r_message_template-description"
                   value="<?php esc_attr_e( $template['description'] ); ?>">
            <td colspan="2"><h4 style="text-align: center;"><?php esc_html_e( $template['description'] ); ?></h4></td>
        </tr>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?>">
            <th scope="row" valign="top" class="e20r-message-template-col">
                <label for="e20r-message-type_<?php esc_attr_e( $template_name ); ?>">
					<?php _e( 'Message type', Payment_Warning::plugin_slug ); ?>
                </label>
            </th>
            <td class="e20r-message-template-col">
                <select id="e20r-message-type_<?php esc_attr_e( $template_name ); ?>" name="e20r_message_template-type">
                    <option value="-1" <?php selected( $template['type'], null ); ?>>
						<?php _e( 'Header/Footer', Payment_Warning::plugin_slug ); ?>
                    </option>
                    <option value="expiration" <?php selected( $template['type'], 'expiration' ); ?>>
						<?php _e( 'Expiration Message', Payment_Warning::plugin_slug ); ?>
                    </option>
                    <option value="recurring" <?php selected( $template['type'], 'recurring' ); ?>>
						<?php _e( 'Recurring Payment', Payment_Warning::plugin_slug ); ?>
                    </option>
                    <option value="ccexpiration" <?php selected( $template['type'], 'recurring' ); ?>>
		                <?php _e( 'Credit Card Expiration', Payment_Warning::plugin_slug ); ?>
                    </option>

                </select>
            </td>
        </tr>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?> e20r-message-schedule-info">
            <th scope="row" valign="top" class="e20r-message-template-col">
                <label for="e20r-message-schedule_<?php esc_attr_e( $template_name ); ?>">
					<?php _e( 'Send schedule', Payment_Warning::plugin_slug ); ?>
                </label>
            </th>
            <td class="e20r-message-template-col">
				<?php
				if ( ! empty( $template['schedule'] ) ) {
					foreach ( $template['schedule'] as $days ) { ?>
                        <div class="e20r-schedule-entry">
                            <input name="e20r_message_template-schedule[]"
                                   type="number"
                                   value="<?php esc_attr_e( $days ); ?>"
                                   class="e20r-message-schedule"/>&nbsp;
                            <span class="e20r-message-schedule-label">
                                                        <?php _e( 'days before event', Payment_Warning::plugin_slug ); ?>
                                                    </span>
                            <span class="e20r-message-schedule-remove">
                                                        <input type="button"
                                                               value="<?php _e( "Remove", Payment_Warning::plugin_slug ); ?>"
                                                               class="e20r-delete-schedule-entry button-secondary"/>
                                                    </span>
                        </div>
					<?php }
					?>
                    <button class="button-secondary e20r-add-new-schedule"><?php _e( "Add new schedule entry", Payment_Warning::plugin_slug ); ?></button><?php
				} else {
					if ( in_array( $template_name, array( 'messagefooter', 'messageheader' ) ) ) {
						_e( "No schedule needed/defined", Payment_Warning::plugin_slug );
					}
				} ?>
            </td>
        </tr>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?>">
            <th scope="row" valign="top" class="e20r-message-template-col">
                <label for="e20r-message-template-subject_<?php esc_attr_e( $template_name ); ?>">
					<?php _e( 'Message subject', Payment_Warning::plugin_slug ); ?>
                </label>
            </th>
            <td class="e20r-message-template-col">
                <input id="e20r-message-template-subject_<?php esc_attr_e( $template_name ); ?>"
                       name="e20r_message_template-subject"
                       type="text" size="100" value="<?php echo esc_html( wp_unslash( $template['subject'] ) ); ?>"/>
            </td>
        </tr>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?>">
            <th scope="row" valign="top" class="e20r-message-template-col">
                <label for="e20r-message-template-body_<?php esc_attr_e( $template_name ); ?>">
					<?php _e( 'Body', Payment_Warning::plugin_slug ); ?>
                </label>
            </th>
            <td class="e20r-message-template-col">
                <div class="template_editor_container">
					<?php
					if ( false === $return && 'new' !== $template_name ) {
						wp_editor( ( ! empty( $template['body'] ) ? wp_unslash( $template['body'] ) : null ), "e20r-message-body_{$template_name}", array( 'editor_height' => 350 ) );
					} else {
						?>
                        <!-- <div id="wp-e20r-message-body_new-media-buttons" class="wp-media-buttons"> -->
                            <button id="e20r-load-media-btn" type="button" class="button insert-media add_media" data-editor="e20r-message-body_new"><span class="wp-media-buttons-icon"></span><?php _e("Add Media", Payment_Warning::plugin_slug ); ?></button>
                        <!-- </div> -->
                        <textarea name="e20r-message-body_new" id="e20r-message-body_new"></textarea><?php
					} ?>
                </div>
            </td>
        </tr>
        <tr class="e20r-start-hidden e20r-message-template-name_<?php esc_attr_e( $template_name ); ?>">
            <th class="e20r-message-template-col"></th>
            <td class="e20r-message-template-col">
                <input id="e20r-message-disabled_<?php esc_attr_e( $template_name ); ?>"
                       name="e20r_message_template-active" type="checkbox"
                       value="1" <?php checked( $template['active'], 0 ); ?> />
                <label>
                    <span id="e20r-message-disabled-label"><?php _e( 'Disable this template?', Payment_Warning::plugin_slug ); ?></span>
                </label>
                <p class="description small">
					<?php _e( 'This template will not be used or sent.', Payment_Warning::plugin_slug ); ?>
                </p>
            </td>
        </tr>
        <tr class="e20r-message-template-name_<?php esc_attr_e( $template_name ); ?> e20r-start-hidden">
            <td colspan="2" class="e20r-message-template-col">
                <hr/>
            </td>
        </tr>
		<?php
		
		if ( true === $return ) {
			return ob_get_clean();
		}
	}
}