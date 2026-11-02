<?php
/**
 * Engine tests. Run inside a WordPress install with the plugin active:
 *   wp eval-file tests/engine-test.php
 */

use function SpamKiller\{normalize, link_domains, is_disposable_email, validate_rule, rule_matches, inspect, screen,
    save_rule, get_item, release, delete_items, expire, make_submission, table, settings, elementor_screen, count_items};

$GLOBALS['sk_failures'] = 0;
function check($label, $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $GLOBALS['sk_failures']++; }
}
function sub($fields, $form = '7', $source = 'cf7') {
    $list = array();
    foreach ($fields as $id => $f) {
        $list[] = array('id' => $id, 'label' => $f[2] ?? $id, 'type' => $f[0], 'value' => $f[1]);
    }
    return make_submission($source, $form, 'Test form', $list, 'https://example.test/contact');
}

global $wpdb;
$wpdb->query('DELETE FROM ' . table('rules'));
$wpdb->query('DELETE FROM ' . table('submissions'));
// Rate-limit counters from earlier runs.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%spam_killer_rl_%'");
update_option('spam_killer_settings', array());
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Firefox/140.0';
// Most tests resend the same text from new IPs; the duplicate check is tested on its own below.
$GLOBALS['sk_dupes'] = false;
function sk_no_dupes($m) { return $GLOBALS['sk_dupes'] ? $m : array_diff_key($m, array('duplicates' => 1)); }
add_filter('spam_killer_modules', 'sk_no_dupes');

// Normalization and parsing.
check('normalize strips zero-width chars and case', normalize("  B\u{200B}TC\n  Offer ") === 'btc offer');
check('link domains from URLs and bare hosts', link_domains('see https://www.Spam.example/x and go.fiverr.com/?bta=1') === array('spam.example', 'go.fiverr.com'));

// Disposable email.
check('mailinator is disposable', is_disposable_email('a@mailinator.com'));
check('subdomain of disposable is disposable', is_disposable_email('a@x.mailinator.com'));
check('gmail is not disposable', !is_disposable_email('a@gmail.com'));

// Rule validation.
check('rule needs a name', is_wp_error(validate_rule(array('name' => '', 'conditions' => array(array('field' => 'message', 'operator' => 'contains', 'value' => 'x'))))));
check('rule needs a condition', is_wp_error(validate_rule(array('name' => 'x', 'conditions' => array(array('field' => 'message', 'operator' => 'contains', 'value' => ' '))))));
check('domain rejects URL', is_wp_error(validate_rule(array('name' => 'x', 'conditions' => array(array('field' => 'link_domain', 'operator' => 'equals', 'value' => 'https://a.com/x'))))));

// Rule matching.
$s = sub(array('your-name' => array('text', 'Isaac'), 'your-email' => array('email', 'isaac@gmail.com'), 'your-phone' => array('tel', '+972 (50) 123-4567'), 'your-message' => array('textarea', 'IMPORTANT! 1.3426 BTC https://linkypay.com/x')));
$r = function ($conditions, $mode = 'all', $forms = '') {
    return validate_rule(array('name' => 't', 'mode' => $mode, 'forms' => $forms, 'enabled' => 1, 'conditions' => $conditions));
};
check('message contains (case-insensitive)', rule_matches($r(array(array('field' => 'message', 'operator' => 'contains', 'value' => '1.3426 btc'))), $s));
check('any field contains', rule_matches($r(array(array('field' => 'any', 'operator' => 'contains', 'value' => 'isaac@'))), $s));
check('link domain equals', rule_matches($r(array(array('field' => 'link_domain', 'operator' => 'equals', 'value' => 'www.linkypay.com'))), $s));
check('phone ignores formatting', rule_matches($r(array(array('field' => 'phone', 'operator' => 'starts_with', 'value' => '+97250123'))), $s));
check('ALL fails if one condition fails', !rule_matches($r(array(array('field' => 'message', 'operator' => 'contains', 'value' => 'btc'), array('field' => 'name', 'operator' => 'equals', 'value' => 'bob'))), $s));
check('ANY passes if one condition passes', rule_matches($r(array(array('field' => 'message', 'operator' => 'contains', 'value' => 'btc'), array('field' => 'name', 'operator' => 'equals', 'value' => 'bob')), 'any'), $s));
check('form scope excludes other forms', !rule_matches($r(array(array('field' => 'message', 'operator' => 'contains', 'value' => 'btc')), 'all', '99'), $s));
$no_phone = sub(array('your-message' => array('textarea', '')));
check('missing field is not "empty"', !rule_matches($r(array(array('field' => 'phone', 'operator' => 'is_empty'))), $no_phone));
check('empty field is "empty"', rule_matches($r(array(array('field' => 'message', 'operator' => 'is_empty'))), $no_phone));

