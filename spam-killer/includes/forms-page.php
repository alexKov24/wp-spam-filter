<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

add_action('admin_post_spam_killer_form', function () {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_form');
    $key = sanitize_text_field(wp_unslash($_POST['form'] ?? ''));
    if (!preg_match('/^(cf7|elementor):[A-Za-z0-9_-]{1,80}$/D', $key)) {
        wp_die('Unknown form.', 400);
    }
    save_form_overrides($key, array_map('sanitize_key', (array) ($_POST['modules'] ?? array())));
    wp_safe_redirect(page_url('spam-killer-forms', array('sk_notice' => 'form_saved')));
    exit;
});

add_action('admin_post_spam_killer_rescan', function () {
    if (!current_user_can(CAP)) {
        wp_die('Not allowed.', 403);
    }
    check_admin_referer('spam_killer_rescan');
    Forms\Repository::invalidate();
    wp_safe_redirect(page_url('spam-killer-forms'));
    exit;
});

function post_links($ids, $elementor = false) {
    $links = array();
    foreach ($ids as $id) {
        $title = get_the_title($id) ?: '#' . $id;
        $parts = array();
        if (get_post_status($id) === 'publish' && get_post_type($id) !== 'elementor_library') {
            $parts[] = sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($id)), esc_html($title));
        } else {
            $parts[] = esc_html($title) . (get_post_status($id) !== 'publish' ? ' <span class="sk-tag">' . esc_html(get_post_status($id)) . '</span>' : '');
        }
        if (current_user_can('edit_post', $id)) {
            $edit = ($elementor || get_post_meta($id, '_elementor_edit_mode', true) === 'builder') ? admin_url('post.php?post=' . $id . '&action=elementor') : get_edit_post_link($id);
            $parts[] = sprintf('<a class="sk-small" href="%s">edit</a>', esc_url($edit));
        }
        $links[] = implode(' ', $parts);
    }
    return $links;
}

