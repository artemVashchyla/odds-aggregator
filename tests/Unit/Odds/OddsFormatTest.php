<?php
/**
 * Tests for the display-format enum.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Odds;

use OddsAggregator\Odds\OddsFormat;
use PHPUnit\Framework\TestCase;

/**
 * Covers resolving a stored string to a format.
 *
 * The stored value comes from a block attribute or a settings row, so it can be
 * anything: an unrecognised value must land on decimal rather than break the
 * table.
 */
final class OddsFormatTest extends TestCase {

	/**
	 * The three cases keep the string values stored in block attributes.
	 */
	public function test_the_stored_values_are_stable(): void {
		$this->assertSame( 'decimal', OddsFormat::Decimal->value );
		$this->assertSame( 'fractional', OddsFormat::Fractional->value );
		$this->assertSame( 'american', OddsFormat::American->value );
		$this->assertCount( 3, OddsFormat::cases() );
	}

	/**
	 * A recognised value resolves to its case.
	 *
	 * @param string     $value    Stored value.
	 * @param OddsFormat $expected Resolved case.
	 * @dataProvider provide_known_values
	 */
	public function test_a_known_value_resolves( string $value, OddsFormat $expected ): void {
		$this->assertSame( $expected, OddsFormat::from_string_or_default( $value ) );
	}

	/**
	 * The values the plugin stores.
	 *
	 * @return array<string,array{0:string,1:OddsFormat}>
	 */
	public static function provide_known_values(): array {
		return array(
			'decimal'    => array( 'decimal', OddsFormat::Decimal ),
			'fractional' => array( 'fractional', OddsFormat::Fractional ),
			'american'   => array( 'american', OddsFormat::American ),
		);
	}

	/**
	 * Anything else falls back to decimal, the canonical internal format.
	 *
	 * @param string|null $value Unrecognised stored value.
	 * @dataProvider provide_unknown_values
	 */
	public function test_an_unknown_value_falls_back_to_decimal( ?string $value ): void {
		$this->assertSame( OddsFormat::Decimal, OddsFormat::from_string_or_default( $value ) );
	}

	/**
	 * Values a block attribute can hold when it was never set, or was set by an
	 * older version of the block.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public static function provide_unknown_values(): array {
		return array(
			'null'         => array( null ),
			'empty'        => array( '' ),
			'wrong case'   => array( 'Decimal' ),
			'padded'       => array( ' decimal ' ),
			'retired name' => array( 'hongkong' ),
			'nonsense'     => array( 'not-a-format' ),
		);
	}

	/**
	 * Every case has a label, because all three are offered in the UI switcher.
	 */
	public function test_every_case_has_a_label(): void {
		$labels = array_map(
			static fn( OddsFormat $format ): string => $format->label(),
			OddsFormat::cases()
		);

		$this->assertSame( array( 'Decimal', 'Fractional', 'American' ), $labels );
	}
}