// Pipeline.
$clean = sub(array('your-name' => array('text', 'Dana'), 'your-email' => array('email', 'dana@gmail.com'), 'your-message' => array('textarea', 'Hi, what are your hours?')));
check('clean submission passes', inspect($clean) === array());
$disposable = sub(array('your-email' => array('email', 'bot@mailinator.com')));
$reasons = inspect($disposable);
check('disposable email is caught', count($reasons) === 1 && $reasons[0]['module'] === 'disposable_email');
update_option('spam_killer_settings', array('modules' => array('disposable_email' => false)));
check('module switched off does nothing', inspect($disposable) === array());
update_option('spam_killer_settings', array());

save_rule(array('name' => 'BTC', 'enabled' => 1, 'conditions' => array(array('field' => 'message', 'operator' => 'contains', 'value' => '1.3426 BTC'))));
$reasons = inspect($s);
check('rule module quarantines', count($reasons) === 1 && $reasons[0]['reason'] === 'Matched rule: BTC');

// Failing module does not count as spam; remote module runs only when local found nothing.
$remote_calls = 0;
add_filter('spam_killer_modules', function ($m) use (&$remote_calls) {
    $m['broken'] = array('label' => 'Broken', 'description' => '', 'remote' => false, 'check' => function () { throw new RuntimeException('boom'); });
    $m['ai'] = array('label' => 'AI', 'description' => '', 'remote' => true, 'check' => function () use (&$remote_calls) { $remote_calls++; return array(); });
    return $m;
});
update_option('spam_killer_settings', array('modules' => array('broken' => true, 'ai' => true)));
check('module error is not a match', inspect($clean) === array());
check('remote module runs when local is clean', $remote_calls === 1);
inspect($s);
check('remote module skipped when local matched', $remote_calls === 1);
remove_all_filters('spam_killer_modules');
add_filter('spam_killer_modules', 'sk_no_dupes');
update_option('spam_killer_settings', array());

// Rate limit: default 5 per 10 minutes.
update_option('spam_killer_settings', array('modules' => array('rules' => false, 'disposable_email' => false)));
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(1, 250);
$rl = make_submission('cf7', 'rl-' . wp_rand(), 'RL', $clean['fields']);
$results = array();
for ($i = 0; $i < 6; $i++) { $results[] = (bool) inspect($rl); }
check('rate limit allows 5 then catches the 6th', $results === array(false, false, false, false, false, true));
update_option('spam_killer_settings', array());

// Quarantine, release, delete, expire.
$sent = array();
add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) { $sent[] = $atts; return true; }, 10, 2);
$id = screen($s);
$item = get_item($id);
check('quarantine stores fields and reasons', $item && $item['status'] === 'quarantined' && count($item['fields']) === 4 && in_array('rules', array_column($item['reasons'], 'module'), true));
check('release emails admin', release($id) === true && count($sent) === 1 && $sent[0]['to'] === get_option('admin_email') && strpos($sent[0]['message'], '1.3426 BTC') !== false);
check('release sets Reply-To', in_array('Reply-To: isaac@gmail.com', (array) $sent[0]['headers'], true));
check('released item changes status', get_item($id)['status'] === 'released');
check('cannot release twice', is_wp_error(release($id)));
check('delete removes item', delete_items(array($id)) === 1 && get_item($id) === null);
$old = screen($s);
$wpdb->update(table('submissions'), array('created_at' => gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS)), array('id' => $old));
$fresh = screen($s);
expire();
check('expiry deletes items older than 30 days only', get_item($old) === null && get_item($fresh) !== null);

