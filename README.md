# Spam Killer

WordPress plugin that quarantines suspicious Contact Form 7 and Elementor Pro form submissions before they send email or run form actions.

- `spam-killer/` — the free plugin (in development)
- `legacy/` — Elementor spam filter v2, restored from the 2026-09-27 Codex session (site-specific predecessor)
- `tests/` — engine tests and a test-only mail-capture mu-plugin

## How it works

Every submission is normalized (fields, form, IP, page) and run through the enabled modules. Any match → the submission is saved to quarantine and no email is sent. What the visitor sees is set in Settings → Visitor sees:

- **Normal success** (default) — bots can't tell they were caught.
- **Held for review** — editable message, shown as success.
- **Blocked** — the form plugin's own error message (CF7 "spam" message; Elementor form error message).

| Module | Free |
|---|---|
| Rate limit (N submissions per form per IP per window, default 5 / 10 min) | ✓ |
| Disposable email ([disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains), CC0, bundled) | ✓ |
| Keyword & domain rules (field → condition → value, ALL/ANY, per-form scope) | ✓ |
| AI filtering | paid add-on (planned) |

Add-ons register modules through the `spam_killer_modules` filter. Modules marked `remote` (AI) run only when local modules found nothing. A module error never counts as spam.

**Quarantine inbox** (Spam Killer → Quarantine): view values and reasons, "Not spam" (marks legitimate and emails the submission to the site admin with Reply-To set), delete, bulk actions, auto-delete after N days (default 30).

## Integration points

- **CF7**: `wpcf7_before_send_mail` (priority 1). Sets `$abort` and reports `mail_sent` (or `spam` when blocked), so `wpcf7_mail_sent` integrations don't fire. Other plugins hooked on `wpcf7_before_send_mail` still run.
- **Elementor Pro**: `elementor_pro/forms/validation` (priority 100). Ends the AJAX request with Elementor-style success/error JSON so no form actions run. Skipped when Elementor already has validation errors.

## Known limits

- IP is `REMOTE_ADDR` only. Behind Cloudflare/a proxy every visitor shares an IP, so rate limiting would hit everyone. Needs trusted-proxy support before release.
- No per-form module overrides yet; rules can be scoped to forms.
- "Not spam" emails the admin; it does not replay the original form actions (CRM, webhooks, autoresponders).
- Elementor adapter tested with stand-in objects only, not against real Elementor Pro.

## Test

```sh
wp eval-file tests/engine-test.php   # in a WP install with the plugin active
```
