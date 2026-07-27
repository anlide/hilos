<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupDeleteSignalData - page → BackupAgent payload for BACKUP_AGENT_DELETE.
 *
 * Carries the backup id to remove; the agent routes it through the shared
 * delete path and drops the matching runtime index row.
 *
 * It also carries who asked, so the agent can stamp that connection as the origin
 * of the index write ({@see \Hilos\Core\Execution\ExecutionContext::withAcceptKey()}):
 * the requester's own row-removed delta then applies at once while other tabs keep
 * the pending gate. Null when the delete has no connection behind it.
 */
final class BackupDeleteSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the target backup id. */
    public const string backupId = 'backupId';

    /** Payload key: the requesting connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /**
     * @param string $backupId Target backup id
     * @param ?string $initiatorAcceptKey Accept key of the connection that asked, or null when none
     */
    public function __construct(
        public readonly string $backupId,
        public readonly ?string $initiatorAcceptKey = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::backupId => $this->backupId,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $initiator = $data[self::initiatorAcceptKey] ?? null;

        return new static(
            backupId: (string)($data[self::backupId] ?? ''),
            initiatorAcceptKey: is_string($initiator) && $initiator !== '' ? $initiator : null,
        );
    }
}
