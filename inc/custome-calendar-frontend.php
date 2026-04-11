<?php
/**
 * Frontend integration for WP Custom Calendar.
 *
 * Responsibilities:
 *   - Enqueue CSS/JS only on pages that actually use the calendar or show a
 *     lesson, so global selectors can never bleed into other pages (this is
 *     what fixes the DIVI CSS conflict).
 *   - Provide the [wp_custom_calendar] shortcode.
 *   - Render the "Dodaj lekcję do historii" button on single lesson pages
 *     the current user has not completed yet.
 *   - Render the yellow "already completed" banner on single lesson pages
 *     the current user HAS completed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Decide whether the current request should load the plugin's CSS/JS.
 *
 * We only want the assets when:
 *   - The current post/page contains the [wp_custom_calendar] shortcode, OR
 *   - We are on a single "lesson" (by default: any single post; site owners
 *     can refine this with the wpcc_is_lesson filter).
 */
function wpcc_should_load_assets() {
    if ( is_admin() ) {
        return false;
    }

    global $post;

    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'wp_custom_calendar' ) ) {
        return true;
    }

    if ( wpcc_is_lesson( $post ) ) {
        return true;
    }

    return false;
}

/**
 * Decide whether a given post object should be treated as a "lesson" for the
 * purpose of rendering the completion button and the yellow banner.
 *
 * Defaults to any singular post. Override via the `wpcc_is_lesson` filter
 * (e.g. restrict to a specific custom post type or category).
 */
function wpcc_is_lesson( $post ) {
    $is_lesson = is_singular() && is_a( $post, 'WP_Post' );
    return (bool) apply_filters( 'wpcc_is_lesson', $is_lesson, $post );
}

/**
 * Conditionally enqueue plugin assets. Registering unconditionally but only
 * enqueueing where needed prevents the global html/body rules in style.css
 * from leaking into every page on the site and conflicting with DIVI.
 */
function wpcc_enqueue_assets() {
    // FullCalendar + moment are only needed on the calendar page itself.
    wp_register_style(
        'wpcc-fullcalendar',
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.css',
        array(),
        '3.10.2'
    );
    wp_register_script(
        'wpcc-moment',
        'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js',
        array(),
        '2.29.4',
        true
    );
    wp_register_script(
        'wpcc-fullcalendar',
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.js',
        array( 'jquery', 'wpcc-moment' ),
        '3.10.2',
        true
    );

    wp_register_style(
        'wp-custom-calendar',
        WPCC_PLUGIN_URL . 'custom/css/style.css',
        array(),
        WPCC_VERSION
    );
    wp_register_script(
        'wp-custom-calendar',
        WPCC_PLUGIN_URL . 'custom/js/script.js',
        array( 'jquery', 'wpcc-fullcalendar' ),
        WPCC_VERSION,
        true
    );

    wp_localize_script(
        'wp-custom-calendar',
        'wp_customcalendar',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpcc_complete_chapter' ),
        )
    );

    if ( ! wpcc_should_load_assets() ) {
        return;
    }

    wp_enqueue_style( 'wpcc-fullcalendar' );
    wp_enqueue_script( 'wpcc-moment' );
    wp_enqueue_script( 'wpcc-fullcalendar' );

    wp_enqueue_style( 'wp-custom-calendar' );
    wp_enqueue_script( 'wp-custom-calendar' );
}
add_action( 'wp_enqueue_scripts', 'wpcc_enqueue_assets' );

/**
 * Returns true when the current user has already completed the given lesson.
 */
function wpcc_user_completed_lesson( $post_id, $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id || ! $post_id ) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wpcc_tracking';
    $found = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE customerID = %d AND post_id = %d LIMIT 1",
            $user_id,
            $post_id
        )
    );

    return ! empty( $found );
}

/**
 * Shortcode: renders the calendar container. The actual FullCalendar
 * initialization happens in custom/js/script.js when the element exists.
 */
function wpcc_calendar_shortcode( $atts ) {
    // Render a scoping wrapper so every rule in style.css can be kept inside
    // `.wpcc-scope` and never touch the rest of the theme.
    return '<div class="wpcc-scope"><div id="wp_custom_calendar"></div></div>';
}
add_shortcode( 'wp_custom_calendar', 'wpcc_calendar_shortcode' );

/**
 * Retrieves the category/lesson icon URL used on the calendar. Tries the
 * featured image first, then falls back to the first term image if any.
 */
function wpcc_get_lesson_image( $post_id ) {
    if ( has_post_thumbnail( $post_id ) ) {
        $url = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
        if ( $url ) {
            return $url;
        }
    }
    return '';
}

/**
 * Injects either the "Dodaj lekcję do historii" button or the yellow
 * "already completed" banner into single lesson content.
 */
function wpcc_render_lesson_marker( $content ) {
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    global $post;
    if ( ! wpcc_is_lesson( $post ) ) {
        return $content;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        // No point showing a personal "done" button to logged-out users.
        return $content;
    }

    if ( wpcc_user_completed_lesson( $post->ID, $user_id ) ) {
        $banner  = '<div class="wpcc-scope">';
        $banner .= '<div class="wpcc-completed-banner" role="status">';
        $banner .= esc_html__( 'Ta lekcja została już ukończona.', 'wp-custom-calendar' );
        $banner .= '</div></div>';
        return $banner . $content;
    }

    $current_user = wp_get_current_user();
    $image_id     = wpcc_get_lesson_image( $post->ID );
    $nonce        = wp_create_nonce( 'wpcc_complete_chapter' );

    $button  = '<div class="wpcc-scope">';
    $button .= '<div class="singlecourse-complete-wrapper">';
    $button .= sprintf(
        '<button type="button" id="btn_complete_chapter" data-post_id="%d" data-image_id="%s" data-customer_id="%d" data-customer_name="%s" data-nonce="%s">%s</button>',
        (int) $post->ID,
        esc_attr( $image_id ),
        (int) $user_id,
        esc_attr( $current_user->display_name ),
        esc_attr( $nonce ),
        esc_html__( 'Dodaj lekcję do historii', 'wp-custom-calendar' )
    );
    $button .= '</div></div>';

    return $content . $button;
}
add_filter( 'the_content', 'wpcc_render_lesson_marker' );
