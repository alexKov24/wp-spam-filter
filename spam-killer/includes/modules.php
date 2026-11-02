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
        'time_trap' => array(
            'label' => 'Time trap',
            'description' => 'Quarantine forms sent faster than a person can type. Bots submit instantly.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_time_trap',
        ),
        'js_check' => array(
            'label' => 'JavaScript check',
            'description' => 'A hidden field that a small script fills in. Bots that post the form without running the page\'s JavaScript leave it empty.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_js',
        ),
        'user_agent' => array(
            'label' => 'Bot user agents',
            'description' => 'Quarantine submissions sent with no browser name, or by scripts and headless browsers (curl, python-requests, HeadlessChrome…).',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_user_agent',
        ),
        'rate_limit' => array(
            'label' => 'Rate limit',
            'description' => 'Quarantine when one visitor (IP) sends too many submissions to the same form.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_rate_limit',
        ),
        'duplicates' => array(
            'label' => 'Duplicate messages',
            'description' => 'Quarantine a message that is nearly the same as a recent one sent from another IP (spam blasts reuse the same text).',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_duplicates',
        ),
        'disposable_email' => array(
            'label' => 'Disposable email',
            'description' => 'Quarantine submissions from temporary email providers (mailinator.com, etc.).',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_disposable_email',
        ),
        'wp_blocklist' => array(
            'label' => 'WordPress disallowed words',
            'description' => 'Quarantine submissions containing anything from Settings → Discussion → Disallowed Comment Keys (words, emails, IPs).',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_wp_blocklist',
        ),
        'scripts' => array(
            'label' => 'Language filter',
            'description' => 'Quarantine messages written mostly in a writing system your visitors don\'t use.',
            'remote' => false,
            'check' => __NAMESPACE__ . '\\check_scripts',
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

function check_time_trap(array $submission, array $settings) {
    if ($submission['rendered_at'] === null) {
        return array(); // No token: don't guess.
    }
    $elapsed = time() - $submission['rendered_at'];
    if ($elapsed < $settings['time_trap_seconds']) {
        return array(array('reason' => sprintf('Sent %d second(s) after the form loaded (minimum %d).', max(0, $elapsed), $settings['time_trap_seconds']), 'fields' => array()));
    }
    return array();
}

function check_js(array $submission) {
    if ($submission['js'] === null || hash_equals(js_token(), $submission['js'])) {
        return array(); // null = form without our field (cached page or direct POST): don't guess.
    }
    return array(array('reason' => 'The form was sent without running the page\'s JavaScript.', 'fields' => array()));
}

function check_user_agent(array $submission) {
    $ua = $submission['user_agent'];
    if ($ua === '') {
        return array(array('reason' => 'Sent with no browser name (empty user agent).', 'fields' => array()));
    }
    if (preg_match('/curl|wget|python|go-http-client|java\/|libwww|perl|ruby|php\/|guzzle|okhttp|axios|node-fetch|undici|httpclient|scrapy|headless|phantomjs|selenium|puppeteer|playwright/i', $ua, $m)) {
        return array(array('reason' => sprintf('Sent by a script or headless browser ("%s" in the user agent).', $m[0]), 'fields' => array()));
    }
    return array();
}

/**
 * Compares the first 500 characters of the message with the last N logged submissions (any form, any outcome); ~0.5 ms each.
 * Copies from the same IP are skipped: repeats from one visitor are the rate limit's job,
 * and a real person re-sending their own message shouldn't be held.
 */
function check_duplicates(array $submission, array $settings) {
    $field = role_field($submission, 'message');
    $text = $field ? mb_substr(normalize(field_text($field['value'])), 0, 500) : '';
    if (mb_strlen($text) < 20) {
        return array(); // Short texts ("Hi", "Call me") repeat naturally.
    }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, ip, fields FROM ' . table('submissions') . ' ORDER BY id DESC LIMIT %d', $settings['duplicate_count']), ARRAY_A) ?: array();
    $min = $settings['duplicate_similarity'];
    $len = strlen($text);
    foreach ($rows as $row) {
        if ($submission['ip'] !== '' && $row['ip'] === $submission['ip']) {
            continue;
        }
        $old = role_field(array('fields' => json_decode($row['fields'], true) ?: array()), 'message');
        $other = $old ? mb_substr(normalize(field_text($old['value'])), 0, 500) : '';
        // similar_text % is at most 2*shorter/(sum of lengths); skip pairs that can't reach the threshold.
        if ($other === '' || 200 * min($len, strlen($other)) / ($len + strlen($other)) < $min) {
            continue;
        }
        similar_text($text, $other, $percent);
        if ($percent >= $min) {
            return array(array('reason' => sprintf('%d%% the same as submission #%d from another IP.', round($percent), $row['id']), 'fields' => array($field['id'])));
        }
    }
    return array();
}

/** Same matching as WordPress comments: case-insensitive substring per line, on every field and the IP. */
function check_wp_blocklist(array $submission) {
    $keys = array_filter(array_map('trim', explode("\n", (string) get_option('disallowed_keys', ''))), 'strlen');
    if (!$keys) {
        return array();
    }
    $values = array('IP' => $submission['ip']);
    foreach ($submission['fields'] as $field) {
        $values[$field['id']] = field_text($field['value']);
    }
    foreach ($keys as $key) {
        foreach ($values as $id => $value) {
            if ($value !== '' && mb_stripos($value, $key) !== false) {
                return array(array('reason' => 'Contains disallowed word "' . $key . '"', 'fields' => $id === 'IP' ? array() : array($id)));
            }
        }
    }
    return array();
}

/** Holds text where most letters belong to a blocked writing system. Needs at least 10 letters. */
function check_scripts(array $submission, array $settings) {
    if (!$settings['blocked_scripts']) {
        return array();
    }
    $text = implode(' ', array_map(function ($f) { return in_array($f['type'], array('email', 'url', 'tel', 'number'), true) ? '' : field_text($f['value']); }, $submission['fields']));
    $letters = preg_match_all('/\p{L}/u', $text);
    if ($letters < 10) {
        return array();
    }
    foreach ($settings['blocked_scripts'] as $key) {
        $script = scripts()[$key];
        $count = preg_match_all('/\p{' . $script[1] . '}/u', $text);
        if ($count / $letters >= 0.5) {
            return array(array('reason' => sprintf('Written mostly in %s (%d%% of letters).', $script[0], round(100 * $count / $letters)), 'fields' => array()));
        }
    }
    return array();
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
