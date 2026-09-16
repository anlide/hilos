<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds the third direction of the contract: a tag whose body can no longer throw it,
 * next to the look-alikes it has to stay silent on. Every tag here is backed by its
 * body or by nothing, so neither of the other two directions has anything to say.
 */
final class OrphanHolder
{
    private readonly Quiet $quiet;

    /**
     * Only assigns, but the assignment constructs: a body with a call in it, read to
     * the end, and nothing that raises the tag.
     *
     * @param SourceInterface $source Source the private helper reads
     * @throws OtherException When the holder is refused
     */
    public function __construct(private readonly SourceInterface $source)
    {
        $this->quiet = new Quiet();
    }

    /**
     * @return string What the quiet source said
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsADeadTag(): string
    {
        return $this->quiet->speak();
    }

    /**
     * The same tag over the same call, with one call before it that nothing resolves:
     * the body was not read whole, so the claim is not made.
     *
     * @param object $unknown Receiver nothing declares a class for
     * @return string What the quiet source said
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsADeadTagPastAnUnresolvedCall(object $unknown): string
    {
        $unknown->speak();

        return $this->quiet->speak();
    }

    /**
     * A `new` of a class that declares no constructor is a call read to the end, not a
     * call left unresolved.
     *
     * @return Quiet A fresh quiet source
     * @throws NarrowException When the source refuses to be made
     */
    public function constructsAClassWithoutAConstructor(): Quiet
    {
        return new Quiet();
    }

    /**
     * Nowhere for an exception to enter: the tag stands for what an override will raise.
     *
     * @throws NarrowException When the stage refuses
     */
    public function isAStub(): void
    {
    }

    /**
     * @return string What the source answered through the helper
     * @throws NarrowException When the source refuses to answer
     */
    public function livesThroughAPrivateHelper(): string
    {
        return $this->readThroughHelper();
    }

    /**
     * Carries no tag, so nothing here is judged — it is only the caller that keeps the
     * private helper below in use.
     *
     * @return string What the private helper said
     */
    public function reachesThePrivateHelper(): string
    {
        return $this->keepsADeadTagOnAPrivateHelper();
    }

    /**
     * @return string What the source answered
     */
    private function readThroughHelper(): string
    {
        return $this->source->read();
    }

    /**
     * A private method carries no contract for a caller, but what it does write down
     * is still meant to be true.
     *
     * @return string What the quiet source said
     * @throws OtherException When the source has gone
     */
    private function keepsADeadTagOnAPrivateHelper(): string
    {
        return $this->quiet->speak();
    }
}
