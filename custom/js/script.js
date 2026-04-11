/*
* @Author: ashokkuikel
* @Date:   2020-03-04 17:39:30
* @Last Modified by:   ashokkuikel
* @Last Modified time: 2020-04-28 12:44:11
*/
jQuery(document).ready(function($) {
    // Announce to the browser console which version of this script is live.
    // The number after "v=" should match WPCC_VERSION in wp-custom-calendar.php.
    // If you refresh a lesson page and don't see this log line, the browser
    // (or a caching plugin / CDN) is still serving a stale copy of script.js.
    try { console.log('[wpcc] script.js v=1.3.0 loaded'); } catch (e) {}

    // ---------------------------------------------------------------------
    // Auto-hide the "THE END OF THE LESSON" DIVI section on completed /
    // hidden lessons. Runs only when body has wpcc-lesson-hide-section (set
    // by wpcc_body_class in PHP). We can't rely on the site owner tagging
    // the DIVI row with a CSS class, because on thebluetree.pl the row
    // lives in a Theme Builder template they can't easily find. So we walk
    // the DOM, look for the known heading text, and hide the nearest DIVI
    // section ancestor.
    // ---------------------------------------------------------------------
    (function autoHideEndOfLesson() {
        if (!document.body || !document.body.classList ||
            !document.body.classList.contains('wpcc-lesson-hide-section')) {
            return;
        }
        var needles = [
            'END OF THE LESSON',
            'END OF LESSON',
            'KONIEC LEKCJI',
            'KONIEC THE LESSON'
        ];
        var $candidates = $('h1, h2, h3, h4, h5, h6, .et_pb_text_inner');
        var $found = null;
        $candidates.each(function() {
            var text = ($(this).text() || '').trim().toUpperCase();
            for (var i = 0; i < needles.length; i++) {
                if (text.indexOf(needles[i]) !== -1) {
                    $found = $(this);
                    return false; // break .each
                }
            }
        });
        if (!$found || !$found.length) {
            try { console.log('[wpcc] end-of-lesson heading not found'); } catch (e) {}
            return;
        }
        var $target = $found.closest('.et_pb_section');
        if (!$target.length) { $target = $found.closest('.et_pb_row'); }
        if (!$target.length) { $target = $found.closest('section'); }
        if (!$target.length) { $target = $found.parents('[class*="et_pb_"]').last(); }
        if ($target.length) {
            $target.addClass('wpcc-end-of-lesson').hide();
            try { console.log('[wpcc] end-of-lesson section hidden'); } catch (e) {}
        } else {
            // Last resort: hide the heading itself plus its next sibling
            // (typically the grey success box module).
            $found.hide();
            $found.next().hide();
        }
    })();

    if (document.getElementById('wp_custom_calendar')) {
        $(window).bind("load", function(){
            var data = {
                'action': 'getprivate_event',
                dataType: "json",
            };
            var event_data = "";
            $.post(wp_customcalendar.ajax_url, data, function (event_response) {
                event_data = JSON.parse(event_response);
                // Client-side safety dedup. The server already dedupes by
                // post_id in getevents.php, but if the same post_id ever
                // slips through (legacy rows with different titles, or
                // post_id = 0) we still refuse to render two icons on the
                // same day for the same lesson.
                try {
                    var seen = {};
                    var deduped = [];
                    for (var i = 0; i < event_data.length; i++) {
                        var ev = event_data[i];
                        var key = ev.post_id
                            ? 'p:' + ev.post_id
                            : 't:' + (ev.title || '') + '|d:' + (ev.start || '');
                        if (seen[key]) { continue; }
                        seen[key] = true;
                        deduped.push(ev);
                    }
                    event_data = deduped;
                } catch (e) {}
                try { console.log('[wpcc] calendar events:', event_data); } catch (e) {}
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
        var $wrapper = $button.parents('.singlecourse-complete-wrapper');
        var currentDate = new Date();
        // Use YYYY for the year token — "Y" in moment emits ISO-8601 signed
        // year (can be +10000 for out-of-range). YYYY is the safe MySQL
        // DATETIME-compatible form.
        currentDate =  moment(currentDate).format("YYYY-MM-DD HH:mm:ss");
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
        // Hide the wrapper only optimistically — if the server rejects, we
        // show it again so the user can retry without reloading.
        $wrapper.hide();
        $.post(wp_customcalendar.ajax_url, course_data, function(response) {
            var res_data;
            try {
                res_data = (response && response.data) ? jQuery.parseJSON(response.data) : null;
            } catch (e) { res_data = null; }
            if (!res_data) {
                try { console.log('[wpcc] bad complete_chapter_report response:', response); } catch (e) {}
                Toast('error', 'toast-bottom-full-width', 'Unexpected server response. See console.');
                $wrapper.show();
                return;
            }
            try { console.log('[wpcc] complete_chapter_report:', res_data); } catch (e) {}
            Toast(res_data['status_class'], 'toast-bottom-full-width', res_data['message']);
            if (res_data['status_class'] === 'success' || res_data['status_class'] === 'info') {
                // Only reload on actual success so the user sees the yellow
                // banner appear. On error, keep the page so the toast stays
                // visible and the button is still usable.
                setTimeout(function() {
                    document.location.reload();
                }, 1500);
            } else {
                // Error — put the button back so the user can try again.
                $wrapper.show();
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            try { console.log('[wpcc] AJAX failed:', textStatus, errorThrown, jqXHR); } catch (e) {}
            Toast('error', 'toast-bottom-full-width', 'Network error: ' + textStatus);
            $wrapper.show();
        });
    });
	 function Toast(type, css, msg) {
		toastr.options.extendedTimeOut = 0; //1000;
    	toastr.options.fadeIn = 500;
        toastr.options.positionClass = css;
        toastr[type](msg);
    }
});