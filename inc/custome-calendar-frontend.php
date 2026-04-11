<?php
/**
 * Frontend integration for WP Custom Calendar.
 *
 * Responsibilities:
 *   - Enqueue CSS/JS only on pages that actually use the calendar or show a
 *     lesson, so global selectors can never bleed into other pages (this is
 *     what fixes the DIVI CSS conflict).
 *   - Provide the [wp_customcalendar] shortcode (renders the calendar).
 *   - Provide the [wp_custom_calendar_single] shortcode (renders the
 *     end-of-lesson info box and the "Dodaj lekcję do historii" button).
 *   - Render the yellow "already completed" banner on single lesson pages
 *     the current user HAS completed.
 *   - Register the "Chapter Complete Button > Hide Button" metabox so the
 *     section can be suppressed on free lessons / non-lesson posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =====================================================================
 * Shortcode / context helpers
 * ================================================================== */

/**
 * Shortcode tags the plugin owns. These EXACTLY match the names the existing
 * content on the site uses, which is why both variants are here:
 *
 *   [wp_customcalendar]          — renders the calendar (history page)
 *   [wp_custom_calendar_single]  — renders the end-of-lesson section
 *
 * `wp_custom_calendar` (with an underscore between "custom" and "calendar")
 * is registered as a back-compat alias so older content keeps working.
 */
function wpcc_shortcodes() {
    return array( 'wp_customcalendar', 'wp_custom_calendar', 'wp_custom_calendar_single' );
}

/**
 * Returns true when the given post's content contains any of the plugin's
 * shortcodes.
 */
