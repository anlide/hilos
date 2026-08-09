<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Tables;

/**
 * Negative sample inside the checked zone: the very fallbacks the Bad samples are
 * reported for are legal here, because the marker above each one says the value
 * arrives from outside the process and an empty one is what the outside sends.
 * The marker legalizes an occurrence, not a spelling, so it covers a ternary
 * branch and a `match` arm the same way it covers `??`.
 */
final class MarkedExternal
{
    /**
     * @param array<string, string> $row Row as the database driver returned it
     * @return string Column title, empty when the stored title is empty
     */
    public function title(array $row): string
    {
        // external-boundary: a NOT NULL column may legitimately hold an empty title
        return $row['title'] ?? '';
    }

    /**
     * @param array<int, string> $argv Command line as the shell handed it over
     * @return string Requested table, empty when the operator named none
     */
    public function requestedTable(array $argv): string
    {
        // external-boundary: a positional argument the operator may omit; usage is printed below
        return isset($argv[1]) ? $argv[1] : '';
    }

    /**
     * @param string $header Accept-Language header as the browser sent it
     * @return string Locale to use, empty when the browser stated no preference
     */
    public function locale(string $header): string
    {
        return match ($header) {
            'ru' => 'ru_RU',
            'en' => 'en_US',
            // external-boundary: a browser is free to send no preference at all
            default => '',
        };
    }
}
