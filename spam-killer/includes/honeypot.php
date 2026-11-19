<?php
/**
 * Adds the honeypot field and the time-trap token to CF7 and Elementor forms.
 * The token is signed, so a stale cached page passes (it only ever looks "slow").
 * Hidden off-screen (not display:none, which some bots skip), out of tab order,
 * and hidden from screen readers. The check lives in modules.php.
 */

namespace SpamKiller;

defined('ABSPATH') || exit;

add_filter('wpcf7_form_elements', function ($html) {
    $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;
    return $html . hidden_fields('cf7', $form ? $form->id() : '');
});

add_filter('elementor/widget/render_content', function ($content, $widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name') || $widget->get_name() !== 'form') {
        return $content;
    }
    $fields = hidden_fields('elementor', method_exists($widget, 'get_id') ? $widget->get_id() : '');
    $pos = strripos($content, '</form>');
    return $pos === false || $fields === '' ? $content : substr_replace($content, $fields, $pos, 0);
}, 10, 2);

/** Honeypot and/or time-trap token, depending on which modules are on for this form. */
function hidden_fields($source, $form_id) {
    $html = '';
    if (module_on('honeypot', $source, (string) $form_id)) {
        $html .= honeypot_html();
    }
    if (module_on('time_trap', $source, (string) $form_id)) {
        $html .= '<input type="hidden" name="' . esc_attr(TIME_FIELD) . '" value="' . esc_attr(time_token()) . '">';
    }
    if (module_on('js_check', $source, (string) $form_id)) {
        $html .= '<input type="hidden" name="' . esc_attr(JS_FIELD) . '" value="">' . js_script();
    }
    return $html;
}

/**
 * Fills the JS field on load and again just before any form is sent.
 * Printed next to the field, not in the footer: Elementor's element cache and
 * page caches reuse the form HTML without re-running our render hook, so a
 * footer script queued at render time would go missing and real people get held.
 */
function js_script() {
    return sprintf('<script>(function(){if(window.skJs)return;window.skJs=1;var v=%s;function f(){document.querySelectorAll(\'input[name="%s"]\').forEach(function(i){i.value=v;});}f();document.addEventListener("DOMContentLoaded",f);document.addEventListener("submit",f,true);document.addEventListener("click",f,true);})();</script>', wp_json_encode(js_token()), JS_FIELD);
}

function honeypot_html() {
    return '<span aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
        . '<label>Leave this field empty <input type="text" name="' . esc_attr(HONEYPOT) . '" value="" tabindex="-1" autocomplete="off"></label></span>';
}
