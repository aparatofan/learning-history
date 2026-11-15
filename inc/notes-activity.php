<?php
/**
 * Reports each newly completed lesson to the TBT Notes activity log.
 *
 * Learning History keeps its own record in wpcc_tracking; this only adds one
 * `lesson` row to Notes' log, which is what TBT Results shows and what TBT
 * Points earns from. Nothing here reads or changes wpcc_tracking.
 *
 * - Runs on `wpcc_lesson_completed`, which fires only after a *new* row was
 *   inserted, so a lesson already in the history is never reported twice.
 * - The user comes from the server-side completion (the logged-in user),
 *   never from the request.
 * - The time is the server's "now", not the browser-supplied `currentDate`.
 * - Fails open: without Notes (or Notes older than 1.22.0) nothing happens,
 *   and no error here can break saving the lesson.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @param int $post_id The lesson.
 * @param int $user_id The student (the current user).
 */
function wpcc_report_lesson_to_notes( $post_id = 0, $user_id = 0 ) {
    if ( ! function_exists( 'tbt_notes_record_activity' ) ) {
        return;
    }

    $post_id = absint( $post_id );
    $user_id = absint( $user_id );
    if ( ! $post_id || ! $user_id ) {
        return;
    }

    try {
        $result = tbt_notes_record_activity(
            array(
                'user_id'      => $user_id,
                'tool'         => 'lesson',
                'object_ref'   => (string) $post_id,
                'object_title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
                'post_id'      => $post_id,
            )
        );

        // A teacher or admin in no class is deliberately not recorded by
        // Notes; anything else is worth a log line.
        if ( is_wp_error( $result ) && 'tbt_notes_no_class' !== $result->get_error_code() ) {
            error_log( 'Learning History: lesson not reported to TBT Notes: ' . $result->get_error_message() );
        }
    } catch ( \Throwable $e ) {
        error_log( 'Learning History: lesson not reported to TBT Notes: ' . $e->getMessage() );
    }
}
add_action( 'wpcc_lesson_completed', 'wpcc_report_lesson_to_notes', 20, 2 );
