<?php
/**
 * Keyword/domain rules: Field → condition → value, combined with ALL or ANY.
 * Ported from the earlier filter (legacy/), minus site-specific starter rules.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

function rule_fields() {
    return array(
        'any' => 'Any field',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'message' => 'Message',
        'link_domain' => 'Link domain (any field)',
    );
}

function rule_operators() {
    return array(
        'contains' => 'contains',
        'equals' => 'equals',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
        'is_empty' => 'is empty',
    );
}

function normalize_for($field, $value) {
    $text = normalize($value);
    return $field === 'phone' ? preg_replace('/[\s().-]+/', '', $text) : $text;
}

/** Returns a clean rule array or WP_Error. */
function validate_rule($input) {
    if (!is_array($input)) {
        return new \WP_Error('invalid_rule', 'Invalid rule.');
    }
    $name = sanitize_text_field($input['name'] ?? '');
    if ($name === '' || strlen($name) > 200) {
        return new \WP_Error('invalid_name', 'Give the rule a name (up to 200 characters).');
    }
    $mode = ($input['mode'] ?? '') === 'any' ? 'any' : 'all';
    $forms = array_values(array_filter(array_map('trim', explode(',', (string) ($input['forms'] ?? '')))));
    foreach ($forms as $form) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $form)) {
            return new \WP_Error('invalid_form', 'Form IDs may contain only letters, numbers, _ and -.');
        }
    }
    $conditions = array();
    foreach ((array) ($input['conditions'] ?? array()) as $c) {
        $field = $c['field'] ?? '';
        $operator = $c['operator'] ?? '';
        $value = trim(wp_unslash((string) ($c['value'] ?? '')));
        if (!isset(rule_fields()[$field]) || !isset(rule_operators()[$operator])) {
            continue;
        }
        if ($operator === 'is_empty') {
            $value = '';
        } elseif (normalize_for($field, $value) === '') {
            continue; // Blank row.
        }
        if (strlen($value) > 5000) {
            return new \WP_Error('invalid_value', 'Condition values are limited to 5,000 characters.');
        }
        if ($field === 'link_domain' && $operator !== 'is_empty') {
            $value = preg_replace('/^www\./', '', strtolower($value));
            if (!preg_match('/^(?:[a-z0-9-]+\.)*[a-z0-9-]+$/D', $value)) {
                return new \WP_Error('invalid_domain', 'Enter a domain like example.com (no https:// or path).');
            }
        }
        $conditions[] = compact('field', 'operator', 'value');
    }
    if (!$conditions || count($conditions) > 25) {
        return new \WP_Error('invalid_conditions', 'Add between 1 and 25 conditions.');
    }
    return array(
        'name' => $name,
        'mode' => $mode,
        'forms' => $forms,
        'enabled' => !empty($input['enabled']),
        'conditions' => $conditions,
    );
}

/** Values a condition looks at. null = the field is not in this form. */
function condition_values($field, array $submission) {
    if ($field === 'any' || $field === 'link_domain') {
        $all = array_map(function ($f) { return field_text($f['value']); }, $submission['fields']);
        if ($field === 'any') {
            return $all;
        }
        return link_domains(implode("\n", $all));
    }
    $match = role_field($submission, $field);
    return $match === null ? null : array(field_text($match['value']));
}

function condition_matches(array $c, array $submission) {
    $values = condition_values($c['field'], $submission);
    if ($values === null) {
        return false; // A missing field is not an empty field.
    }
    $values = array_map(function ($v) use ($c) { return normalize_for($c['field'], $v); }, $values);
    if ($c['operator'] === 'is_empty') {
        return !array_filter($values, 'strlen');
    }
    $needle = normalize_for($c['field'], $c['value']);
    foreach ($values as $value) {
        switch ($c['operator']) {
            case 'equals':
                if ($value === $needle) { return true; }
                break;
            case 'contains':
                if (strpos($value, $needle) !== false) { return true; }
                break;
            case 'starts_with':
                if (strpos($value, $needle) === 0) { return true; }
                break;
            case 'ends_with':
                if ($needle !== '' && substr($value, -strlen($needle)) === $needle) { return true; }
                break;
        }
    }
    return false;
}

function rule_matches(array $rule, array $submission) {
    if ($rule['forms'] && !in_array($submission['form_id'], $rule['forms'], true)) {
        return false;
    }
    $results = array_map(function ($c) use ($submission) { return condition_matches($c, $submission); }, $rule['conditions']);
    return $rule['mode'] === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true);
}

function hydrate_rule($row) {
    $data = json_decode($row['definition'], true);
    if (!is_array($data)) {
        return null;
    }
    return array_merge($data, array(
        'id' => (int) $row['id'],
        'enabled' => (bool) $row['enabled'],
        'match_count' => (int) $row['match_count'],
        'last_match' => $row['last_match'],
    ));
}

function get_rules($enabled_only = false) {
    global $wpdb;
    $sql = 'SELECT * FROM ' . table('rules') . ($enabled_only ? ' WHERE enabled = 1' : '') . ' ORDER BY id ASC';
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: array();
    return array_values(array_filter(array_map(__NAMESPACE__ . '\\hydrate_rule', $rows)));
}

function get_rule($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . table('rules') . ' WHERE id = %d', $id), ARRAY_A);
    return $row ? hydrate_rule($row) : null;
}

function save_rule($input, $id = 0) {
    global $wpdb;
    $rule = validate_rule($input);
    if (is_wp_error($rule)) {
        return $rule;
    }
    $now = gmdate('Y-m-d H:i:s');
    $data = array('definition' => wp_json_encode($rule), 'enabled' => (int) $rule['enabled'], 'updated_at' => $now);
    if ($id) {
        $wpdb->update(table('rules'), $data, array('id' => $id));
    } else {
        $wpdb->insert(table('rules'), $data + array('created_at' => $now));
        $id = (int) $wpdb->insert_id;
    }
    return get_rule($id);
}

function delete_rule($id) {
    global $wpdb;
    $wpdb->delete(table('rules'), array('id' => $id));
}

function set_rule_enabled($id, $enabled) {
    global $wpdb;
    $wpdb->update(table('rules'), array('enabled' => (int) $enabled), array('id' => $id));
}

function count_rule_match($id) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . table('rules') . ' SET match_count = match_count + 1, last_match = %s WHERE id = %d', gmdate('Y-m-d H:i:s'), $id));
}
