<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupSetKeepSignalData - page → BackupAgent payload for BACKUP_AGENT_SET_KEEP.
 *
 * Carries the target backup id and the desired keep pin; the agent atomically
 * rewrites the sidecar (files=truth) and re-mirrors the runtime index.
 *
 * It also carries who asked, so the agent can stamp that connection as the origin
 * of the re-mirror write ({@see ExecutionContext::withAcceptKey()}):
 * the requester's own row-updated delta then applies at once while other tabs keep
 * the pending gate. Null when the toggle has no connection behind it.
 */
final class BackupSetKeepSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the target backup id. */
    public const string backupId = 'backupId';

    /** Payload key: the desired keep pin. */
    public const string keep = 'keep';

    /** Payload key: the requesting connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /**
     * @param string $backupId Target backup id
     * @param bool $keep Desired keep pin
     * @param ?string $initiatorAcceptKey Accept key of the connection that asked, or null when none
     */
    public function __construct(
        public readonly string $backupId,
        public readonly bool $keep,
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
            self::keep => $this->keep,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no backup or carries no keep pin
     */
    public static function fromArray(array $data): static
    {
        return new static(
            backupId: self::requireString($data, self::backupId),
            keep: self::requireBool($data, self::keep),
            initiatorAcceptKey: self::optionalString($data, self::initiatorAcceptKey),
        );
    }
}
