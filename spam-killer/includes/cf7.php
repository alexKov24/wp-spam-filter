<?php
/**
 * Contact Form 7 adapter.
 *
 * Runs on wpcf7_before_send_mail (after CF7's own validation and spam checks).
 * A quarantined submission aborts mail, so wpcf7_mail_sent integrations don't fire.
 * The visitor sees the normal success message, the review message (both as
 * "mail_sent"), or CF7's spam message (as "spam"), depending on Settings.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

add_action('wpcf7_before_send_mail', __NAMESPACE__ . '\\cf7_screen', 1, 3);

function cf7_submission($contact_form, $cf7_submission) {
    $fields = array();
    foreach ($contact_form->scan_form_tags() as $tag) {
        if ($tag->name === '' || in_array($tag->basetype, array('submit', 'hidden', 'quiz', 'captchar', 'acceptance'), true)) {
            continue;
        }
        $fields[] = array(
            'id' => $tag->name,
            'label' => $tag->name,
            'type' => $tag->basetype,
            'value' => $cf7_submission->get_posted_data($tag->name),
        );
    }
    // meta 'url' is the REST endpoint; the page holding the form is the container post or the referer.
    $post_id = (int) $cf7_submission->get_meta('container_post_id');
    $page = $post_id ? get_permalink($post_id) : wp_get_raw_referer();
    return make_submission('cf7', $contact_form->id(), $contact_form->title(), $fields, (string) $page);
}

function cf7_screen($contact_form, &$abort, $cf7_submission) {
    if ($abort || !$cf7_submission) {
        return;
    }
    if (!screen(cf7_submission($contact_form, $cf7_submission))) {
        return;
    }
    $abort = true;
    $settings = settings();
    if ($settings['response'] === 'blocked') {
        // CF7's own (editable) spam message.
        $cf7_submission->set_status('spam');
        $cf7_submission->set_response($contact_form->message('spam'));
        return;
    }
    $cf7_submission->set_status('mail_sent');
    $cf7_submission->set_response($settings['response'] === 'review' ? $settings['review_message'] : $contact_form->message('mail_sent_ok'));
}
