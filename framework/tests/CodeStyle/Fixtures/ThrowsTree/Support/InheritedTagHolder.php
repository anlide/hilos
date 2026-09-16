<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * A tag the body does not back but the contract it implements declares: an
 * implementation may repeat what its interface allows.
 */
final class InheritedTagHolder implements SourceInterface
{
    /**
     * @param Quiet $quiet Source the read goes through
     */
    public function __construct(private readonly Quiet $quiet)
    {
    }

    /**
     * @return string What the quiet source said
     * @throws NarrowException When the source refuses to answer
     */
    public function read(): string
    {
        return $this->quiet->speak();
    }

    /**
     * @return bool True when the source came up
     */
    public function start(): bool
    {
        return true;
    }
}
