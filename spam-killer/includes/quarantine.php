<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

add_action('spam_killer_expire', __NAMESPACE__ . '\\expire');

/** Stores every submission: 'passed' when clean, 'quarantined' when held. */
function log_submission(array $submission, array $reasons) {
    global $wpdb;
    $wpdb->insert(table('submissions'), array(
        'source' => $submission['source'],
        'form_id' => substr($submission['form_id'], 0, 80),
        'form_title' => substr($submission['form_title'], 0, 255),
        // Unescaped so log search (LIKE) finds non-Latin text such as Hebrew.
        'fields' => wp_json_encode($submission['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'reasons' => wp_json_encode($reasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip' => $submission['ip'],
        'page_url' => substr($submission['page_url'], 0, 2048),
        'status' => $reasons ? 'quarantined' : 'passed',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    return (int) $wpdb->insert_id;
}

function get_item($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . table('submissions') . ' WHERE id = %d', $id), ARRAY_A);
    if (!$row) {
        return null;
    }
    $row['fields'] = json_decode($row['fields'], true) ?: array();
    $row['reasons'] = json_decode($row['reasons'], true) ?: array();
    return $row;
}

/** WHERE clause for a status ('' = all) and filters (form key, search text). */
function items_where($status, array $filters = array()) {
    global $wpdb;
    $where = array('1=1');
    if ($status !== '') {
        $where[] = $wpdb->prepare('status = %s', $status);
    }
    if (!empty($filters['form'])) {
        list($source, $form_id) = explode(':', $filters['form'], 2);
        $where[] = $wpdb->prepare('source = %s AND form_id = %s', $source, $form_id);
    }
    if (!empty($filters['s'])) {
        $like = '%' . $wpdb->esc_like($filters['s']) . '%';
        $where[] = $wpdb->prepare('(fields LIKE %s OR ip LIKE %s OR form_title LIKE %s)', $like, $like, $like);
    }
    return ' WHERE ' . implode(' AND ', $where);
}

function count_items($status = 'quarantined', array $filters = array()) {
    global $wpdb;
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . table('submissions') . items_where($status, $filters));
}

/** Newest first. $limit 0 = all. */
function query_items($status, array $filters, $limit, $offset) {
    global $wpdb;
    $sql = 'SELECT * FROM ' . table('submissions') . items_where($status, $filters) . ' ORDER BY id DESC';
    if ($limit) {
        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
    }
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: array();
    foreach ($rows as &$row) {
        $row['fields'] = json_decode($row['fields'], true) ?: array();
        $row['reasons'] = json_decode($row['reasons'], true) ?: array();
    }
    return $rows;
}

/**
 * Marks a submission legitimate and emails it to the site admin.
 * Returns true if the email was handed to wp_mail, false if sending failed, WP_Error if not found.
 */
function release($id) {
    global $wpdb;
    $item = get_item($id);
    if (!$item || $item['status'] !== 'quarantined') {
        return new \WP_Error('not_found', 'Submission not found in quarantine.');
    }
    $lines = array();
    foreach ($item['fields'] as $field) {
        $lines[] = ($field['label'] !== '' ? $field['label'] : $field['id']) . ': ' . field_text($field['value']);
    }
    $title = $item['form_title'] !== '' ? $item['form_title'] : $item['form_id'];
    $body = "A form submission was released from Spam Killer quarantine.\n\n"
        . 'Form: ' . $title . "\n"
        . 'Received: ' . get_date_from_gmt($item['created_at']) . "\n"
        . ($item['page_url'] !== '' ? 'Page: ' . $item['page_url'] . "\n" : '')
        . "\n" . implode("\n", $lines) . "\n";
    $headers = array();
    $reply = role_field(array('fields' => $item['fields']), 'email');
    if ($reply && is_email(field_text($reply['value']))) {
        $headers[] = 'Reply-To: ' . field_text($reply['value']);
    }
    $sent = wp_mail(get_option('admin_email'), '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Released submission: ' . $title, $body, $headers);
    $wpdb->update(table('submissions'), array('status' => 'released', 'released_at' => gmdate('Y-m-d H:i:s')), array('id' => $id));
    return (bool) $sent;
}

function delete_items(array $ids) {
    global $wpdb;
    $ids = array_filter(array_map('absint', $ids));
    if (!$ids) {
        return 0;
    }
    return (int) $wpdb->query('DELETE FROM ' . table('submissions') . ' WHERE id IN (' . implode(',', $ids) . ')');
}

function delete_all($status = 'quarantined') {
    global $wpdb;
    return (int) $wpdb->delete(table('submissions'), array('status' => $status));
}

/** Daily cron: delete submissions (any status) older than the configured number of days. */
function expire() {
    global $wpdb;
    $cutoff = gmdate('Y-m-d H:i:s', time() - settings()['expire_days'] * DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare('DELETE FROM ' . table('submissions') . ' WHERE created_at < %s', $cutoff));
}
