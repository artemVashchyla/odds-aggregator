<?php
/**
 * Transport-level failure.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Http;

use RuntimeException;

/**
 * Thrown when a request cannot be completed, or completes unsuccessfully.
 */
final class HttpException extends RuntimeException {

	/**
	 * HTTP status, or 0 when the request never landed.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Constructor.
	 *
	 * @param string $message Failure description.
	 * @param int    $status  HTTP status, or 0 when the request never landed.
	 */
	public function __construct( string $message, int $status = 0 ) {
		parent::__construct( $message, $status );

		$this->status = $status;
	}

	/**
	 * HTTP status, or 0 when the request never landed.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}
}
