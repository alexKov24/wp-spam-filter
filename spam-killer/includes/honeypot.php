<?php
/**
 * Adds the honeypot field to CF7 and Elementor forms.
 * Hidden off-screen (not display:none, which some bots skip), out of tab order,
 * and hidden from screen readers. The check lives in modules.php.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

add_filter('wpcf7_form_elements', function ($html) {
    return honeypot_on() ? $html . honeypot_html() : $html;
});

add_filter('elementor/widget/render_content', function ($content, $widget) {
    if (!honeypot_on() || !is_object($widget) || !method_exists($widget, 'get_name') || $widget->get_name() !== 'form') {
        return $content;
    }
    $pos = strripos($content, '</form>');
    return $pos === false ? $content : substr_replace($content, honeypot_html(), $pos, 0);
}, 10, 2);

function honeypot_on() {
    return !empty(settings()['modules']['honeypot']);
}

function honeypot_html() {
    return '<span aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
        . '<label>Leave this field empty <input type="text" name="' . esc_attr(HONEYPOT) . '" value="" tabindex="-1" autocomplete="off"></label></span>';
}
