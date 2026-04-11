<?php
/**
 * Frontend integration for WP Custom Calendar.
 *
 * Simplified to only the things the DIVI templates on thebluetree.pl actually
 * need. The plugin is now the minimal layer between the DIVI content the site
 * owner has already built and the wpcc_tracking database table:
 *
 *   - Enqueues CSS/JS only on pages that use a plugin shortcode.
 *   - Provides [wp_customcalendar]         — the calendar on the history page.
 *   - Provides [wp_customcalendar_single]  — the "Dodaj lekcję do historii"
 *                                            button, or nothing when the
 *                                            lesson is already completed or
 *                                            the "Hide Button" toggle is on.
 *   - Adds body classes wpcc-lesson-completed / wpcc-button-hidden so the
 *     DIVI "end of the lesson" section in the template can be hidden with a
 *     single CSS rule without the plugin having to touch the template.
 *
 * Things the plugin explicitly does NOT do anymore (they were causing the
 * duplicate section / "not original" box / Hide-Button-is-ignored problems):
 *
 *   - Does not render its own grey success box. That lives in the DIVI
 *     template as static content and should stay there — it's "the original".
 *   - Does not filter the_content to auto-append anything. Placement is 100%
 *     controlled by where the user drops [wp_customcalendar_single] in DIVI.
 *   - Does not register its own "Hide Button" metabox. The site already has
 *     one (the Yes/No toggle in the post edit screen); we just read whatever
 *     meta key it writes to.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =====================================================================
 * Shortcode / context helpers
 * ================================================================== */

/**
 * All shortcode tags the plugin responds to. Multiple spellings are listed
 * because the DIVI content on thebluetree.pl uses the no-underscore form
 * (wp_customcalendar) and the previous version of this plugin used the
 * underscore form (wp_custom_calendar). Both have to keep working so legacy
 * pages don't suddenly render raw shortcode text.
 */
function wpcc_shortcodes() {
    return array(
        'wp_customcalendar',
        'wp_custom_calendar',
        'wp_customcalendar_single',
        'wp_custom_calendar_single',
    );
}

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
 * Load the plugin's CSS/JS on pages that need them:
 *
 *   1. Any page containing a plugin shortcode in its post_content (e.g. the
 *      history page with [wp_customcalendar]).
 *   2. Any singular post where the current user has already completed the
 *      lesson — needed so the yellow "already in history" banner and the JS
 *      that auto-hides the "end of the lesson" section can actually run even
 *      when the lesson layout comes from a DIVI Theme Builder template and
 *      the shortcode isn't literally in the post_content.
 *   3. Any singular post with the "Hide Button" toggle on, for the same
 *      reason.
 */
function wpcc_should_load_assets() {
    if ( is_admin() ) {
        return false;
    }
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return false;
    }
    if ( wpcc_post_has_plugin_shortcode( $post ) ) {
        return true;
    }
    if ( is_singular() && wpcc_user_completed_lesson( $post->ID ) ) {
        return true;
    }
    if ( is_singular() && wpcc_is_button_hidden( $post->ID ) ) {
        return true;
    }
    return false;
}

/**
 * Returns a cache-busting version string for an asset inside the plugin.
 * Uses the file's mtime so a simple git pull / SFTP upload changes the URL
 * query string automatically — no plugin-version bump required. Falls back
 * to WPCC_VERSION if the file can't be stat'd.
 */
function wpcc_asset_version( $relative_path ) {
    $full = WPCC_PLUGIN_DIR . ltrim( $relative_path, '/' );
    $mtime = @filemtime( $full );
    if ( $mtime ) {
        return WPCC_VERSION . '.' . $mtime;
    }
    return WPCC_VERSION;
}

/* =====================================================================
 * Asset registration
 * ================================================================== */