// Elementor adapter, with stand-ins for Elementor's record and handler.
class Fake_Record {
    public $data;
    public function __construct($fields) { $this->data = array('fields' => $fields, 'form_settings' => array('id' => 'abc123', 'form_name' => 'Contact', 'success_message' => 'Thanks!')); }
    public function get($k) { return $this->data[$k]; }
    public function get_form_settings($k) { return $this->data['form_settings'][$k] ?? null; }
}
class Fake_Handler { public $errors = array(); }
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', function () { return function () { throw new Exception('died'); }; });
$run = function ($fields, $handler = null) {
    ob_start();
    $died = false;
    try { elementor_screen(new Fake_Record($fields), $handler ?: new Fake_Handler()); } catch (Exception $e) { $died = true; }
    return array($died, ob_get_clean());
};
$before = count_items();
list($died, $out) = $run(array('email' => array('id' => 'email', 'type' => 'email', 'title' => 'Email', 'value' => 'x@mailinator.com', 'raw_value' => 'x@mailinator.com')));
$json = json_decode($out, true);
check('elementor spam ends request with success message', $died && $json['success'] === true && $json['data']['message'] === 'Thanks!');
check('elementor spam is quarantined', count_items() === $before + 1);
list($died) = $run(array('email' => array('id' => 'email', 'type' => 'email', 'title' => 'Email', 'value' => 'dana@gmail.com', 'raw_value' => 'dana@gmail.com')));
check('elementor clean submission continues', !$died);
$h = new Fake_Handler(); $h->errors = array('email' => 'Invalid');
list($died) = $run(array('email' => array('id' => 'email', 'type' => 'email', 'title' => 'Email', 'value' => 'x@mailinator.com', 'raw_value' => 'x@mailinator.com')), $h);
check('elementor invalid submission left to Elementor', !$died);

// Log: clean submissions are stored as passed.
check('clean submission returns 0 from screen', screen(make_submission('cf7', 'log-' . wp_rand(), 'Log', $clean['fields'])) === 0);
check('clean submission is logged as passed', ($row = get_item((int) $wpdb->get_var('SELECT MAX(id) FROM ' . table('submissions')))) && $row['status'] === 'passed' && $row['reasons'] === array());

// Honeypot.
$_POST['sk_hp'] = 'http://spam.example';
check('filled honeypot is caught', array_column(inspect(make_submission('cf7', 'hp', 'HP', $clean['fields'])), 'module') === array('honeypot'));
$_POST['sk_hp'] = '';
check('empty honeypot passes', inspect(make_submission('cf7', 'hp', 'HP', $clean['fields'])) === array());
unset($_POST['sk_hp']);
check('missing honeypot (cached page) passes', inspect(make_submission('cf7', 'hp', 'HP', $clean['fields'])) === array());
check('honeypot added to CF7 markup', strpos(apply_filters('wpcf7_form_elements', '<p>x</p>'), 'name="sk_hp"') !== false);
class Fake_Widget { public function get_name() { return 'form'; } }
check('honeypot added inside Elementor form', preg_match('~name="sk_hp".*</form>~s', apply_filters('elementor/widget/render_content', '<form><input></form>', new Fake_Widget())) === 1);
update_option('spam_killer_settings', array('modules' => array('honeypot' => false, 'time_trap' => false, 'js_check' => false)));
check('honeypot, time trap and JS check off add nothing', apply_filters('wpcf7_form_elements', '<p>x</p>') === '<p>x</p>');
update_option('spam_killer_settings', array());

// Editable disposable list.
check('domain list parsing', SpamKiller\domain_list("Foo.com, bar@baz.org\nwww.qux.net\nnot a domain\nfoo.com") === array('foo.com', 'baz.org', 'qux.net'));
update_option('spam_killer_settings', SpamKiller\sanitize_settings(array('modules' => array('disposable_email' => 1), 'disposable_block' => 'spammy.example', 'disposable_allow' => 'mailinator.com')));
check('extra blocked domain is caught', is_disposable_email('a@mail.spammy.example'));
check('allowed domain overrides built-in list', !is_disposable_email('a@mailinator.com'));
check('other built-in domains still blocked', is_disposable_email('a@guerrillamail.com'));
update_option('spam_killer_settings', array());

// Default banned-word rules.
$defaults = SpamKiller\default_rules();
check('default rules are valid', count($defaults) === 3 && !array_filter(array_map('is_wp_error', array_map('SpamKiller\\validate_rule', $defaults))));
$hit = function ($text) use ($defaults) {
    $sub = make_submission('cf7', '1', 'T', array(array('id' => 'your-message', 'label' => '', 'type' => 'textarea', 'value' => $text)));
    foreach ($defaults as $rule) { if (rule_matches(validate_rule($rule), $sub)) { return true; } }
    return false;
};
check('default rules catch SEO pitch', $hit('We can get you to the FIRST PAGE of Google with quality backlinks'));
check('default rules catch crypto bait', $hit('Withdraw your Bitcoin now'));
check('default rules ignore a normal enquiry', !$hit('Hi, I live in Essex and want to book a lesson for my kids next week.'));

