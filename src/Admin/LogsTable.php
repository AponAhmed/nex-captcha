<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Admin;

use Bitflw\NexCaptcha\Logging\Schema;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the Logs tab, plus the CSV export action.
 */
class LogsTable extends WP_List_Table {

	private const PER_PAGE = 20;
	private const RESULTS  = array( 'passed', 'failed', 'blocked', 'rate_limited' );

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'Time', 'nex-captcha' ),
			'form'       => __( 'Form', 'nex-captcha' ),
			'mode'       => __( 'Mode', 'nex-captcha' ),
			'result'     => __( 'Result', 'nex-captcha' ),
			'reason'     => __( 'Reason', 'nex-captcha' ),
			'ip_hash'    => __( 'IP hash', 'nex-captcha' ),
			'solve_ms'   => __( 'Solve time (ms)', 'nex-captcha' ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;

		$table   = Schema::table_name();
		$filters = self::current_filters();
		$page    = max( 1, $this->get_pagenum() );
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		list( $where_sql, $values ) = self::where_clause( $filters );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = (int) ( array() === $values
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only log view, table name isn't user input, nothing to cache.
			: $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$items_sql   = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $items_sql, ...array_merge( $values, array( self::PER_PAGE, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $column_name
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * @param string $which
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = self::current_filters();
		?>
		<div class="alignleft actions">
			<select name="nxc_form">
				<option value=""><?php esc_html_e( 'All forms', 'nex-captcha' ); ?></option>
				<?php foreach ( self::distinct_forms() as $form_id ) : ?>
					<option value="<?php echo esc_attr( $form_id ); ?>" <?php selected( $filters['form'], $form_id ); ?>>
						<?php echo esc_html( $form_id ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="nxc_result">
				<option value=""><?php esc_html_e( 'All results', 'nex-captcha' ); ?></option>
				<?php foreach ( self::RESULTS as $result ) : ?>
					<option value="<?php echo esc_attr( $result ); ?>" <?php selected( $filters['result'], $result ); ?>>
						<?php echo esc_html( $result ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'nex-captcha' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * @return string[]
	 */
	private static function distinct_forms(): array {
		global $wpdb;

		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name isn't user input.
		return $wpdb->get_col( "SELECT DISTINCT form FROM {$table} ORDER BY form" );
	}

	/**
	 * @param array{form: string, result: string} $filters
	 * @return array{0: string, 1: string[]}
	 */
	private static function where_clause( array $filters ): array {
		$where  = array();
		$values = array();

		if ( '' !== $filters['form'] ) {
			$where[]  = 'form = %s';
			$values[] = $filters['form'];
		}

		if ( '' !== $filters['result'] ) {
			$where[]  = 'result = %s';
			$values[] = $filters['result'];
		}

		return array( $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '', $values );
	}

	/**
	 * Extracted so it's independently unit-testable: WP_List_Table's own
	 * lifecycle (prepare_items(), the admin screen it renders inside)
	 * needs real WP machinery the unit suite doesn't have.
	 *
	 * @return array{form: string, result: string}
	 */
	public static function current_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, no state change.
		$form = isset( $_GET['nxc_form'] ) ? sanitize_key( wp_unslash( $_GET['nxc_form'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = isset( $_GET['nxc_result'] ) ? sanitize_key( wp_unslash( $_GET['nxc_result'] ) ) : '';

		return array(
			'form'   => $form,
			'result' => in_array( $result, self::RESULTS, true ) ? $result : '',
		);
	}

	/**
	 * Re-applies whatever filters are active on the current table view,
	 * so "export what I'm looking at" actually matches.
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nex-captcha' ) );
		}

		check_admin_referer( 'nxc_export_logs' );

		global $wpdb;

		$table                      = Schema::table_name();
		list( $where_sql, $values ) = self::where_clause( self::current_filters() );

		$sql  = "SELECT id, created_at, form, mode, result, reason, ip_hash, solve_ms FROM {$table} {$where_sql} ORDER BY id DESC";
		$rows = $wpdb->get_results( array() === $values ? $sql : $wpdb->prepare( $sql, ...$values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=nexcaptcha-events-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'form', 'mode', 'result', 'reason', 'ip_hash', 'solve_ms' ) );

		foreach ( (array) $rows as $row ) {
			fputcsv( $out, (array) $row );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a CSV to the browser via php://output, not a real file; WP_Filesystem has no equivalent for this.
		exit;
	}
}
