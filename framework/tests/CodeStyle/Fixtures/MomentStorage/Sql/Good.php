<?php

declare(strict_types=1);

namespace MomentSqlFixtures;

use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;

class Good
{
    public function values(int $offset): void
    {
        Database::sql('INSERT INTO event (at, created_at) VALUES (?, ?)', [TimeHelper::nowMs(), null]);
        Database::sql('INSERT INTO event (at) VALUES (0), (-1234), (1700000000123), (NULL)');
        Database::sql('UPDATE event SET at = :at, created_at = :created', ['created' => -1234, 'at' => 0]);
        Database::sql('UPDATE event SET at = ?, created_at = ?', [123 + $offset, TimeHelper::nowMs() * 1000]);
        Database::sql('UPDATE event SET at = ?, created_at = ?', [0, -1234]);
        Database::sql('UPDATE event SET at = :at, created_at = :created', ['at' => TimeHelper::nowMs(), 'created' => null]);
    }

    public function journal(): void
    {
        Database::sql('INSERT INTO journal (at) VALUES (CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) * 1000 AS SIGNED))');
        Database::sql('UPDATE journal SET at = CAST(UNIX_TIMESTAMP(NOW(3)) * 1000 AS SIGNED)');
    }

    public function nonMoments(): void
    {
        Database::sql('UPDATE event SET birthday = CURRENT_TIMESTAMP, duration = UNIX_TIMESTAMP(), id = NOW(), foreign_id = NOW()');
        Database::sql('INSERT INTO event (birthday, duration, id, foreign_id) VALUES (?, ?, ?, ?)', [date('Y-m-d'), time(), time(), time()]);
    }

    public function optOut(): void
    {
        Database::sql('UPDATE own_event SET at = NOW()');
        Database::sql('INSERT INTO own_event (at) VALUES (?)', [time()]);
    }

    public function quotedAndCommented(): void
    {
        Database::sql("UPDATE event SET at = 'NOW()', created_at = 'UNIX_TIMESTAMP()' /* at = NOW() */");
        Database::sql("SELECT 'UPDATE event SET at = NOW()'");
        $source = 'Database::sql("UPDATE event SET at = NOW()")';
        // Database::sql('UPDATE event SET at = NOW()');
        /* Database::sql('INSERT INTO event (at) VALUES (NOW())'); */
        Database::sql('UPDATE elsewhere SET at = NOW()');
        time();
        date('Y-m-d H:i:s');
    }

    public function columnCase(): void
    {
        Database::sql('UPDATE event SET `BIRTHDAY` = NOW(), `Duration` = UNIX_TIMESTAMP()');
        Database::sql('INSERT INTO own_event (`AT`) VALUES (?)', [time()]);
        Database::sql('UPDATE own_event SET `At` = NOW()');
        Database::sql('UPDATE elsewhere SET `AT` = NOW()');
        Database::sql('UPDATE EVENT SET `AT` = NOW()');
    }

    public function namedFormats(string $format): void
    {
        Database::sql('UPDATE event SET at = ?, created_at = ?', [date(format: $format), gmdate(format: 'Y-m-d')]);
        Database::sql('UPDATE event SET birthday = ?, duration = ?', [date(format: 'Y-m-d H:i:s'), gmdate(format: 'Y-m-d H:i:s')]);
    }

    public function insertModifiers(): void
    {
        Database::sql('INSERT IGNORE INTO event (at, created_at) VALUES (?, ?)', [TimeHelper::nowMs(), null]);
        Database::sql('INSERT INTO event (at) VALUES (0), (-1234), (1700000000123) ON DUPLICATE KEY UPDATE at = NULL');
        Database::sql('INSERT IGNORE INTO event (at) VALUES (?) ON DUPLICATE KEY UPDATE at = ?', [null, TimeHelper::nowMs()]);
        Database::sql('INSERT INTO event (at) VALUES (:at) ON DUPLICATE KEY UPDATE at = :changed', ['at' => TimeHelper::nowMs(), 'changed' => 0]);
        Database::sql('INSERT INTO event (at) VALUES (0) ON DUPLICATE KEY UPDATE at = VALUES(at), created_at = unknown_clock()');
        Database::sql('INSERT IGNORE INTO event (duration) VALUES (NOW()) ON DUPLICATE KEY UPDATE birthday = NOW()');
        Database::sql('INSERT IGNORE INTO own_event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
        Database::sql('INSERT INTO event (duration) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = VALUES(duration), created_at = unknown_clock(NOW())');
    }

    public function insertJournal(): void
    {
        Database::sql('INSERT IGNORE INTO journal (at) VALUES (CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) * 1000 AS SIGNED))');
        Database::sql('INSERT INTO journal (at) VALUES (0) ON DUPLICATE KEY UPDATE at = CAST(UNIX_TIMESTAMP(NOW(3)) * 1000 AS SIGNED)');
    }
}
