<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\Backup\BackupScope;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupCreateSignalData - page → BackupAgent payload for BACKUP_AGENT_CREATE.
 *
 * Carries the validated scope value a manual create should capture; the agent
 * resolves it back to a {@see BackupScope} on the guarded create path.
 *
 * It also carries who asked. A backup is accepted synchronously but finishes long
 * after its action was acked, so the run's outcome has no reply to ride: the agent
 * keeps this accept key with the in-flight run and addresses the failure notice back
 * to that one connection. Only a human create carries it — a scheduled or CLI run
 * has no initiator, and its failure is deliberately told to nobody (it is recorded
 * in the storage index and the agent log instead).
 */
final class BackupCreateSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the backup scope value. */
    public const string scope = 'scope';

    /** Payload key: the requesting connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /**
     * @param string $scope Backup scope value
     * @param ?string $initiatorAcceptKey Accept key of the connection that asked, or null when unattended
     */
    public function __construct(
        public readonly string $scope,
        public readonly ?string $initiatorAcceptKey = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::scope => $this->scope,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no scope to capture
     */
    public static function fromArray(array $data): static
    {
        return new static(
            scope: self::requireString($data, self::scope),
            initiatorAcceptKey: self::optionalString($data, self::initiatorAcceptKey),
        );
    }
}
