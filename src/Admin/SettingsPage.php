<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Admin;

use Bitflw\NexCaptcha\Core\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Settings -> NexCaptcha: General, Forms and Security as stacked
 * panels on one page (no tab-switching -- every panel lives inside ONE
 * shared <form>, so every Save button submits the complete settings
 * state together; see Settings::sanitize()'s own docblock for why that
 * matters), plus a slide-in Logs drawer. Appearance/Tools are added
 * once Phase 5 gives them a real backing implementation (see
 * docs/PLAN.md section 2.4).
 */
class SettingsPage {

	private const SLUG   = 'nex-captcha';
	private const OPTION = 'nxc_settings';

	private const NONCE_ACTION = 'nxc_admin';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_ajax_nxc_save_settings', array( self::class, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_nxc_filter_logs', array( self::class, 'ajax_filter_logs' ) );
	}

	/**
	 * AJAX counterpart to the plain options.php submit -- same
	 * Settings::sanitize()/update_option() path, just returning JSON
	 * instead of a redirect so the settings screen never has to reload.
	 * The <form>'s own action="options.php" stays in the markup as a
	 * no-JS fallback; this is purely a progressive enhancement.
	 */
	public static function ajax_save_settings(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'nex-captcha' ) ), 403 );
		}

		$raw = isset( $_POST[ self::OPTION ] ) && is_array( $_POST[ self::OPTION ] )
			? wp_unslash( $_POST[ self::OPTION ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize() (called by update()) is the sanitizer.
			: array();

		Settings::update( $raw );

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'nex-captcha' ) ) );
	}

	/**
	 * Re-renders the Logs drawer's table for a given filter/page without
	 * reloading the screen. Reuses LogsTable::prepare_items() verbatim by
	 * feeding sanitized values through $_GET for this request only --
	 * the same keys current_filters()/prepare_items() already read when
	 * the table renders on a normal GET page load, so there's exactly
	 * one query implementation instead of a parallel one for AJAX.
	 */
	public static function ajax_filter_logs(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'nex-captcha' ) ), 403 );
		}

		$_GET['nxc_result'] = isset( $_POST['nxc_result'] ) ? sanitize_key( wp_unslash( $_POST['nxc_result'] ) ) : '';
		$_GET['nxc_form']   = isset( $_POST['nxc_form'] ) ? sanitize_key( wp_unslash( $_POST['nxc_form'] ) ) : '';
		$_GET['paged']      = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;

		$table = new LogsTable();
		$table->prepare_items();

		ob_start();
		$table->display();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function add_page(): void {
		add_options_page(
			__( 'NexCaptcha', 'nex-captcha' ),
			__( 'NexCaptcha', 'nex-captcha' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * add_options_page() always names its hook suffix this way -- only
	 * loading the admin stylesheet here, not unconditionally, keeps it
	 * from ever reaching the rest of wp-admin.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		Assets::enqueue_admin();
	}

	public static function register_setting(): void {
		register_setting(
			self::SLUG,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		?>
		<div class="wrap nxc-admin">
			<div class="nxc-card nxc-identity">
				<div class="nxc-card__body nxc-identity__top">
					<span class="nxc-icon-chip">
						<?php echo Icons::svg( 'shield', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns static, plugin-authored SVG markup from a fixed internal path map, not user input. ?>
					</span>
					<div class="nxc-identity__text">
						<h1>
							<?php esc_html_e( 'NexCaptcha', 'nex-captcha' ); ?>
							<span class="nxc-version-pill">v<?php echo esc_html( NXC_VERSION ); ?></span>
						</h1>
						<p class="nxc-admin__tagline"><?php esc_html_e( 'Self-hosted, privacy-first form protection.', 'nex-captcha' ); ?></p>
					</div>
					<button type="button" id="nxc-view-logs" class="button nxc-identity__logs-btn">
						<?php echo Icons::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						<?php esc_html_e( 'View Logs', 'nex-captcha' ); ?>
					</button>
				</div>
			</div>

			<form id="nxc-settings-form" method="post" action="options.php" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
				<?php settings_fields( self::SLUG ); ?>

				<div class="nxc-card">
					<div class="nxc-card__head">
						<?php echo Icons::svg( 'settings', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						<?php esc_html_e( 'General', 'nex-captcha' ); ?>
					</div>
					<div class="nxc-card__body">
						<?php self::render_general_panel( $settings ); ?>
					</div>
					<div class="nxc-card__footer">
						<?php self::render_save_button(); ?>
					</div>
				</div>

				<div class="nxc-card">
					<div class="nxc-card__head">
						<?php echo Icons::svg( 'list-checks', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						<?php esc_html_e( 'Forms', 'nex-captcha' ); ?>
					</div>
					<div class="nxc-card__body">
						<?php self::render_forms_panel( $settings ); ?>
					</div>
					<div class="nxc-card__footer">
						<?php self::render_save_button(); ?>
					</div>
				</div>

				<div class="nxc-card">
					<div class="nxc-card__head">
						<?php echo Icons::svg( 'lock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						<?php esc_html_e( 'Security', 'nex-captcha' ); ?>
					</div>
					<div class="nxc-card__body">
						<?php self::render_security_panel( $settings ); ?>
					</div>
					<div class="nxc-card__footer">
						<?php self::render_save_button(); ?>
					</div>
				</div>
			</form>

			<?php self::render_logs_drawer(); ?>
			<div id="nxc-toaster" class="nxc-toaster" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function render_save_button(): void {
		?>
		<button type="submit" class="button button-primary">
			<?php echo Icons::svg( 'save', 16, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
			<span class="nxc-btn-label"><?php esc_html_e( 'Save Changes', 'nex-captcha' ); ?></span>
		</button>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_general_panel( array $settings ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nxc-difficulty"><?php esc_html_e( 'Difficulty', 'nex-captcha' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[difficulty]" id="nxc-difficulty">
						<?php foreach ( array( 'low', 'balanced', 'high', 'custom' ) as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['difficulty'], $value ); ?>>
								<?php echo esc_html( ucfirst( $value ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'How long the visitor\'s browser spends solving the proof-of-work challenge. Low is fastest but weakest, High is slowest but strongest.', 'nex-captcha' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nxc-custom-maxnumber"><?php esc_html_e( 'Custom max number', 'nex-captcha' ); ?></label></th>
				<td>
					<input type="number" min="1" name="<?php echo esc_attr( self::OPTION ); ?>[custom_maxnumber]" id="nxc-custom-maxnumber" value="<?php echo esc_attr( (string) $settings['custom_maxnumber'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Only used when Difficulty is set to Custom.', 'nex-captcha' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Escalation', 'nex-captcha' ); ?></th>
				<td>
					<?php
					self::render_toggle(
						'nxc-escalation',
						'[escalation]',
						$settings['escalation'],
						__( 'Switch suspicious requests to the slider puzzle', 'nex-captcha' )
					);
					?>
					<p class="description"><?php esc_html_e( 'When a visitor\'s IP gets close to the rate limit, serve the slider puzzle instead of the invisible challenge.', 'nex-captcha' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_forms_panel( array $settings ): void {
		$labels = array(
			'login'        => __( 'Login', 'nex-captcha' ),
			'register'     => __( 'Registration', 'nex-captcha' ),
			'lostpassword' => __( 'Lost password', 'nex-captcha' ),
			'comments'     => __( 'Comments', 'nex-captcha' ),
		);
		?>
		<table class="form-table" role="presentation">
			<?php foreach ( $labels as $form_id => $label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td>
						<?php
						self::render_toggle(
							'nxc-form-' . $form_id,
							'[forms][' . $form_id . ']',
							! empty( $settings['forms'][ $form_id ] ),
							__( 'Protect this form', 'nex-captcha' )
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_security_panel( array $settings ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nxc-allowlist"><?php esc_html_e( 'IP allowlist', 'nex-captcha' ); ?></label></th>
				<td>
					<textarea name="<?php echo esc_attr( self::OPTION ); ?>[allowlist]" id="nxc-allowlist" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['allowlist'] ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One IP or CIDR range per line (e.g. 203.0.113.5 or 203.0.113.0/24). Matching visitors skip verification entirely.', 'nex-captcha' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bypass roles', 'nex-captcha' ); ?></th>
				<td>
					<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
						<?php $role_slug = (string) $role_slug; ?>
						<div style="margin-bottom: 8px;">
							<?php
							self::render_toggle(
								'nxc-role-' . $role_slug,
								'[bypass_roles][]',
								in_array( $role_slug, $settings['bypass_roles'], true ),
								translate_user_role( $role_name ),
								$role_slug
							);
							?>
						</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Logged-in users with any of these roles skip verification on comments.', 'nex-captcha' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rate limit', 'nex-captcha' ); ?></th>
				<td>
					<input type="number" min="1" name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit][max]" value="<?php echo esc_attr( (string) $settings['rate_limit']['max'] ); ?>" class="small-text" />
					<?php esc_html_e( 'challenge requests per', 'nex-captcha' ); ?>
					<input type="number" min="1" name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit][window]" value="<?php echo esc_attr( (string) $settings['rate_limit']['window'] ); ?>" class="small-text" />
					<?php esc_html_e( 'seconds, per IP.', 'nex-captcha' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nxc-time-trap"><?php esc_html_e( 'Minimum time to submit', 'nex-captcha' ); ?></label></th>
				<td>
					<input type="number" min="0" name="<?php echo esc_attr( self::OPTION ); ?>[time_trap_seconds]" id="nxc-time-trap" value="<?php echo esc_attr( (string) $settings['time_trap_seconds'] ); ?>" class="small-text" />
					<?php esc_html_e( 'seconds.', 'nex-captcha' ); ?>
					<p class="description"><?php esc_html_e( 'Submissions faster than this after the form renders are rejected as bots.', 'nex-captcha' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * A checkbox styled as a modern toggle switch (assets/src/admin.css's
	 * .nxc-toggle), reused across every boolean setting on this page.
	 * $name_suffix is appended to the option's array name (e.g.
	 * "[escalation]" or "[forms][login]"), matching how each call site
	 * already named its field before this helper existed.
	 */
	private static function render_toggle( string $id, string $name_suffix, bool $checked, string $label, ?string $value = null ): void {
		?>
		<label class="nxc-toggle" for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::OPTION . $name_suffix ); ?>"
				value="<?php echo esc_attr( $value ?? '1' ); ?>"
				<?php checked( $checked ); ?>
			/>
			<span class="nxc-toggle__track"></span>
			<span class="nxc-toggle__label"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * True when the page was loaded specifically to show the Logs
	 * drawer open -- set by the "View Logs" button's link, and by
	 * LogsTable's own pagination/filter links (which preserve whatever
	 * query args were already on the page, including this one), so
	 * paging/filtering the drawer's table is normal GET navigation that
	 * re-opens the drawer automatically on reload, no AJAX needed.
	 */
	private static function is_logs_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view flag, no state change.
		return isset( $_GET['view'] ) && 'logs' === sanitize_key( wp_unslash( $_GET['view'] ) );
	}

	/**
	 * The Logs drawer: a right-docked slide-in panel (not a tab), with
	 * its own header, a left icon-rail that filters the already-rendered
	 * rows client-side by result (assets/src/admin.ts), and the existing
	 * LogsTable for the actual data -- unchanged from before, just
	 * rendered inside the drawer's content area instead of a tab panel.
	 */
	private static function render_logs_drawer(): void {
		$table = new LogsTable();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg( self::logs_query_args(), admin_url( 'admin-post.php?action=nxc_export_logs' ) ),
			'nxc_export_logs'
		);

		$filters = array(
			''             => array( 'list', __( 'All', 'nex-captcha' ) ),
			'passed'       => array( 'check-circle', __( 'Passed', 'nex-captcha' ) ),
			'failed'       => array( 'x-circle', __( 'Failed', 'nex-captcha' ) ),
			'blocked'      => array( 'shield-off', __( 'Blocked', 'nex-captcha' ) ),
			'rate_limited' => array( 'clock', __( 'Rate limited', 'nex-captcha' ) ),
		);
		?>
		<div id="nxc-log-drawer-backdrop" class="nxc-drawer-backdrop"></div>
		<div id="nxc-log-drawer" class="nxc-drawer" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" data-default-open="<?php echo self::is_logs_requested() ? '1' : '0'; ?>">
			<div class="nxc-drawer__head">
				<span class="nxc-drawer__icon" aria-hidden="true">
					<?php echo Icons::svg( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
				</span>
				<h2><?php esc_html_e( 'Event Log', 'nex-captcha' ); ?></h2>
				<div class="nxc-drawer__head-actions">
					<a href="<?php echo esc_url( $export_url ); ?>" class="button nxc-drawer__export">
						<?php echo Icons::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						<?php esc_html_e( 'Export CSV', 'nex-captcha' ); ?>
					</a>
					<button type="button" class="nxc-drawer__refresh" aria-label="<?php esc_attr_e( 'Refresh', 'nex-captcha' ); ?>">
						<?php echo Icons::svg( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
					</button>
					<button type="button" class="nxc-drawer__close" aria-label="<?php esc_attr_e( 'Close', 'nex-captcha' ); ?>">
						<?php echo Icons::svg( 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
					</button>
				</div>
			</div>
			<div class="nxc-drawer__body">
				<div class="nxc-drawer__filters" role="toolbar" aria-label="<?php esc_attr_e( 'Filter log entries by result', 'nex-captcha' ); ?>">
					<?php foreach ( $filters as $result => $filter ) : ?>
						<button
							type="button"
							class="nxc-filter<?php echo '' === $result ? ' is-active' : ''; ?>"
							data-result="<?php echo esc_attr( $result ); ?>"
							title="<?php echo esc_attr( $filter[1] ); ?>"
						>
							<?php echo Icons::svg( $filter[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see Icons::svg() docblock. ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="nxc-drawer__content">
					<form method="get" data-ajax-filter="1">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
						<input type="hidden" name="view" value="logs" />
						<?php $table->display(); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The currently active Logs filters, as query args -- reused for
	 * both the CSV export link and (via LogsTable::current_filters(),
	 * reading the same $_GET keys) the table itself, so "export what
	 * I'm looking at" actually matches.
	 *
	 * @return array<string, string>
	 */
	private static function logs_query_args(): array {
		$filters = LogsTable::current_filters();
		$args    = array();

		if ( '' !== $filters['form'] ) {
			$args['nxc_form'] = $filters['form'];
		}

		if ( '' !== $filters['result'] ) {
			$args['nxc_result'] = $filters['result'];
		}

		return $args;
	}
}
