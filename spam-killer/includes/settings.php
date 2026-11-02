<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

function defaults() {
    return array(
        'modules' => array('honeypot' => true, 'time_trap' => true, 'rate_limit' => true, 'disposable_email' => true, 'wp_blocklist' => true, 'scripts' => false, 'rules' => true),
        'time_trap_seconds' => 3,
        'blocked_scripts' => array(),
        'ip_source' => 'remote',
        'rate_limit_max' => 5,
        'rate_limit_minutes' => 10,
        'expire_days' => 30,
        'disposable_block' => array(),
        'disposable_allow' => array(),
        'response' => 'success',
        'review_message' => __('Thanks! Your message was received and will be reviewed shortly.', 'spam-killer'),
    );
}

/** Writing systems the language filter can hold. Key => array(label, PCRE script name). */
function scripts() {
    return array(
        'cyrillic' => array('Cyrillic (Russian, Ukrainian…)', 'Cyrillic'),
        'han' => array('Chinese / Japanese characters', 'Han'),
        'hangul' => array('Korean', 'Hangul'),
        'arabic' => array('Arabic', 'Arabic'),
        'hebrew' => array('Hebrew', 'Hebrew'),
        'latin' => array('Latin (English, French…)', 'Latin'),
        'thai' => array('Thai', 'Thai'),
        'devanagari' => array('Devanagari (Hindi…)', 'Devanagari'),
        'greek' => array('Greek', 'Greek'),
    );
}

/** Where the visitor's IP comes from. Only pick a header your server/proxy actually sets. */
function ip_sources() {
    return array(
        'remote' => 'Direct connection (REMOTE_ADDR) — default',
        'cloudflare' => 'Cloudflare (CF-Connecting-IP header)',
        'forwarded' => 'Other proxy / load balancer (first X-Forwarded-For address)',
    );
}

/** What the visitor sees when a submission is quarantined. */
function responses() {
    return array(
        'success' => 'Normal success message — the visitor can\'t tell it was held',
        'review' => 'Held for review — shows the message below',
        'blocked' => 'Blocked — shows the form\'s error message',
    );
}

function settings() {
    $saved = get_option('spam_killer_settings', array());
    $settings = array_merge(defaults(), is_array($saved) ? $saved : array());
    $settings['modules'] = array_merge(defaults()['modules'], (array) $settings['modules']);
    return $settings;
}

/** Textarea (one domain per line, or commas) → clean unique list. */
function domain_list($text) {
    $domains = array();
    foreach (preg_split('/[\s,]+/', strtolower((string) $text)) as $domain) {
        $domain = preg_replace('/^(?:.*@|www\.)/', '', trim($domain, " .\t"));
        if (preg_match('/^(?:[a-z0-9-]+\.)+[a-z0-9-]{2,}$/D', $domain)) {
            $domains[$domain] = true;
        }
    }
    return array_slice(array_keys($domains), 0, 5000);
}

function sanitize_settings($input) {
    $input = is_array($input) ? $input : array();
    $out = defaults();
    foreach (array_keys(all_modules()) as $id) {
        $out['modules'][$id] = !empty($input['modules'][$id]);
    }
    $out['rate_limit_max'] = max(1, min(1000, absint($input['rate_limit_max'] ?? 5)));
    $out['rate_limit_minutes'] = max(1, min(1440, absint($input['rate_limit_minutes'] ?? 10)));
    $out['expire_days'] = max(1, min(365, absint($input['expire_days'] ?? 30)));
    $out['time_trap_seconds'] = max(1, min(60, absint($input['time_trap_seconds'] ?? 3)));
    $out['blocked_scripts'] = array_values(array_intersect(array_keys(scripts()), array_map('strval', (array) ($input['blocked_scripts'] ?? array()))));
    $out['ip_source'] = isset(ip_sources()[$input['ip_source'] ?? '']) ? $input['ip_source'] : 'remote';
    $out['disposable_block'] = domain_list($input['disposable_block'] ?? '');
    $out['disposable_allow'] = domain_list($input['disposable_allow'] ?? '');
    $out['response'] = isset(responses()[$input['response'] ?? '']) ? $input['response'] : 'success';
    $review = sanitize_text_field($input['review_message'] ?? '');
    $out['review_message'] = $review !== '' ? mb_substr($review, 0, 500) : defaults()['review_message'];
    return $out;
}
