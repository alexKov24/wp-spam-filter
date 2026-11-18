<?php
/**
 * Updates from GitHub releases. A release is a vX.Y.Z tag with a spam-killer.zip asset
 * (built by .github/workflows/release.yml). Hooked via the plugin's Update URI header.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

const REPO = 'alexKov24/wp-spam-filter';

/** Latest release as array(version, zip, url, notes, date), or null. Cached 12 hours (1 hour after a failure). */
function latest_release() {
    $cached = get_site_transient('spam_killer_release');
    if ($cached !== false && empty($_GET['force-check'])) {
        return $cached ?: null;
    }
    $release = null;
    $res = wp_remote_get('https://api.github.com/repos/' . REPO . '/releases/latest', array(
        'timeout' => 10,
        'headers' => array('Accept' => 'application/vnd.github+json'),
    ));
    if (wp_remote_retrieve_response_code($res) === 200) {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $zip = '';
        foreach ($data['assets'] ?? array() as $asset) {
            if (($asset['name'] ?? '') === 'spam-killer.zip') {
                $zip = $asset['browser_download_url'];
            }
        }
        if ($zip && preg_match('/^v?(\d+\.\d+\.\d+)$/D', $data['tag_name'] ?? '', $m)) {
            $release = array(
                'version' => $m[1],
                'zip'     => $zip,
                'url'     => $data['html_url'] ?? 'https://github.com/' . REPO,
                'notes'   => (string) ($data['body'] ?? ''),
                'date'    => (string) ($data['published_at'] ?? ''),
            );
        }
    }
    set_site_transient('spam_killer_release', $release ?: 0, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $release;
}

// WordPress compares 'version' with the installed one and offers the update if newer.
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(FILE) || !($release = latest_release())) {
        return $update;
    }
    return array(
        'slug'    => 'spam-killer',
        'version' => $release['version'],
        'url'     => $release['url'],
        'package' => $release['zip'],
    );
}, 10, 3);

// "View version details" popup.
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'spam-killer' || !($release = latest_release())) {
        return $result;
    }
    return (object) array(
        'name'          => 'Spam Killer',
        'slug'          => 'spam-killer',
        'version'       => $release['version'],
        'homepage'      => 'https://github.com/' . REPO,
        'download_link' => $release['zip'],
        'last_updated'  => $release['date'],
        'sections'      => array('changelog' => wpautop(esc_html($release['notes'] ?: 'See ' . $release['url']))),
    );
}, 10, 3);
