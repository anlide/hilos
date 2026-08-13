<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Attribute;

/**
 * An attribute to hang on a parameter and on a method, so the walk has to step over
 * `#[` — one token that opens a bracket a plain `]` closes.
 */
#[Attribute]
final class SinceAttribute
{
    /**
     * An array argument is the shape that closes the attribute early when only `#[`
     * is counted as its opener.
     *
     * @param array<int, int> $versions Versions the attribute names
     */
    public function __construct(public array $versions = [])
    {
    }
}
