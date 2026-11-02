<?php

namespace SpamKiller;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Quarantine_Table extends \WP_List_Table {
    private $status;
    private $page;
    private $filters;

    /** $status '' = all submissions (the log). */
    public function __construct($status, $page = 'spam-killer', array $filters = array()) {
        $this->status = $status;
        $this->page = $page;
        $this->filters = $filters;
        parent::__construct(array('singular' => 'submission', 'plural' => 'submissions', 'ajax' => false));
    }

    public function get_columns() {
        $columns = array('cb' => '<input type="checkbox">', 'submission' => 'Submission');
        if ($this->page === 'spam-killer-log') {
            $columns['outcome'] = 'Outcome';
            $columns['mail'] = 'Email';
        }
        $columns += array('reasons' => 'Why held', 'form' => 'Form', 'created_at' => 'Received');
        if ($this->page === 'spam-killer-log') {
            $columns += array('ip' => 'IP', 'page' => 'Page');
        }
        return $columns;
    }

    protected function get_bulk_actions() {
        if ($this->status === 'quarantined') {
            return array('release' => 'Not spam — email to admin', 'delete' => 'Delete');
        }
        return $this->status === 'passed' ? array('resend' => 'Email to admin again', 'delete' => 'Delete') : array('delete' => 'Delete');
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
        $per_page = 20;
        $page = $this->get_pagenum();
        $total = count_items($this->status, $this->filters);
        $this->items = query_items($this->status, $this->filters, $per_page, ($page - 1) * $per_page);
        $this->_column_headers = array($this->get_columns(), get_hidden_columns($this->screen), array(), 'submission');
        $this->set_pagination_args(array('total_items' => $total, 'per_page' => $per_page));
    }

    public function no_items() {
        echo $this->status === 'quarantined' ? 'Nothing in quarantine.' : 'No submissions.';
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d">', $item['id']);
    }

    protected function column_submission($item) {
        list($who, $text) = summary($item['fields']);
        $url = page_url($this->page, array('item' => $item['id']));
        return sprintf('<a class="row-title" href="%s">%s</a><div class="sk-excerpt">%s</div><div class="row-actions"><span><a href="%s">View</a></span></div>',
            esc_url($url), esc_html($who !== '' ? $who : '(no name or email)'), esc_html($text), esc_url($url));
    }

    protected function column_outcome($item) {
        $labels = array('passed' => 'Passed', 'quarantined' => 'Held', 'released' => 'Released');
        return sprintf('<span class="sk-outcome sk-o-%s">%s</span>', esc_attr($item['status']), esc_html($labels[$item['status']] ?? $item['status']));
    }

    protected function column_mail($item) {
        return mail_label($item);
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

    protected function column_ip($item) {
        return esc_html($item['ip']);
    }

    protected function column_page($item) {
        if ($item['page_url'] === '') {
            return '';
        }
        $path = wp_parse_url($item['page_url'], PHP_URL_PATH) ?: $item['page_url'];
        return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($item['page_url']), esc_html(urldecode($path)));
    }

    protected function column_created_at($item) {
        return esc_html(get_date_from_gmt($item['created_at'], 'Y-m-d H:i'));
    }
}
