<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

const CAP = 'manage_options';

add_action('admin_menu', __NAMESPACE__ . '\\admin_menu');
add_action('admin_init', function () {
    register_setting('spam_killer', 'spam_killer_settings', array('sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings'));
});
add_action('admin_post_spam_killer_quarantine', __NAMESPACE__ . '\\handle_quarantine_action');
add_action('admin_post_spam_killer_rule', __NAMESPACE__ . '\\handle_rule_action');
add_action('admin_post_spam_killer_export', __NAMESPACE__ . '\\export_csv');
add_action('admin_post_spam_killer_clear_errors', function () {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_clear_errors');
    delete_option('spam_killer_errors');
    wp_safe_redirect(page_url('spam-killer-settings'));
    exit;
});
require_once __DIR__ . '/forms-page.php';
add_action('admin_enqueue_scripts', function () {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (strpos($page, 'spam-killer') === 0) {
        wp_enqueue_style('spam-killer', plugins_url('assets/admin.css', FILE), array(), VERSION);
        wp_enqueue_script('spam-killer', plugins_url('assets/admin.js', FILE), array(), VERSION, true);
    }
});

function admin_menu() {
    $count = count_items();
    $badge = $count ? ' <span class="awaiting-mod">' . number_format_i18n($count) . '</span>' : '';
    $icon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(dirname(FILE) . '/assets/menu-icon.svg'));
    add_menu_page('Spam Killer', 'Spam Killer' . $badge, CAP, 'spam-killer', __NAMESPACE__ . '\\quarantine_page', $icon, 80);
    add_submenu_page('spam-killer', 'Quarantine', 'Quarantine', CAP, 'spam-killer', __NAMESPACE__ . '\\quarantine_page');
    $log = add_submenu_page('spam-killer', 'Log', 'Log', CAP, 'spam-killer-log', __NAMESPACE__ . '\\log_page');
    add_action('load-' . $log, __NAMESPACE__ . '\\log_screen');
    add_submenu_page('spam-killer', 'Forms', 'Forms', CAP, 'spam-killer-forms', __NAMESPACE__ . '\\forms_page');
    add_submenu_page('spam-killer', 'Rules', 'Rules', CAP, 'spam-killer-rules', __NAMESPACE__ . '\\rules_page');
    add_submenu_page('spam-killer', 'Settings', 'Settings', CAP, 'spam-killer-settings', __NAMESPACE__ . '\\settings_page');
}

function page_url($page = 'spam-killer', array $args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=' . $page));
}

function notice() {
    $messages = array(
        'released' => array('success', 'Released. The submission was emailed to %s.'),
        'released_failed' => array('warning', 'Marked as legitimate, but the email to %s could not be sent. Check your site\'s mail setup.'),
        'deleted' => array('success', 'Deleted.'),
        'rule_saved' => array('success', 'Rule saved.'),
        'rule_deleted' => array('success', 'Rule deleted.'),
        'form_saved' => array('success', 'Form protection saved.'),
        'resent' => array('success', 'Sent to %s.'),
        'resent_failed' => array('warning', 'The email to %s could not be sent. Check your site\'s mail setup (an SMTP plugin usually fixes this).'),
    );
    $key = isset($_GET['sk_notice']) ? sanitize_key($_GET['sk_notice']) : '';
    if (isset($messages[$key])) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$key][0]), esc_html(sprintf($messages[$key][1], get_option('admin_email'))));
    }
    if (isset($_GET['sk_error'])) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(wp_unslash($_GET['sk_error'])));
    }
}

function module_label($id) {
    $modules = all_modules();
    return $modules[$id]['label'] ?? $id;
}

/** "Sent" / "Failed: reason" / "No email" for the log and item page. */
function mail_label(array $item) {
    switch ($item['mail_status']) {
        case 'sent':
            return '<span class="sk-mail-sent">Sent</span>';
        case 'failed':
            return '<span class="sk-mail-failed" title="' . esc_attr((string) $item['mail_error']) . '">Failed</span>' . ($item['mail_error'] ? ' <span class="sk-small">' . esc_html(wp_trim_words($item['mail_error'], 12)) . '</span>' : '');
        case 'none':
            return '<span class="sk-muted">No email sent by the form</span>';
    }
    return '<span class="sk-muted">—</span>';
}

