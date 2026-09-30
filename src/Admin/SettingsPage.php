<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Settings -> NexCaptcha. Only General, Forms and Security are
 * shown -- Appearance/Logs/Tools are added once Phase 3/5 give them a
 * real backing implementation (see docs/PLAN.md section 2.4 and this
 * change's own plan notes on scope).
 */
class SettingsPage {

	private const SLUG   = 'nex-captcha';
	private const OPTION = 'nxc_settings';
	private const TABS   = array( 'general', 'forms', 'security' );

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
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

		$tab      = self::current_tab();
		$settings = Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NexCaptcha', 'nex-captcha' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $tab_id ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $tab_id ) ); ?>" class="nav-tab<?php echo $tab_id === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( self::tab_label( $tab_id ) ); ?>
					</a>
				<?php endforeach; ?>
			</h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SLUG );
				self::render_tab( $tab, $settings );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, no state change; the form POST itself is nonce-protected by settings_fields().
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		return in_array( $tab, self::TABS, true ) ? $tab : 'general';
	}

	private static function tab_url( string $tab_id ): string {
		return (string) add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab_id,
			),
			admin_url( 'options-general.php' )
		);
	}

	private static function tab_label( string $tab_id ): string {
		$labels = array(
			'general'  => __( 'General', 'nex-captcha' ),
			'forms'    => __( 'Forms', 'nex-captcha' ),
			'security' => __( 'Security', 'nex-captcha' ),
		);

		return $labels[ $tab_id ] ?? $tab_id;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_tab( string $tab, array $settings ): void {
		if ( 'forms' === $tab ) {
			self::render_forms_tab( $settings );
		} elseif ( 'security' === $tab ) {
			self::render_security_tab( $settings );
		} else {
			self::render_general_tab( $settings );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_general_tab( array $settings ): void {
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
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_forms_tab( array $settings ): void {
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
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[forms][<?php echo esc_attr( $form_id ); ?>]" value="1" <?php checked( ! empty( $settings['forms'][ $form_id ] ) ); ?> />
							<?php esc_html_e( 'Protect this form', 'nex-captcha' ); ?>
						</label>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function render_security_tab( array $settings ): void {
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
						<label style="display:block;">
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[bypass_roles][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $settings['bypass_roles'], true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role_name ) ); ?>
						</label>
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
}
