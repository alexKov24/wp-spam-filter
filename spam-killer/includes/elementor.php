<?php
/**
 * Elementor Pro Forms adapter.
 *
 * Elementor's new_record hook runs after form actions (email, webhooks), so we
 * intercept in the validation hook. A quarantined submission ends the request
 * with a success (normal or review message) or error response, per Settings;
 * no form actions run.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

add_action('elementor_pro/forms/validation', __NAMESPACE__ . '\\elementor_screen', 100, 2);

function elementor_submission($record) {
    $fields = array();
    foreach ((array) $record->get('fields') as $key => $field) {
        if (!is_array($field) || in_array($field['type'] ?? '', array('honeypot', 'recaptcha', 'recaptcha_v3', 'hidden', 'step', 'html'), true)) {
            continue;
        }
        $fields[] = array(
            'id' => (string) ($field['id'] ?? $key),
            'label' => (string) ($field['title'] ?? ''),
            'type' => (string) ($field['type'] ?? 'text'),
            'value' => $field['raw_value'] ?? $field['value'] ?? '',
        );
    }
    $page = isset($_POST['referrer']) && is_string($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '';
    return make_submission('elementor', $record->get_form_settings('id'), (string) $record->get_form_settings('form_name'), $fields, $page);
}

function elementor_screen($record, $handler) {
    // Leave invalid submissions to Elementor's own error handling.
    if (!empty($handler->errors)) {
        return;
    }
    if (!screen(elementor_submission($record))) {
        return;
    }
    $settings = settings();
    if ($settings['response'] === 'review') {
        wp_send_json_success(array('message' => $settings['review_message'], 'data' => array()));
    }
    if ($settings['response'] === 'blocked') {
        $message = elementor_message($record, $handler, 'error', __('Your submission could not be sent.', 'spam-killer'));
        wp_send_json_error(array('message' => $message, 'errors' => array(), 'data' => array()));
    }
    $message = elementor_message($record, $handler, 'success', __('Your submission was successful.', 'spam-killer'));
    wp_send_json_success(array('message' => $message, 'data' => array()));
}

/** The form's custom message, else Elementor's default, else ours. */
function elementor_message($record, $handler, $type, $fallback) {
    $message = (string) $record->get_form_settings($type . '_message');
    if ($message === '' && is_callable(array($handler, 'get_default_message'))) {
        $message = (string) call_user_func(array($handler, 'get_default_message'), $type, $record->get('form_settings'));
    }
    return $message !== '' ? $message : $fallback;
}
