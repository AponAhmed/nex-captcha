<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Inline Lucide icon markup (https://lucide.dev, ISC licensed), matching
 * the seo-article plugin's own confirmed convention: viewBox 0 0 24 24,
 * stroke=currentColor (no hardcoded fill/stroke colors, so icons tint
 * with whatever text color they inherit), stroke-linecap/linejoin round.
 * Raw inline markup instead of an icon font (same reasoning as that
 * reference: no new asset/network dependency, one less thing to enqueue).
 *
 * Path data is copied verbatim from the lucide-static package so shapes
 * stay pixel-accurate to the real icon set rather than hand-approximated.
 */
class Icons {

	/**
	 * @return array<string, string>
	 */
	private static function paths(): array {
		return array(
			'shield'       => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />',
			'shield-off'   => '<path d="m2 2 20 20" /><path d="M5 5a1 1 0 0 0-1 1v7c0 5 3.5 7.5 7.67 8.94a1 1 0 0 0 .67.01c2.35-.82 4.48-1.97 5.9-3.71" /><path d="M9.309 3.652A12.252 12.252 0 0 0 11.24 2.28a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1v7a9.784 9.784 0 0 1-.08 1.264" />',
			'settings'     => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" /><circle cx="12" cy="12" r="3" />',
			'list-checks'  => '<path d="M13 5h8" /><path d="M13 12h8" /><path d="M13 19h8" /><path d="m3 17 2 2 4-4" /><path d="m3 7 2 2 4-4" />',
			'lock'         => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />',
			'save'         => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" /><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7" /><path d="M7 3v4a1 1 0 0 0 1 1h7" />',
			'list'         => '<path d="M3 5h.01" /><path d="M3 12h.01" /><path d="M3 19h.01" /><path d="M8 5h13" /><path d="M8 12h13" /><path d="M8 19h13" />',
			'download'     => '<path d="M12 15V3" /><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m7 10 5 5 5-5" />',
			'refresh-cw'   => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" /><path d="M8 16H3v5" />',
			'x'            => '<path d="M18 6 6 18" /><path d="m6 6 12 12" />',
			'check'        => '<path d="M20 6 9 17l-5-5" />',
			'check-circle' => '<path d="M21.801 10A10 10 0 1 1 17 3.335" /><path d="m9 11 3 3L22 4" />',
			'x-circle'     => '<circle cx="12" cy="12" r="10" /><path d="m15 9-6 6" /><path d="m9 9 6 6" />',
			'clock'        => '<circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" />',
		);
	}

	/**
	 * @param string $name Icon key, see self::paths().
	 * @param int $size Pixel width/height (icons are square).
	 * @param float $stroke_width Matches seo-article's own per-icon convention: 1.25 for most UI icons, heavier (2+) for small/emphasis icons like save or toast check/x.
	 */
	public static function svg( string $name, int $size = 20, float $stroke_width = 1.25 ): string {
		$inner = self::paths()[ $name ] ?? '';

		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<svg class="nxc-icon lucide lucide-%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%3$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%4$s</svg>',
			esc_attr( $name ),
			$size,
			esc_attr( (string) $stroke_width ),
			$inner
		);
	}
}
