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

var e20r_editor = {};

(function ($) {
        "use strict";

        // TODO: Add check that no more than a single template per delay value combo exists.
        e20r_editor = {
            init: function () {

                this.hideable = $('.e20r-start-hidden');
                this.selected_template = $('select#e20r-message-templates');
                this.new_schedule_entry = $('.e20r-add-new-schedule');
                this.remove_entry = $('.e20r-delete-schedule-entry');
                this.type_select = $('select[id^="e20r-message-type"]');
                this.first_remove_btn = this.remove_entry.first();
                this.save_btn = $('#save-template-data');
                this.reset_btn = $('#reset-template-data');
                this.add_btn = $('#add-new-template');
                this.schedule_fields = $('input.e20r-message-schedule');
                this.disable_template = $('input[id^="e20r-message-disabled"]');
                this.file_frame = null;
                this.new_media = $('#e20r-load-media-btn');

                var self = this;

                self.hide_all(self.hideable);

                // Select the template to edit/update
                self.selected_template.unbind('change').on('change', function () {

                    var $template_name = self.selected_template.val();
                    console.log($template_name);

                    if ($template_name === '-1') {
                        self.hide_all(self.hideable);
                    } else if ($template_name === 'new') {
                        self.hide_all(self.hideable);
                        self.add_new_template_entry();
                        $('tr.controls').show();

                        self.activate_media_btn();

                    } else {
                        self.toggle_visibility($('.e20r-message-settings .e20r-message-template-name_' + $template_name));
                    }
                });

                // Add new schedule (day) entry for the template
                self.type_select.unbind('change').on('change', function () {

                    var $current_type = $(this).val();
                    var $template_name = self.extract_template_name();
                    var append_to = $('.e20r-message-template-name_' + $template_name + '.e20r-message-schedule th.e20r-message-template-col');

                    $('.e20r-message-template-name_' + $template_name + '.e20r-message-schedule-info td.e20r-message-template-col').empty();

                    if ('-1' !== $current_type) {
                        self.add_schedule_entry(append_to, true);
                    } else {
                        self.add_schedule_entry(append_to, false);
                    }
                });

                // Disable the current template (disable input fields & tinyMCE editor
                self.disable_template.on('click', function () {

                    var $template_name = self.extract_template_name();
                    self.disable_fields($(this), $template_name);
                });

                // Save the template content to the back-end
                self.save_btn.unbind('click').on('click', function () {

                    self.save_template_settings();
                });

                self.reset_btn.unbind('click').on('click', function () {
                    // TODO: Implement reset logic
                    console.log("TODO: Implement template reset (load default template if available)");
                });

                // Add another schedule entry to the template definition
                self.update_add_action();
                self.update_remove_action();
                self.hide_first_remove_btn();
                self.update_schedule_action();
                self.activate_media_btn();

                // Loop through and enable/disable the fields for the template(s)
                $('input[name="e20r_message_template-key"]').each(function () {

                    var $template_name = $(this).val();

                    // Run on a delay (to allow TinyMCE to complete init)
                    setTimeout(function () {
                        self.disable_fields($('input#e20r-message-disabled_' + $template_name), $template_name);
                    }, 1000);
                });
            },
            activate_media_btn: function () {

                var self = this;
                self.new_media = $('button#e20r-load-media-btn');
                window.console.log("Loading click event for NEW media button: ", self.new_media );

                self.new_media.unbind('click').on('click', function () {

                    window.console.log("Clicked the media button for the new entry");

                    event.preventDefault();

                    // if the file_frame has already been created, just reuse it
                    if (self.file_frame) {
                        self.file_frame.open();
                        return;
                    }

                    self.file_frame = wp.media.frames.file_frame = wp.media({
                        title: $(this).data('uploader_title'),
                        button: {
                            text: $(this).data('uploader_button_text'),
                        },
                        multiple: false // set this to true for multiple file selection
                    });

                    self.file_frame.on('select', function () {
                        var attachment = self.file_frame.state().get('selection').first().toJSON();

                        // do something with the file here
                        $('#frontend-button').hide();
                        $('#frontend-image').attr('src', attachment.url);
                    });

                    self.file_frame.open();
                });
            },
            add_new_template_entry: function () {

                var self = this;

                event.preventDefault();
                var $new_entry = e20r_pw_editor.data.new_template;

                window.console.log("Generating empty template row/column");
                var $last_row = $('table.e20r-message-settings > tbody tr:last-child');
                $last_row.after($new_entry);

                self.update_remove_action();
                self.update_add_action();
                self.update_schedule_action();

                var settings = {
                    tinymce: {
                        add_form_submit_trigger: true,
                        add_unload_trigger: true,
                        branding: false,
                        convert_fonts_to_spans: true,
                        convert_urls: false,
                        doctype: "<!DOCTYPE html>",
                        end_container_on_empty_block: true,
                        fix_list_elements: true,
                        theme: 'modern',
                        skin: "lightgray",
                        selector: '#e20r-message-body_new',
                        height: 350,
                        font_size_legacy_values: "xx-small,small,medium,large,x-large,xx-large,300%",
                        font_size_style_values: "xx-small,x-small,small,medium,large,x-large,xx-large",
                        ie7_compat: true,
                        indent: true,
                        indent_after: "p,h1,h2,h3,h4,h5,h6,blockquote,div,title,style,pre,script,td,th,ul,ol,li,dl,dt,dd,area,table,thead,tfoot,tbody,tr,section,article,hgroup,aside,figure,figcaption,option,optgroup,datalist",
                        indent_before: "p,h1,h2,h3,h4,h5,h6,blockquote,div,title,style,pre,script,td,th,ul,ol,li,dl,dt,dd,area,table,thead,tfoot,tbody,tr,section,article,hgroup,aside,figure,figcaption,option,optgroup,datalist",
                        indentation: "30px",
                        inline_styles: true,
                        insert_button_items: "image link | inserttable | insertdatetime",
                        keep_styles: false,
                        menubar: false,
                        object_resizing: true,
                        padd_empty_editor: true,
                        plugins: "charmap,colorpicker,hr,lists,media,paste,tabfocus,textcolor,fullscreen,wordpress,wpautoresize,wpeditimage,wpemoji,wpgallery,wplink,wpdialogs,wptextpattern,wpview",
                        preview_styles: "font-family font-size font-weight font-style text-decoration text-transform",
                        remove_script_host: false,
                        remove_trailing_brs: true,
                        render_ui: true,
                        resize: "vertical",
                        toolbar1: "formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,fullscreen,wp_adv",
                        toolbar2: "strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help",
                        toolbar3: "",
                        toolbar4: "",
                        wpautop: true,
                        wpeditimage_html5_captions: true,
                        wp_shortcut_labels: {
                            'Align center': "accessC",
                            'Align left': "accessL",
                            'Align right': "accessR",
                            'Blockquote': "accessQ",
                            'Bold': "metaB",
                            'Bullet list': "accessU",
                            'Code': "accessX",
                            'Copy': "metaC",
                            'Cut': "metaX",
                            'Distraction-free writing mode': "accessW",
                            'Heading 1': "access1",
                            'Heading 2': "access2",
                            'Heading 3': "access3",
                            'Heading 4': "access4",
                            'Heading 5': "access5",
                            'Heading 6': "access6",
                            'Insert Page Break tag': "accessP",
                            'Insert Read More tag': "accessT",
                            'Insert/edit image': "accessM",
                            'Italic': "metaI",
                            'Justify': "accessJ",
                            'Keyboard Shortcuts': "accessH",
                            'Numbered list': "accessO",
                            'Paragraph': "access7",
                            'Paste': "metaV",
                            'Redo': "metaY",
                            'Remove link': "accessS",
                            'Select all': "metaA",
                            'Strikethrough': "accessD",
                            'Toolbar Toggle': "accessZ",
                            'Underline': "metaU",
                            'Undo': "metaZ"
                        }
                    },
                    quicktags: {
                        height: 350,
                        width: '100%',
                        buttons: 'strong,em,block,del,img,link,code,li,ol,ins,more,ul,spell,close'
                    }
                };

                wp.editor.initialize('e20r-message-body_new', settings);
                self.activate_media_btn();

                self.save_btn.show();
            },
            update_add_action: function () {

                var self = this;

                self.new_schedule_entry = $('.e20r-add-new-schedule');

                self.new_schedule_entry.on('click', function () {

                    event.preventDefault();

                    var current_col = $(this).closest('td.e20r-message-template-col');
                    var schedule_element = current_col.find('div.e20r-schedule-entry').last();
                    var new_element = self.add_schedule_div('input');

                    schedule_element.append(new_element);

                    self.update_schedule_action();
                    self.update_remove_action();
                    self.activate_media_btn();
                });
            }
            ,
            update_schedule_action: function () {

                var self = this;
                self.schedule_fields = $('input.e20r-message-schedule');

                self.schedule_fields.on('change', function () {
                    var me = $(this);
                    var $value = me.val();
                    me.val($value);
                    console.log("Value for element is: " + $value);
                });
            }
            ,
            update_remove_action: function () {

                var self = this;
                self.remove_entry = $('.e20r-delete-schedule-entry');
                self.remove_entry.on('click', function () {

                    var remove_btn = $(this);
                    remove_btn.closest('.e20r-schedule-entry').remove();
                });
            }
            ,
            disable_fields: function ($element, $template_name) {

                var self = this;
                var $row_selector = 'tr.e20r-message-template-name_' + $template_name;

                if ($element.attr('checked')) {

                    $($row_selector).find('input[name^="e20r_message_template-"], select[name^="e20r_message_template-"]').each(function () {

                        $(this).attr("disabled", true);
                        tinymce.get('e20r-message-body_' + $template_name).getBody().setAttribute('contenteditable', false);
                    });
                } else if (!$element.attr('checked')) {

                    $($row_selector).find('input[name^="e20r_message_template-"], select[name^="e20r_message_template-"]').each(function () {

                        $(this).attr("disabled", false);
                        tinymce.get('e20r-message-body_' + $template_name).getBody().setAttribute('contenteditable', true);
                    });
                }

                $element.attr("disabled", false);
            }
            ,
            hide_all: function ($element) {

                $element.each(function () {

                    $(this).hide();
                });
            }
            ,
            hide_first_remove_btn: function () {
                var self = this;

                self.first_remove_btn = $('.e20r-delete-schedule-entry').first();
                self.first_remove_btn.each(function () {
                    var btn = $(this);
                    btn.hide();
                })
            }
            ,
            toggle_visibility: function ($element) {

                var self = this;
                self.hide_all(self.hideable);

                $element.toggle();
                $('tr.controls').show();
            }
            ,
            add_schedule_div: function ($type) {

                var self = this;
                var $current_template = self.extract_template_name();

                var $html = '';

                if ($type === 'input') {
                    $html += '\t<div class="e20r-schedule-entry">\n';
                    $html += '\t\t<input name="e20r_message_template-schedule[]" type="number" value class="e20r-message-schedule" />&nbsp;\n';
                    $html += '\t\t<span class="e20r-message-schedule-label">' + e20r_pw_editor.lang.period_label + '</span>\n';
                    $html += '\t\t<span class="e20r-message-schedule-remove">\n';
                    $html += '\t\t\t<input type="button" value="Remove" class="e20r-delete-schedule-entry button-secondary">\n';
                    $html += '\t\t</span>\n';
                    $html += '\t</div>\n';
                } else {
                    $html += e20r_pw_editor.lang.no_schedule;
                }

                return $html;
            }
            ,
            add_schedule_entry: function (add_to, include_input) {

                var self = this;
                var $html = '<td class="e20r-message-template-col">\n';

                if (true === include_input) {
                    $html += self.add_schedule_div('input');
                    $html += '\t<button class="button-secondary e20r-add-new-schedule">' + e20r_pw_editor.lang.period_btn_label + '</button>\n';
                } else {
                    $html += self.add_schedule_div(null);
                }

                $html += '</td>';

                add_to.after($html);

                self.hide_first_remove_btn();
                self.update_remove_action();
                self.update_add_action();
                self.activate_media_btn();
                self.update_schedule_action();
            }
            ,
            extract_template_name: function () {

                return $('#e20r-message-templates').val();
            },
            reset_template_settings: function () {

                // TODO: Implement reset template logic!

                event.preventDefault();
                var self = this;

                var $template_name = self.extract_template_name();
                var $row_selector = 'tr.e20r-message-template-name_' + $template_name;

                var data = {
                    action: 'e20rpw_reset_template',
                    message_template: $($row_selector + ' input[name="message_template"]').val()
                };

                $.ajax({
                    url: e20r_pw_editor.config.save_url,
                    timeout: e20r_pw_editor.config.timeout,
                    type: 'POST',
                    data: data,
                    success: function (info) {

                    },
                    error: function () {
                        alert("Error: Unable to reset template");
                    }
                });
            },
            save_template_settings: function () {

                event.preventDefault();

                var self = this;
                var $template_name = self.extract_template_name();
                var $row_selector = 'tr.e20r-message-template-name_' + $template_name;

                var save_btn = $('#save-template-data');
                var status_message = $('.e20r-status-message');
                var $body_content = tinyMCE.get('e20r-message-body_' + $template_name).getContent();
                var schedule = [];
                var data = {
                    action: 'e20rpw_save_template',
                    message_template: $('#message_template').val()
                };

                save_btn.attr("disabled", true);

                $($row_selector + ' input.e20r-message-schedule').each(function () {

                    var value = $(this).val();

                    if (isNaN(parseInt(value))) {

                        save_btn.attr("disabled", false);
                        alert(e20r_pw_editor.lang.invalid_schedule_error + value);
                        throw new Error();
                    }

                    schedule.push(value);
                });

                $($row_selector + ' input[name^="e20r_message_template-"]').each(function () {

                    var $name = $(this).attr('name');
                    var $type = $(this).attr('type');

                    console.log("Name: " + $name + " and type: " + $type);

                    if (-1 === $name.indexOf('schedule') && 'checkbox' !== $type) {
                        data[$name] = $(this).val();
                    }

                    if ('checkbox' === $type && $(this).attr('checked')) {
                        data[$name] = true;
                    } else if ('checkbox' === $type && !$(this).attr('checked')) {
                        data[$name] = 0;
                    }

                });

                $($row_selector + ' select[name^="e20r_message_template-type"]').each(function () {

                    data[$(this).attr('name')] = $(this).val();
                });

                data['e20r_message_template-body'] = $body_content;
                data['e20r_message_template-schedule'] = schedule;

                $.ajax({
                    url: e20r_pw_editor.config.save_url,
                    timeout: e20r_pw_editor.config.timeout,
                    type: 'POST',
                    data: data,
                    success: function (response) {

                        var message = $('#message');

                        if (true === response.success) {
                            message.addClass('updated');
                        } else {
                            message.addClass('error');
                        }

                        save_btn.attr("disabled", false);

                        if (typeof response.data !== 'undefined') {
                            status_message.html(response.data.message);
                            $('tr.status').show();
                            status_message.show();
                            setTimeout(function () {
                                if (true === response.data.reload) {
                                    location.reload();
                                }
                            }, 3000);
                        }
                    },
                    error: function () {
                        alert("Error: Unable to save template updates");
                    }
                });
            }
        }
    })(jQuery);

jQuery(document).ready(function () {
    "use strict";
    e20r_editor.init();
});