function forms_page() {
    if (!current_user_can(CAP)) {
        return;
    }
    $forms = all_forms();
    if (isset($_GET['form'])) {
        $key = sanitize_text_field(wp_unslash($_GET['form']));
        foreach ($forms as $form) {
            if ($form['key'] === $key) {
                form_settings_page($form);
                return;
            }
        }
    }
    $stats = form_stats();
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $sources = array_unique(array_column($forms, 'source'));
    $source = isset($_GET['source']) && in_array($_GET['source'], $sources, true) ? $_GET['source'] : '';
    if ($source !== '') {
        $forms = array_values(array_filter($forms, function ($f) use ($source) { return $f['source'] === $source; }));
    }
    if ($search !== '') {
        $forms = array_values(array_filter($forms, function ($f) use ($search) {
            $text = implode(' ', array_merge(array($f['name'], $f['form_id'], $f['source_title']), array_map('get_the_title', array_merge($f['pages'], $f['templates']))));
            return mb_stripos($text, $search) !== false;
        }));
    }
    echo '<div class="wrap"><h1 class="wp-heading-inline">Forms</h1>';
    printf('<form method="post" action="%s" class="sk-inline">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_rescan');
    echo '<input type="hidden" name="action" value="spam_killer_rescan"><button class="page-title-action">Rescan</button></form><hr class="wp-header-end">';
    notice();
    echo '<p class="description">Every Contact Form 7 and Elementor Pro form on the site, where it is placed, and how it is protected. Placements come from saved page content; Theme Builder and popup display conditions are not expanded.</p>';
    echo '<form method="get" class="sk-search"><input type="hidden" name="page" value="spam-killer-forms">';
    if (count($sources) > 1) {
        echo '<select name="source" aria-label="Form plugin"><option value="">All form plugins</option>';
        foreach (array('cf7' => 'Contact Form 7', 'elementor' => 'Elementor') as $key => $label) {
            if (in_array($key, $sources, true)) {
                printf('<option value="%s"%s>%s</option>', $key, selected($source, $key, false), $label);
            }
        }
        echo '</select> ';
    }
    printf('<input type="search" name="s" value="%s" placeholder="Search forms or pages…" aria-label="Search forms or pages"> <button class="button">Filter</button></form>', esc_attr($search));
    if (!$forms) {
        echo '<p>No forms found.</p></div>';
        return;
    }
    echo '<table class="widefat striped sk-forms"><thead><tr><th>Form</th><th>Placed on</th><th>Fields</th><th>Submissions</th><th>Protection</th></tr></thead><tbody>';
    foreach ($forms as $f) {
        $s = $stats[$f['key']] ?? array('total' => 0, 'quarantined' => 0, 'last' => '');
        $placed = array_merge(post_links($f['pages']), array_map(function ($l) { return 'Template: ' . $l; }, post_links($f['templates'], true)));
        $overrides = form_overrides($f['key']);
        $protection = $overrides ? implode(', ', array_map(function ($m, $on) { return module_label($m) . ' ' . ($on ? 'on' : 'off'); }, array_keys($overrides), $overrides)) : 'Site defaults';
        printf('<tr><td><strong><a href="%s">%s</a></strong> <span class="sk-tag">%s</span><div class="sk-small">ID %s%s</div></td>',
            esc_url($f['edit_url']), esc_html($f['name']), $f['source'] === 'cf7' ? 'CF7' : 'Elementor', esc_html($f['form_id']),
            $f['actions'] && $f['source'] === 'elementor' ? ' · actions: ' . esc_html(implode(', ', $f['actions'])) : '');
        printf('<td>%s</td>', $placed ? implode('<br>', $placed) : '<span class="sk-muted">Not placed on any page</span>');
        printf('<td class="sk-small">%s</td>', esc_html(implode(', ', array_map(function ($x) { return $x['label'] !== '' ? $x['label'] : $x['type']; }, $f['fields']))));
        printf('<td><a href="%s">%d total</a>%s%s</td>',
            esc_url(page_url('spam-killer-log', array('form' => $f['key']))), $s['total'],
            $s['quarantined'] ? sprintf(' · <a class="sk-held" href="%s">%d held</a>', esc_url(page_url('spam-killer-log', array('form' => $f['key'], 'status' => 'quarantined'))), $s['quarantined']) : '',
            $s['last'] ? '<div class="sk-small">last ' . esc_html(human_time_diff(strtotime($s['last'] . ' UTC'))) . ' ago</div>' : '');
        printf('<td>%s <a class="sk-small" href="%s">change</a></td></tr>', esc_html($protection), esc_url(page_url('spam-killer-forms', array('form' => $f['key']))));
    }
    echo '</tbody></table></div>';
}

function form_settings_page(array $form) {
    $overrides = form_overrides($form['key']);
    $site = settings()['modules'];
    printf('<div class="wrap"><h1>Protection: %s</h1>', esc_html($form['name']));
    printf('<p><a href="%s">&larr; All forms</a></p>', esc_url(page_url('spam-killer-forms')));
    printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
    wp_nonce_field('spam_killer_form');
    printf('<input type="hidden" name="action" value="spam_killer_form"><input type="hidden" name="form" value="%s">', esc_attr($form['key']));
    echo '<table class="form-table"><tbody>';
    foreach (all_modules() as $id => $module) {
        $value = array_key_exists($id, $overrides) ? ($overrides[$id] ? 'on' : 'off') : '';
        printf('<tr><th>%s</th><td><select name="modules[%s]" aria-label="%s">', esc_html($module['label']), esc_attr($id), esc_attr($module['label']));
        foreach (array('' => 'Site default (' . (!empty($site[$id]) ? 'on' : 'off') . ')', 'on' => 'On for this form', 'off' => 'Off for this form') as $k => $label) {
            printf('<option value="%s"%s>%s</option>', $k, selected($value, $k, false), esc_html($label));
        }
        printf('</select><p class="description">%s</p></td></tr>', esc_html($module['description']));
    }
    echo '</tbody></table>';
    submit_button('Save');
    echo '</form></div>';
}
