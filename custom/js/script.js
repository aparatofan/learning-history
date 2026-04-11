/*
* @Author: ashokkuikel
* @Date:   2020-03-04 17:39:30
* @Last Modified by:   ashokkuikel
* @Last Modified time: 2020-04-28 12:44:11
*/
jQuery(document).ready(function($) {
    if (document.getElementById('wp_custom_calendar')) {
        $(window).bind("load", function(){
            var data = {
                'action': 'getprivate_event',
                dataType: "json",
            };
            var event_data = "";
            $.post(wp_customcalendar.ajax_url, data, function (event_response) {
                event_data = JSON.parse(event_response);
                console.log(event_data);
            });
            $(document).ajaxComplete(function(){
                var calendar = $('#wp_custom_calendar').fullCalendar({
                    // defaultView: 'dayGridMonth',
                    header: {
                      left: 'dayGridMonth,timeGridWeek,timeGridDay',
                      center: 'title',
                      right: 'back prevYear,prev,next,nextYear'
                    },
                    footer: {
                      left: 'dayGridMonth,timeGridWeek,timeGridDay custom1',
                      center: '',
                      right: 'prev,next'
                    },
                    customButtons: {
                      back: {
                        text: 'Back',
                        click: function() {
                          //alert('clicked custom button 1!');
                        }
                      }
                    },
					firstDay: 1,
                    events: event_data,
                    aspectRatio: 1.8,
					contentHeight: 'auto',
					height: 'auto',
					handleWindowResize: true,
                    displayEventTime: false,
                    eventRender: function (event, element, view) {
                        if (event.allDay === 'true') {
                            event.allDay = true;
                        } else {
                            event.allDay = false;
                        }
                        // Replace the WHOLE event element's inner HTML with a
                        // single clean icon. Wiping element.html() (not just
                        // .fc-content) guarantees no FullCalendar decorations
                        // (dot, title, time) or stale thumbnail can remain
                        // stacked next to the category icon.
                        element.addClass('wpcc-lesson-event');
                        element.removeAttr('title');
                        var inner = '<div class="fc-content">';
                        if (event.imageID) {
                            var safeSrc = String(event.imageID)
                                .replace(/&/g, '&amp;')
                                .replace(/"/g, '&quot;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;');
                            inner += '<img class="wpcc-lesson-icon" alt="" src="' + safeSrc + '" />';
                        }
                        inner += '</div>';
                        element.html(inner);
                    },
                    eventMouseover: function(calEvent, jsEvent) {
                        // Title-only tooltip. No excerpt, no image — just the
                        // lesson title on hover.
                        $('.wpcc-event-tooltip').remove();
                        var $tooltip = $('<div class="wpcc-event-tooltip" role="tooltip"></div>').text(calEvent.title || '').appendTo('body');
                        $(this).css('z-index', 10000);
                        $tooltip.css({ top: jsEvent.pageY + 10, left: jsEvent.pageX + 20 }).fadeIn(150);
                        $(this).on('mousemove.wpccTooltip', function(e) {
                            $tooltip.css({ top: e.pageY + 10, left: e.pageX + 20 });
                        });
                    },
                    eventMouseout: function(calEvent, jsEvent) {
                        $(this).css('z-index', 8).off('mousemove.wpccTooltip');
                        $('.wpcc-event-tooltip').remove();
                    },
                });
            });
        });
    }
    $('#btn_complete_chapter').click(function(){
        var $button = $(this);
		$button.parents('.singlecourse-complete-wrapper').hide();
        var currentDate = new Date();
        currentDate =  moment(currentDate).format("Y-MM-DD HH:mm:ss");
        // $button.width( $button.width() ).text('...').prop('disabled', true);
        var course_data = {
            'action' : 'complete_chapter_report',
             dataType: 'json',
            'post_id': $button.data('post_id'),
			'image_id': $button.data('image_id'),
            'customer_id': $button.data('customer_id'),
            'customer_name': $button.data('customer_name'),
            'currentDate': currentDate,
            'nonce'  : $button.data('nonce'),
        };
        $.post(wp_customcalendar.ajax_url, course_data, function(response) {
			var res_data = jQuery.parseJSON(response.data);
			console.log(res_data['status_class']);
			Toast(res_data['status_class'], 'toast-bottom-full-width', res_data['message']);
			setTimeout(function() {
				document.location.reload()
		  }, 2500);
        });
    });
	 function Toast(type, css, msg) {
		toastr.options.extendedTimeOut = 0; //1000;
    	toastr.options.fadeIn = 500;
        toastr.options.positionClass = css;
        toastr[type](msg);
    }
});