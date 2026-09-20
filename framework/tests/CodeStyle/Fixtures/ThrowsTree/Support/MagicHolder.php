<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/**
 * Seeds live and dead tags past a magic read. The property's tag resolves the call;
 * the reader's contract backs NarrowException while leaving OtherException orphaned.
 *
 * @property-read Quiet $quiet
 */
class MagicHolder
{
    /**
     * @param string $name Property nobody declared
     * @return Quiet A quiet source
     * @throws NarrowException When the property is not known
     */
    public function __get(string $name): Quiet
    {
        return $name === 'quiet' ? new Quiet() : throw new NarrowException('no');
    }

    /**
     * The reader's contract backs this tag even though the target raises nothing.
     *
     * @return string What the quiet source said
     * @throws NarrowException When the property is not known
     */
    public function keepsATagPastAMagicRead(): string
    {
        return $this->quiet->speak();
    }

    /**
     * @return string What the quiet source said
     * @throws OtherException A tag neither the reader nor the target backs
     */
    public function keepsADeadTagPastAMagicRead(): string
    {
        return $this->quiet->speak();
    }

    /**
     * @return string What the helper read from the quiet source
     * @throws NarrowException A live reader contract carried through the private link
     * @throws OtherException A dead tag carried beside the live one
     */
    public function keepsADeadTagPastAMagicReadInAHelper(): string
    {
        return $this->readThroughTheHelper();
    }

    /**
     * @return string What the quiet source said, or the handled refusal
     * @throws NarrowException A dead tag because the catch swallows the reader's contract
     */
    public function catchesTheMagicRead(): string
    {
        try {
            return $this->quiet->speak();
        } catch (NarrowException) {
            return 'handled';
        }
    }

    /**
     * @return string What the quiet source said
     */
    private function readThroughTheHelper(): string
    {
        return $this->quiet->speak();
    }
}
