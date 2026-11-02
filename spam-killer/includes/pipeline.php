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
            if ((bool) ($module['remote'] ?? false) !== $remote || empty($settings['modules'][$id]) || !is_callable($module['check'] ?? null)) {
                continue;
            }
            try {
                foreach ((array) call_user_func($module['check'], $submission, $settings) as $reason) {
                    $reasons[] = array('module' => $id) + $reason;
                }
            } catch (\Throwable $e) {
                error_log('Spam Killer: module ' . $id . ' failed: ' . $e->getMessage());
            }
        }
    }
    return $reasons;
}

/**
 * Inspects a submission and quarantines it on a match.
 * Returns the quarantine id, or 0 if the submission is clean.
 */
function screen(array $submission) {
    $reasons = inspect($submission);
    if (!$reasons) {
        return 0;
    }
    $id = quarantine($submission, $reasons);
    do_action('spam_killer_quarantined', $id, $submission, $reasons);
    return $id;
}
