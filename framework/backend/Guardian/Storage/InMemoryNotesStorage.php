<?php

declare(strict_types=1);

namespace Hilos\Guardian\Storage;

/**
 * In-memory notes storage for guardian (scope → list of note strings).
 */
final class InMemoryNotesStorage
{
    /** @var array<string, list<string>> scope => list of note strings */
    private static array $notes = [];

    /**
     * Read notes for scope.
     *
     * @param string $scope Storage scope
     * @return list<string> Notes for the scope
     */
    public static function read(string $scope): array
    {
        return self::$notes[$scope] ?? [];
    }

    /**
     * Append note to scope.
     *
     * @param string $scope Storage scope
     * @param string $note Note to append
     */
    public static function write(string $scope, string $note): void
    {
        if (!isset(self::$notes[$scope])) {
            self::$notes[$scope] = [];
        }
        self::$notes[$scope][] = $note;
    }
}