/** Rule draft from a stored submission: its email, and every link domain in it. */
function rule_from_submission($id) {
    $item = get_item($id);
    if (!$item) {
        return null;
    }
    $conditions = array();
    $email = role_field($item, 'email');
    if ($email && field_text($email['value']) !== '') {
        $conditions[] = array('field' => 'email', 'operator' => 'equals', 'value' => field_text($email['value']));
    }
    $text = implode("\n", array_map(function ($f) { return field_text($f['value']); }, $item['fields']));
    foreach (array_slice(link_domains($text), 0, 10) as $domain) {
        $conditions[] = array('field' => 'link_domain', 'operator' => 'equals', 'value' => $domain);
    }
    if (!$conditions) {
        $message = role_field($item, 'message');
        $conditions[] = array('field' => 'message', 'operator' => 'contains', 'value' => $message ? wp_html_excerpt(field_text($message['value']), 80) : '');
    }
    return array('name' => 'From submission #' . $id, 'mode' => 'any', 'forms' => '', 'enabled' => true, 'conditions' => $conditions);
}

function summary(array $fields) {
    $item = array('fields' => $fields);
    $parts = array();
    foreach (array('name', 'email') as $role) {
        $f = role_field($item, $role);
        if ($f && field_text($f['value']) !== '') {
            $parts[] = field_text($f['value']);
        }
    }
    $message = role_field($item, 'message');
    $text = $message ? field_text($message['value']) : '';
    return array(implode(' · ', $parts), wp_trim_words($text, 18));
}

/* ---------- Quarantine ---------- */

function quarantine_page() {
    if (!current_user_can(CAP)) {
        return;
    }
    if (!empty($_GET['item'])) {
        item_page(absint($_GET['item']));
        return;
    }
    require_once __DIR__ . '/list-table.php';
    $status = (isset($_GET['status']) && $_GET['status'] === 'released') ? 'released' : 'quarantined';
    $table = new Quarantine_Table($status);
    $table->prepare_items();
    echo '<div class="wrap"><h1 class="wp-heading-inline">Quarantine</h1>';
    if ($status === 'quarantined' && $table->get_pagination_arg('total_items')) {
        printf('<form method="post" action="%s" class="sk-inline" onsubmit="return confirm(\'Delete every quarantined submission?\')">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('spam_killer_quarantine', 'sk_nonce');
        echo '<input type="hidden" name="action" value="spam_killer_quarantine"><input type="hidden" name="op" value="empty">';
        echo '<button type="submit" class="page-title-action">Empty quarantine</button>';
        echo '</form>';
    }
    echo '<hr class="wp-header-end">';
    notice();
    printf('<p class="description">Held submissions did not send email or trigger form actions. Submissions are kept for %d days. <a href="%s">See all submissions</a>.</p>', (int) settings()['expire_days'], esc_url(page_url('spam-killer-log')));
    $views = array(
        'quarantined' => sprintf('Quarantined <span class="count">(%d)</span>', count_items('quarantined')),
        'released' => sprintf('Released <span class="count">(%d)</span>', count_items('released')),
    );
    echo '<ul class="subsubsub">';
    $links = array();
    foreach ($views as $key => $label) {
        $links[] = sprintf('<li><a href="%s"%s>%s</a></li>', esc_url(page_url('spam-killer', array('status' => $key))), $key === $status ? ' class="current"' : '', $label);
    }
    echo implode(' | ', $links) . '</ul>';
    printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_quarantine', 'sk_nonce');
    echo '<input type="hidden" name="action" value="spam_killer_quarantine"><input type="hidden" name="back" value="spam-killer">';
    $table->display();
    echo '</form></div>';
}

/* ---------- Log ---------- */

/** Registers the log's columns with Screen Options so each user can show or hide them. */
function log_screen() {
    if (!empty($_GET['item'])) {
        return;
    }
    require_once __DIR__ . '/list-table.php';
    new Quarantine_Table('', 'spam-killer-log'); // Its constructor hooks the columns into Screen Options.
    add_filter('default_hidden_columns', function ($hidden, $screen) {
        return $screen->id === get_current_screen()->id ? array_merge($hidden, array('ip', 'page')) : $hidden;
    }, 10, 2);
}

function log_page() {
    if (!current_user_can(CAP)) {
        return;
    }
    if (!empty($_GET['item'])) {
        item_page(absint($_GET['item']), 'spam-killer-log');
        return;
    }
    require_once __DIR__ . '/list-table.php';
    $status = isset($_GET['status']) && in_array($_GET['status'], array('passed', 'quarantined', 'released'), true) ? $_GET['status'] : '';
    $filters = log_filters();
    $table = new Quarantine_Table($status, 'spam-killer-log', $filters);
    $table->prepare_items();
    echo '<div class="wrap"><h1 class="wp-heading-inline">Log</h1><hr class="wp-header-end">';
    notice();
    printf('<p class="description">Every form submission, passed or held. Kept for %d days (<a href="%s">change</a>).</p>', (int) settings()['expire_days'], esc_url(page_url('spam-killer-settings')));
    $views = array('' => 'All', 'passed' => 'Passed', 'quarantined' => 'Held', 'released' => 'Released');
    $links = array();
    foreach ($views as $key => $label) {
        $links[] = sprintf('<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>', esc_url(page_url('spam-killer-log', array_filter(array('status' => $key) + $filters))), $key === $status ? ' class="current"' : '', $label, count_items($key, $filters));
    }
    echo '<ul class="subsubsub">' . implode(' | ', $links) . '</ul>';
    // Filters (GET) and export.
    $forms = array();
    foreach (all_forms() as $f) {
        $forms[$f['key']] = $f['name'] . ' (' . ($f['source'] === 'cf7' ? 'CF7' : 'Elementor') . ')';
    }
    echo '<form method="get" class="sk-log-filters"><input type="hidden" name="page" value="spam-killer-log">';
    if ($status !== '') {
        printf('<input type="hidden" name="status" value="%s">', esc_attr($status));
    }
    echo '<select name="form" aria-label="Form"><option value="">All forms</option>';
    foreach ($forms as $key => $label) {
        printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($filters['form'] ?? '', $key, false), esc_html($label));
    }
    printf('</select> <input type="search" name="s" value="%s" placeholder="Search submissions…" aria-label="Search submissions"> <button class="button">Filter</button>', esc_attr($filters['s'] ?? ''));
    printf(' <a class="button" href="%s">Export CSV</a></form>', esc_url(wp_nonce_url(add_query_arg(array_filter(array('action' => 'spam_killer_export', 'status' => $status) + $filters), admin_url('admin-post.php')), 'spam_killer_export')));
    printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_quarantine', 'sk_nonce');
    echo '<input type="hidden" name="action" value="spam_killer_quarantine"><input type="hidden" name="back" value="spam-killer-log">';
    $table->display();
    echo '</form></div>';
}

