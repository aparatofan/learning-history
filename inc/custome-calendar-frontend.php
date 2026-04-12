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
 *   - Provides [wp_customcalendar_single]  — registered so DIVI templates
 *                                            don't render raw shortcode text,
 *                                            but always returns empty. The
 *                                            button is appended via the_content.
 *   - Appends the "Dodaj lekcję do historii" button at the bottom of post
 *     content via the_content filter (wpcc_append_complete_button).
 *   - Adds body class wpcc-lesson-hide-section on ALL singular pages so the
 *     DonDivi / "THE END OF THE LESSON" section is always hidden.
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
 *   4. Any singular post viewed by a logged-in, non-admin user. This is the
 *      "brand new lesson, about to complete it" case: the DIVI Theme Builder
 *      template injects [wp_customcalendar_single] at render time, so the
 *      shortcode is NOT in post_content and the post has no tracking row and
 *      no hide_button flag — but the button DOES get rendered, and it needs
 *      style.css (for the blue pill) and script.js (for the click handler)
 *      to work. Anonymous visitors never see the button, so they don't need
 *      the assets; gating on is_user_logged_in() keeps the asset payload off
 *      everyone else's lesson pages.
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
    if ( is_singular() && is_user_logged_in() ) {
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
 * Returns true when the post belongs to one of the lesson categories.
 *
 * Only posts in these categories should show the "Dodaj lekcję do historii"
 * button and the yellow "already in your history" banner. Category matching
 * is case-insensitive and uses the category name (not slug) so it works
 * regardless of how WordPress slugified the Polish / English names.
 *
 * Filterable via `wpcc_lesson_categories` if the list ever changes.
 */
function wpcc_is_lesson_post( $post_id ) {
    $lesson_categories = apply_filters( 'wpcc_lesson_categories', array(
        'GENERAL INTEREST',
        'LEARNING ENGLISH',
        'PERSONAL DEVELOPMENT',
        'PRIVATE EYES ONLY',
    ) );

    $categories = get_the_category( $post_id );
    if ( empty( $categories ) ) {
        return false;
    }

    foreach ( $categories as $cat ) {
        foreach ( $lesson_categories as $lesson_cat ) {
            if ( strcasecmp( $cat->name, $lesson_cat ) === 0 ) {
                return true;
            }
        }
    }

    return false;
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
 * Returns the URL to use as the event image on the calendar for a given
 * lesson.
 *
 * thebluetree.pl wants the calendar to show each lesson's featured image
 * (book cover, portrait, cover art) rather than the shared category icon —
 * it gives the month view a lot more visual variety. So we look up the
 * post's featured image first, and only fall back to the category's
 * `category_icon` / `icon` / `category_image` / `thumbnail_id` term meta
 * if the post has no featured image at all. Override the whole thing with
 * the `wpcc_lesson_image` filter if your site needs a different rule.
 */
function wpcc_get_lesson_image( $post_id ) {
    $image_url = '';

    // Prefer the post's featured image. "medium" is used because the
    // thumbnail size on many DIVI sites is cropped to 150x150 square, which
    // makes landscape lesson covers look awkward; "medium" keeps the
    // original aspect ratio while staying small enough for calendar cells.
    if ( has_post_thumbnail( $post_id ) ) {
        $thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
        if ( ! $thumb ) {
            $thumb = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
        }
        if ( $thumb ) {
            $image_url = $thumb;
        }
    }

    // Fallback: first category's icon. Kept so lessons without a featured
    // image still render something instead of a blank calendar cell.
    if ( empty( $image_url ) ) {
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
    }

    return apply_filters( 'wpcc_lesson_image', $image_url, $post_id );
}

/* =====================================================================
 * Shortcodes
 * ================================================================== */

/**
 * Force-enqueues the plugin's CSS/JS from inside a shortcode callback.
 *
 * This is a safety net. The preferred path is the `wp_enqueue_scripts`
 * action running `wpcc_enqueue_assets()` before `the_content` is rendered,
 * but when the lesson layout comes from a DIVI Theme Builder template the
 * post object and the query context aren't always what wpcc_should_load_
 * assets() expects. By also calling wp_enqueue_* from the shortcode, we
 * guarantee that whenever the shortcode actually renders markup, the CSS
 * and JS it needs are printed in the footer (and any pending styles are
 * printed via wp_print_footer_scripts / wp_print_late_styles).
 */
function wpcc_force_enqueue_assets() {
    wp_enqueue_style( 'wpcc-fullcalendar' );
    wp_enqueue_style( 'wpcc-toastr' );
    wp_enqueue_script( 'wpcc-moment' );
    wp_enqueue_script( 'wpcc-fullcalendar' );
    wp_enqueue_script( 'wpcc-toastr' );
    wp_enqueue_style( 'wp-custom-calendar' );
    wp_enqueue_script( 'wp-custom-calendar' );
}

/**
 * Calendar shortcode — renders the container FullCalendar mounts into.
 * Registered under both spellings because the existing DIVI content uses
 * the no-underscore form.
 */
function wpcc_calendar_shortcode( $atts ) {
    wpcc_force_enqueue_assets();
    return '<div class="wpcc-scope"><div id="wp_custom_calendar"></div></div>';
}
add_shortcode( 'wp_customcalendar', 'wpcc_calendar_shortcode' );
add_shortcode( 'wp_custom_calendar', 'wpcc_calendar_shortcode' );

/**
 * Single-lesson shortcode — now always returns empty.
 *
 * The button is appended to post content by wpcc_append_complete_button()
 * via the_content filter instead. The shortcode still needs to be
 * registered so that [wp_customcalendar_single] in DIVI templates does
 * not render as raw text, but it produces no output.
 */
function wpcc_single_shortcode( $atts ) {
    return '';
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
 * Adds helper body classes on every singular page.
 *
 * Classes added:
 *   - wpcc-single-lesson        — every singular view
 *   - wpcc-lesson-completed     — current user has finished this lesson
 *   - wpcc-button-hidden        — the per-post "Hide Button" toggle is on
 *   - wpcc-lesson-hide-section  — ALWAYS added on singular pages so the
 *                                  DonDivi / "THE END OF THE LESSON" section
 *                                  is hidden for everyone, not just users
 *                                  who completed the lesson.
 */
function wpcc_body_class( $classes ) {
    if ( is_admin() || ! is_singular() ) {
        return $classes;
    }

    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) {
        return $classes;
    }

    $classes[] = 'wpcc-single-lesson';
    $classes[] = 'wpcc-lesson-hide-section';

    if ( wpcc_user_completed_lesson( $post->ID ) ) {
        $classes[] = 'wpcc-lesson-completed';
    }
    if ( wpcc_is_button_hidden( $post->ID ) ) {
        $classes[] = 'wpcc-button-hidden';
    }

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
    if ( ! wpcc_is_lesson_post( $post->ID ) ) {
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
 * Append "Dodaj lekcję do historii" button at the bottom of post content
 * ================================================================== */

/**
 * Appends the "Dodaj lekcję do historii" button directly to the post
 * content on singular pages for logged-in users who have not yet completed
 * the lesson.
 *
 * This replaces the old approach where the button was rendered by the
 * [wp_customcalendar_single] shortcode inside the DonDivi "end of the
 * lesson" section. Since that entire section is now hidden on all lesson
 * pages, the button must be injected independently.
 *
 * Runs at priority 20 (after wpcc_prepend_completed_banner at 9) so the
 * banner comes first and the button is at the very bottom.
 */
function wpcc_append_complete_button( $content ) {
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

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return $content;
    }

    if ( ! wpcc_is_lesson_post( $post->ID ) ) {
        return $content;
    }
    if ( wpcc_is_button_hidden( $post->ID ) ) {
        return $content;
    }
    if ( wpcc_user_completed_lesson( $post->ID, $user_id ) ) {
        return $content;
    }

    wpcc_force_enqueue_assets();

    return $content . wpcc_render_complete_button( $post->ID );
}
add_filter( 'the_content', 'wpcc_append_complete_button', 20 );

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
    $parts[] = 'logged_in=' . ( is_user_logged_in() ? '1' : '0' );
    $parts[] = 'user_id=' . (int) get_current_user_id();
    if ( is_a( $post, 'WP_Post' ) ) {
        $parts[] = 'post_id=' . (int) $post->ID;
        $parts[] = 'has_shortcode=' . ( wpcc_post_has_plugin_shortcode( $post ) ? '1' : '0' );
        $parts[] = 'completed=' . ( wpcc_user_completed_lesson( $post->ID ) ? '1' : '0' );
        $parts[] = 'hidden=' . ( wpcc_is_button_hidden( $post->ID ) ? '1' : '0' );
        $parts[] = 'assets=' . ( wpcc_should_load_assets() ? '1' : '0' );
    }
    echo "\n<!-- " . esc_html( implode( ' ', $parts ) ) . " -->\n";
}
add_action( 'wp_footer', 'wpcc_debug_footer_comment', 99 );
