<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

/**
 * Runs enabled modules over a submission.
 * Returns the list of reasons; empty = clean.
 * Local modules all run (so the inbox shows every reason). Remote modules
 * only run when local ones found nothing. A module error never counts as spam.
 */
function inspect(array $submission) {
    $settings = settings();
    $modules = all_modules();
    $reasons = array();
    foreach (array(false, true) as $remote) {
        if ($remote && $reasons) {
            break;
        }
        foreach ($modules as $id => $module) {
            if ((bool) ($module['remote'] ?? false) !== $remote || !module_on($id, $submission['source'], $submission['form_id']) || !is_callable($module['check'] ?? null)) {
                continue;
            }
            try {
                foreach ((array) call_user_func($module['check'], $submission, $settings) as $reason) {
                    $reasons[] = array('module' => $id) + $reason;
                }
            } catch (\Throwable $e) {
                error_log('Spam Killer: module ' . $id . ' failed: ' . $e->getMessage());
                record_error($id, $e->getMessage());
            }
        }
    }
    return $reasons;
}

/**
 * Inspects and logs a submission; a match is quarantined.
 * Returns the quarantine id, or 0 if the submission is clean.
 */
function screen(array $submission) {
    $reasons = inspect($submission);
    $id = log_submission($submission, $reasons);
    if (!$reasons) {
        track_mail($id);
        return 0;
    }
    do_action('spam_killer_quarantined', $id, $submission, $reasons);
    return $id;
}

/** Keeps the last 20 module errors for the Settings page. */
function record_error($module, $message) {
    $errors = (array) get_option('spam_killer_errors', array());
    array_unshift($errors, array('module' => $module, 'message' => substr($message, 0, 500), 'time' => time()));
    update_option('spam_killer_errors', array_slice($errors, 0, 20), false);
}