// Per-form module overrides.
$dispo = array(array('id' => 'your-email', 'label' => '', 'type' => 'email', 'value' => 'z@mailinator.com'));
SpamKiller\save_form_overrides('cf7:news', array('disposable_email' => 'off'));
check('override turns a module off for one form', inspect(make_submission('cf7', 'news', 'N', $dispo)) === array());
check('other forms keep the site default', count(inspect(make_submission('cf7', 'other', 'O', $dispo))) === 1);
update_option('spam_killer_settings', array('modules' => array('disposable_email' => false)));
SpamKiller\save_form_overrides('cf7:sales', array('disposable_email' => 'on'));
check('override turns a module on when the site has it off', count(inspect(make_submission('cf7', 'sales', 'S', $dispo))) === 1);
SpamKiller\save_form_overrides('cf7:sales', array('disposable_email' => ''));
check('"site default" removes the override', SpamKiller\form_overrides('cf7:sales') === array());
delete_option('spam_killer_overrides');
update_option('spam_killer_settings', array());

// Log filters.
$wpdb->query('DELETE FROM ' . table('submissions'));
$heb = make_submission('cf7', 'f1', 'F1', array(array('id' => 'msg', 'label' => 'הודעה', 'type' => 'textarea', 'value' => 'שלום, אני רוצה הצעת מחיר')));
SpamKiller\log_submission($heb, array());
SpamKiller\log_submission(make_submission('elementor', 'abc', 'F2', $clean['fields']), array(array('module' => 'rules', 'reason' => 'x')));
check('filter by form', SpamKiller\count_items('', array('form' => 'cf7:f1')) === 1);
check('search finds Hebrew text', SpamKiller\count_items('', array('s' => 'הצעת מחיר')) === 1);
check('search + status combine', SpamKiller\count_items('quarantined', array('s' => 'hours')) === 1 && SpamKiller\count_items('passed', array('s' => 'hours')) === 0);

// Time trap.
$fresh_form = function () use ($clean) { return make_submission('cf7', 'tt-' . wp_rand(), 'TT', $clean['fields']); };
$_POST['sk_ts'] = SpamKiller\time_token();
check('instant submit is caught by time trap', array_column(inspect($fresh_form()), 'module') === array('time_trap'));
$_POST['sk_ts'] = SpamKiller\time_token(time() - 30);
check('slow submit passes time trap', inspect($fresh_form()) === array());
$_POST['sk_ts'] = (time()) . '.0000000000000000';
check('forged token is ignored, not flagged', inspect($fresh_form()) === array());
unset($_POST['sk_ts']);
check('time token is added to forms', strpos(apply_filters('wpcf7_form_elements', ''), 'name="sk_ts"') !== false);

