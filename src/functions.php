<?php
/**
 * Public PHP API (docs/PLAN.md section 4.5), for themes and other
 * plugins that want to protect a custom form.
 *
 * @package Bitflw\NexCaptcha
 */

declare( strict_types=1 );

use Bitflw\NexCaptcha\Admin\Settings;
use Bitflw\NexCaptcha\Core\Assets;
use Bitflw\NexCaptcha\Support\Template;
use Bitflw\NexCaptcha\Verification\Result;
use Bitflw\NexCaptcha\Verification\Verifier;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the NexCaptcha widget for a custom form and enqueues the
 * assets it needs.
 *
 * @param string                $form_id Form identifier to protect.
 * @param array<string, mixed>  $args    Unused for now; reserved for
 *                                       per-call overrides in a later phase.
 */
function nxc_render( string $form_id, array $args = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- part of the documented public signature (docs/PLAN.md section 4.5), reserved for per-call overrides in a later phase.
	Assets::enqueue_widget();

	return Template::widget( $form_id );
}

/**
 * Verifies a submitted NexCaptcha token for a custom form.
 *
 * @param string                     $form_id Form identifier to verify.
 * @param array<string, mixed>|null  $data    Defaults to $_POST.
 */
function nxc_verify( string $form_id, ?array $data = null ): Result {
	return Verifier::verify( $form_id, $data ?? $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token check is the nonce equivalent here.
}

/**
 * Whether $form_id is enabled in NexCaptcha's settings.
 */
function nxc_is_enabled( string $form_id ): bool {
	return (bool) ( Settings::get( 'forms' )[ $form_id ] ?? true );
}
