<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Same WordPress stand-ins as the unit suite, except that outbound HTTP is real.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

require_once __DIR__ . '/http-live.php';
require_once __DIR__ . '/bootstrap.php';
