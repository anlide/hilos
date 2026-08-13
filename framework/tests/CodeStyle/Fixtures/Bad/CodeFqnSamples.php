<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: four written-out names, one for each position a class
 * is named in — a base class, a construction, a catch and a static access. CODE-FQN
 * must report every one. Never autoloaded — this file exists to be read as text.
 */
final class CodeFqnSamples extends \RuntimeException
{
    /**
     * @return string Where the scanner was pointed, or what the catch caught
     */
    public function build(): string
    {
        try {
            $scanner = new \Hilos\Tests\CodeStyle\SourceScanner(__DIR__);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return $scanner->root() . \Hilos\Tests\CodeStyle\Baseline::PATH;
    }

    /**
     * The two spellings that break the same rule differently: a name qualified against
     * this file's own namespace, which carries no backslash to remove, and a global
     * function, which needs the backslash gone and no import at all.
     *
     * @return int Width of the sample's own rule id
     */
    public function measure(): int
    {
        $sample = new Rule\CodeFqnSample(__DIR__);

        return \strlen($sample->id());
    }
}
