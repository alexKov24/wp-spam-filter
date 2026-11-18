<?php
/**
 * Runs when the plugin is deleted: removes stored submissions (personal data), rules and settings.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}spam_killer_submissions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}spam_killer_rules");

// Options, plus transients (rate-limit counters, rule drafts, update cache).
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'spam\\_killer\\_%'"
    . " OR option_name LIKE '\\_transient\\_spam\\_killer\\_%'"
    . " OR option_name LIKE '\\_transient\\_timeout\\_spam\\_killer\\_%'"
);

delete_site_transient('spam_killer_release');

wp_clear_scheduled_hook('spam_killer_expire');
wp_clear_scheduled_hook('spam_killer_digest');
