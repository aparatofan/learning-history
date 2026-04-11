<?php
/**
 * AJAX callbacks that write to the wpcc_tracking table.
 *
 * The main addition vs. the original file is `wpcc_complete_chapter_report_callback`,
 * which actually persists a completed lesson into the DB. Without it, the
 * "Dodaj lekcję do historii" button on single lesson pages did nothing — the
 * JS POSTed to `complete_chapter_report` but no PHP action ever handled it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function addprivate_event_callback() {
    $user_data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
    $event_array = array(
        array(
            'status' => 'true',
            'n_data' => wp_json_encode( $user_data ),
        ),
    );
    echo wp_json_encode( $event_array );
    wp_die();
}

function modifyprivate_event_callback() {
    $user_data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
    $event_array = array(
        array(
            'status' => 'true',
            'n_data' => wp_json_encode( $user_data ),
        ),
    );
    echo wp_json_encode( $event_array );
    wp_die();
}

function deleteprivate_event_callback() {
    $user_data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
    $event_array = array(
        array(
            'status'  => 'true',
            'deleted' => isset( $user_data['id'] ) ? $user_data['id'] : null,
        ),
    );
    echo wp_json_encode( $event_array );
    wp_die();
}

/**
 * Persist a completed lesson for the current user and return a status payload
 * the frontend toast can consume.
 */
function wpcc_complete_chapter_report_callback() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wpcc_complete_chapter' ) ) {
        wp_send_json(
            array(
                'data' => wp_json_encode(
                    array(
                        'status_class' => 'error',
                        'message'      => __( 'Sesja wygasła, odśwież stronę i spróbuj ponownie.', 'wp-custom-calendar' ),
                    )
                ),
            )
        );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json(
            array(
                'data' => wp_json_encode(
                    array(
                        'status_class' => 'error',
                        'message'      => __( 'Musisz być zalogowany.', 'wp-custom-calendar' ),
                    )
                ),
            )
        );
    }

    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $image_id      = isset( $_POST['image_id'] ) ? esc_url_raw( wp_unslash( $_POST['image_id'] ) ) : '';
    $customer_name = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
    $current_date  = isset( $_POST['currentDate'] ) ? sanitize_text_field( wp_unslash( $_POST['currentDate'] ) ) : current_time( 'mysql' );

    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json(
            array(
                'data' => wp_json_encode(
                    array(
                        'status_class' => 'error',
                        'message'      => __( 'Nie znaleziono lekcji.', 'wp-custom-calendar' ),
                    )
                ),
            )
        );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wpcc_tracking';

    $already = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE customerID = %d AND post_id = %d LIMIT 1",
            $user_id,
            $post_id
        )
    );

    if ( $already ) {
        wp_send_json(
            array(
                'data' => wp_json_encode(
                    array(
                        'status_class' => 'info',
                        'message'      => __( 'Ta lekcja jest już w Twojej historii.', 'wp-custom-calendar' ),
                    )
                ),
            )
        );
    }

    $inserted = $wpdb->insert(
        $table,
        array(
            'customerID'    => $user_id,
            'customer_name' => $customer_name,
            'post_id'       => $post_id,
            'title'         => get_the_title( $post_id ),
            'description'   => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
            'imageID'       => $image_id,
            'start'         => $current_date,
        ),
        array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
    );

    if ( false === $inserted ) {
        // Surface the real MySQL error to the toast so it's actually
        // debuggable. $wpdb->last_error is the raw error message from the
        // driver, e.g. "Unknown column 'foo' in 'field list'" or "Data too
        // long for column 'title' at row 1".
        $db_error = $wpdb->last_error ? ' (' . $wpdb->last_error . ')' : '';
        wp_send_json(
            array(
                'data' => wp_json_encode(
                    array(
                        'status_class' => 'error',
                        'message'      => __( 'Nie udało się zapisać lekcji. Spróbuj ponownie.', 'wp-custom-calendar' ) . $db_error,
                    )
                ),
            )
        );
    }

    // Let the rest of the plugin react (e.g. clear DIVI's static CSS cache so
    // the yellow banner becomes visible without a manual purge).
    do_action( 'wpcc_lesson_completed', $post_id, $user_id );

    wp_send_json(
        array(
            'data' => wp_json_encode(
                array(
                    'status_class' => 'success',
                    'message'      => __( 'Lekcja została dodana do Twojej historii.', 'wp-custom-calendar' ),
                )
            ),
        )
    );
}
