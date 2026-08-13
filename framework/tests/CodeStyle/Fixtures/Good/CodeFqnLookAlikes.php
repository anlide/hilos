<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use JsonException;

/**
 * Negative sample: everything CODE-FQN has to stay silent about — a backslash that is
 * data, a backslash that is prose, an imported global class, and a neighbour of this
 * namespace that no import mentions.
 */
final class CodeFqnLookAlikes
{
    /**
     * @return string A class name carried as data, leading backslash and all
     */
    public function separator(): string
    {
        // Prose may spell \Hilos\Tests\CodeStyle\Violation without naming a class.
        return '\\Hilos\\Tests\\CodeStyle\\Violation';
    }

    /**
     * @return string What the neighbour of this namespace answered, encoded
     * @throws JsonException When the values cannot be encoded
     */
    public function reads(): string
    {
        return (string)json_encode(CodeFqnNeighbour::values(), JSON_THROW_ON_ERROR);
    }
}
