<?php

declare(strict_types=1);

namespace Demo\Polls\Tables\HilosLegal;

use Demo\Polls\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;

/** Supplies the demo's person names to the framework acceptance window. */
final class HilosLegalAcceptancesTable extends AbstractHilosLegalAcceptancesTable
{
    /**
     * @param list<int> $userIds People in the requested window
     * @return array<int, string> Available names by person id
     * @throws DatabaseException When a person cannot be loaded
     * @throws LogicException When the user collection is not configured
     * @throws InvalidArgumentException When the loaded user object has an invalid type
     */
    protected function displayNamesOf(array $userIds): array
    {
        $names = [];
        foreach ($userIds as $userId) {
            $name = Hilos::$db->users[$userId]?->name;
            if ($name !== null) {
                $names[$userId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param string $term Literal name substring
     * @return list<int> Matching people
     * @throws DatabaseException When the user collection cannot be loaded
     * @throws LogicException When the user collection is not configured
     * @throws InvalidArgumentException When a loaded user object has an invalid type
     */
    protected function userIdsNamed(string $term): array
    {
        $ids = [];
        $needle = mb_strtolower($term);
        foreach (Hilos::$db->users->listAll() as $user) {
            if (str_contains(mb_strtolower($user->name), $needle)) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }
}
