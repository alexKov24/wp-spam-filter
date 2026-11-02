<?php
/**
 * Filter modules. Each module can be switched on/off in Settings.
 *
 * A module is array(
 *   'label' => string, 'description' => string,
 *   'remote' => bool,   // true = slow/paid (e.g. AI). Only runs if local modules found nothing.
 *   'check' => callable(array $submission, array $settings): list of array('reason' => string, 'fields' => list of field ids),
 * )
 *
 * Add-ons (the paid AI module) register through the `spam_killer_modules` filter.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

function all_modules() {
    $modules = array(
        'honeypot' => array(
            'label' => 'Honeypot',
            'description' => 'Adds a hidden field to every form. People never see it; bots fill it in.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_honeypot',
        ),
        'rate_limit' => array(
            'label' => 'Rate limit',
            'description' => 'Quarantine when one visitor (IP) sends too many submissions to the same form.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_rate_limit',
        ),
        'disposable_email' => array(
            'label' => 'Disposable email',
            'description' => 'Quarantine submissions from temporary email providers (mailinator.com, etc.).',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_disposable_email',
        ),
        'rules' => array(
            'label' => 'Keyword & domain rules',
            'description' => 'Quarantine submissions that match your rules.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_rules',
        ),
    );
    return apply_filters('spam_killer_modules', $modules);
}

function check_honeypot(array $submission) {
    if ($submission['honeypot'] === null || trim($submission['honeypot']) === '') {
        return array();
    }
    return array(array('reason' => 'The hidden honeypot field was filled in.', 'fields' => array()));
}

function check_rate_limit(array $submission, array $settings) {
    if ($submission['ip'] === '') {
        return array();
    }
    $key = 'spam_killer_rl_' . md5($submission['ip'] . '|' . $submission['source'] . '|' . $submission['form_id']);
    $window = $settings['rate_limit_minutes'] * MINUTE_IN_SECONDS;
    $now = time();
    $hits = array_filter((array) get_transient($key), function ($t) use ($now, $window) {
        return is_int($t) && $t > $now - $window;
    });
    $hits[] = $now;
    set_transient($key, array_values($hits), $window);
    if (count($hits) > $settings['rate_limit_max']) {
        return array(array(
            'reason' => sprintf('%d submissions from this IP in %d minutes (limit %d).', count($hits), $settings['rate_limit_minutes'], $settings['rate_limit_max']),
            'fields' => array(),
        ));
    }
    return array();
}

function disposable_domains() {
    static $domains = null;
    if ($domains === null) {
        $lines = file(dirname(__DIR__) . '/data/disposable-domains.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $domains = array_fill_keys(array_map('strtolower', $lines ?: array()), true);
    }
    return $domains;
}

function is_disposable_email($email) {
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $parts = explode('.', strtolower(trim(substr($email, $at + 1), " .\t\n")));
    $settings = settings();
    $allow = array_fill_keys($settings['disposable_allow'], true);
    $block = array_fill_keys($settings['disposable_block'], true) + disposable_domains();
    // Most specific first: a.b.mailinator.com → b.mailinator.com → mailinator.com. Allow wins at the same level.
    for ($i = 0; $i < count($parts) - 1; $i++) {
        $domain = implode('.', array_slice($parts, $i));
        if (isset($allow[$domain])) {
            return false;
        }
        if (isset($block[$domain])) {
            return true;
        }
    }
    return false;
}

function check_disposable_email(array $submission) {
    $reasons = array();
    foreach ($submission['fields'] as $field) {
        $value = trim(field_text($field['value']));
        if ($value !== '' && ($field['type'] === 'email' || is_email($value)) && is_disposable_email($value)) {
            $reasons[] = array('reason' => 'Disposable email address: ' . $value, 'fields' => array($field['id']));
        }
    }
    return $reasons;
}

function check_rules(array $submission) {
    $reasons = array();
    foreach (get_rules(true) as $rule) {
        if (rule_matches($rule, $submission)) {
            count_rule_match($rule['id']);
            $reasons[] = array('reason' => 'Matched rule: ' . $rule['name'], 'fields' => array(), 'rule_id' => $rule['id']);
        }
    }
    return $reasons;
}
