<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree;

use DateTimeImmutable;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\AbstractSource;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * The look-alikes. Every method here is a case the rule must stay silent on, and a
 * hit from this file is as much a failure as a missed one from {@see Caller}.
 */
final class Silent extends AbstractSource
{
    /**
     * @return string Payload read from the source
     * @throws NarrowException When the source refuses to answer
     */
    public function read(): string
    {
        // A mention of $this->source->read() in a comment is not a call.
        return 'read';
    }

    /**
     * A contract is inherited downward silently: restating what the parent already
     * declares is not widening it.
     *
     * @return bool True when the source came up
     * @throws OtherException When the socket cannot be bound
     */
    public function start(): bool
    {
        return parent::start();
    }

    /**
     * @return string A literal that merely spells a method name
     */
    public function namesAMethodInAString(): string
    {
        return 'Registry::lookup';
    }

    /**
     * @param SourceInterface $source Source read through a parameter's declared type
     * @return string What the source answered, or the fallback
     */
    public function catchesWithAMultiTypeCatch(SourceInterface $source): string
    {
        try {
            return $source->read();
        } catch (OtherException | NarrowException) {
            return 'fallback';
        }
    }

    /**
     * @return string The current year, from a class no scanned root declares
     */
    public function callsOutsideTheIndex(): string
    {
        return (new DateTimeImmutable())->format('Y');
    }
}
