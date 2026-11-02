# Spam Killer

WordPress plugin that quarantines suspicious Contact Form 7 and Elementor Pro form submissions before they send email or run form actions.

- `spam-killer/` — the free plugin (in development)
- `legacy/` — Elementor spam filter v2, restored from the 2026-09-27 Codex session (site-specific predecessor)
- `tests/` — engine tests and a test-only mail-capture mu-plugin

## How it works

Every submission is normalized (fields, form, IP, page), run through the enabled modules and logged (Spam Killer → Log). Any match → the submission is held in quarantine and no email is sent. What the visitor sees is set in Settings → Visitor sees:

- **Normal success** (default) — bots can't tell they were caught.
- **Held for review** — editable message, shown as success.
- **Blocked** — the form plugin's own error message (CF7 "spam" message; Elementor form error message).

| Module | Default |
|---|---|
| Honeypot — hidden `sk_hp` field injected into CF7/Elementor forms; missing field (cached page) is not spam | on |
| Time trap — signed render-time token `sk_ts`; held if sent faster than N seconds (default 3) | on |
| Rate limit — N submissions per form per IP per window (default 5 / 10 min); IP from REMOTE_ADDR, Cloudflare or X-Forwarded-For (setting) | on |
| Disposable email — [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) (CC0, bundled) + editable also-block / never-block lists | on |
| WordPress disallowed words — Settings → Discussion → Disallowed Comment Keys, matched against every field and the IP | on |
| Language filter — hold text that is ≥50% letters of a chosen writing system (Cyrillic, Han, Arabic…) | off |
| Keyword & domain rules — field → condition → value, ALL/ANY, per-form scope; 3 default banned-word rules | on |
| AI filtering | paid add-on (planned) |

Every module can be overridden per form (Spam Killer → Forms → change). Module errors are listed on the Settings page.

Add-ons register modules through the `spam_killer_modules` filter. Modules marked `remote` (AI) run only when local modules found nothing. A module error never counts as spam.

**Forms** (Spam Killer → Forms): every CF7 and Elementor Pro form, where it is placed (pages, templates; CF7 by ID or hash, shortcode or block), fields, edit/view links, submission counts, per-form protection. Elementor scanning is ported from Elementor Form Finder.

**Log** (Spam Killer → Log): every submission with outcome and notification email status (sent / failed with error / none). Filter by form, search, CSV export (UTF-8 BOM, formula-safe). "Email it again" for passed submissions; "Create rule from this" prefills sender email and link domains.

**Extras**: WordPress privacy export/erase (Tools → Export/Erase Personal Data, matched by email), daily digest email of held submissions (~08:00, only when something was held; can be turned off), dashboard widget (7-day counts, failed emails, recently held), "Add default rules" to restore deleted defaults, Settings/Log links on the Plugins screen.

**Quarantine inbox** (Spam Killer → Quarantine): view values and reasons, "Not spam" (marks legitimate and emails the submission to the site admin with Reply-To set), delete, bulk actions. Log and quarantine entries are deleted after N days (Settings → Keep submissions for, default 30).

## Integration points

- **CF7**: `wpcf7_before_send_mail` (priority 1). Sets `$abort` and reports `mail_sent` (or `spam` when blocked), so `wpcf7_mail_sent` integrations don't fire. Other plugins hooked on `wpcf7_before_send_mail` still run.
- **Elementor Pro**: `elementor_pro/forms/validation` (priority 100). Ends the AJAX request with Elementor-style success/error JSON so no form actions run. Skipped when Elementor already has validation errors.

## Known limits

- Proxy IP headers are trusted only when chosen in Settings (spoofable otherwise). Settings warns when Cloudflare/proxy headers are seen.
- "Not spam" emails the admin; it does not replay the original form actions (CRM, webhooks, autoresponders).
- Elementor adapter tested with stand-in objects only, not against real Elementor Pro.

## Test

```sh
wp eval-file tests/engine-test.php   # in a WP install with the plugin active
```
