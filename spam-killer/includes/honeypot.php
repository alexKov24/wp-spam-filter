<?php
/**
 * Adds the honeypot field to CF7 and Elementor forms.
 * Hidden off-screen (not display:none, which some bots skip), out of tab order,
 * and hidden from screen readers. The check lives in modules.php.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

add_filter('wpcf7_form_elements', function ($html) {
    $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;
    return honeypot_on('cf7', $form ? $form->id() : '') ? $html . honeypot_html() : $html;
});

add_filter('elementor/widget/render_content', function ($content, $widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name') || $widget->get_name() !== 'form' || !honeypot_on('elementor', method_exists($widget, 'get_id') ? $widget->get_id() : '')) {
        return $content;
    }
    $pos = strripos($content, '</form>');
    return $pos === false ? $content : substr_replace($content, honeypot_html(), $pos, 0);
}, 10, 2);

function honeypot_on($source, $form_id) {
    return module_on('honeypot', $source, (string) $form_id);
}

function honeypot_html() {
    return '<span aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
        . '<label>Leave this field empty <input type="text" name="' . esc_attr(HONEYPOT) . '" value="" tabindex="-1" autocomplete="off"></label></span>';
}
