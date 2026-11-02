<?php
/**
 * Form directory: every CF7 and Elementor Pro form, where it's placed, and per-form module overrides.
 * Elementor scanning is ported from Elementor Form Finder (includes/forms/).
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

require_once __DIR__ . '/forms/parser.php';
require_once __DIR__ . '/forms/inventory.php';
require_once __DIR__ . '/forms/repository.php';

add_action('init', function () {
    (new Forms\Repository())->register_hooks();
});

function form_key($source, $form_id) {
    return $source . ':' . $form_id;
}

/** Per-form module overrides: array('cf7:4' => array('honeypot' => false, ...)). Missing = site default. */
function form_overrides($key = null) {
    $all = get_option('spam_killer_overrides', array());
    $all = is_array($all) ? $all : array();
    return $key === null ? $all : (array) ($all[$key] ?? array());
}

function save_form_overrides($key, array $input) {
    $all = form_overrides();
    $clean = array();
    foreach (array_keys(all_modules()) as $id) {
        $value = $input[$id] ?? '';
        if ($value === 'on' || $value === 'off') {
            $clean[$id] = $value === 'on';
        }
    }
    if ($clean) {
        $all[$key] = $clean;
    } else {
        unset($all[$key]);
    }
    update_option('spam_killer_overrides', $all, false);
}

/** Is a module on for this form? Per-form override wins over the site setting. */
function module_on($module, $source = '', $form_id = '') {
    $override = form_overrides(form_key($source, $form_id));
    if (array_key_exists($module, $override)) {
        return (bool) $override[$module];
    }
    return !empty(settings()['modules'][$module]);
}

/**
 * All forms, newest data each call (Elementor scan is cached 5 minutes by the repository).
 * Each: key, source, form_id, name, fields (list of label/type), actions, edit_url, source_title, pages, templates (lists of post IDs).
 */
function all_forms() {
    $forms = array();
    try {
        $inventory = (new Forms\Repository())->get();
    } catch (\RuntimeException $e) {
        $inventory = array('documents' => array(), 'forms' => array());
    }
    $docs = $inventory['documents'];

    foreach ($inventory['forms'] as $f) {
        if (($f['plugin'] ?? 'elementor') !== 'elementor' || $f['widget_id'] === '') {
            continue;
        }
        $key = form_key('elementor', $f['widget_id']);
        $forms[$key . '@' . $f['source_id']] = array(
            'key' => $key,
            'source' => 'elementor',
            'form_id' => $f['widget_id'],
            'name' => $f['name'] !== '' ? $f['name'] : '(unnamed form)',
            'fields' => $f['fields'],
            'actions' => $f['actions'],
            'edit_url' => admin_url('post.php?post=' . (int) $f['source_id'] . '&action=elementor'),
            'source_title' => $docs[$f['source_id']]['title'] ?? '',
            'pages' => $f['pages'],
            'templates' => $f['templates'],
        );
    }

    if (post_type_exists('wpcf7_contact_form')) {
        $embeds = array_filter($inventory['forms'], function ($f) { return ($f['plugin'] ?? '') === 'cf7'; });
        foreach (get_posts(array('post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC')) as $post) {
            $hash = (string) get_post_meta($post->ID, '_hash', true);
            $pages = array();
            $templates = array();
            foreach ($embeds as $embed) {
                $ref = $embed['ref'];
                if ($ref === (string) $post->ID || (strlen($ref) >= 7 && $hash !== '' && strpos($hash, $ref) === 0)) {
                    $pages = array_merge($pages, $embed['pages']);
                    $templates = array_merge($templates, $embed['templates']);
                }
            }
            $fields = array();
            $cf7 = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($post->ID) : null;
            if ($cf7) {
                foreach ($cf7->scan_form_tags() as $tag) {
                    if ($tag->name !== '' && $tag->basetype !== 'submit') {
                        $fields[] = array('label' => $tag->name, 'type' => $tag->basetype);
                    }
                }
            }
            $key = form_key('cf7', $post->ID);
            $forms[$key] = array(
                'key' => $key,
                'source' => 'cf7',
                'form_id' => (string) $post->ID,
                'name' => $post->post_title,
                'fields' => $fields,
                'actions' => array('email'),
                'edit_url' => admin_url('admin.php?page=wpcf7&post=' . $post->ID . '&action=edit'),
                'source_title' => '',
                'pages' => array_values(array_unique($pages)),
                'templates' => array_values(array_unique($templates)),
            );
        }
    }
    return array_values($forms);
}

/** One form from all_forms() by key, or null if it no longer exists. */
function find_form($key) {
    foreach (all_forms() as $form) {
        if ($form['key'] === $key) {
            return $form;
        }
    }
    return null;
}

/** Submission counts per form key: array('cf7:4' => array('total' => n, 'quarantined' => n, 'last' => datetime)). */
function form_stats() {
    global $wpdb;
    $rows = $wpdb->get_results('SELECT source, form_id, status, COUNT(*) AS n, MAX(created_at) AS last FROM ' . table('submissions') . ' GROUP BY source, form_id, status', ARRAY_A) ?: array();
    $stats = array();
    foreach ($rows as $r) {
        $key = form_key($r['source'], $r['form_id']);
        $stats[$key] = $stats[$key] ?? array('total' => 0, 'quarantined' => 0, 'last' => '');
        $stats[$key]['total'] += (int) $r['n'];
        if ($r['status'] !== 'passed') {
            $stats[$key]['quarantined'] += (int) $r['n'];
        }
        $stats[$key]['last'] = max($stats[$key]['last'], (string) $r['last']);
    }
    return $stats;
}
