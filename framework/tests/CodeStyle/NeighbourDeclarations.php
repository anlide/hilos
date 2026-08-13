<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

/**
 * Answers whether a short name is declared beside a file, which under PSR-4 is the
 * same question as whether it belongs to the file's own namespace.
 *
 * A file is not assumed to declare the one type its name spells: a suite parks its
 * doubles at the bottom of the test that needs them, and those are as reachable from
 * a neighbour as any other class of the namespace. Reading the directory instead of
 * probing for `<Name>.php` is what keeps the two FQN rules answering alike — the same
 * name in the same file must not be legal in code and reported in a docblock.
 *
 * Names are collected by pattern rather than by tokens: a hit inside prose or a
 * string only makes the rule that asks quieter, never louder.
 */
final class NeighbourDeclarations
{
    /** Matches the name a file declares. */
    private const string DECLARED_TYPE = '/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/';

    private readonly string $root;

    /** @var array<string, array<string, true>> Lowercased names declared by a directory, keyed by that directory */
    private array $byDirectory = [];

    /**
     * @param string $root Absolute path of the scanned root the relative paths are read against
     */
    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param string $name Short name written in that file
     * @return bool True when a file of the same directory declares the name
     */
    public function declares(string $relativePath, string $name): bool
    {
        return isset($this->inDirectory(dirname($relativePath))[strtolower($name)]);
    }

    /**
     * @param string $directory Directory path relative to the scanned root
     * @return array<string, true> Lowercased names that directory declares, read once
     */
    private function inDirectory(string $directory): array
    {
        if (isset($this->byDirectory[$directory])) {
            return $this->byDirectory[$directory];
        }

        $declared = [];
        foreach (glob($this->root . '/' . $directory . '/*.php') ?: [] as $file) {
            if (preg_match_all(self::DECLARED_TYPE, (string)file_get_contents($file), $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $name) {
                $declared[strtolower($name)] = true;
            }
        }
        $this->byDirectory[$directory] = $declared;

        return $declared;
    }
}