function wpcc_post_has_plugin_shortcode( $post ) {
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return false;
    }
    foreach ( wpcc_shortcodes() as $tag ) {
        if ( has_shortcode( $post->post_content, $tag ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Decide whether the current request should load the plugin's CSS/JS.
 *
 * We only want the assets when:
 *   - The current post/page contains one of the plugin's shortcodes, OR
 *   - We are on a single "lesson" (see wpcc_is_lesson).
 */
function wpcc_should_load_assets() {
    if ( is_admin() ) {
        return false;
    }

    global $post;

    if ( wpcc_post_has_plugin_shortcode( $post ) ) {
        return true;
    }

    if ( wpcc_is_lesson( $post ) ) {
        return true;
    }

    return false;
}

/**
 * Decide whether a given post object should be treated as a "lesson" for the
 * purpose of rendering the completion button / yellow banner / success box.
 *
 * Default: any singular post whose post_type is NOT `page`, and that does
 * not already contain a plugin shortcode (so the calendar page itself is
 * never treated as a lesson). Override via the `wpcc_is_lesson` filter to
 * restrict to a specific custom post type or category.
 */
function wpcc_is_lesson( $post ) {
    if ( ! is_singular() || ! is_a( $post, 'WP_Post' ) ) {
        return false;
    }

    $is_lesson = ( 'page' !== $post->post_type );

    if ( wpcc_post_has_plugin_shortcode( $post ) ) {
        $is_lesson = false;
    }

    return (bool) apply_filters( 'wpcc_is_lesson', $is_lesson, $post );
}

/* =====================================================================
 * Asset registration
 * ================================================================== */

/**
 * Conditionally enqueue plugin assets. Registering unconditionally but only
 * enqueueing where needed prevents the global rules in style.css from
 * leaking into every page on the site and conflicting with DIVI.
 */
function wpcc_enqueue_assets() {
    // Everything the plugin needs is shipped locally in /lib so the plugin
    // is fully self-contained and does not depend on a third-party CDN.
    //
    // FullCalendar v3.9.0 is the version the monolithic `$().fullCalendar()`
    // API in custom/js/script.js expects; the v4.x files also present in
    // /lib are leftovers from an unfinished migration and are NOT loaded.

    wp_register_style(
        'wpcc-fullcalendar',
        WPCC_PLUGIN_URL . 'lib/css/fullcalendar.min.css',
        array(),
        '3.9.0'
    );
    wp_register_style(
        'wpcc-toastr',
        WPCC_PLUGIN_URL . 'lib/css/toastr.min.css',
        array(),
        '2.1.3'
    );
    wp_register_script(
        'wpcc-moment',
        WPCC_PLUGIN_URL . 'lib/js/moment.min.js',
        array(),
        '2.24.0',
        true
    );
    wp_register_script(
        'wpcc-fullcalendar',
        WPCC_PLUGIN_URL . 'lib/js/fullcalendar.min.js',
        array( 'jquery', 'wpcc-moment' ),
        '3.9.0',
        true
    );
    wp_register_script(
        'wpcc-toastr',
        WPCC_PLUGIN_URL . 'lib/js/toastr.min.js',
        array( 'jquery' ),
        '2.1.3',
        true
    );

    wp_register_style(
        'wp-custom-calendar',
        WPCC_PLUGIN_URL . 'custom/css/style.css',
        array( 'wpcc-fullcalendar', 'wpcc-toastr' ),
        WPCC_VERSION
    );
    wp_register_script(
        'wp-custom-calendar',
        WPCC_PLUGIN_URL . 'custom/js/script.js',
        array( 'jquery', 'wpcc-fullcalendar', 'wpcc-toastr' ),
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
    wp_enqueue_style( 'wpcc-toastr' );
    wp_enqueue_script( 'wpcc-moment' );
    wp_enqueue_script( 'wpcc-fullcalendar' );
    wp_enqueue_script( 'wpcc-toastr' );

    wp_enqueue_style( 'wp-custom-calendar' );
    wp_enqueue_script( 'wp-custom-calendar' );
}
add_action( 'wp_enqueue_scripts', 'wpcc_enqueue_assets' );

/* =====================================================================
 * Lesson-state lookups
 * ================================================================== */

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
 * Respects the per-post "Hide Button" toggle in the Chapter Complete Button
 * metabox. Used to suppress the whole end-of-lesson section on free lessons
 * or posts that aren't part of the lesson catalogue.
 */
function wpcc_is_button_hidden( $post_id ) {
    $value = get_post_meta( $post_id, '_wpcc_hide_button', true );
    $is_hidden = in_array( $value, array( 'yes', '1', 1, true, 'true', 'on' ), true );
    return (bool) apply_filters( 'wpcc_is_button_hidden', $is_hidden, $post_id );
}

/**
 * Returns a URL to use as the calendar icon for a given lesson. Tries in order:
 *
 *   1. Term-meta fields on the post's first category (category_icon, icon,
 *      category_image, thumbnail_id). This matches the common
 *      "category images" / ACF pattern.
 *   2. The post's featured image.
 *
 * Sites using a non-standard term meta key can hook into the `wpcc_lesson_image`
 * filter to supply their own URL.
 */
function wpcc_get_lesson_image( $post_id ) {
    $image_url = '';

    $categories = get_the_category( $post_id );
    if ( ! empty( $categories ) ) {
        $meta_keys = array( 'category_icon', 'icon', 'category_image', 'thumbnail_id' );
        foreach ( $categories as $cat ) {
            foreach ( $meta_keys as $meta_key ) {
                $value = get_term_meta( $cat->term_id, $meta_key, true );
                if ( empty( $value ) ) {
                    continue;
                }
                if ( is_numeric( $value ) ) {
                    $resolved = wp_get_attachment_url( (int) $value );
                    if ( $resolved ) {
                        $image_url = $resolved;
                        break 2;
                    }
                    continue;
                }
                if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $image_url = $value;
                    break 2;
                }
            }
        }
    }

    if ( empty( $image_url ) && has_post_thumbnail( $post_id ) ) {
        $thumb = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
        if ( $thumb ) {
            $image_url = $thumb;
        }
    }

    return apply_filters( 'wpcc_lesson_image', $image_url, $post_id );
}

/* =====================================================================
 * Shortcodes
 * ================================================================== */

/**
 * Shortcode: renders the calendar container. The actual FullCalendar
 * initialization happens in custom/js/script.js when the element exists.
 */
function wpcc_calendar_shortcode( $atts ) {
    // Scoping wrapper so every rule in style.css stays inside `.wpcc-scope`.
    return '<div class="wpcc-scope"><div id="wp_custom_calendar"></div></div>';
}
add_shortcode( 'wp_customcalendar', 'wpcc_calendar_shortcode' );
add_shortcode( 'wp_custom_calendar', 'wpcc_calendar_shortcode' ); // back-compat alias

/**
 * Shortcode: renders the end-of-lesson section (success info box + the
 * "Dodaj lekcję do historii" button, or the yellow banner if the lesson is
 * already completed). Respects the Hide Button metabox.
 */
function wpcc_single_shortcode( $atts ) {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return '';
    }
    return wpcc_render_lesson_section( $post->ID );
}
add_shortcode( 'wp_custom_calendar_single', 'wpcc_single_shortcode' );

/* =====================================================================
 * Renderers
 * ================================================================== */

/**
 * Renders the full end-of-lesson section for the given post, honoring the
 * user's completion state and the Hide Button toggle.
 */
function wpcc_render_lesson_section( $post_id ) {
    if ( wpcc_is_button_hidden( $post_id ) ) {
        return '';
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }

    if ( wpcc_user_completed_lesson( $post_id, $user_id ) ) {
        return wpcc_render_completed_banner();
    }

    return wpcc_render_success_box() . wpcc_render_complete_button( $post_id );
}

/**
 * Yellow "already completed" banner.
 */
function wpcc_render_completed_banner() {
    return '<div class="wpcc-scope"><div class="wpcc-completed-banner" role="status">'
        . esc_html__( 'Ta lekcja została już ukończona.', 'wp-custom-calendar' )
        . '</div></div>';
}

/**
 * Grey info box that reminds the user to mark the lesson as done.
 * This is the "end of lesson" section from the original design.
 */
function wpcc_render_success_box() {
    $title   = esc_html__( 'ŚWIETNIE! LEKCJA UKOŃCZONA!', 'wp-custom-calendar' );
    $line1   = esc_html__( 'Pamiętaj, żeby dodać lekcję do zrobionych.', 'wp-custom-calendar' );
    $line2   = esc_html__( 'W ten sposób zapiszesz lekcję w swojej historii nauki. Możesz ją sprawdzić z poziomu swojego profilu.', 'wp-custom-calendar' );

    // Inline SVG for the calendar icon — avoids a dependency on an icon font
    // (the original used ETmodules) and keeps the box self-contained.
    $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="72" height="72" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<rect x="3" y="4.5" width="18" height="17" rx="2" ry="2"/>'
        . '<line x1="16" y1="2.5" x2="16" y2="6.5"/>'
        . '<line x1="8"  y1="2.5" x2="8"  y2="6.5"/>'
        . '<line x1="3"  y1="10"  x2="21" y2="10"/>'
        . '<circle cx="15.5" cy="16" r="3.2"/>'
        . '<polyline points="15.5,14.2 15.5,16 17,16.8"/>'
        . '</svg>';

    $html  = '<div class="wpcc-scope">';
    $html .= '<div class="wpcc-success-box">';
    $html .= '  <div class="wpcc-success-box__body">';
    $html .= '    <h3 class="wpcc-success-box__title">' . $title . '</h3>';
    $html .= '    <p class="wpcc-success-box__text">' . $line1 . '</p>';
    $html .= '    <p class="wpcc-success-box__text">' . $line2 . '</p>';
    $html .= '  </div>';
    $html .= '  <div class="wpcc-success-box__icon">' . $icon . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * The "Dodaj lekcję do historii" button, with all the data-* attributes
 * custom/js/script.js expects on click.
 */
function wpcc_render_complete_button( $post_id ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }

    $current_user = wp_get_current_user();
    $image_id     = wpcc_get_lesson_image( $post_id );
    $nonce        = wp_create_nonce( 'wpcc_complete_chapter' );

    $html  = '<div class="wpcc-scope">';
    $html .= '<div class="singlecourse-complete-wrapper">';
    $html .= sprintf(
        '<button type="button" id="btn_complete_chapter" data-post_id="%d" data-image_id="%s" data-customer_id="%d" data-customer_name="%s" data-nonce="%s">%s</button>',
        (int) $post_id,
        esc_attr( $image_id ),
        (int) $user_id,
        esc_attr( $current_user->display_name ),
        esc_attr( $nonce ),
        esc_html__( 'Dodaj lekcję do historii', 'wp-custom-calendar' )
    );
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/* =====================================================================
 * Auto-inject fallback for lessons without the shortcode
 * ================================================================== */

/**
 * Auto-appends the end-of-lesson section to lesson content that does NOT
 * already contain `[wp_custom_calendar_single]`. This way:
 *
 *   - Lessons built in DIVI with a Text module containing
 *     [wp_custom_calendar_single] render the section wherever the user
 *     placed that shortcode.
 *   - Legacy lessons without the shortcode still get the button + box at
 *     the end of content automatically.
 *   - The calendar page never gets a button (it has [wp_customcalendar],
 *     so wpcc_is_lesson() returns false for it).
 */
function wpcc_render_lesson_marker( $content ) {
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    global $post;
    if ( ! wpcc_is_lesson( $post ) ) {
        return $content;
    }

    if ( wpcc_post_has_plugin_shortcode( $post ) ) {
        // The shortcode renders the section itself — don't duplicate it.
        return $content;
    }

    if ( wpcc_is_button_hidden( $post->ID ) ) {
        return $content;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return $content;
    }

    if ( wpcc_user_completed_lesson( $post->ID, $user_id ) ) {
        return wpcc_render_completed_banner() . $content;
    }

    return $content . wpcc_render_success_box() . wpcc_render_complete_button( $post->ID );
}
add_filter( 'the_content', 'wpcc_render_lesson_marker' );

/* =====================================================================
 * "Chapter Complete Button > Hide Button" metabox
 * ================================================================== */

/**
 * Registers a small metabox on every public post type with a Hide Button
 * toggle. When checked, the end-of-lesson section is suppressed on that
 * post — useful for free lessons and posts that aren't lessons at all.
 *
 * Stores the choice in post meta key `_wpcc_hide_button` as `yes` or `no`.
 */
function wpcc_register_metabox() {
    $post_types = get_post_types( array( 'public' => true ), 'names' );
    unset( $post_types['attachment'] );

    foreach ( $post_types as $pt ) {
        add_meta_box(
            'wpcc_chapter_complete_button',
            __( 'Chapter Complete Button', 'wp-custom-calendar' ),
            'wpcc_metabox_render',
            $pt,
            'side',
            'default'
        );
    }
}
add_action( 'add_meta_boxes', 'wpcc_register_metabox' );

function wpcc_metabox_render( $post ) {
    $value   = get_post_meta( $post->ID, '_wpcc_hide_button', true );
    $checked = in_array( $value, array( 'yes', '1', 1, true, 'true', 'on' ), true );
    wp_nonce_field( 'wpcc_metabox_save', 'wpcc_metabox_nonce' );
    ?>
    <p>
        <label>
            <input type="checkbox" name="wpcc_hide_button" value="yes" <?php checked( $checked ); ?> />
            <strong><?php esc_html_e( 'Hide Button', 'wp-custom-calendar' ); ?></strong>
        </label>
    </p>
    <p class="description">
        <?php esc_html_e( 'Hide the "Dodaj lekcję do historii" button and success box on this post. Use for free lessons or posts that are not part of the lesson catalogue.', 'wp-custom-calendar' ); ?>
    </p>
    <?php
}

function wpcc_metabox_save( $post_id ) {
    if ( ! isset( $_POST['wpcc_metabox_nonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcc_metabox_nonce'] ) ), 'wpcc_metabox_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $hide = isset( $_POST['wpcc_hide_button'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_wpcc_hide_button', $hide );
}
add_action( 'save_post', 'wpcc_metabox_save' );