// WordPress disallowed words.
update_option('disallowed_keys', "cheap-pills\n198.51.100.9");
check('WP disallowed word is caught', array_column(inspect(make_submission('cf7', 'bl', 'BL', array(array('id' => 'm', 'label' => '', 'type' => 'textarea', 'value' => 'Buy CHEAP-PILLS now')))), 'module') === array('wp_blocklist'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check('WP disallowed IP is caught', array_column(inspect(make_submission('cf7', 'bl2' . wp_rand(), 'BL', $clean['fields'])), 'module') === array('wp_blocklist'));
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
update_option('disallowed_keys', '');

// Language filter.
update_option('spam_killer_settings', SpamKiller\sanitize_settings(array('modules' => array('scripts' => 1), 'blocked_scripts' => array('cyrillic', 'han'))));
$msg = function ($t) { return make_submission('cf7', 'lang', 'L', array(array('id' => 'm', 'label' => '', 'type' => 'textarea', 'value' => $t), array('id' => 'e', 'label' => '', 'type' => 'email', 'value' => 'a@b.com'))); };
check('Cyrillic message is held', array_column(inspect($msg('Здравствуйте, хочу узнать цену на ваши услуги')), 'module') === array('scripts'));
check('Chinese message is held', array_column(inspect($msg('你好，我想知道你们的价格是多少')), 'module') === array('scripts'));
check('Hebrew message passes', inspect($msg('שלום, אני רוצה לקבוע שיעור לשבוע הבא')) === array());
check('one Cyrillic word in English text passes', inspect($msg('Hello, my name is Иван and I would like to book a lesson please')) === array());
update_option('spam_killer_settings', array());

// Proxy IP.
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.44';
check('REMOTE_ADDR used by default', SpamKiller\client_ip() === '203.0.113.5');
update_option('spam_killer_settings', array('ip_source' => 'cloudflare'));
check('Cloudflare header used when chosen', SpamKiller\client_ip() === '192.0.2.44');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check('invalid header falls back to REMOTE_ADDR', SpamKiller\client_ip() === '203.0.113.5');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
update_option('spam_killer_settings', array());

// Module errors are recorded.
delete_option('spam_killer_errors');
add_filter('spam_killer_modules', function ($m) { $m['broken'] = array('label' => 'Broken', 'description' => '', 'remote' => false, 'check' => function () { throw new RuntimeException('boom'); }); return $m; });
update_option('spam_killer_settings', array('modules' => array('broken' => true)));
inspect($clean);
check('module error shown in settings list', (get_option('spam_killer_errors')[0]['message'] ?? '') === 'boom');
remove_all_filters('spam_killer_modules');
add_filter('spam_killer_modules', 'sk_no_dupes');
update_option('spam_killer_settings', array());
delete_option('spam_killer_errors');

// Rule from submission.
$rid = SpamKiller\log_submission(make_submission('cf7', 'rf', 'RF', array(array('id' => 'e', 'label' => '', 'type' => 'email', 'value' => 'mike@seo.example'), array('id' => 'm', 'label' => '', 'type' => 'textarea', 'value' => 'Visit https://www.backlinks.example/offer'))), array());
$draft = SpamKiller\rule_from_submission($rid);
check('rule from submission uses email and link domains', $draft['conditions'] === array(
    array('field' => 'email', 'operator' => 'equals', 'value' => 'mike@seo.example'),
    array('field' => 'link_domain', 'operator' => 'equals', 'value' => 'backlinks.example'),
) && !is_wp_error(validate_rule($draft)));

// Privacy export / erase.
$wpdb->query('DELETE FROM ' . table('submissions'));
$person = function ($email, $msg) { return make_submission('cf7', 'p', 'Privacy form', array(array('id' => 'e', 'label' => 'Email', 'type' => 'email', 'value' => $email), array('id' => 'm', 'label' => 'Message', 'type' => 'textarea', 'value' => $msg))); };
SpamKiller\log_submission($person('Noa@Example.com', 'first'), array());
SpamKiller\log_submission($person('noa@example.com', 'second'), array(array('module' => 'rules', 'reason' => 'x')));
SpamKiller\log_submission($person('other@example.com', 'mentions noa@example.com in text only'), array());
$export = SpamKiller\privacy_export('noa@example.com', 1);
check('privacy export finds the person\'s submissions only', count($export['data']) === 2 && $export['done'] === true);
check('privacy export includes field values', in_array(array('name' => 'Message', 'value' => 'first'), $export['data'][0]['data'], true));
$erase = SpamKiller\privacy_erase('noa@example.com', 1);
check('privacy erase removes them', $erase['items_removed'] === true && SpamKiller\count_items('') === 1);

// Daily digest.
$sent = array();
add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) { $sent[] = $atts; return true; }, 9, 2);
$wpdb->query('DELETE FROM ' . table('submissions'));
check('no digest on a quiet day', SpamKiller\send_digest() === false && !$sent);
SpamKiller\log_submission($person('bot@spam.example', 'Buy backlinks'), array(array('module' => 'rules', 'reason' => 'Matched rule: SEO')));
check('digest lists held submissions', SpamKiller\send_digest() === true && strpos($sent[0]['body'] ?? $sent[0]['message'], 'Matched rule: SEO') !== false && strpos($sent[0]['subject'], '1 form submission') !== false);
update_option('spam_killer_settings', array('digest' => false));
check('digest can be turned off', SpamKiller\send_digest() === false);
update_option('spam_killer_settings', array());
check('digest is scheduled', (bool) wp_next_scheduled('spam_killer_digest'));

