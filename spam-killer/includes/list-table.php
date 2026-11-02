<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Quarantine_Table extends \WP_List_Table {
    private $status;

    public function __construct($status) {
        $this->status = $status;
        parent::__construct(array('singular' => 'submission', 'plural' => 'submissions', 'ajax' => false));
    }

    public function get_columns() {
        return array('cb' => '<input type="checkbox">', 'submission' => 'Submission', 'reasons' => 'Why held', 'form' => 'Form', 'created_at' => 'Received');
    }

    protected function get_bulk_actions() {
        return $this->status === 'quarantined' ? array('release' => 'Not spam — email to admin', 'delete' => 'Delete') : array('delete' => 'Delete');
    }

    public function current_action() {
        return false; // Bulk actions post to admin-post.php instead.
    }

    protected function bulk_actions($which = '') {
        $actions = $this->get_bulk_actions();
        $name = $which === 'top' ? 'op' : 'action2';
        printf('<label for="bulk-%1$s" class="screen-reader-text">Bulk action</label><select name="%1$s" id="bulk-%1$s"><option value="">Bulk actions</option>', esc_attr($name));
        foreach ($actions as $k => $v) {
            printf('<option value="%s">%s</option>', esc_attr($k), esc_html($v));
        }
        echo '</select>';
        submit_button('Apply', 'action', '', false);
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = 20;
        $page = $this->get_pagenum();
        $total = count_items($this->status);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . table('quarantine') . ' WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
            $this->status, $per_page, ($page - 1) * $per_page
        ), ARRAY_A) ?: array();
        foreach ($rows as &$row) {
            $row['fields'] = json_decode($row['fields'], true) ?: array();
            $row['reasons'] = json_decode($row['reasons'], true) ?: array();
        }
        $this->items = $rows;
        $this->_column_headers = array($this->get_columns(), array(), array());
        $this->set_pagination_args(array('total_items' => $total, 'per_page' => $per_page));
    }

    public function no_items() {
        echo $this->status === 'quarantined' ? 'Nothing in quarantine.' : 'No released submissions.';
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d">', $item['id']);
    }

    protected function column_submission($item) {
        list($who, $text) = summary($item['fields']);
        $url = page_url('spam-killer', array('item' => $item['id']));
        return sprintf('<a class="row-title" href="%s">%s</a><div class="sk-excerpt">%s</div><div class="row-actions"><span><a href="%s">View</a></span></div>',
            esc_url($url), esc_html($who !== '' ? $who : '(no name or email)'), esc_html($text), esc_url($url));
    }

    protected function column_reasons($item) {
        return implode('<br>', array_map(function ($r) {
            return '<strong>' . esc_html(module_label($r['module'] ?? '')) . '</strong>: ' . esc_html($r['reason'] ?? '');
        }, $item['reasons']));
    }

    protected function column_form($item) {
        $source = $item['source'] === 'cf7' ? 'CF7' : 'Elementor';
        return esc_html(($item['form_title'] !== '' ? $item['form_title'] : '#' . $item['form_id']) . ' (' . $source . ')');
    }

    protected function column_created_at($item) {
        return esc_html(get_date_from_gmt($item['created_at'], 'Y-m-d H:i'));
    }
}