function wpcc_enqueue_assets() {
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
        wpcc_asset_version( 'custom/css/style.css' )
    );
    wp_register_script(
        'wp-custom-calendar',
        WPCC_PLUGIN_URL . 'custom/js/script.js',
        array( 'jquery', 'wpcc-fullcalendar', 'wpcc-toastr' ),
        wpcc_asset_version( 'custom/js/script.js' ),
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
 * Returns true when the per-post "Hide Button" toggle is set.
 *
 * thebluetree.pl already has a "Chapter Complete Button > Hide Button"
 * metabox (visible in the post edit screen as a Yes/No toggle switch) that
 * was registered by some other part of the site — not by this plugin. We
 * don't know exactly which meta key it writes to, so we check all of the
 * common names, and expose a filter for any weirder ones.
 *
 * If you know the exact meta key, the fastest fix is:
 *
 *     add_filter( 'wpcc_is_button_hidden', function( $hidden, $post_id ) {
 *         return $hidden || 'yes' === get_post_meta( $post_id, 'YOUR_KEY', true );
 *     }, 10, 2 );
 */
function wpcc_is_button_hidden( $post_id ) {
    $candidate_keys = array(
        '_wpcc_hide_button',
        'wpcc_hide_button',
        'hide_button',
        'hide_chapter_complete_button',
        'chapter_complete_button_hide',
        '_chapter_complete_button',
        'chapter_complete_button',
    );
    $truthy = array( 'yes', 'Yes', 'YES', '1', 1, true, 'true', 'on' );

    $is_hidden = false;
    foreach ( $candidate_keys as $key ) {
        $value = get_post_meta( $post_id, $key, true );
        if ( in_array( $value, $truthy, true ) ) {
            $is_hidden = true;
            break;
        }
    }

    return (bool) apply_filters( 'wpcc_is_button_hidden', $is_hidden, $post_id );
}

/**
 * Returns the URL to use as the event icon on the calendar for a given
 * lesson. Reads the first category's term-meta first (category_icon -> icon
 * -> category_image -> thumbnail_id) and falls back to the post's featured
 * image. Override with the wpcc_lesson_image filter if your site stores the
 * icon somewhere else.
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
 * Calendar shortcode — renders the container FullCalendar mounts into.
 * Registered under both spellings because the existing DIVI content uses
 * the no-underscore form.
 */
function wpcc_calendar_shortcode( $atts ) {
    return '<div class="wpcc-scope"><div id="wp_custom_calendar"></div></div>';
}
add_shortcode( 'wp_customcalendar', 'wpcc_calendar_shortcode' );
add_shortcode( 'wp_custom_calendar', 'wpcc_calendar_shortcode' );

/**
 * Single-lesson shortcode — renders ONLY the "Dodaj lekcję do historii"
 * button. The grey "end of the lesson" success box belongs to the DIVI
 * template, not to the plugin, so the plugin does not output it anymore.
 *
 * Returns an empty string (and therefore outputs nothing visible) when:
 *   - the visitor is not logged in,
 *   - the "Hide Button" meta toggle is set on this post, or
 *   - the current user has already completed this lesson.
 *
 * The body-class filter below lets the DIVI template hide its own static
 * "end of the lesson" heading and grey box in those same cases via CSS.
 */
function wpcc_single_shortcode( $atts ) {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return '';
    }

    $post_id = $post->ID;

    if ( wpcc_is_button_hidden( $post_id ) ) {
        return '';
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }

    if ( wpcc_user_completed_lesson( $post_id, $user_id ) ) {
        return '';
    }

    return wpcc_render_complete_button( $post_id );
}
add_shortcode( 'wp_customcalendar_single', 'wpcc_single_shortcode' );
add_shortcode( 'wp_custom_calendar_single', 'wpcc_single_shortcode' );

/**
 * Builds the "Dodaj lekcję do historii" button with the data-* attributes
 * custom/js/script.js reads when it fires the complete_chapter_report AJAX.
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
    $html .= '</div></div>';
    return $html;
}

/* =====================================================================
 * Body classes for DIVI-side conditional hiding
 * ================================================================== */

/**
 * Adds helper body classes on any singular post that contains one of the
 * plugin's shortcodes, so the DIVI template's static "end of the lesson"
 * section can hide itself with a single CSS rule.
 *
 * Classes added:
 *   - wpcc-single-lesson        — every singular view with a plugin shortcode
 *   - wpcc-lesson-completed     — current user has finished this lesson
 *   - wpcc-button-hidden        — the per-post "Hide Button" toggle is on
 *   - wpcc-lesson-hide-section  — composite: use THIS one in your DIVI CSS
 *                                  class to hide everything in one rule
 *
 * Usage in DIVI: on the Row/Section that contains your "THE END OF THE
 * LESSON" heading + grey success box, set the CSS class "wpcc-end-of-lesson"
 * (Design tab → Advanced → CSS ID & Classes). Then add one rule to DIVI's
 * Custom CSS (Theme Options → Custom CSS):
 *
 *     body.wpcc-lesson-hide-section .wpcc-end-of-lesson { display: none; }
 *
 * That single rule makes the DIVI section disappear whenever the lesson is
 * already completed or the Hide Button toggle is on — without the plugin
 * having to touch the template.
 */
