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
                'title'       => isset( $row->title ) ? $row->title : '',
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
