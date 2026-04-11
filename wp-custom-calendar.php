<?php
/**
 * Plugin Name: WP Custom Calendar
 * Description: Tracks learning history. Users mark a lesson as done and it appears
 *              on a calendar page; a yellow banner is shown on lessons they have
 *              already completed.
 * Version:     1.2.0
 * Author:      ashokkuikel (patched)
 * Text Domain: wp-custom-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPCC_VERSION', '1.2.0' );
define( 'WPCC_PLUGIN_FILE', __FILE__ );
define( 'WPCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPCC_PLUGIN_DIR . 'inc/custome-calendar-frontend.php';
require_once WPCC_PLUGIN_DIR . 'inc/getevents.php';
require_once WPCC_PLUGIN_DIR . 'inc/post-operations.php';

/**
 * AJAX action registrations. Both logged-in (wp_ajax_) and anonymous
 * (wp_ajax_nopriv_) are wired so the frontend calendar works for any visitor
 * that is allowed to see it.
 */
add_action( 'wp_ajax_getprivate_event',       'getprivate_event_callback' );
add_action( 'wp_ajax_nopriv_getprivate_event','getprivate_event_callback' );

add_action( 'wp_ajax_addprivate_event',       'addprivate_event_callback' );
add_action( 'wp_ajax_modifyprivate_event',    'modifyprivate_event_callback' );
add_action( 'wp_ajax_deleteprivate_event',    'deleteprivate_event_callback' );

add_action( 'wp_ajax_complete_chapter_report','wpcc_complete_chapter_report_callback' );

/**
 * Create the tracking table and flush rewrite rules on activation. Flushing
 * rewrite rules here removes the need to manually re-save Permalinks after
 * activating the plugin.
 */
function wpcc_activate() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'wpcc_tracking';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customerID BIGINT(20) UNSIGNED NOT NULL,
        customer_name VARCHAR(191) NOT NULL DEFAULT '',
        post_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT NULL,
        imageID VARCHAR(500) NOT NULL DEFAULT '',
        start DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY customerID (customerID),
        KEY post_id (post_id),
        UNIQUE KEY customer_post (customerID, post_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Flush rewrite rules so the user does not have to re-save Permalinks.
    flush_rewrite_rules();

    // Purge DIVI's cached static CSS file if the theme provides the helper.
    wpcc_clear_divi_static_css();
}
register_activation_hook( __FILE__, 'wpcc_activate' );

function wpcc_deactivate() {
    flush_rewrite_rules();
    wpcc_clear_divi_static_css();
}
register_deactivation_hook( __FILE__, 'wpcc_deactivate' );

/**
 * Clears DIVI's "Static CSS File Generation" cache so stale rules from a
 * previous page load do not continue to be served after the plugin changes
 * something. Safe no-op on non-DIVI sites.
 */
function wpcc_clear_divi_static_css() {
    if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
        ET_Core_PageResource::remove_static_resources( 'all', 'all' );
    }
    if ( function_exists( 'et_core_page_resource_auto_clear' ) ) {
        et_core_page_resource_auto_clear();
    }
}

// Also clear DIVI's static CSS whenever a lesson is added, so the calendar
// icon / yellow banner become visible immediately without a manual cache purge.
add_action( 'wpcc_lesson_completed', 'wpcc_clear_divi_static_css' );
