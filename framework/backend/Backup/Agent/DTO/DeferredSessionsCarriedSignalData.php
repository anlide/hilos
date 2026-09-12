<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\SessionCarryResult;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DeferredSessionsCarriedSignalData - sessions library -> BackupAgent payload for
 * BACKUP_AGENT_SESSIONS_CARRIED (HIL-846).
 *
 * The receipt for one handed-over batch of deferred logins. It says the batch is the library's
 * now, not that every login in it came back: {@see AbstractSessionsLibraryAgent} sends it after a
 * failed pass too, with nothing carried, because a login that could not be re-created is already
 * lost on purpose and offering the batch again would not bring it back.
 *
 * The three numbers are {@see SessionCarryResult}'s, unchanged, because the holder passes them on
 * to its own master as the outcome of the carry-over.
 */
final class DeferredSessionsCarriedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: id of the batch this receipt is for. */
    public const string batch = 'batch';

    /** Payload key: logins written into the restored database. */
    public const string carried = 'carried';

    /** Payload key: logins that will not survive the restore. */
    public const string dropped = 'dropped';

    /** Payload key: logins that came back inside the archive. */
    public const string kept = 'kept';

    /**
     * @param string $batch Id of the batch this receipt is for
     * @param int $carried Logins written into the restored database
     * @param int $dropped Logins that will not survive the restore
     * @param int $kept Logins that came back inside the archive
     */
    public function __construct(
        public readonly string $batch,
        public readonly int $carried,
        public readonly int $dropped,
        public readonly int $kept,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::batch => $this->batch,
            self::carried => $this->carried,
            self::dropped => $this->dropped,
            self::kept => $this->kept,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no batch or misses one of the counts
     */
    public static function fromArray(array $data): static
    {
        return new static(
            batch: self::requireString($data, self::batch),
            carried: self::requireInt($data, self::carried),
            dropped: self::requireInt($data, self::dropped),
            kept: self::requireInt($data, self::kept),
        );
    }
}
