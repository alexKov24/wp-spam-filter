<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

function defaults() {
    return array(
        'modules' => array('rate_limit' => true, 'disposable_email' => true, 'rules' => true),
        'rate_limit_max' => 5,
        'rate_limit_minutes' => 10,
        'expire_days' => 30,
    );
}

function settings() {
    $saved = get_option('spam_killer_settings', array());
    $settings = array_merge(defaults(), is_array($saved) ? $saved : array());
    $settings['modules'] = array_merge(defaults()['modules'], (array) $settings['modules']);
    return $settings;
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
    return $out;
}
