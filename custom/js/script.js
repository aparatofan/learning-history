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
						if(event.imageID){
							element.find(".fc-title").html("<img src="+event.imageID+" height='25' width='25'>");
//                             element.find(".fc-title").prepend("<img src="+event.imageID+" height='25' width='25'>");
                         }
                    },
                    eventMouseover: function(calEvent, jsEvent) {
                        var tooltip = '<div class="tooltip event-popup-meta" >' + '<span class="ev_title">'+ calEvent.title+'</span><span class="ev_description">'+calEvent.description + '</span></div>';
                        var $tooltip = $(tooltip).appendTo('body');
                        $(this).mouseover(function(e) {
                            $(this).css('z-index', 10000);
                            $tooltip.fadeIn('500');
                            $tooltip.fadeTo('10', 1.9);
                        }).mousemove(function(e) {
                            $tooltip.css('top', e.pageY + 10);
                            $tooltip.css('left', e.pageX + 20);
                        });
                    },
                    eventMouseout: function(calEvent, jsEvent) {
                        $(this).css('z-index', 8);
                        $('.tooltip').remove();
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