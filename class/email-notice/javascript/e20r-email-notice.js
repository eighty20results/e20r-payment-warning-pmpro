/**
 * Copyright (c) 2017-2018 - Eighty / 20 Results by Wicked Strong Chicks.
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
 * @version 2.3
 */

var e20r_email_notice = {};

(function ($) {
	"use strict";
	// noinspection JSNonStrictModeUsed
	e20r_email_notice = {
		init: function () {

			this.hideable = $('.e20r-start-hidden');
			this.new_schedule_entry = $('.e20r-add-new-schedule');
			this.remove_entry = $('.e20r-delete-schedule-entry');
			this.first_remove_btn = this.remove_entry.first();
			this.schedule_fields = $('input.e20r-message-schedule');

			var self = this;

			self.hide_all(self.hideable);

			// Add another schedule entry to the template definition
			self.update_add_action();
			self.update_remove_action();
			self.hide_first_remove_btn();
			self.update_schedule_action();

			/*
			// Loop through and enable/disable the fields for the template(s)
			$('input[name="e20r_message_template-key"]').each(function () {

				var $template_name = $(this).val();

				// Run on a delay (to allow TinyMCE to complete init)
				setTimeout(function () {
					self.disable_fields($('input#e20r-message-disabled_' + $template_name), $template_name);
				}, 500);
			});
			*/
		},
		update_add_action: function () {

			var self = this;

			self.new_schedule_entry = $('.e20r-add-new-schedule');

			self.new_schedule_entry.on('click', function () {

				event.preventDefault();

				var current_col = $(this).closest('div.e20r-message-template');
				var schedule_element = current_col.find('div.e20r-schedule-entry').last();
				var new_element = self.add_schedule_div('input');

				schedule_element.append(new_element);

				self.update_schedule_action();
				self.update_remove_action();
			});
		},
		update_schedule_action: function () {

			var self = this;
			self.schedule_fields = $('input.e20r-message-schedule');

			self.schedule_fields.on('change', function () {
				var me = $(this);
				var $value = me.val();
				me.val($value);
				window.console.log("Value for element is: " + $value);
			});
		},
		update_remove_action: function () {

			var self = this;
			self.remove_entry = $('.e20r-delete-schedule-entry');
			self.remove_entry.on('click', function () {

				var remove_btn = $(this);
				remove_btn.closest('.e20r-schedule-entry').remove();
			});
		},
		/*
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
		}*/
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
			});
		},
		add_schedule_div: function ($type) {

			var self = this;
			var $html = '';

			if ($type === 'input') {
				$html += '\t<div class="e20r-schedule-entry">\n';
				$html += '\t\t<input name="e20r_message_template-schedule[]" type="number" value class="e20r-message-schedule" />&nbsp;\n';
				$html += '\t\t<span class="e20r-message-schedule-remove">\n';
				$html += '\t\t\t<input type="button" value="Remove" class="e20r-delete-schedule-entry button-secondary">\n';
				$html += '\t\t</span>\n';
				$html += '\t</div>\n';
			} else {
				$html += e20r_email_notice.lang.no_schedule;
			}

			return $html;
		}
		,
		add_schedule_entry: function (add_to, include_input) {

			var self = this;
			var $html = '<td class="e20r-message-template-col">\n';

			if (true === include_input) {
				$html += self.add_schedule_div('input');
				$html += '\t<button class="button-secondary e20r-add-new-schedule">' + e20r_email_notice.lang.period_btn_label + '</button>\n';
			} else {
				$html += self.add_schedule_div(null);
			}

			$html += '</td>';

			add_to.after($html);

			self.hide_first_remove_btn();
			self.update_remove_action();
			self.update_add_action();
			self.update_schedule_action();
		}
	};
})(jQuery);

jQuery(document).ready(function () {
	'use strict';
	// noinspection JSNonStrictModeUsed
    e20r_email_notice.init();
});

