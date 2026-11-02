<?php
// Test-only mu-plugin: log outgoing mail to wp-content/mail.log instead of sending.
// A subject containing FAILTEST simulates a delivery failure.
add_filter('pre_wp_mail', function ($null, $atts) {
    if (strpos($atts['subject'], 'FAILTEST') !== false) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP connect() failed (simulated)'));
        return false;
    }
    file_put_contents(WP_CONTENT_DIR . '/mail.log', wp_json_encode(array('to' => $atts['to'], 'subject' => $atts['subject'])) . "\n", FILE_APPEND);
    return true;
}, 10, 2);
