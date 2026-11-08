<?php

declare(strict_types=1);

namespace MomentSqlFixtures;

use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;

class Unknown
{
    public function templates(string $sql, string $table, string $column, string $method, $connection): void
    {
        Database::sql($sql, [time()]);
        Database::sql('UPDATE ' . $table . ' SET at = ?', [time()]);
        Database::sql('UPDATE event SET ' . $column . ' = ?', [time()]);
        Database::sql(makeSql(), [time()]);
        Database::$method('UPDATE event SET at = ?', [time()]);
        $connection->sql('UPDATE event SET at = ?', [time()]);
        sqlHelper('UPDATE event SET at = ?', [time()]);
        FakeDatabase::sql('UPDATE event SET at = ?', [time()]);
    }

    public function bindings(array $parameters, string $key): void
    {
        Database::sql('UPDATE event SET at = ?', $parameters);
        Database::sql('UPDATE event SET at = ?', makeParameters());
        Database::sql('UPDATE event SET at = ?', [...$parameters, time()]);
        Database::sql('UPDATE event SET at = :at', [$key => time()]);
        Database::sql('UPDATE event SET at = :at', ['at' => time(), ':at' => TimeHelper::nowMs()]);
        Database::sql('UPDATE event SET at = ? WHERE id = ?', [time()]);
        Database::sql('UPDATE event SET at = ?', [time(), 11]);
        Database::sql('UPDATE event SET at = :at WHERE id = ?', ['at' => time(), 7]);
        Database::sql('UPDATE event SET at = :missing', ['at' => time()]);
        Database::sql('UPDATE event SET at = ?', [5 => time()]);
        Database::sql('UPDATE event SET at = ?', ['notTime' => time()]);
        Database::sql('UPDATE event SET at = :at', ['::at' => time()]);
    }

    public function aliases(bool $branch): void
    {
        $when = time();
        $when = TimeHelper::nowMs();
        Database::sql('UPDATE event SET at = ?', [$when]);
        $before = time();
        if ($branch) {
            Database::sql('UPDATE event SET at = ?', [$before]);
        }
        $nested = time();
        Database::sql('UPDATE event SET at = ?', [$nested = TimeHelper::nowMs()]);
        $option = time();
        Database::sql(sql: 'UPDATE event SET at = ?', timeout: ($option = TimeHelper::nowMs()), params: [$option]);
    }

    public function grammar(): void
    {
        Database::sql('INSERT INTO event VALUES (NOW())');
        Database::sql('INSERT INTO event (at) SELECT NOW()');
        Database::sql('UPDATE event e SET at = NOW()');
        Database::sql('UPDATE event JOIN other ON event.id = other.id SET at = NOW()');
        Database::sql('UPDATE event SET ? = NOW()', ['at']);
        Database::sql('UPDATE event SET at = NOW(); UPDATE elsewhere SET at = NOW()');
        Database::sql('UPDATE event SET at = unknown_clock()');
        Database::sql('UPDATE event SET at = UNIX_TIMESTAMP() + 1');
        Database::sql('UPDATE event SET at = NOW() /*! hidden executable SQL */');
        Database::sql('UPDATE event SET at = NOW() /*M! hidden executable SQL */');
        Database::sql('UPDATE event SET at = NOW() /* unclosed comment');
        Database::sql('UPDATE other_schema.event SET at = NOW()');
    }

    public function insertGrammar(string $table, string $column): void
    {
        Database::sql('INSERT IGNORE INTO event VALUES (NOW())');
        Database::sql('INSERT IGNORE INTO event (at) SELECT NOW() ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO event JOIN other (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO event (at) VALUES (NOW()); UPDATE event SET at = NOW()');
        Database::sql('INSERT IGNORE INTO event (at) VALUES (NOW()) /*! ON DUPLICATE KEY UPDATE at = NOW() */');
        Database::sql('INSERT INTO event (at) VALUES (NOW()) /*M! ON DUPLICATE KEY UPDATE at = NOW() */');
        Database::sql('INSERT INTO elsewhere (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO other_schema.event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO EVENT (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO ' . $table . ' (at) VALUES (?) ON DUPLICATE KEY UPDATE at = ?', [time(), time()]);
        Database::sql('INSERT INTO event (' . $column . ') VALUES (?) ON DUPLICATE KEY UPDATE at = ?', [time(), time()]);
        FakeDatabase::sql('INSERT IGNORE INTO event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        sqlHelper('INSERT INTO event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO event (at) VALUES (NOW()) ON SOMETHING ELSE at = NOW()');
    }

    public function insertBindings(array $parameters): void
    {
        Database::sql('INSERT INTO event (at) VALUES (?) ON DUPLICATE KEY UPDATE at = ?', [time()]);
        Database::sql('INSERT INTO event (at) VALUES (:at) ON DUPLICATE KEY UPDATE at = :changed', ['at' => time()]);
        Database::sql('INSERT IGNORE INTO event (at) VALUES (?) ON DUPLICATE KEY UPDATE at = ?', $parameters);
        Database::sql('INSERT INTO event (at) VALUES (:at) ON DUPLICATE KEY UPDATE at = ?', ['at' => time(), time()]);
    }
}

class FakeDatabase
{
}
