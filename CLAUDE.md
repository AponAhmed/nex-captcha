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
