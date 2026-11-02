<?php
/**
 * Privacy tools, daily digest, dashboard widget, plugin links.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

/* ---------- Privacy: Tools → Export / Erase Personal Data ---------- */

add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['spam-killer'] = array('exporter_friendly_name' => 'Spam Killer form submissions', 'callback' => __NAMESPACE__ . '\\privacy_export');
    return $exporters;
});
add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['spam-killer'] = array('eraser_friendly_name' => 'Spam Killer form submissions', 'callback' => __NAMESPACE__ . '\\privacy_erase');
    return $erasers;
});

/** Stored submissions where some field equals this email (case-insensitive). */
function submissions_by_email($email, $page) {
    global $wpdb;
    $like = '%' . $wpdb->esc_like($email) . '%';
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . table('submissions') . ' WHERE fields LIKE %s ORDER BY id ASC LIMIT 100 OFFSET %d', $like, ($page - 1) * 100), ARRAY_A) ?: array();
    $matches = array();
    foreach ($rows as $row) {
        foreach (json_decode($row['fields'], true) ?: array() as $field) {
            if (strcasecmp(trim(field_text($field['value'])), $email) === 0) {
                $row['fields'] = json_decode($row['fields'], true);
                $matches[] = $row;
                break;
            }
        }
    }
    return array($matches, count($rows) === 100);
}

function privacy_export($email, $page = 1) {
    list($rows, $more) = submissions_by_email($email, (int) $page);
    $data = array();
    foreach ($rows as $row) {
        $items = array(
            array('name' => 'Form', 'value' => $row['form_title']),
            array('name' => 'Received', 'value' => get_date_from_gmt($row['created_at'])),
            array('name' => 'Outcome', 'value' => $row['status']),
            array('name' => 'IP', 'value' => $row['ip']),
        );
        foreach ($row['fields'] as $field) {
            $items[] = array('name' => $field['label'] !== '' ? $field['label'] : $field['id'], 'value' => field_text($field['value']));
        }
        $data[] = array('group_id' => 'spam-killer', 'group_label' => 'Form submissions', 'item_id' => 'submission-' . $row['id'], 'data' => $items);
    }
    return array('data' => $data, 'done' => !$more);
}

function privacy_erase($email, $page = 1) {
    // Always read page 1: erased rows no longer appear.
    list($rows, $more) = submissions_by_email($email, 1);
    $deleted = delete_items(array_column($rows, 'id'));
    return array('items_removed' => $deleted > 0, 'items_retained' => false, 'messages' => array(), 'done' => !$more || !$deleted);
}

/* ---------- Daily digest of held submissions ---------- */

add_action('spam_killer_digest', __NAMESPACE__ . '\\send_digest');

/** Emails the admin a list of submissions held in the last 24 hours. Sends nothing on quiet days. */
function send_digest() {
    if (!settings()['digest']) {
        return false;
    }
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . table('submissions') . " WHERE status = 'quarantined' AND created_at >= %s ORDER BY id DESC LIMIT 50", $since), ARRAY_A) ?: array();
    if (!$rows) {
        return false;
    }
    $lines = array();
    foreach ($rows as $row) {
        list($who, $text) = summary(json_decode($row['fields'], true) ?: array());
        $reasons = array_column(json_decode($row['reasons'], true) ?: array(), 'reason');
        $lines[] = '- ' . ($who !== '' ? $who : '(no name or email)') . ' — ' . $row['form_title'] . "\n  " . $text . "\n  Why: " . implode('; ', $reasons) . "\n  " . page_url('spam-killer', array('item' => $row['id']));
    }
    $total = count_items('quarantined');
    $body = sprintf("Spam Killer held %d submission(s) in the last 24 hours. Check that no real enquiry is among them:\n\n%s\n\n%d held in total: %s\n\nTurn this email off in Spam Killer → Settings.", count($rows), implode("\n\n", $lines), $total, page_url('spam-killer'));
    return wp_mail(get_option('admin_email'), '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . count($rows) . ' form submission(s) held as spam', $body);
}

/* ---------- Dashboard widget ---------- */

add_action('wp_dashboard_setup', function () {
    if (current_user_can(CAP)) {
        wp_add_dashboard_widget('spam_killer', 'Spam Killer — last 7 days', __NAMESPACE__ . '\\dashboard_widget');
    }
});

function dashboard_widget() {
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
    $counts = array('passed' => 0, 'quarantined' => 0, 'released' => 0);
    foreach ($wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM ' . table('submissions') . ' WHERE created_at >= %s GROUP BY status', $since), ARRAY_A) ?: array() as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    $failed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . table('submissions') . " WHERE mail_status = 'failed' AND created_at >= %s", $since));
    printf('<div class="sk-dash"><a href="%s"><strong>%d</strong>passed</a><a href="%s"><strong>%d</strong>held</a><a href="%s"><strong>%d</strong>released</a></div>',
        esc_url(page_url('spam-killer-log', array('status' => 'passed'))), $counts['passed'],
        esc_url(page_url('spam-killer')), $counts['quarantined'],
        esc_url(page_url('spam-killer-log', array('status' => 'released'))), $counts['released']);
    if ($failed) {
        printf('<p class="sk-warn">%d notification email(s) failed to send. <a href="%s">See the log</a> and resend them.</p>', $failed, esc_url(page_url('spam-killer-log')));
    }
    $held = query_items('quarantined', array(), 5, 0);
    if ($held) {
        echo '<h3>Recently held</h3><ul>';
        foreach ($held as $row) {
            list($who) = summary($row['fields']);
            printf('<li><a href="%s">%s</a> <span class="sk-small">— %s</span></li>', esc_url(page_url('spam-killer', array('item' => $row['id']))), esc_html($who !== '' ? $who : '(no name or email)'), esc_html($row['reasons'][0]['reason'] ?? ''));
        }
        echo '</ul>';
    }
}

add_action('admin_head-index.php', function () {
    echo '<style>.sk-dash{display:flex;gap:8px;margin-bottom:8px}.sk-dash a{flex:1;text-align:center;border:1px solid #dcdcde;border-radius:4px;padding:8px;text-decoration:none;color:#50575e}.sk-dash strong{display:block;font-size:22px;color:#1d2327}.sk-warn{color:#b32d2e;font-weight:600}.sk-small{color:#646970}</style>';
});

/* ---------- Plugins screen links ---------- */

add_filter('plugin_action_links_' . plugin_basename(FILE), function ($links) {
    if (current_user_can(CAP)) {
        array_unshift($links,
            '<a href="' . esc_url(page_url('spam-killer-settings')) . '">Settings</a>',
            '<a href="' . esc_url(page_url('spam-killer-log')) . '">Log</a>');
    }
    return $links;
});
