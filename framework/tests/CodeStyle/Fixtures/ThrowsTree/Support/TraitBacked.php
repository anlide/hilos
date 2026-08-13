<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;

/**
 * Satisfies the interface entirely through a trait, naming neither method itself.
 * The widening {@see WideningTrait::start()} carries is reachable only through here.
 */
final class TraitBacked implements SourceInterface
{
    use WideningTrait;
}
