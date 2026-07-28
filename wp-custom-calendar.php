<?php
/**
 * Plugin Name: WP Custom Calendar
 * Description: Tracks learning history. Users mark a lesson as done and it appears
 *              on a calendar page; a yellow banner is shown on lessons they have
 *              already completed.
 * Version:     1.4.1
 * Author:      ashokkuikel (patched)
 * Text Domain: wp-custom-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPCC_VERSION', '1.4.1' );
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

/**
 * One-shot schema migration.
 *
 * An older version of the plugin (or a manually imported schema) shipped a
 * UNIQUE INDEX named `unique_complete` on the wpcc_tracking table. That index
 * is on a legacy column this plugin no longer writes to, so every new INSERT
 * defaults that column to 0 and immediately collides with the previous row,
 * producing:
 *
 *     Duplicate entry '45-0' for key 'unique_complete'
 *
 * The new plugin already has its own UNIQUE KEY `customer_post (customerID,
 * post_id)` from dbDelta(), so we can safely drop the orphan `unique_complete`
 * index. We gate the migration on an option so it only runs once per install.
 * Also runs on plugins_loaded (not just activation) so an existing install
 * gets fixed on the next page load without the user having to deactivate /
 * reactivate the plugin.
 */
function wpcc_maybe_migrate_schema() {
    $current = get_option( 'wpcc_schema_version', '0' );
    if ( version_compare( $current, '1.3.1', '>=' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wpcc_tracking';

    // Bail out quickly if the table doesn't exist yet (plugin not activated).
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );
    if ( ! $table_exists ) {
        return;
    }

    // Drop the legacy unique_complete index if it exists. Use SHOW INDEX so
    // we don't attempt a DROP on a key that isn't there (which would log an
    // error on strict hosts).
    $has_legacy = $wpdb->get_var(
        "SHOW INDEX FROM `$table` WHERE Key_name = 'unique_complete'"
    );
    if ( $has_legacy ) {
        $wpdb->query( "ALTER TABLE `$table` DROP INDEX `unique_complete`" );
    }

    // Make sure our intended unique key exists. dbDelta() normally handles
    // this on activation, but older tables may not have it.
    $has_customer_post = $wpdb->get_var(
        "SHOW INDEX FROM `$table` WHERE Key_name = 'customer_post'"
    );
    if ( ! $has_customer_post ) {
        // Only add it if we can — a table that already contains duplicate
        // (customerID, post_id) pairs will reject the ALTER. In that case
        // we leave it to the admin to clean up.
        $wpdb->query(
            "ALTER TABLE `$table` ADD UNIQUE KEY `customer_post` (`customerID`, `post_id`)"
        );
    }

    update_option( 'wpcc_schema_version', '1.3.1' );
}
add_action( 'plugins_loaded', 'wpcc_maybe_migrate_schema' );
