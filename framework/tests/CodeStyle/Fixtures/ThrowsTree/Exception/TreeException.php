<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception;

use Exception;

/**
 * Base of the fixture tree's own exceptions, so the rule's coverage question has a
 * hierarchy to answer it from without reaching for a production class.
 */
class TreeException extends Exception
{
}
