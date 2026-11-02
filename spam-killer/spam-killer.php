<?php
/**
 * Plugin Name: Spam Killer
 * Description: Quarantines suspicious Contact Form 7 and Elementor Pro form submissions before they send email.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: spam-killer
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

const VERSION = '0.1.0';
const SCHEMA = '4';
const FILE = __FILE__;

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/submission.php';
require_once __DIR__ . '/includes/rules.php';
require_once __DIR__ . '/includes/modules.php';
require_once __DIR__ . '/includes/quarantine.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/cf7.php';
require_once __DIR__ . '/includes/elementor.php';
require_once __DIR__ . '/includes/honeypot.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/extras.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook(__FILE__, __NAMESPACE__ . '\\install');
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('spam_killer_expire');
    wp_clear_scheduled_hook('spam_killer_digest');
});

add_action('plugins_loaded', function () {
    if (get_option('spam_killer_schema') !== SCHEMA) {
        install();
    }
});

function table($name) {
    global $wpdb;
    return $wpdb->prefix . 'spam_killer_' . $name;
}

function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    dbDelta('CREATE TABLE ' . table('submissions') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source varchar(20) NOT NULL,
        form_id varchar(80) NOT NULL,
        form_title varchar(255) NOT NULL DEFAULT '',
        fields longtext NOT NULL,
        reasons longtext NOT NULL,
        ip varchar(45) NOT NULL DEFAULT '',
        page_url varchar(2048) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'quarantined',
        created_at datetime NOT NULL,
        released_at datetime DEFAULT NULL,
        mail_status varchar(10) NOT NULL DEFAULT '',
        mail_error text NULL,
        PRIMARY KEY  (id),
        KEY status_created (status,created_at)
    ) $charset;");

    dbDelta('CREATE TABLE ' . table('rules') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        definition longtext NOT NULL,
        enabled tinyint(1) NOT NULL DEFAULT 1,
        match_count bigint(20) unsigned NOT NULL DEFAULT 0,
        last_match datetime DEFAULT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");

    if (!wp_next_scheduled('spam_killer_expire')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'spam_killer_expire');
    }
    if (!wp_next_scheduled('spam_killer_digest')) {
        // First digest tomorrow at 08:00 site time.
        $next = new \DateTime('tomorrow 08:00', wp_timezone());
        wp_schedule_event($next->getTimestamp(), 'daily', 'spam_killer_digest');
    }
    if (!get_option('spam_killer_seeded')) {
        foreach (default_rules() as $rule) {
            save_rule($rule);
        }
        update_option('spam_killer_seeded', 1, false);
    }
    update_option('spam_killer_schema', SCHEMA, false);
}