function item_page($id, $back = 'spam-killer') {
    $item = get_item($id);
    $titles = array('quarantined' => 'Held submission', 'released' => 'Released submission', 'passed' => 'Passed submission');
    echo '<div class="wrap"><h1>' . esc_html($titles[$item['status'] ?? ''] ?? 'Submission') . '</h1>';
    notice();
    if (!$item) {
        echo '<p>Submission not found. It may have been deleted or expired.</p></div>';
        return;
    }
    printf('<p><a href="%s">&larr; Back to %s</a></p>', esc_url(page_url($back)), $back === 'spam-killer-log' ? 'log' : 'quarantine');
    if ($item['reasons']) {
        echo '<div class="sk-card"><h2>Why it was held</h2><ul class="sk-reasons">';
        foreach ($item['reasons'] as $r) {
            printf('<li><strong>%s:</strong> %s</li>', esc_html(module_label($r['module'] ?? '')), esc_html($r['reason'] ?? ''));
        }
        echo '</ul></div>';
    } else {
        echo '<div class="sk-card"><p>No filter matched, so the form ran normally (its emails and actions were not blocked).</p></div>';
    }
    echo '<div class="sk-card"><h2>Submitted values</h2><table class="widefat striped"><tbody>';
    foreach ($item['fields'] as $f) {
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($f['label'] !== '' ? $f['label'] : $f['id']), nl2br(esc_html(field_text($f['value']))));
    }
    echo '</tbody></table><p class="sk-meta">';
    printf('Form: %s (%s #%s) · Received: %s', esc_html($item['form_title']), esc_html($item['source'] === 'cf7' ? 'Contact Form 7' : 'Elementor'), esc_html($item['form_id']), esc_html(get_date_from_gmt($item['created_at'], 'Y-m-d H:i')));
    if ($item['ip'] !== '') {
        printf(' · IP: %s', esc_html($item['ip']));
    }
    if ($item['page_url'] !== '') {
        printf(' · Page: <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($item['page_url']), esc_html($item['page_url']));
    }
    echo '</p>';
    if ($item['mail_status'] !== '') {
        printf('<p class="sk-meta">Notification email: %s</p>', mail_label($item));
    }
    echo '</div>';
    printf('<form method="post" action="%s" class="sk-actions">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_quarantine', 'sk_nonce');
    printf('<input type="hidden" name="action" value="spam_killer_quarantine"><input type="hidden" name="ids[]" value="%d"><input type="hidden" name="back" value="%s">', $id, esc_attr($back));
    if ($item['status'] === 'quarantined') {
        printf('<button class="button button-primary" name="op" value="release">Not spam — email it to %s</button> ', esc_html(get_option('admin_email')));
    } elseif ($item['status'] === 'passed') {
        printf('<button class="button%s" name="op" value="resend">Email it to %s</button> ', $item['mail_status'] === 'sent' ? '' : ' button-primary', esc_html(get_option('admin_email')));
    } elseif ($item['status'] === 'released') {
        printf('<span class="sk-released">Released %s</span> ', esc_html(get_date_from_gmt($item['released_at'], 'Y-m-d H:i')));
    }
    printf('<a class="button" href="%s">Create rule from this</a> ', esc_url(page_url('spam-killer-rules', array('edit' => 0, 'from' => $id))));
    $form = find_form(form_key($item['source'], $item['form_id']));
    if ($form) {
        printf('<a class="button" href="%s">Edit form</a> ', esc_url($form['edit_url']));
    }
    echo '<button class="button" name="op" value="delete">Delete</button></form></div>';
}

