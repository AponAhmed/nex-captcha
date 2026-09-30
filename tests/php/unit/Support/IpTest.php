<?php
declare( strict_types=1 );

namespace Bitflw\NexCaptcha\Tests\Unit\Support;

use Bitflw\NexCaptcha\Support\Ip;
use Bitflw\NexCaptcha\Tests\TestCase;

final class IpTest extends TestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * @dataProvider allowlist_cases
	 * @param string[] $entries
	 */
	public function test_in_allowlist( string $address, array $entries, bool $expected ): void {
		$_SERVER['REMOTE_ADDR'] = $address;

		$this->assertSame( $expected, Ip::in_allowlist( $entries ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string[], 2: bool}>
	 */
	public static function allowlist_cases(): array {
		return array(
			'exact IPv4 match'        => array( '203.0.113.5', array( '203.0.113.5' ), true ),
			'IPv4 mismatch'           => array( '203.0.113.6', array( '203.0.113.5' ), false ),
			'inside a /24'            => array( '203.0.113.200', array( '203.0.113.0/24' ), true ),
			'outside a /24'           => array( '203.0.114.1', array( '203.0.113.0/24' ), false ),
			'edge of a /30'           => array( '10.0.0.7', array( '10.0.0.4/30' ), true ),
			'just past a /30'         => array( '10.0.0.8', array( '10.0.0.4/30' ), false ),
			'0.0.0.0/0 matches all'   => array( '198.51.100.1', array( '0.0.0.0/0' ), true ),
			'exact IPv6 match'        => array( '::1', array( '::1' ), true ),
			'inside an IPv6 range'    => array( '2001:db8::1', array( '2001:db8::/32' ), true ),
			'outside an IPv6 range'   => array( '2001:db9::1', array( '2001:db8::/32' ), false ),
			'second entry matches'    => array( '192.168.1.1', array( '10.0.0.0/8', '192.168.1.0/24' ), true ),
			'empty list never matches' => array( '203.0.113.5', array(), false ),
		);
	}

	public function test_in_allowlist_is_false_with_no_remote_addr(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertFalse( Ip::in_allowlist( array( '203.0.113.5' ) ) );
	}

	/**
	 * @dataProvider validity_cases
	 */
	public function test_is_valid_allowlist_entry( string $entry, bool $expected ): void {
		$this->assertSame( $expected, Ip::is_valid_allowlist_entry( $entry ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function validity_cases(): array {
		return array(
			'plain IPv4'        => array( '203.0.113.5', true ),
			'IPv4 CIDR'         => array( '203.0.113.0/24', true ),
			'IPv6 CIDR'         => array( '2001:db8::/32', true ),
			'prefix too large'  => array( '203.0.113.0/33', false ),
			'not an IP'         => array( 'not-an-ip', false ),
			'empty string'      => array( '', false ),
			'whitespace only'   => array( '   ', false ),
		);
	}
}
