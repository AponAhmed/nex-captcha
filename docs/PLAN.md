# NexCaptcha — WordPress CAPTCHA Plugin: Documentation & Development Plan

Sep 30, 2026 · @Apon

## 1. Overview

NexCaptcha is a self-hosted, privacy-first CAPTCHA plugin for WordPress. It stops bots on core and third-party forms with no calls to Google, Cloudflare or any other outside service. By default it is invisible: the visitor's browser solves a small proof-of-work puzzle in the background, and an interactive challenge appears only when a request looks suspicious.

NexCaptcha is a Bitflw product, alongside ApiTab, SendPortal, BizOn and HelpDesk. The name NexCaptcha is confirmed; no existing WordPress.org plugin uses it.

| Field | Value |
| --- | --- |
| Publisher (plugin `Author`) | Bitflw |
| Author URI | https://bitflw.com |
| Plugin URI (product page) | https://nexcaptcha.bitflw.com |
| WordPress.org account | `bitflw` (register it before submission) |
| GitHub repo | `bitflw/nex-captcha` |
| Slug and text domain | `nex-captcha` |
| PHP namespace | `Bitflw\NexCaptcha\` |
| Prefix (functions, options, hooks, CSS) | `nxc_` / `.nxc-` |

### Assumptions (change these first if they're wrong)

- Fully self-hosted. Challenges are generated and verified on the site's own server, with no external API and no cookies.
- Default mode is invisible proof-of-work (PoW), similar in spirit to ALTCHA or Friendly Captcha. Fallbacks are a slider puzzle and a math/text challenge.
- Free core plugin on WordPress.org. A Pro add-on is optional and comes later.
- Minimum requirements: WordPress 6.4+ and PHP 8.0+.
- Localization ships with English, Japanese and Bangla.

### Goals

1. Block automated spam and credential stuffing on login, registration, lost password, comments and the most-used form plugins.
2. Be invisible to real people in over 95% of submissions, with zero added clicks.
3. Stay light: frontend JS under 15 KB gzipped, no jQuery, and scripts loaded only on pages that render a protected form.
4. Meet WCAG 2.2 AA: keyboard operable, screen-reader labelled, and an accessible alternative for every interactive challenge.
5. Be GDPR-friendly by design: no cookies, no fingerprinting, and IPs stored only hashed and only when rate limiting is on.
6. Pass WordPress.org plugin review and Plugin Check with no errors.

### Non-goals (v1)

- No hosted SaaS verification service in v1. A Bitflw-hosted tier can come later, matching the SaaS plus self-hosted model of the other Bitflw products.
- No machine-learning bot scoring. Signals are rule-based.
- No image-recognition challenges ("click the buses"), which are poor for accessibility and easy for modern bots to solve.
- No multisite network-level settings. It works per site in v1.

## 2. Feature specification

v1 ships three challenge modes, five passive signals, integrations with core WordPress and five form plugins, one settings screen and a basic log.

### 2.1 Challenge modes

| Mode | What the visitor sees | How it works | Default |
| --- | --- | --- | --- |
| Invisible PoW | Nothing, or a small "Verified" badge | Browser finds a nonce so that SHA-256(salt + nonce) meets the difficulty target, running in a Web Worker | Yes |
| Checkbox PoW | "I'm not a robot" checkbox that ticks after solving | Same PoW, started on click, which is useful when the site owner wants a visible cue | No |
| Slider puzzle | Drag a piece into its gap on a server-rendered image | Server stores the gap X position. The client sends the final position plus the drag trace (timing and jitter), and the server checks both within tolerance | Escalation |
| Math / text | "What is 7 + 5?" (image or text) | Answer is hashed into the signed token | Escalation, and accessible fallback |

Escalation rule: if passive signals score a request as suspicious, the form gets the slider instead of invisible PoW. The slider always offers an "Accessible alternative" link that switches to math/text.

Adaptive difficulty: PoW difficulty starts low (target about 0.3 to 0.8 s on a mid-range phone) and rises per IP-hash as failures or request volume rise.

### 2.2 Passive signals

- Honeypot field: a hidden input that bots fill in.
- Time trap: the form was submitted faster than a configurable minimum (default 2 s) after render.
- Rate limit: attempts per IP-hash per form per window, stored in transients or the object cache.
- Replay check: each challenge ID can be redeemed only once.
- Optional JS interaction check: at least one real input, focus or pointer event before submit.

### 2.3 Integrations

| Integration | Hook points | Phase |
| --- | --- | --- |
| WP Login | `login_form`, `authenticate` (priority 30) | 1 |
| WP Registration | `register_form`, `registration_errors` | 1 |
| Lost password | `lostpassword_form`, `lostpassword_post` | 1 |
| Comments | `comment_form_after_fields` / `comment_form_logged_in_after`, `preprocess_comment` | 1 |
| WooCommerce | login, register, lost password, checkout (classic and Checkout block via Store API) | 3 |
| Contact Form 7 | form tag `[nexcaptcha]`, `wpcf7_validate` | 3 |
| WPForms | field or auto-append, `wpforms_process` | 3 |
| Elementor Pro Forms | custom field type, `elementor_pro/forms/validation` | 4 |
| Fluent Forms | `fluentform/validation_errors` | 4 |
| Gravity Forms | custom field, `gform_validation` | 4 |
| Generic | shortcode `[nexcaptcha]`, Gutenberg block, PHP helper `nxc_verify()` | 2 |

Each integration is a separate class that the site owner can turn on or off, and it loads only if the target plugin is active.

### 2.4 Admin

- Settings page under **Settings → NexCaptcha** with tabs: General, Forms, Appearance, Security, Logs, Tools.
- General: default mode, difficulty preset (Low, Balanced, High, Custom), escalation on or off.
- Forms: a toggle per integration and per form location.
- Appearance: light, dark or auto theme, badge position, custom label text, and a CSS variables override.
- Security: rate-limit thresholds, time-trap seconds, IP allowlist (CIDR), logged-in-user bypass by role, and trust of `X-Forwarded-For` behind a proxy.
- Tools: rotate HMAC secret, run a self-test, and export or import settings as JSON.
- Dashboard widget: blocked versus passed counts for the last 7 days.

### 2.5 Logs and analytics

- Custom table stores events (passed, failed, blocked, rate-limited) with form, mode, reason, IP-hash and timestamp.
- Retention is configurable (default 30 days) and pruned by a daily WP-Cron job.
- CSV export.
- Logging can be turned off completely for strict-privacy sites.

## 3. Architecture

The plugin is a small object-oriented PHP core with pluggable challenges, signals and integrations. It talks to one lightweight TypeScript widget through a single public REST endpoint. Verification is stateless apart from a short-lived replay list, so it works behind full-page caches and load balancers.

&#91;embedded content: verification flow · 4 browser steps, 1 server decision\]

The challenge is fetched by REST after the page loads, never embedded in the HTML, so cached pages never serve a stale or shared challenge.

### 3.1 Stack

| Layer | Choice | Why |
| --- | --- | --- |
| PHP | 8.0+, PSR-4 via Composer autoload, no runtime dependencies | Clean namespaces with nothing to prefix or bundle |
| Frontend widget | TypeScript, no framework, built with esbuild | Keeps the bundle under 15 KB gzipped |
| PoW solver | Web Worker inlined as a Blob URL, using `crypto.subtle.digest` | Keeps the main thread free and avoids a separate file |
| Admin UI | PHP Settings API with a little vanilla JS | Simple, reviewable, native look |
| Gutenberg block | `@wordpress/scripts`, `block.json` | Standard block tooling |
| Local environment | `@wordpress/env` (Docker) | Same environment for you and Claude Code |
| Tests | PHPUnit + Brain Monkey (unit), WP test suite (integration), Playwright (e2e) | Standard WordPress testing stack |
| Code standards | PHPCS with WordPress-Extra and PHPCompatibilityWP, ESLint, Prettier | Needed for WordPress.org review |

### 3.2 Directory structure

```
nex-captcha/
├── nex-captcha.php            # header, constants, bootstrap
├── uninstall.php              # drop table, delete options
├── readme.txt                 # WordPress.org readme
├── composer.json  package.json  .wp-env.json  phpcs.xml.dist
├── src/
│   ├── Plugin.php             # boots services, registers hooks
│   ├── Core/        Activator.php  Deactivator.php  Assets.php  Container.php
│   ├── Challenge/   ChallengeInterface.php  PowChallenge.php
│   │                SliderChallenge.php  MathChallenge.php  ChallengeFactory.php
│   ├── Verification/ Token.php  Verifier.php  ReplayGuard.php  Result.php
│   ├── Signals/     SignalInterface.php  Honeypot.php  TimeTrap.php
│   │                RateLimiter.php  InteractionCheck.php  RiskScorer.php
│   ├── Integrations/ IntegrationInterface.php  AbstractIntegration.php
│   │                WpLogin.php  WpRegister.php  WpLostPassword.php  WpComments.php
│   │                WooCommerce.php  ContactForm7.php  WPForms.php
│   │                Elementor.php  FluentForms.php  GravityForms.php
│   ├── Rest/        ChallengeController.php
│   ├── Admin/       SettingsPage.php  Settings.php  DashboardWidget.php  LogsTable.php
│   ├── Logging/     EventLogger.php  Schema.php
│   └── Support/     Crypto.php  Ip.php  Options.php  Template.php
├── assets/
│   ├── src/         widget.ts  worker.ts  slider.ts  math.ts  widget.css  admin.ts
│   └── build/       (generated)
├── blocks/nexcaptcha/ block.json  edit.tsx  render.php
├── templates/       widget.php  slider.php  math.php
├── languages/       nex-captcha.pot  (ja, bn)
└── tests/           php/unit  php/integration  e2e
```

### 3.3 Core flow in PHP

1. `Plugin::boot()` runs on `plugins_loaded`. It loads settings, registers the REST route and asks each enabled integration to `register()`.
2. An integration's render hook calls `Template::widget( $form_id )`. That prints a container `<div class="nxc-widget" data-form="login">`, a hidden `nxc_token` input and a honeypot, then enqueues the widget script.
3. The widget calls `GET /wp-json/nexcaptcha/v1/challenge?form=login`. `RiskScorer` decides the mode, and `ChallengeFactory` builds the challenge.
4. The integration's validation hook calls `Verifier::verify( $form_id, $_POST )`, which returns a `Result` (`ok`, `reason`, `mode`). It converts a failure into a `WP_Error` or a form-plugin error, and logs the event.

### 3.4 Security model

The PoW scheme follows the proven ALTCHA-style design. The server picks a secret number *n* and a random salt, publishes `challenge = SHA-256(salt + n)` with the maximum *n*, and signs it. The browser brute-forces *n*.

- **Challenge payload:** `{ algorithm, challenge, maxnumber, salt, signature }`. The salt embeds `expires=<unix>&form=<id>&v=1`, so expiry and form binding are covered by the signature.
- **Signature:** `HMAC-SHA256( challenge, secret )`. The secret is 32 random bytes generated on activation and stored in a non-autoloaded option. A `NXC_SECRET` constant in `wp-config.php` overrides it.
- **Verification:** recompute the hash and HMAC, and compare with `hash_equals()`. Check that expiry is in the future (default TTL 10 min) and that the form ID matches the form being submitted.
- **Replay:** after a successful verify, store `sha256(challenge)` in a transient (or object cache) until expiry. A second use fails with `replayed`.
- **Difficulty:** `maxnumber` from 20,000 (Low) to 250,000 (High). `RiskScorer` can multiply it per IP-hash.
- **Slider and math:** the answer is never sent to the client. The server stores it inside the signed, encrypted token (`sodium_crypto_secretbox`) or in a transient keyed by challenge ID.
- **IP handling:** `REMOTE_ADDR` by default. Proxy headers are used only when explicitly enabled with trusted proxy CIDRs. IPs are stored as `HMAC(ip, secret)`, never raw.
- **REST endpoint:** public and nonce-free, which it must be for cached pages. It is rate-limited per IP-hash and returns `Cache-Control: no-store`.
- **No-JS visitors:** the form shows the math challenge server-side inside a `<noscript>` block, so it still works.
- **Fail mode:** if the plugin breaks (secret missing, crypto unavailable), a setting chooses fail-closed (default for login) or fail-open (default for comments). Admins get a notice either way.

## 4. Data model, REST API and hooks

Storage is two options, one custom table and short-lived transients. The public surface is one REST route plus a set of prefixed actions and filters.

### 4.1 Options

| Option key | Autoload | Contents |
| --- | --- | --- |
| `nxc_settings` | yes | One array holding all settings, validated by `Settings::sanitize()` and versioned with `schema_version` |
| `nxc_secret` | no | 32-byte HMAC secret, base64 encoded |
| `nxc_db_version` | yes | Logs table schema version, used for upgrades |

Default `nxc_settings` shape:

```json
{
  "schema_version": 1,
  "mode": "invisible",
  "difficulty": "balanced",
  "escalation": true,
  "forms": { "login": true, "register": true, "lostpassword": true, "comments": true },
  "theme": "auto",
  "time_trap_seconds": 2,
  "rate_limit": { "max": 10, "window": 600 },
  "allowlist": [],
  "bypass_roles": ["administrator"],
  "trusted_proxies": [],
  "fail_mode": { "login": "closed", "comments": "open" },
  "logging": { "enabled": true, "retention_days": 30 }
}
```

### 4.2 Logs table `{$wpdb->prefix}nxc_events`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED AUTO\_INCREMENT | Primary key |
| `created_at` | DATETIME (UTC) | Indexed |
| `form` | VARCHAR(64) | e.g. `login`, `cf7:123` |
| `mode` | VARCHAR(16) | `invisible`, `checkbox`, `slider`, `math` |
| `result` | VARCHAR(16) | `passed`, `failed`, `blocked`, `rate_limited` |
| `reason` | VARCHAR(32) | e.g. `bad_signature`, `expired`, `replayed`, `honeypot`, `too_fast` |
| `ip_hash` | CHAR(64) | HMAC of the IP, indexed |
| `solve_ms` | INT UNSIGNED NULL | PoW solve time reported by the client |

Created with `dbDelta()` on activation. It is pruned daily by the `nxc_prune_events` cron job and dropped in `uninstall.php`.

### 4.3 Transients and cache keys

- `nxc_used_{sha256}`: replay guard, TTL = challenge expiry.
- `nxc_rl_{form}_{iphash}`: rate-limit counter, TTL = window.
- `nxc_ans_{challenge_id}`: slider or math answer, only if the stateless encrypted mode is off.

Use `wp_cache_*` with a group when a persistent object cache exists; otherwise use transients.

### 4.4 REST API

`GET /wp-json/nexcaptcha/v1/challenge`

| Param | Required | Notes |
| --- | --- | --- |
| `form` | yes | Form ID, sanitized to `[a-z0-9:_-]` |
| `mode` | no | Requested mode. The server may upgrade it, never downgrade it |

Response `200` for PoW:

```json
{
  "mode": "invisible",
  "algorithm": "SHA-256",
  "challenge": "4f1c…",
  "maxnumber": 50000,
  "salt": "9a2b…?expires=1759212000&form=login&v=1",
  "signature": "e3d0…"
}
```

The slider response also includes `image` (data URI), `piece` (data URI), `pieceY` and `token`. Errors are `429 rate_limited` and `400 invalid_form`. Headers always include `Cache-Control: no-store`.

**Submitted token** (hidden field `nxc_token`): base64 of `{ algorithm, challenge, number, salt, signature, took }` for PoW, or `{ token, answer, trace }` for slider and math.

### 4.5 Public PHP API

- `nxc_render( string $form_id, array $args = [] ): string` returns the widget HTML.
- `nxc_verify( string $form_id, ?array $data = null ): Bitflw\NexCaptcha\Verification\Result`
- `nxc_is_enabled( string $form_id ): bool`

### 4.6 Actions and filters

| Hook | Type | Purpose |
| --- | --- | --- |
| `nxc_loaded` | action | Fires after boot, so add-ons can register integrations |
| `nxc_register_integrations` | filter | Add or remove integration classes |
| `nxc_challenge_mode` | filter | Override the chosen mode per request (`$mode, $form_id, $risk`) |
| `nxc_pow_maxnumber` | filter | Adjust difficulty |
| `nxc_challenge_ttl` | filter | Change expiry seconds |
| `nxc_risk_score` | filter | Change the computed risk (0 to 100) |
| `nxc_should_protect` | filter | Skip protection for a request (`$bool, $form_id, $user`) |
| `nxc_verify_result` | filter | Inspect or alter the final `Result` |
| `nxc_verified` | action | After a successful verify |
| `nxc_failed` | action | After a failed verify, useful for fail2ban-style add-ons |
| `nxc_widget_html` | filter | Change the widget markup |
| `nxc_client_ip` | filter | Custom IP detection |
| `nxc_error_message` | filter | Change the message shown to users per reason |

## 5. Development plan

Build in six phases. Each ends with a working, tested plugin and a git tag, and the first shippable version (v0.1) comes at the end of Phase 2. Phases are ordered by dependency; add calendar dates once you know your weekly hours.

### Phase 0 — Scaffolding

- [ ] Create the repo `nex-captcha`, `.gitignore`, GPL-2.0-or-later license (the WordPress.org standard)
- [ ] Main plugin file with header (Author: Bitflw, Author URI: https://bitflw.com, Plugin URI: https://nexcaptcha.bitflw.com), `NXC_VERSION`, `NXC_FILE` and `NXC_PATH` constants, and a PHP/WP version check with an admin notice
- [ ] `composer.json` with PSR-4 `Bitflw\\NexCaptcha\\` → `src/`, and dev deps: phpunit, brain/monkey, wp-coding-standards/wpcs, phpcompatibility-wp
- [ ] `package.json` with esbuild, `@wordpress/scripts`, `@wordpress/env`, `@playwright/test` and TypeScript
- [ ] `.wp-env.json` (WordPress latest, PHP 8.2, plugins: WooCommerce, Contact Form 7, WPForms Lite)
- [ ] `phpcs.xml.dist`, ESLint and Prettier configs, and scripts `composer lint`, `composer test`, `npm run build`, `npm run e2e`
- [ ] GitHub Actions CI: PHPCS, PHPUnit on PHP 8.0 and 8.3, build, Plugin Check
- [ ] `CLAUDE.md` (see section 7)

**Done when:** `npx wp-env start` boots, the plugin activates with no notices, and CI is green on an empty test.

### Phase 1 — Core engine (PoW)

- [ ] `Support\Crypto`: secret load and generate, `hmac()`, `random_hex()`, `equals()`
- [ ] `Challenge\PowChallenge::create( $form, $maxnumber, $ttl )` and `::verify( $payload, $form )`
- [ ] `Verification\ReplayGuard`, `Token` (decode and validate shape), `Result`, `Verifier`
- [ ] `Rest\ChallengeController`, with `no-store` headers and a stub rate limit
- [ ] `assets/src/worker.ts`: a SHA-256 loop over `crypto.subtle`, batched to report progress, with abort support
- [ ] `assets/src/widget.ts`: finds `.nxc-widget` elements, lazy-fetches a challenge on first focus or on view, solves, fills `nxc_token`, and refreshes before expiry
- [ ] `Template::widget()` markup with ARIA live region and status text
- [ ] Unit tests: a valid token passes, and a tampered salt, expired token, wrong form, replayed token or bad number each fails with the right reason

**Done when:** a PHP test can create a challenge, solve it in PHP with a brute-force helper and verify it, and a browser test page gets a valid token in under 1 s at Balanced difficulty.

### Phase 2 — Core WordPress forms and admin (v0.1)

- [ ] `Integrations\AbstractIntegration` with `is_available()`, `register()`, `render()` and `validate()`
- [ ] WpLogin, WpRegister, WpLostPassword and WpComments
- [ ] Role bypass for logged-in users, and IP allowlist
- [ ] `Admin\Settings` (defaults, sanitize, schema migration) and `SettingsPage` with General, Forms and Appearance tabs
- [ ] Shortcode `[nexcaptcha form="custom-id"]` and `nxc_verify()` helper
- [ ] Activation (secret, defaults), deactivation (clear cron) and `uninstall.php`
- [ ] Playwright e2e: login with a valid token succeeds, login with the token removed fails, and a comment posts

**Done when:** a fresh site with only the plugin blocks a scripted `curl` login POST and lets a real browser log in without a click. Tag `v0.1.0`.

### Phase 3 — Signals, logging and interactive challenges

- [ ] Signals: Honeypot, TimeTrap, RateLimiter (object cache aware), InteractionCheck
- [ ] `RiskScorer`: combine signals into 0 to 100, and choose mode and difficulty
- [ ] `Logging\Schema` (dbDelta), `EventLogger`, prune cron, `LogsTable` (a `WP_List_Table` with filters) and CSV export
- [ ] `SliderChallenge`: server generates the background and piece with GD (random shapes and noise, no stock images), stores the gap X, and validates position ± tolerance plus a human-like drag trace
- [ ] `MathChallenge`: text and GD image variants, plus the `<noscript>` fallback
- [ ] Escalation flow and the "Accessible alternative" switch
- [ ] Security tab settings

**Done when:** repeated failed logins from one IP escalate to the slider and then rate-limit, every event appears in Logs, and the slider can be completed with keyboard arrow keys.

### Phase 4 — Third-party integrations

- [ ] WooCommerce: my-account login, register, lost password, classic checkout (`woocommerce_after_checkout_validation`) and Checkout block (Store API extension data plus `woocommerce_store_api_checkout_update_order_from_request`)
- [ ] Contact Form 7: `[nexcaptcha]` form tag and a tag generator in the editor
- [ ] WPForms: a field type, or an auto-append option
- [ ] Elementor Pro Forms, Fluent Forms and Gravity Forms
- [ ] Gutenberg block `nexcaptcha/widget` for custom forms
- [ ] e2e for each integration that runs free in wp-env; manual checklist for paid ones

**Done when:** each integration renders only when its plugin is active, blocks a token-less submission with that plugin's native error style, and passes a real submission.

### Phase 5 — Polish and v1.0 release

- [ ] Dark and light themes, CSS variables, RTL check
- [ ] Dashboard widget (7-day blocked vs passed) and Tools tab (rotate secret, self-test, export or import)
- [ ] i18n: all strings wrapped, `.pot` generated, Japanese and Bangla translations, and JS translations via `wp_set_script_translations`
- [ ] Accessibility audit with axe plus manual NVDA and VoiceOver passes
- [ ] Performance budget check (section 6) and a security review (section 6)
- [ ] `readme.txt`, screenshots, banner and icon in Bitflw branding (readme \`Contributors: bitflw\`), a product page at nexcaptcha.bitflw.com, FAQ, and privacy policy text via `wp_add_privacy_policy_content`
- [ ] Submit to WordPress.org and tag `v1.0.0`

**Done when:** Plugin Check shows no errors, all tests pass, and the plugin is submitted for review.

## 6. Testing, QA and release

Every phase merges only with green CI: lint, unit, integration and e2e. v1.0 must also pass the performance budget, a security checklist and WordPress.org Plugin Check.

### 6.1 Test layers

| Layer | Tool | Covers | Runs |
| --- | --- | --- | --- |
| Unit | PHPUnit + Brain Monkey | Crypto, challenges, verifier, signals, settings sanitize | Every push |
| Integration | WP test suite in wp-env | Hooks fire, REST route, DB schema, integrations register | Every push |
| JS unit | Vitest | Worker solving, token encoding, widget state machine | Every push |
| E2E | Playwright + `@wordpress/e2e-test-utils-playwright` | Real form submissions per integration, slider by keyboard | PRs to `main` |
| Static | PHPCS (WordPress-Extra, PHPCompatibilityWP 8.0+), PHPStan level 6, ESLint | Standards, escaping, nonces, compatibility | Every push |
| Plugin Check | `wp plugin check nex-captcha` | WordPress.org review rules | Before each tag |

### 6.2 Bot simulation checklist

- [ ] `curl` POST to `wp-login.php` with no token is rejected
- [ ] Replaying a captured valid token a second time is rejected (`replayed`)
- [ ] A token issued for `comments` is rejected on `login` (`wrong_form`)
- [ ] Editing one character of the salt or signature is rejected (`bad_signature`)
- [ ] A token used after expiry is rejected (`expired`)
- [ ] Headless Chrome with a filled honeypot is rejected
- [ ] 11 requests within 10 minutes from one IP are rate-limited
- [ ] A spoofed `X-Forwarded-For` is ignored unless a trusted proxy is set

### 6.3 Performance budget

| Metric | Budget |
| --- | --- |
| Widget JS (gzipped, incl. worker) | ≤ 15 KB |
| Widget CSS (gzipped) | ≤ 3 KB |
| Scripts on pages without a protected form | 0 |
| PoW solve, Balanced, mid-range Android | ≤ 1 s median |
| `/challenge` server time | ≤ 20 ms (PoW), ≤ 80 ms (slider with GD) |
| Verify cost added to a form submit | ≤ 5 ms |

### 6.4 Security review checklist

- [ ] Every output is escaped (`esc_html`, `esc_attr`, `wp_kses`)
- [ ] Every admin action checks capability `manage_options` and a nonce
- [ ] All SQL uses `$wpdb->prepare()`
- [ ] Comparisons use `hash_equals()`, and randomness uses `random_bytes()`
- [ ] No secret, answer or raw IP is ever sent to the browser or logged
- [ ] The REST route validates and sanitizes every argument

### 6.5 Release process

1. Bump the version in the plugin header, `readme.txt` (Stable tag) and `NXC_VERSION`, and update the changelog.
2. Run `npm run build`, `composer install --no-dev -o`, then `npm run plugin-zip` (excludes tests, src TS and dev configs through `.distignore`).
3. Install the zip on a clean wp-env and run a smoke test.
4. First release: submit the zip at WordPress.org "Add your plugin" and wait for review. Later releases: deploy to SVN with the `10up/action-wordpress-plugin-deploy` GitHub Action on tag push.
5. Tag `vX.Y.Z` in git and create a GitHub release.

## 7. Working with Claude Code

Put this doc in the repo as `docs/PLAN.md` (export it as Markdown), add the `CLAUDE.md` below, and run Claude Code one phase at a time. Use one session per phase and one commit per checked task.

### 7.1 `CLAUDE.md` for the repo root

```markdown
# NexCaptcha — project instructions

