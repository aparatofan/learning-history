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
    // for each lesson. This matters because earlier versions of the plugin
    // could insert multiple rows per lesson (and with the pre-fix imageID =
    // featured image instead of the category icon), which would render as two
    // stacked images in the calendar cell.
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $wpcct_name WHERE customerID = %d ORDER BY id DESC",
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

            $event_array[] = array(
                'id'          => isset( $row->id ) ? (int) $row->id : 0,
                'title'       => isset( $row->title ) ? $row->title : '',
                'description' => isset( $row->description ) ? $row->description : '',
                'start'       => isset( $row->start ) ? $row->start : '',
                'imageID'     => isset( $row->imageID ) ? $row->imageID : '',
                'allDay'      => true,
                'post_id'     => $post_id,
            );
        }
    }

    echo wp_json_encode( $event_array );
    wp_die();
}
