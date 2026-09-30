=== NexCaptcha ===
Contributors: bitflw
Tags: captcha, anti-spam, security, login, privacy
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted, privacy-first CAPTCHA. Invisible proof-of-work by default, with slider and math fallbacks. No external calls, no cookies.

== Description ==

NexCaptcha is a self-hosted, privacy-first CAPTCHA plugin for WordPress. It stops bots on core and third-party forms with no calls to Google, Cloudflare or any other outside service. By default it is invisible: the visitor's browser solves a small proof-of-work puzzle in the background, and an interactive challenge appears only when a request looks suspicious.

= Features =

* Invisible proof-of-work challenge by default, with a checkbox variant, a slider puzzle and a math/text fallback
* Protects WordPress login, registration, lost password and comments out of the box
* Optional integrations for WooCommerce, Contact Form 7, WPForms, Elementor Pro Forms, Fluent Forms and Gravity Forms
* No external API calls, no cookies, no fingerprinting
* WCAG 2.2 AA accessible, with an "accessible alternative" on every interactive challenge
* Under 15 KB gzipped frontend JavaScript, loaded only on pages with a protected form

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Configure it under Settings -> NexCaptcha.

== Frequently Asked Questions ==

= Does this send any data to a third-party service? =

No. Challenges are generated and verified entirely on your own server.

= Does it use cookies? =

No. Verification is stateless, based on a short-lived signed token.

== Changelog ==

= 0.1.0 =
* Initial scaffolding.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
