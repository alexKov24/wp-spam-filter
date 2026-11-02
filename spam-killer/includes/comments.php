<?php
/**
 * WordPress comments: run the keyword rules on them, or turn comments off site-wide.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

/** A new comment that matches an enabled rule goes to Comments → Spam. Rules limited to specific forms are skipped. */
add_filter('pre_comment_approved', function ($approved, $data) {
    if (!settings()['comment_rules'] || is_wp_error($approved) || in_array($approved, array('spam', 'trash'), true) || current_user_can('moderate_comments')) {
        return $approved;
    }
    return comment_rule($data) ? 'spam' : $approved;
}, 20, 2);

/** The first enabled site-wide rule this comment matches, or null. */
function comment_rule(array $data) {
    $submission = array(
        'source' => 'comment',
        'form_id' => '',
        'fields' => array(
            array('id' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => (string) ($data['comment_author'] ?? '')),
            array('id' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => (string) ($data['comment_author_email'] ?? '')),
            array('id' => 'url', 'label' => 'Website', 'type' => 'url', 'value' => (string) ($data['comment_author_url'] ?? '')),
            array('id' => 'message', 'label' => 'Comment', 'type' => 'textarea', 'value' => (string) ($data['comment_content'] ?? '')),
        ),
    );
    foreach (get_rules(true) as $rule) {
        if (!$rule['forms'] && rule_matches($rule, $submission)) {
            count_rule_match($rule['id']);
            return $rule;
        }
    }
    return null;
}

/* ---------- Disable comments ---------- */

add_action('init', function () {
    if (!settings()['disable_comments']) {
        return;
    }
    add_filter('comments_open', '__return_false', 20);
    add_filter('pings_open', '__return_false', 20);
    add_filter('comments_array', '__return_empty_array', 20);
    add_filter('get_comments_number', '__return_zero', 20);
    add_action('admin_menu', function () {
        remove_menu_page('edit-comments.php');
    });
    add_action('admin_bar_menu', function ($bar) {
        $bar->remove_node('comments');
    }, 999);
});
