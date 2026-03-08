<?php

declare(strict_types=1);

namespace Hilos\Guardian\Storage;

final class InMemoryNotesStorage
{
    /** @var array<string, list<string>> */
    private static array $notes = [];

    /**
     * @return list<string>
     */
    public static function read(string $scope): array
    {
        return self::$notes[$scope] ?? [];
    }

    public static function write(string $scope, string $note): void
    {
        if (!isset(self::$notes[$scope])) {
            self::$notes[$scope] = [];
        }
        self::$notes[$scope][] = $note;
    }
}
