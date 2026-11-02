<?php
/**
 * A submission is normalized to one shape regardless of the form plugin:
 * array(
 *   'source' => 'cf7' | 'elementor',
 *   'form_id' => string, 'form_title' => string,
 *   'fields' => list of array('id', 'label', 'type', 'value'),
 *   'ip' => string, 'page_url' => string,
 * )
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

const ROLES = array('name', 'email', 'phone', 'message');

function client_ip() {
    // REMOTE_ADDR only: forwarded headers are spoofable unless a trusted proxy sets them.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function make_submission($source, $form_id, $form_title, array $fields, $page_url = '') {
    return array(
        'source' => $source,
        'form_id' => (string) $form_id,
        'form_title' => (string) $form_title,
        'fields' => array_values($fields),
        'ip' => client_ip(),
        'page_url' => (string) $page_url,
    );
}

function field_text($value) {
    if (is_array($value)) {
        $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
    }
    return is_scalar($value) || $value === null ? (string) $value : '';
}

/**
 * Finds the field that plays a role (name/email/phone/message).
 * Field type wins; then id/label aliases.
 */
function role_field(array $submission, $role) {
    $types = array('email' => array('email'), 'phone' => array('tel'), 'message' => array('textarea'), 'name' => array());
    $aliases = array(
        'name' => array('name', 'your-name', 'full_name', 'fullname', 'first_name', 'שם', 'שם מלא'),
        'email' => array('email', 'your-email', 'e-mail', 'אימייל', 'דוא"ל'),
        'phone' => array('phone', 'tel', 'telephone', 'mobile', 'your-phone', 'טלפון'),
        'message' => array('message', 'your-message', 'comments', 'הודעה'),
    );
    $by_type = array_values(array_filter($submission['fields'], function ($f) use ($types, $role) {
        return in_array($f['type'], $types[$role], true);
    }));
    if (count($by_type) === 1) {
        return $by_type[0];
    }
    foreach ($submission['fields'] as $field) {
        if (in_array(normalize($field['id']), $aliases[$role], true) || in_array(normalize($field['label']), $aliases[$role], true)) {
            return $field;
        }
    }
    return $by_type[0] ?? null;
}

function normalize($value) {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip invisible characters spammers use to dodge keyword filters.
    $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function link_domains($text) {
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('~https?://[^\s<>"\[\]]+~iu', $text, $explicit);
    $rest = preg_replace('~https?://[^\s<>"\[\]]+~iu', ' ', $text) ?? '';
    preg_match_all('~(?<![\w@.-])(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]+)?(?:/[^\s<>"\[\]]*)?~iu', $rest, $bare);
    $hosts = array();
    foreach (array_merge($explicit[0], $bare[0]) as $url) {
        $url = rtrim($url, ".,;:!?)'\\");
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            $hosts[] = preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
        }
    }
    return array_values(array_unique($hosts));
}
