<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

function defaults() {
    return array(
        'modules' => array('honeypot' => true, 'rate_limit' => true, 'disposable_email' => true, 'rules' => true),
        'rate_limit_max' => 5,
        'rate_limit_minutes' => 10,
        'expire_days' => 30,
        'disposable_block' => array(),
        'disposable_allow' => array(),
        'response' => 'success',
        'review_message' => __('Thanks! Your message was received and will be reviewed shortly.', 'spam-killer'),
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
    $out['disposable_block'] = domain_list($input['disposable_block'] ?? '');
    $out['disposable_allow'] = domain_list($input['disposable_allow'] ?? '');
    $out['response'] = isset(responses()[$input['response'] ?? '']) ? $input['response'] : 'success';
    $review = sanitize_text_field($input['review_message'] ?? '');
    $out['review_message'] = $review !== '' ? mb_substr($review, 0, 500) : defaults()['review_message'];
    return $out;
}