// Visitor response modes.
$spam_fields = array('email' => array('id' => 'email', 'type' => 'email', 'title' => 'Email', 'value' => 'y@mailinator.com', 'raw_value' => 'y@mailinator.com'));
update_option('spam_killer_settings', SpamKiller\sanitize_settings(array('response' => 'review', 'review_message' => 'נבדק, נחזור אליך', 'modules' => array('disposable_email' => 1))));
list($died, $out) = $run($spam_fields);
$json = json_decode($out, true);
check('elementor review mode shows review message', $died && $json['success'] === true && $json['data']['message'] === 'נבדק, נחזור אליך');
update_option('spam_killer_settings', SpamKiller\sanitize_settings(array('response' => 'blocked', 'modules' => array('disposable_email' => 1))));
list($died, $out) = $run($spam_fields);
$json = json_decode($out, true);
check('elementor blocked mode returns error', $died && $json['success'] === false && $json['data']['message'] === 'Your submission could not be sent.');
check('unknown response falls back to success', SpamKiller\sanitize_settings(array('response' => 'x'))['response'] === 'success');
check('empty review message falls back to default', SpamKiller\sanitize_settings(array('review_message' => ' '))['review_message'] === SpamKiller\defaults()['review_message']);
update_option('spam_killer_settings', array());

// JavaScript check.
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);
check('JS field is added to forms', strpos(apply_filters('wpcf7_form_elements', ''), 'name="sk_js" value=""') !== false);
$mods = function ($s) { return array_column(inspect($s), 'module'); };
$_POST['sk_js'] = '';
check('JS field left empty is caught', $mods(make_submission('cf7', 'js1', 'J', $clean['fields'])) === array('js_check'));
$_POST['sk_js'] = SpamKiller\js_token();
check('JS field filled by the script passes', $mods(make_submission('cf7', 'js2', 'J', $clean['fields'])) === array());
unset($_POST['sk_js']);
check('no JS field (cached page) passes', $mods(make_submission('cf7', 'js3', 'J', $clean['fields'])) === array());
ob_start(); SpamKiller\js_script(); $script = ob_get_clean();
check('page script carries the token', strpos($script, SpamKiller\js_token()) !== false);

// Bot user agents.
$ua = $_SERVER['HTTP_USER_AGENT'];
foreach (array('' => true, 'curl/8.4.0' => true, 'python-requests/2.31' => true, 'Mozilla/5.0 HeadlessChrome/120' => true, 'Mozilla/5.0 (iPhone) Safari/604.1' => false) as $agent => $bot) {
    $_SERVER['HTTP_USER_AGENT'] = $agent;
    check(sprintf('user agent "%s" %s', $agent, $bot ? 'is caught' : 'passes'), $mods(make_submission('cf7', 'ua' . wp_rand(), 'U', $clean['fields'])) === ($bot ? array('user_agent') : array()));
}
$_SERVER['HTTP_USER_AGENT'] = $ua;

// Duplicate messages.
$GLOBALS['sk_dupes'] = true;
$wpdb->query('DELETE FROM ' . table('submissions'));
$pitch = function ($text) { return make_submission('cf7', 'dup', 'D', array(array('id' => 'your-message', 'label' => '', 'type' => 'textarea', 'value' => $text))); };
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
screen($pitch('We can grow your Instagram by 10,000 real followers this month, reply YES.'));
check('same IP repeating itself is not a duplicate', $mods($pitch('We can grow your Instagram by 10,000 real followers this month, reply YES.')) === array());
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
check('near-copy from another IP is caught', $mods($pitch('We can grow your Instagram by 20,000 real followers this week, reply YES!')) === array('duplicates'));
check('different text passes', $mods($pitch('Hi, do you have a table for four on Friday evening at 8?')) === array());
screen($pitch('Hi'));
$_SERVER['REMOTE_ADDR'] = '192.0.2.3';
check('short texts are never duplicates', $mods($pitch('Hi')) === array());
update_option('spam_killer_settings', array('duplicate_count' => 10));
for ($i = 0; $i < 10; $i++) { $_SERVER['REMOTE_ADDR'] = '192.0.2.' . (10 + $i); screen($pitch('Filler message number ' . $i . ' about something else entirely.')); }
$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
check('only the last N submissions are compared', $mods($pitch('We can grow your Instagram by 10,000 real followers this month, reply YES.')) === array());
update_option('spam_killer_settings', array());
$wpdb->query('DELETE FROM ' . table('submissions'));

echo $GLOBALS['sk_failures'] ? "\n{$GLOBALS['sk_failures']} FAILED\n" : "\nAll passed\n";
