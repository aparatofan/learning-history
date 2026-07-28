<?php
/**
 * AJAX callback: returns the current user's completed lessons formatted for
 * FullCalendar.
 *
 * NOTE: The previous version of this file looped over `$wpdb->get_results()`
 * with `mysqli_fetch_assoc()`, which is incorrect — $wpdb returns an array of
 * objects, not a mysqli resource. The loop silently did nothing and the raw
 * array was echoed. We now build a clean event array explicitly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cleans up a stored lesson title for display in the calendar tooltip.
 *
 * Two problems are fixed here, both at read time so existing wpcc_tracking
 * rows benefit without rewriting the table:
 *
 * 1. HTML entities. The title column is populated by get_the_title(), which
 *    runs the `the_title` filters — including wptexturize(), which replaces a
 *    typed " - " with the *entity* " &#8211; " rather than a literal en dash.
 *    custom/js/script.js builds the tooltip with jQuery .text(), so the entity
 *    was rendered literally: "Talking Picture &#8211; Books and reading".
 *    Decoding here also covers curly apostrophes (&#8217;) and quotes
 *    (&#8220;), which had the same problem in any title containing them.
 *    Decoding is safe because the tooltip inserts the string as text, never
 *    as HTML.
 *
 * 2. A redundant category prefix. Lessons in some categories are titled
 *    "<Category> - <Topic>" (e.g. "Talking Picture - Books and reading"), so
 *    the tooltip repeated the category name. Only a prefix matching one of
 *    *this post's own* category names is removed, followed by a hyphen, en
 *    dash or em dash — so an ordinary title that merely contains a dash is
 *    left alone.
 *
 * Note this affects the calendar tooltip only. The lesson's own page still
 *    shows its real, full post title.
 */
function wpcc_clean_event_title( $title, $post_id = 0 ) {
    $title = trim( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );

    if ( ! $post_id || '' === $title ) {
        return $title;
    }

    $categories = get_the_category( $post_id );
    if ( empty( $categories ) ) {
        return $title;
    }

    foreach ( $categories as $cat ) {
        $name = trim( html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $name ) {
            continue;
        }

        // "<name>" + optional space + hyphen / en dash / em dash + space.
        $pattern  = '/^' . preg_quote( $name, '/' ) . '\s*(?:-|\x{2013}|\x{2014})\s*/ui';
        $stripped = preg_replace( $pattern, '', $title, 1 );

        // preg_replace() returns null on a malformed-UTF-8 subject; and never
        // strip a title down to nothing, which would leave a blank tooltip.
        if ( null === $stripped || $stripped === $title || '' === trim( $stripped ) ) {
            continue;
        }

        return trim( $stripped );
    }

    return $title;
}

function getprivate_event_callback() {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        echo wp_json_encode( array() );
        wp_die();
    }

    $wpcct_name = $wpdb->prefix . 'wpcc_tracking';

    // Newest row first so when we dedupe below we keep the most recent record
    // for each lesson. post_id > 0 drops legacy orphan rows from the old
    // pre-rewrite plugin, which stored the shared category icon in imageID
    // without populating post_id — those rows rendered as stale icons on
    // top of the real featured image for the same lesson and could not be
    // deduped against it (post_id 0 != real post_id).
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $wpcct_name WHERE customerID = %d AND post_id > 0 ORDER BY id DESC",
            $user_id
        )
    );

    $event_array = array();
    $seen        = array();
    if ( is_array( $results ) ) {
        foreach ( $results as $row ) {
            $post_id = isset( $row->post_id ) ? (int) $row->post_id : 0;
            if ( $post_id && isset( $seen[ $post_id ] ) ) {
                continue;
            }
            $seen[ $post_id ] = true;

            // Resolve the icon at query time rather than trusting the stored
            // imageID column. Legacy rows (pre-1.3.0) stored the post's
            // featured image there, which rendered as a rectangular photo on
            // the calendar. By calling wpcc_get_lesson_image() here we always
            // get the current category icon for the lesson — no need to
            // rewrite the DB. Falls back to the stored value if the helper
            // is unavailable.
            $resolved_icon = '';
            if ( $post_id && function_exists( 'wpcc_get_lesson_image' ) ) {
                $resolved_icon = wpcc_get_lesson_image( $post_id );
            }
            if ( empty( $resolved_icon ) ) {
                $resolved_icon = isset( $row->imageID ) ? $row->imageID : '';
            }

            $event_array[] = array(
                'id'          => isset( $row->id ) ? (int) $row->id : 0,
                'title'       => wpcc_clean_event_title( isset( $row->title ) ? $row->title : '', $post_id ),
                'description' => isset( $row->description ) ? $row->description : '',
                'start'       => isset( $row->start ) ? $row->start : '',
                'imageID'     => $resolved_icon,
                'allDay'      => true,
                'post_id'     => $post_id,
            );
        }
    }

    echo wp_json_encode( $event_array );
    wp_die();
}