function handle_quarantine_action() {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_quarantine', 'sk_nonce');
    $op = sanitize_key($_POST['op'] ?? '');
    if ($op === '' || $op === '-1') {
        $op = sanitize_key($_POST['action2'] ?? '');
    }
    $ids = array_map('absint', (array) ($_POST['ids'] ?? array()));
    $notice = '';
    if ($op === 'release') {
        $ok = true;
        foreach ($ids as $id) {
            $result = release($id);
            $ok = $ok && $result === true;
        }
        $notice = $ok ? 'released' : 'released_failed';
    } elseif ($op === 'resend') {
        $ok = true;
        foreach ($ids as $id) {
            $ok = resend($id) === true && $ok;
        }
        $notice = $ok ? 'resent' : 'resent_failed';
    } elseif ($op === 'delete') {
        delete_items($ids);
        $notice = 'deleted';
    } elseif ($op === 'empty') {
        delete_all();
        $notice = 'deleted';
    }
    $back = ($_POST['back'] ?? '') === 'spam-killer-log' ? 'spam-killer-log' : 'spam-killer';
    wp_safe_redirect(page_url($back, array_filter(array('sk_notice' => $notice))));
    exit;
}

/* ---------- Rules ---------- */

function rules_page() {
    if (!current_user_can(CAP)) {
        return;
    }
    if (isset($_GET['edit'])) {
        rule_form(absint($_GET['edit']));
        return;
    }
    echo '<div class="wrap"><h1 class="wp-heading-inline">Rules</h1>';
    printf('<a href="%s" class="page-title-action">Add rule</a><hr class="wp-header-end">', esc_url(page_url('spam-killer-rules', array('edit' => 0))));
    notice();
    if (!settings()['modules']['rules']) {
        printf('<div class="notice notice-warning inline"><p>The rules module is off. <a href="%s">Turn it on in Settings</a>.</p></div>', esc_url(page_url('spam-killer-settings')));
    }
    printf('<p class="description">A submission that matches any enabled rule goes to quarantine. Blog comments: %s (<a href="%s">change</a>).</p>', settings()['comment_rules'] ? 'checked too, matches go to Comments → Spam' : 'not checked', esc_url(page_url('spam-killer-settings') . '#comments'));
    $rules = get_rules();
    $missing = array_diff(array_column(default_rules(), 'name'), array_column($rules, 'name'));
    if ($missing) {
        printf('<form method="post" action="%s" class="sk-defaults">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('spam_killer_rule');
        printf('<input type="hidden" name="action" value="spam_killer_rule"><input type="hidden" name="op" value="defaults"><span>Default rules not installed: %s</span> <button class="button">Add default rules</button></form>', esc_html(implode(', ', $missing)));
    }
    if (!$rules) {
        echo '<p>No rules yet. Example: <em>Message contains “1.3426 BTC”</em>, or <em>Link domain equals strictlydigital.net</em>.</p></div>';
        return;
    }
    echo '<table class="widefat striped sk-rules"><thead><tr><th>Rule</th><th>Conditions</th><th>Forms</th><th>Matches</th><th></th></tr></thead><tbody>';
    foreach ($rules as $rule) {
        $conditions = array_map(function ($c) {
            $text = rule_fields()[$c['field']] . ' ' . rule_operators()[$c['operator']];
            return esc_html($c['operator'] === 'is_empty' ? $text : $text . ' “' . $c['value'] . '”');
        }, $rule['conditions']);
        printf('<tr class="%s"><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td>%s</td><td>%d</td><td class="sk-row-actions">',
            $rule['enabled'] ? '' : 'sk-disabled',
            esc_url(page_url('spam-killer-rules', array('edit' => $rule['id']))),
            esc_html($rule['name']),
            $rule['enabled'] ? '' : ' <span class="sk-tag">off</span>',
            implode(' <em>' . ($rule['mode'] === 'all' ? 'and' : 'or') . '</em><br>', $conditions),
            esc_html($rule['forms'] ? implode(', ', $rule['forms']) : 'All forms'),
            $rule['match_count']);
        rule_button($rule['id'], $rule['enabled'] ? 'disable' : 'enable', $rule['enabled'] ? 'Turn off' : 'Turn on');
        rule_button($rule['id'], 'delete', 'Delete', 'return confirm(\'Delete this rule?\')');
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function rule_button($id, $op, $label, $confirm = '') {
    printf('<form method="post" action="%s" class="sk-inline"%s>', esc_url(admin_url('admin-post.php')), $confirm ? ' onsubmit="' . esc_attr($confirm) . '"' : '');
    wp_nonce_field('spam_killer_rule');
    printf('<input type="hidden" name="action" value="spam_killer_rule"><input type="hidden" name="id" value="%d"><button class="button-link" name="op" value="%s">%s</button></form> ', $id, esc_attr($op), esc_html($label));
}

function rule_form($id) {
    $rule = $id ? get_rule($id) : null;
    $draft = get_transient('spam_killer_rule_draft_' . get_current_user_id());
    if (!is_array($draft) && !empty($_GET['from'])) {
        $draft = rule_from_submission(absint($_GET['from']));
    }
    if (is_array($draft)) {
        delete_transient('spam_killer_rule_draft_' . get_current_user_id());
        $rule = $draft;
        $rule['forms'] = array_filter(array_map('trim', explode(',', (string) ($draft['forms'] ?? ''))));
    }
    $rule = $rule ?: array('name' => '', 'mode' => 'all', 'forms' => array(), 'enabled' => true, 'conditions' => array());
    $conditions = $rule['conditions'] ?: array(array('field' => 'message', 'operator' => 'contains', 'value' => ''));
    echo '<div class="wrap"><h1>' . ($id ? 'Edit rule' : 'Add rule') . '</h1>';
    notice();
    printf('<p><a href="%s">&larr; All rules</a></p>', esc_url(page_url('spam-killer-rules')));
    printf('<form method="post" action="%s" class="sk-rule-form">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_rule');
    printf('<input type="hidden" name="action" value="spam_killer_rule"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="%d">', $id);
    echo '<table class="form-table"><tbody>';
    printf('<tr><th><label for="sk-name">Name</label></th><td><input id="sk-name" name="rule[name]" class="regular-text" value="%s" required></td></tr>', esc_attr($rule['name']));
    echo '<tr><th>Quarantine when</th><td><select name="rule[mode]" aria-label="Match mode">';
    foreach (array('all' => 'ALL conditions match', 'any' => 'ANY condition matches') as $k => $v) {
        printf('<option value="%s"%s>%s</option>', $k, selected($rule['mode'], $k, false), $v);
    }
    echo '</select><div id="sk-conditions">';
    foreach (array_values($conditions) as $i => $c) {
        condition_row($i, $c);
    }
    echo '</div><template id="sk-condition-template">';
    condition_row('__i__', array('field' => 'message', 'operator' => 'contains', 'value' => ''));
    echo '</template><p><button type="button" class="button" id="sk-add-condition">+ Add condition</button></p>';
    echo '<p class="description">Matching ignores letter case and extra spaces. Phone matching ignores spaces, dashes and brackets.</p></td></tr>';
    printf('<tr><th><label for="sk-forms">Forms</label></th><td><input id="sk-forms" name="rule[forms]" class="regular-text" value="%s" placeholder="All forms"><p class="description">Optional. Comma-separated form IDs (CF7 form number or Elementor form ID). Empty = all forms.</p></td></tr>', esc_attr(implode(', ', (array) $rule['forms'])));
    printf('<tr><th>Status</th><td><label><input type="checkbox" name="rule[enabled]" value="1"%s> Enabled</label></td></tr>', checked(!empty($rule['enabled']), true, false));
    echo '</tbody></table>';
    submit_button($id ? 'Save rule' : 'Add rule');
    echo '</form></div>';
}

function condition_row($i, array $c) {
    echo '<div class="sk-condition">';
    printf('<select name="rule[conditions][%s][field]" aria-label="Field">', esc_attr($i));
    foreach (rule_fields() as $k => $v) {
        printf('<option value="%s"%s>%s</option>', $k, selected($c['field'], $k, false), esc_html($v));
    }
    printf('</select><select name="rule[conditions][%s][operator]" aria-label="Condition">', esc_attr($i));
    foreach (rule_operators() as $k => $v) {
        printf('<option value="%s"%s>%s</option>', $k, selected($c['operator'], $k, false), esc_html($v));
    }
    printf('</select><input type="text" name="rule[conditions][%s][value]" value="%s" aria-label="Value" class="regular-text">', esc_attr($i), esc_attr($c['value']));
    echo '<button type="button" class="button-link sk-remove" aria-label="Remove condition">Remove</button></div>';
}

function handle_rule_action() {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_rule');
    $id = absint($_POST['id'] ?? 0);
    switch (sanitize_key($_POST['op'] ?? '')) {
        case 'save':
            $input = (array) ($_POST['rule'] ?? array());
            $input['name'] = wp_unslash($input['name'] ?? '');
            $input['forms'] = wp_unslash($input['forms'] ?? '');
            $result = save_rule($input, $id);
            if (is_wp_error($result)) {
                // Keep what the user typed so they can fix it.
                set_transient('spam_killer_rule_draft_' . get_current_user_id(), validate_draft($input), 5 * MINUTE_IN_SECONDS);
                wp_safe_redirect(page_url('spam-killer-rules', array('edit' => $id, 'sk_error' => rawurlencode($result->get_error_message()))));
                exit;
            }
            $notice = 'rule_saved';
            break;
        case 'enable':
        case 'disable':
            set_rule_enabled($id, $_POST['op'] === 'enable');
            $notice = '';
            break;
        case 'delete':
            delete_rule($id);
            $notice = 'rule_deleted';
            break;
        case 'defaults':
            $names = array_column(get_rules(), 'name');
            foreach (default_rules() as $rule) {
                if (!in_array($rule['name'], $names, true)) {
                    save_rule($rule);
                }
            }
            $notice = 'rule_saved';
            break;
        default:
            $notice = '';
    }
    wp_safe_redirect(page_url('spam-killer-rules', array_filter(array('sk_notice' => $notice))));
    exit;
}

function validate_draft(array $input) {
    $conditions = array();
    foreach ((array) ($input['conditions'] ?? array()) as $c) {
        $conditions[] = array(
            'field' => isset(rule_fields()[$c['field'] ?? '']) ? $c['field'] : 'message',
            'operator' => isset(rule_operators()[$c['operator'] ?? '']) ? $c['operator'] : 'contains',
            'value' => wp_unslash((string) ($c['value'] ?? '')),
        );
    }
    return array(
        'name' => sanitize_text_field($input['name'] ?? ''),
        'mode' => ($input['mode'] ?? '') === 'any' ? 'any' : 'all',
        'forms' => sanitize_text_field($input['forms'] ?? ''),
        'enabled' => !empty($input['enabled']),
        'conditions' => $conditions,
    );
}

/* ---------- Settings ---------- */

function settings_page() {
    if (!current_user_can(CAP)) {
        return;
    }
    $s = settings();
    echo '<div class="wrap"><h1>Settings</h1>';
    $errors = (array) get_option('spam_killer_errors', array());
    if ($errors) {
        echo '<div class="notice notice-warning"><p><strong>Some modules failed recently.</strong> Failed checks never count as spam, but they don\'t protect either.</p><ul class="sk-reasons">';
        foreach (array_slice($errors, 0, 5) as $e) {
            printf('<li>%s — %s: %s</li>', esc_html(human_time_diff($e['time']) . ' ago'), esc_html(module_label($e['module'])), esc_html($e['message']));
        }
        printf('</ul><p><a href="%s">Clear</a></p></div>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=spam_killer_clear_errors'), 'spam_killer_clear_errors')));
    }
    // Module sections. Modules added by add-ons land in "Other checks".
    $modules = all_modules();
    $groups = array(
        'bots' => array('Bot traps', 'Invisible to visitors. They catch software that fills in forms automatically.', array('honeypot', 'time_trap', 'js_check', 'user_agent')),
        'volume' => array('Floods and repeats', 'Hold bursts from one visitor and the same text sent from many.', array('rate_limit', 'duplicates')),
        'content' => array('Content filters', 'Hold submissions by what they say or who sent them.', array('disposable_email', 'wp_blocklist', 'scripts', 'rules')),
    );
    $other = array_diff(array_keys($modules), array_merge(...array_column($groups, 2)));
    if ($other) {
        $groups['other'] = array('Other checks', '', $other);
    }
    $sections = array_map(function ($g) { return $g[0]; }, $groups) + array('visitors' => 'What visitors see', 'storage' => 'Notifications and storage', 'comments' => 'Comments');
    echo '<ul class="sk-sections">';
    foreach ($sections as $anchor => $title) {
        printf('<li><a href="#%s">%s</a></li>', esc_attr($anchor), esc_html($title));
    }
    echo '</ul>';
    echo '<form method="post" action="options.php">';
    settings_fields('spam_killer');
    foreach ($groups as $anchor => list($title, $intro, $ids)) {
        printf('<h2 id="%s" class="sk-section">%s</h2>%s<table class="form-table"><tbody>', esc_attr($anchor), esc_html($title), $intro !== '' ? '<p class="description">' . esc_html($intro) . '</p>' : '');
        foreach (array_intersect_key($modules, array_flip($ids)) as $id => $module) {
            printf('<tr><th>%s</th><td><label><input type="checkbox" name="spam_killer_settings[modules][%s]" value="1"%s> On</label><p class="description">%s</p>',
                esc_html($module['label']), esc_attr($id), checked(!empty($s['modules'][$id]), true, false), esc_html($module['description']));
            if ($id === 'rate_limit') {
                printf('<p class="sk-inline-fields">Allow <input type="number" min="1" max="1000" name="spam_killer_settings[rate_limit_max]" value="%d" class="small-text" aria-label="Maximum submissions"> submissions per form every <input type="number" min="1" max="1440" name="spam_killer_settings[rate_limit_minutes]" value="%d" class="small-text" aria-label="Minutes"> minutes from one IP.</p>', $s['rate_limit_max'], $s['rate_limit_minutes']);
            }
            if ($id === 'duplicates') {
                printf('<p class="sk-inline-fields">Compare with the last <input type="number" min="10" max="500" name="spam_killer_settings[duplicate_count]" value="%d" class="small-text" aria-label="Number of submissions"> submissions; hold at <input type="number" min="50" max="100" name="spam_killer_settings[duplicate_similarity]" value="%d" class="small-text" aria-label="Similarity percent">%% similar or more.</p>', $s['duplicate_count'], $s['duplicate_similarity']);
            }
            if ($id === 'time_trap') {
                printf('<p class="sk-inline-fields">Hold forms sent less than <input type="number" min="1" max="60" name="spam_killer_settings[time_trap_seconds]" value="%d" class="small-text" aria-label="Seconds"> seconds after the page loaded.</p>', $s['time_trap_seconds']);
            }
            if ($id === 'rate_limit') {
                echo '<fieldset class="sk-inline-fields"><legend>Visitor IP comes from:</legend>';
                foreach (ip_sources() as $key => $label) {
                    printf('<label><input type="radio" name="spam_killer_settings[ip_source]" value="%s"%s> %s</label><br>', esc_attr($key), checked($s['ip_source'], $key, false), esc_html($label));
                }
                if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && $s['ip_source'] !== 'cloudflare') {
                    echo '<p class="sk-warn">This site looks like it is behind Cloudflare. Choose Cloudflare, or every visitor will share one IP and the rate limit will hold real people.</p>';
                } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && $s['ip_source'] === 'remote') {
                    echo '<p class="sk-warn">This site looks like it is behind a proxy. If all submissions show the same IP, choose the proxy option.</p>';
                }
                echo '</fieldset>';
            }
            if ($id === 'wp_blocklist') {
                $count = count(array_filter(array_map('trim', explode("\n", (string) get_option('disallowed_keys', ''))), 'strlen'));
                printf('<p class="sk-small">%s <a href="%s">Edit the list</a>.</p>', $count ? sprintf('%d entries.', $count) : 'The list is empty.', esc_url(admin_url('options-discussion.php#disallowed_keys')));
            }
            if ($id === 'scripts') {
                echo '<fieldset class="sk-inline-fields sk-scripts"><legend>Hold messages written mostly in:</legend>';
                foreach (scripts() as $key => $script) {
                    printf('<label><input type="checkbox" name="spam_killer_settings[blocked_scripts][]" value="%s"%s> %s</label>', esc_attr($key), checked(in_array($key, $s['blocked_scripts'], true), true, false), esc_html($script[0]));
                }
                echo '</fieldset>';
            }
            if ($id === 'disposable_email') {
                printf('<details class="sk-domains"%s><summary>Edit the list (%s domains built in)</summary>', ($s['disposable_block'] || $s['disposable_allow']) ? ' open' : '', number_format_i18n(count(disposable_domains())));
                printf('<p><label for="sk-block">Also block these domains</label><textarea id="sk-block" name="spam_killer_settings[disposable_block]" rows="5" class="large-text code" placeholder="one domain per line">%s</textarea></p>', esc_textarea(implode("\n", $s['disposable_block'])));
                printf('<p><label for="sk-allow">Never block these domains</label><textarea id="sk-allow" name="spam_killer_settings[disposable_allow]" rows="5" class="large-text code" placeholder="one domain per line">%s</textarea></p>', esc_textarea(implode("\n", $s['disposable_allow'])));
                echo '<p class="description">Subdomains are included: blocking example.com also blocks mail.example.com. "Never block" wins over the built-in list.</p></details>';
            }
            if ($id === 'rules') {
                printf('<p><a href="%s">Manage rules</a></p>', esc_url(page_url('spam-killer-rules')));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<h2 id="visitors" class="sk-section">What visitors see</h2><table class="form-table"><tbody>';
    $digest = sprintf('<tr><th>Daily digest</th><td><label><input type="checkbox" name="spam_killer_settings[digest]" value="1"%s> Email %s a list of held submissions once a day</label><p class="description">Sent at about 08:00, only on days something was held, so you never miss a real enquiry.</p></td></tr>', checked($s['digest'], true, false), esc_html(get_option('admin_email')));
    echo '<tr><th>When a submission is held</th><td><fieldset>';
    foreach (responses() as $key => $label) {
        printf('<label><input type="radio" name="spam_killer_settings[response]" value="%s"%s> %s</label><br>', esc_attr($key), checked($s['response'], $key, false), esc_html($label));
    }
    printf('<p><label for="sk-review">Review message</label><br><input id="sk-review" name="spam_killer_settings[review_message]" value="%s" class="large-text"></p>', esc_attr($s['review_message']));
    echo '<p class="description">Normal success hides the filter from bots. Blocked tells real people their message didn\'t go through, so they can call instead.</p></fieldset></td></tr>';
    echo '</tbody></table><h2 id="storage" class="sk-section">Notifications and storage</h2><table class="form-table"><tbody>' . $digest;
    printf('<tr><th><label for="sk-expire">Keep submissions for</label></th><td><input id="sk-expire" type="number" min="1" max="365" name="spam_killer_settings[expire_days]" value="%d" class="small-text"> days<p class="description">Log and quarantine entries older than this are deleted automatically once a day.</p></td></tr>', $s['expire_days']);
    echo '</tbody></table><h2 id="comments" class="sk-section">Comments</h2><table class="form-table"><tbody>';
    printf('<tr><th>Rules</th><td><label><input type="checkbox" name="spam_killer_settings[comment_rules]" value="1"%s> Apply the <a href="%s">rules</a> to blog comments too</label><p class="description">A matching comment goes to Comments → Spam. Rules limited to specific forms are skipped. Comments from moderators are never checked.</p></td></tr>', checked($s['comment_rules'], true, false), esc_url(page_url('spam-killer-rules')));
    printf('<tr><th>Turn off comments</th><td><label><input type="checkbox" name="spam_killer_settings[disable_comments]" value="1"%s> Close comments and pingbacks on the whole site</label><p class="description">Also hides existing comments and the Comments menu. Nothing is deleted: untick to bring them back.</p></td></tr>', checked($s['disable_comments'], true, false));
    echo '</tbody></table>';
    submit_button();
    echo '</form></div>';
}

/* ---------- Log filters and export ---------- */

/** Log filters from the query string: form key and search text. */
function log_filters() {
    $filters = array();
    if (!empty($_GET['form']) && preg_match('/^(cf7|elementor):[A-Za-z0-9_-]{1,80}$/D', wp_unslash($_GET['form']))) {
        $filters['form'] = wp_unslash($_GET['form']);
    }
    if (isset($_GET['s']) && trim(wp_unslash($_GET['s'])) !== '') {
        $filters['s'] = sanitize_text_field(wp_unslash($_GET['s']));
    }
    return $filters;
}

function export_csv() {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_export');
    $status = isset($_GET['status']) && in_array($_GET['status'], array('passed', 'quarantined', 'released'), true) ? $_GET['status'] : '';
    $rows = query_items($status, log_filters(), 0, 0);
    $labels = array();
    foreach ($rows as $row) {
        foreach ($row['fields'] as $f) {
            $labels[$f['label'] !== '' ? $f['label'] : $f['id']] = true;
        }
    }
    $labels = array_keys($labels);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="spam-killer-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 (Hebrew) correctly.
    fputcsv($out, array_merge(array('ID', 'Received', 'Outcome', 'Form', 'Plugin', 'Why held', 'IP', 'Page'), $labels), ',', '"', '');
    foreach ($rows as $row) {
        $values = array();
        foreach ($row['fields'] as $f) {
            $values[$f['label'] !== '' ? $f['label'] : $f['id']] = field_text($f['value']);
        }
        $line = array(
            $row['id'], get_date_from_gmt($row['created_at']), $row['status'], $row['form_title'], $row['source'],
            implode('; ', array_column($row['reasons'], 'reason')), $row['ip'], $row['page_url'],
        );
        foreach ($labels as $label) {
            $line[] = $values[$label] ?? '';
        }
        // Neutralise spreadsheet formulas in submitted values (CSV injection).
        $line = array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }, $line);
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}
