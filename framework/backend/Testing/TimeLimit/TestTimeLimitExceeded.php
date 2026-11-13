<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Framework\Exception;

/** Keeps an alarm outside the exceptions a test may expect. */
final class TestTimeLimitExceeded extends Exception
{
}