Self-hosted, privacy-first CAPTCHA plugin for WordPress, published by Bitflw (https://bitflw.com). Full spec: docs/PLAN.md.
Always read the relevant section of docs/PLAN.md before starting a task.

## Stack
- PHP 8.0+, WordPress 6.4+, PSR-4 namespace Bitflw\NexCaptcha\ in src/
- Plugin header: Author Bitflw, Author URI https://bitflw.com, Plugin URI https://nexcaptcha.bitflw.com
- Frontend: TypeScript in assets/src, built with esbuild to assets/build
- Local env: npx wp-env start (http://localhost:8888, admin/password)

## Commands
- composer lint      # PHPCS WordPress-Extra + PHPCompatibilityWP
- composer test      # PHPUnit unit + integration (inside wp-env)
- npm run build      # build widget + block
- npm run test:js    # Vitest
- npm run e2e        # Playwright

## Rules
- Prefix everything: functions nxc_, options/transients nxc_, hooks nxc_, CSS .nxc-
- Text domain: nex-captcha. Wrap every user-facing string.
- Escape all output, sanitize all input, nonce + capability on every admin action.
- Use hash_equals() for secret comparisons, random_bytes() for randomness.
- Never send secrets, answers or raw IPs to the browser or into logs.
- No external HTTP calls, no cookies, no jQuery on the frontend.
- Load frontend assets only when a protected form renders.
- Each integration is its own class extending AbstractIntegration.
- Write or update tests with every change. Run composer lint and composer test before saying a task is done.
- Do not add Composer or npm runtime dependencies without asking.

## Workflow
- Work only on the current phase's unchecked tasks in docs/PLAN.md.
- After finishing a task: run tests, tick it in docs/PLAN.md, commit with a conventional commit message.
```

### 7.2 Session prompts by phase

| Phase | Prompt to start the session |
| --- | --- |
| 0 | "Read CLAUDE.md and docs/PLAN.md sections 3 and 5. Do Phase 0: scaffold the plugin, tooling, wp-env and CI. Show me the plan first." |
| 1 | "Do Phase 1 from docs/PLAN.md. Start with Support\\Crypto and PowChallenge with unit tests first (TDD), then the REST controller, then worker.ts and widget.ts. Follow the security model in section 3.4 exactly." |
| 2 | "Do Phase 2. Build AbstractIntegration, then WpLogin with an e2e test, and stop for my review before the other three integrations." |
| 3 | "Do Phase 3. Signals and RiskScorer first, then logging, then SliderChallenge. The slider must be keyboard operable and have a math fallback." |
| 4 | "Do Phase 4, one integration per commit. For each: check the plugin's current hook docs, implement, add e2e if it runs in wp-env." |
| 5 | "Do Phase 5. Run Plugin Check and the section 6 checklists and fix everything they report. Then write readme.txt." |

### 7.3 Tips for a smooth build

- Start each phase in plan mode (Shift+Tab) so you approve the approach before any code is written.
- Ask for tests first on security code (Phase 1). It keeps Claude Code honest about edge cases.
- Keep sessions focused. Use `/clear` between phases and let `CLAUDE.md` plus `docs/PLAN.md` carry the context.
- For third-party plugins, tell Claude Code to read the installed plugin's source in `wp-content/plugins/` rather than rely on memory, since form plugin hooks change between versions.
- Review every diff that touches `Crypto`, `Verifier`, `Token` or the REST controller yourself. That is where a subtle bug becomes a bypass.
