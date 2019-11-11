/**
 * Copyright (c) 2017-2019 - Eighty / 20 Results by Wicked Strong Chicks.
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

// noinspection JSNonStrictModeUsed
jQuery(document).ready(function ($) {
	"use strict";

	$('#e20r-email-notice-examples #send-example').on('click',function(e){
		e.preventDefault();
		window.console.log("Clicked 'Send' for Example email");

		var data = {
			action: 'e20pw_send_sample',
			'e20rpw_msg_type': $('#e20r-email-notice-type-select').val(),
			'e20rpw_msg_id': $('#post_ID').val(),
			'_wpnonce': $('#_wpnonce').val()
		};

		$.ajax({
			url: ajaxurl,
			timeout: parseInt(e20rpw.timeout) * 1000,
			dataType: 'json',
			method: 'POST',
			data: data,
			success: function( $response, $textStatus, jqXHR ) {

				if (true === $response.success) {
					window.console.log("Returned: ", $response);

					if (typeof $response.data !== 'undefined') {
						window.console.log("Error sending example message");
						return false;
					}
				} else if (false === $response.success) {
					window.console.log("Error while sending example message!");
					return false;
				}
			},
			error: function( jqXHR, $textStatus, $errorThrown ) {
				window.console.log('Error returned: ' + $textStatus);

				if ( 'timeout' === $textStatus ){
					window.alert('Unable to process request in a timely fashion. Please reload and try again.');
				}
			}
		});
	})
});
