<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Session\SessionCarryResult;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\DeferredSessionsCarriedSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeLiftAnnouncer;

/**
 * SessionCarryOverDoneSignalData - backup agent -> own daemon payload for SESSION_CARRY_OVER_DONE.
 *
 * The answer to {@see SessionCarryOverDeferredSignalData}, from the agent that sent that one
 * ({@see BackupAgent}): the sessions library has answered for the batch the restore left
 * ({@see DeferredSessionsCarriedSignalData}) and the logins are in the restored database, so the
 * master may tell the browsers to reload ({@see ProtectedModeLiftAnnouncer}). The holder says it and
 * not the library because the master waiting is the one on the node that ran the restore, and in a
 * cluster the library may run on another (HIL-846). It is sent when the pass FAILED too, with
 * nothing carried - the master's question is whether anything more is coming, and after a failed
 * pass the answer is no. What went wrong is the library's own log line; holding the lift over it
 * would only delay the reload by the full timeout and tell the operator the same thing twice.
 *
 * The three numbers are {@see SessionCarryResult}'s, passed on unchanged from the library's receipt,
 * so the line the master logs about a held lift reads the same as the one the library logs about
 * the pass.
 */
final class SessionCarryOverDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: logins written into the restored database. */
    public const string carried = 'carried';

    /** Payload key: logins that will not survive the restore. */
    public const string dropped = 'dropped';

    /** Payload key: logins that came back inside the archive. */
    public const string kept = 'kept';

    /**
     * @param int $carried Logins written into the restored database
     * @param int $dropped Logins that will not survive the restore
     * @param int $kept Logins that came back inside the archive
     */
    public function __construct(
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
            self::carried => $this->carried,
            self::dropped => $this->dropped,
            self::kept => $this->kept,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            carried: (int)$data[self::carried],
            dropped: (int)$data[self::dropped],
            kept: (int)$data[self::kept],
        );
    }
}
