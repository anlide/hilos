<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * Seeds the silence of the third direction past a magic read. The tag names the
 * property's class, so the call on it resolves; the read itself runs `__get()`, and
 * what that raises is what the tag of the caller stands for.
 *
 * @property-read Quiet $quiet
 */
final class MagicHolder
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
     * @return string What the quiet source said
     * @throws NarrowException When the property is not known
     */
    public function keepsATagPastAMagicRead(): string
    {
        return $this->quiet->speak();
    }
}
