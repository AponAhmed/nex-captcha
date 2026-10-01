<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Logging;

use Bitflw\NexCaptcha\Logging\EventLogger;
use Bitflw\NexCaptcha\Support\Crypto;
use Bitflw\NexCaptcha\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionProperty;

final class EventLoggerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $settings_override = array();

	protected function setUp(): void {
		parent::setUp();
		$this->settings_override = array();

		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				return 'nxc_secret' === $key ? base64_encode( str_repeat( 's', 32 ) ) : $this->settings_override;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		$this->reset_secret_cache();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$this->reset_secret_cache();
		parent::tearDown();
	}

	private function reset_secret_cache(): void {
		$property = new ReflectionProperty( Crypto::class, 'secret' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function test_log_verified_inserts_a_passed_row_with_solve_ms(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->withArgs(
				static function ( string $table, array $data, array $formats ): bool {
					return 'wp_nxc_events' === $table
						&& 'login' === $data['form']
						&& 'invisible' === $data['mode']
						&& 'passed' === $data['result']
						&& null === $data['reason']
						&& 1250 === $data['solve_ms']
						&& array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) === $formats;
				}
			);
		$GLOBALS['wpdb'] = $wpdb;

		EventLogger::log_verified( 'invisible', 'login', 1250 );

		$this->addToAssertionCount( 1 );
	}

	public function test_log_failed_inserts_a_failed_row(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->withArgs(
				static function ( string $table, array $data ): bool {
					return 'failed' === $data['result'] && 'honeypot' === $data['reason'] && null === $data['solve_ms'];
				}
			);
		$GLOBALS['wpdb'] = $wpdb;

		EventLogger::log_failed( 'honeypot', 'invisible', 'comments' );

		$this->addToAssertionCount( 1 );
	}

	public function test_log_rate_limited_inserts_a_rate_limited_row(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->withArgs(
				static function ( string $table, array $data ): bool {
					return 'rate_limited' === $data['result'] && 'login' === $data['form'];
				}
			);
		$GLOBALS['wpdb'] = $wpdb;

		EventLogger::log_rate_limited( 'login' );

		$this->addToAssertionCount( 1 );
	}

	public function test_logging_disabled_skips_the_insert_entirely(): void {
		$this->settings_override = array( 'logging' => array( 'enabled' => false ) );

		$wpdb = Mockery::mock();
		$wpdb->shouldNotReceive( 'insert' );
		$GLOBALS['wpdb'] = $wpdb;

		EventLogger::log_verified( 'invisible', 'login' );

		$this->addToAssertionCount( 1 );
	}

	public function test_prune_deletes_rows_older_than_the_configured_retention(): void {
		$this->settings_override = array( 'logging' => array( 'retention_days' => 7 ) );

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( string $query, string $cutoff ): string {
					return str_replace( '%s', "'{$cutoff}'", $query );
				}
			);
		$wpdb->shouldReceive( 'query' )
			->once()
			->withArgs(
				static function ( string $query ): bool {
					return str_contains( $query, 'wp_nxc_events' ) && str_contains( $query, 'DELETE FROM' );
				}
			);
		$GLOBALS['wpdb'] = $wpdb;

		EventLogger::prune();

		$this->addToAssertionCount( 1 );
	}
}
