<?php

declare(strict_types=1);

namespace MomentSqlFixtures;

use DateTimeInterface;
use Hilos\Database\Database as Sql;
use Hilos\Utils\Helpers\TimeHelper as Clock;
use function time as seconds;

class Writers
{
    // Static template pieces are resolved without loading this class.
    public const string TABLE = 'event';
    public const string COLUMN = 'at';

    public function insert(): void
    {
        Sql::sql("INSERT INTO event (at, created_at) VALUES (NOW(), '2026-11-07 12:13:14.123')");
    }

    public function update(): void
    {
        Sql::sql('UPDATE event SET at = CURRENT_TIMESTAMP, created_at = UNIX_TIMESTAMP(NOW()) WHERE id = 1');
    }

    public function positional(DateTimeInterface $date): void
    {
        Sql::sql('INSERT INTO event (id, at, created_at) VALUES (?, ?, ?)', [17, time(), $date->getTimestamp()]);
    }

    public function named(): void
    {
        Sql::sql('UPDATE event SET at = :at, created_at = :created WHERE id = :id', [
            'id' => 7, 'created' => Clock::getSqlDateTime(), 'at' => '2026-11-07 ' . '12:13:14',
        ]);
    }

    public function alias(): void
    {
        $when = Clock::getSqlDateTime();
        Sql::sql('UPDATE event SET at = ?', [(string)($when)]);
    }

    public function constants(): void
    {
        Sql::sql('UPDATE `' . self::TABLE . '` SET `' . self::COLUMN . '` = (cAsT((nOw(3)) As SIGNED))');
    }

    public function dbOnly(): void
    {
        Sql::sql('UPDATE event SET unmapped = CURRENT_TIMESTAMP(3)');
    }

    public function provider(): void
    {
        Sql::sql('INSERT INTO bootstrap (claimed_at) VALUES (CURRENT_TIMESTAMP(3))');
    }

    public function journal(): void
    {
        Sql::sql('UPDATE journal SET at = CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) AS SIGNED)');
        Sql::sql('UPDATE journal SET at = NOW()');
    }

    public function duplicates(): void
    {
        Sql::sql('INSERT INTO event (at) VALUES (NOW()), (NOW())');
    }

    public function namedArguments(): void
    {
        Sql::sqlRun(params: [':moment' => (int)(seconds())], sql: 'UPDATE event SET at = CAST(:moment AS SIGNED)');
    }

    public function dates(): void
    {
        Sql::sql('UPDATE event SET at = ?, created_at = ?', [date('Y-m-d H:i:s'), gmdate('Y-m-d ' . 'H:i:s')]);
    }

    public function qualified(): void
    {
        \Hilos\Database\Database::sql('UPDATE event SET at = ?', [\time()]);
    }

    public function whereOrdinals(): void
    {
        Sql::sql("UPDATE event SET duration = ?, at = (?) WHERE label = '?' AND id = ?", [5, time(), 7]);
    }

    public function literalWithUnknownParameters(array $parameters): void
    {
        Sql::sql('UPDATE event SET at = NOW(), created_at = ? WHERE id = ?', $parameters);
    }

    public function positionalDatetime(): void
    {
        Sql::sql('INSERT INTO event (at, created_at) VALUES (?, ?)', [Clock::getSqlDateTime(), '2026-11-07 12:13:14']);
    }

    public function namedTimestamp(DateTimeInterface $date): void
    {
        Sql::sql('UPDATE event SET at = :at', ['at' => $date->getTimestamp()]);
    }

    public function keyedList(): void
    {
        Sql::sql('UPDATE event SET at = ?', array(0 => time()));
    }

    public function columnCase(): void
    {
        Sql::sql('UPDATE event SET `AT` = NOW(), `CrEaTeD_At` = CURRENT_TIMESTAMP');
        Sql::sql('INSERT INTO event (`AT`, `cReAtEd_At`) VALUES (?, ?)', [time(), time()]);
    }

    public function namedDates(): void
    {
        Sql::sql('UPDATE event SET at = ?, created_at = ?', [date(format: 'Y-m-d H:i:s'), gmdate(timestamp: 0, format: 'Y-m-d H:i:s')]);
    }

    public function namedDateAlias(): void
    {
        $when = date(timestamp: 0, format: 'Y-m-d H:i:s');
        Sql::sql('INSERT INTO event (at) VALUES (?)', [$when]);
    }

    public function insertIgnore(): void
    {
        Sql::sql('INSERT IGNORE INTO event (at) VALUES (NOW())');
        Sql::sql('INSERT IGNORE INTO event (at) VALUES (?)', [time()]);
        Sql::sql('INSERT IGNORE INTO event (at) VALUES (:at)', ['at' => seconds()]);
    }

    public function upsert(): void
    {
        Sql::sql('INSERT INTO event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NOW()');
    }

    public function upsertValues(): void
    {
        Sql::sql('INSERT IGNORE INTO event (at) VALUES (NOW()) ON DUPLICATE KEY UPDATE at = NULL');
    }

    public function upsertDuplicate(): void
    {
        Sql::sql('INSERT INTO event (at, created_at) VALUES (0, NULL) ON DUPLICATE KEY UPDATE at = NOW(), created_at = UNIX_TIMESTAMP()');
    }

    public function upsertPositional(): void
    {
        Sql::sql('INSERT IGNORE INTO event (id, at) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE duration = ?, at = ?, created_at = ?',
            [19, Clock::nowMs(), 18, time(), time(), 0, time()]);
    }

    public function upsertNamed(): void
    {
        Sql::sql('INSERT INTO event (id, at, created_at) VALUES (:id, :at, :created) ON DUPLICATE KEY UPDATE at = :changed, created_at = :created',
            ['changed' => Clock::nowMs(), 'id' => time(), 'created' => Clock::getSqlDateTime(), 'at' => time()]);
    }

    public function upsertNamedArguments(): void
    {
        Sql::sqlRun(params: [Clock::nowMs(), (int)(seconds())], sql:
            'insert ignore into `event` (`AT`) values (?) on duplicate key update `At` = CAST(? AS SIGNED), `CrEaTeD_At` = CURRENT_TIMESTAMP');
    }

    public function upsertMultiple(): void
    {
        Sql::sql('INSERT INTO event (at) VALUES (NOW()), (NOW()) ON DUPLICATE KEY UPDATE at = NOW(), created_at = CURRENT_TIMESTAMP(3)');
    }
}
