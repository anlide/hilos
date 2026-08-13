<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: the backslash is gone and the import never arrived, so
 * the catch below asks for a class of THIS namespace, matches nothing and says
 * nothing. Never autoloaded — this file exists to be read as text.
 */
final class CodeFqnUnresolved
{
    /**
     * @return string The encoded value, or what the catch that never fires would say
     */
    public function encodes(): string
    {
        try {
            return (string)json_encode(['ok'], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }
    }
}
