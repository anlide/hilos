<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception;

/**
 * A leaf of the fixture hierarchy: covered by a `@throws TreeException`, and never
 * covering one.
 */
final class NarrowException extends TreeException
{
    /**
     * Seeds `@throws self` over a `throw new self()`: the tag has to resolve exactly
     * as the code does, or the method reads as declaring nothing.
     *
     * @param ?string $value Value to guard
     * @return string The value, once it is known to be there
     * @throws self When the value is absent
     */
    public static function requireValue(?string $value): string
    {
        if ($value === null) {
            throw new self();
        }

        return $value;
    }
}
