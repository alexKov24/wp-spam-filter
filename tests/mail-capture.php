<?php
// Test-only mu-plugin: log outgoing mail to wp-content/mail.log instead of sending.
add_filter('pre_wp_mail', function ($null, $atts) {
    file_put_contents(WP_CONTENT_DIR . '/mail.log', wp_json_encode(array('to' => $atts['to'], 'subject' => $atts['subject'])) . "\n", FILE_APPEND);
    return true;
}, 10, 2);