function wpcc_body_class( $classes ) {
    if ( is_admin() || ! is_singular() ) {
        return $classes;
    }

    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return $classes;
    }

    $hidden    = wpcc_is_button_hidden( $post->ID );
    $completed = wpcc_user_completed_lesson( $post->ID );

    // NOTE: we intentionally do NOT require the shortcode to live inside
    // post_content here. On thebluetree.pl the lesson layout comes from a
    // DIVI Theme Builder template, so [wp_customcalendar_single] is not in
    // the post's post_content — it is injected by the template at render
    // time. Gating on "completed" / "hide_button" is specific enough: a
    // regular blog post cannot have a wpcc_tracking row unless the owner
    // deliberately created one.
    if ( ! $completed && ! $hidden ) {
        return $classes;
    }

    $classes[] = 'wpcc-single-lesson';

    if ( $completed ) {
        $classes[] = 'wpcc-lesson-completed';
    }
    if ( $hidden ) {
        $classes[] = 'wpcc-button-hidden';
    }
    $classes[] = 'wpcc-lesson-hide-section';

    return $classes;
}
add_filter( 'body_class', 'wpcc_body_class' );

/* =====================================================================
 * Yellow "already in your history" banner on completed lessons
 * ================================================================== */

/**
 * Prepends a yellow banner to the top of the post content on lessons the
 * current user has already completed. Gated ONLY on:
 *   - main query, singular, WP_Post (so related-posts modules and widgets
 *     re-running the_content can't trigger it), AND
 *   - the current user has a wpcc_tracking row for this post.
 *
 * We do NOT also check for a plugin shortcode in post_content, because on
 * thebluetree.pl the shortcode is injected by a DIVI Theme Builder template
 * and never appears in post_content. Gating on "completed" is specific
 * enough on its own.
 *
 * Style lives in custom/css/style.css under .wpcc-completed-banner.
 */
function wpcc_prepend_completed_banner( $content ) {
    if ( is_admin() ) {
        return $content;
    }
    if ( ! in_the_loop() || ! is_main_query() || ! is_singular() ) {
        return $content;
    }

    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return $content;
    }
    if ( ! wpcc_user_completed_lesson( $post->ID ) ) {
        return $content;
    }

    $message = apply_filters(
        'wpcc_completed_banner_text',
        __( 'Ta lekcja jest już w Twojej historii nauki.', 'wp-custom-calendar' ),
        $post->ID
    );

    $banner  = '<div class="wpcc-scope">';
    $banner .= '<div class="wpcc-completed-banner">' . esc_html( $message ) . '</div>';
    $banner .= '</div>';

    return $banner . $content;
}
add_filter( 'the_content', 'wpcc_prepend_completed_banner', 9 );

/* =====================================================================
 * Diagnostic footer comment
 * ================================================================== */

/**
 * Emits an HTML comment in the footer so the site owner can verify from
 * "View Source" whether the round-6 code is actually running on the server
 * (and which state the plugin thinks the current page is in). Cheap, only
 * runs on the frontend.
 */
function wpcc_debug_footer_comment() {
    if ( is_admin() ) {
        return;
    }
    global $post;
    $parts = array(
        'wpcc v=' . WPCC_VERSION,
    );
    if ( is_a( $post, 'WP_Post' ) ) {
        $parts[] = 'post_id=' . (int) $post->ID;
        $parts[] = 'has_shortcode=' . ( wpcc_post_has_plugin_shortcode( $post ) ? '1' : '0' );
        $parts[] = 'completed=' . ( wpcc_user_completed_lesson( $post->ID ) ? '1' : '0' );
        $parts[] = 'hidden=' . ( wpcc_is_button_hidden( $post->ID ) ? '1' : '0' );
    }
    echo "\n<!-- " . esc_html( implode( ' ', $parts ) ) . " -->\n";
}
add_action( 'wp_footer', 'wpcc_debug_footer_comment', 99 );
