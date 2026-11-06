<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Server grammar bound to the destination of this restore invocation. */
final readonly class DumpServerVersion
{
    private const int MAJOR_MULTIPLIER = 10000;
    private const int MINOR_MULTIPLIER = 100;
    private const int MYSQL_IGNORED_FROM = 50700;
    private const int MYSQL_IGNORED_THROUGH = 99999;
    private const string MARIADB_COMPATIBILITY_PREFIX = '5.5.5-';

    private int $number;
    private bool $mariaDb;

    /**
     * @param string $serverInfo Destination's mysqli server version, also retained for the final recheck
     * @throws RestoreFailedException When the server's version cannot be interpreted
     */
    public function __construct(public string $serverInfo)
    {
        $this->mariaDb = str_contains($serverInfo, 'MariaDB');
        $version = $this->mariaDb && str_starts_with($serverInfo, self::MARIADB_COMPATIBILITY_PREFIX)
            ? substr($serverInfo, strlen(self::MARIADB_COMPATIBILITY_PREFIX)) : $serverInfo;
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{1,2})(?:[-.]|$)/D', $version, $parts) !== 1) {
            throw new RestoreFailedException('Unsupported full restore server version');
        }
        $this->number = (int)$parts[1] * self::MAJOR_MULTIPLIER + (int)$parts[2] * self::MINOR_MULTIPLIER + (int)$parts[3];
    }

    /**
     * Inactive branches refuse admission rather than contribute facts to the restore proof.
     *
     * @param bool $mariaDbOnly Whether the comment uses the MariaDB-specific family
     * @param int $minimum Minimum server version, or zero for an unversioned comment
     * @throws RestoreFailedException When the destination will not execute this comment's body
     */
    public function assertExecutes(bool $mariaDbOnly, int $minimum): void
    {
        if (($mariaDbOnly && !$this->mariaDb) || $minimum > $this->number
            || ($this->mariaDb && !$mariaDbOnly && $minimum >= self::MYSQL_IGNORED_FROM && $minimum <= self::MYSQL_IGNORED_THROUGH)) {
            throw new RestoreFailedException('Unproven executable comment in full restore dump');
        }
    }
}
