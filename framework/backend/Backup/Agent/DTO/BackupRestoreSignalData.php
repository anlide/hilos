<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\Backup\BackupScope;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupRestoreSignalData - page → BackupAgent payload for BACKUP_AGENT_RESTORE.
 *
 * The browser's half of the restore request, shaped like what the `backup:restore` CLI
 * already puts on the command channel: which archive, under which {@see BackupScope} it
 * was captured, and what {@see RestoreEnvGuard} answered for this archive/target pair.
 * The verdict travels rather than being recomputed by the agent for the reason the CLI
 * path gives too - the matrix is authoritative where the request was validated - and the
 * agent still refuses a plain {@see RestoreEnvDecision::REFUSE} as a backstop.
 *
 * It also carries who pressed the button, twice over. The accept key is the connection:
 * a restore outlives its ack by minutes and freezes the node while it runs, so it is both
 * what protected mode keeps alive and the one address the progress frames and the refusal
 * are sent to. The user id is the person behind it, which the agent needs for a different
 * span - it photographs their identities before the swap so the finished restore can be
 * announced to them in the database that replaced this one (HIL-279). Null on either is
 * the CLI's answer to the same question, and it means nobody is watching.
 */
final class BackupRestoreSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the archive to restore. */
    public const string backupId = 'backupId';

    /** Payload key: the scope value the archive was captured under. */
    public const string scope = 'scope';

    /** Payload key: the recorded ENV guard verdict. */
    public const string decision = 'decision';

    /** Payload key: the requesting connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /** Payload key: the durable user id behind the requesting connection. */
    public const string initiatorUserId = 'initiatorUserId';

    /**
     * @param string $backupId Id of the archive to restore
     * @param string $scope Scope value the archive was captured under
     * @param string $decision Recorded ENV guard verdict value
     * @param ?string $initiatorAcceptKey Accept key of the connection that asked, or null when unattended
     * @param ?int $initiatorUserId User id behind that connection, or null when unattended or unauthenticated
     */
    public function __construct(
        public readonly string $backupId,
        public readonly string $scope,
        public readonly string $decision,
        public readonly ?string $initiatorAcceptKey = null,
        public readonly ?int $initiatorUserId = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
            self::scope => $this->scope,
            self::decision => $this->decision,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
            self::initiatorUserId => $this->initiatorUserId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no archive, scope or verdict
     */
    public static function fromArray(array $data): static
    {
        return new static(
            backupId: self::requireString($data, self::backupId),
            scope: self::requireString($data, self::scope),
            decision: self::requireString($data, self::decision),
            initiatorAcceptKey: self::optionalString($data, self::initiatorAcceptKey),
            initiatorUserId: self::optionalInt($data, self::initiatorUserId),
        );
    }
}
