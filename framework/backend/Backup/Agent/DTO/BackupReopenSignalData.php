<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * BackupReopenSignalData - page → BackupAgent payload for BACKUP_AGENT_REOPEN (HIL-676).
 *
 * One field, and it is not an argument: the freeze to end is the one the protected-mode
 * runtime row names, and the agent re-reads that row rather than trusting anything carried
 * here. The accept key travels so the agent can say in its log whose click it carried out -
 * or whose it refused when a terminal opened the system between the page's answer and this
 * frame.
 */
final class BackupReopenSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the requesting connection's accept key. */
    public const string acceptKey = 'acceptKey';

    /**
     * @param string $acceptKey Accept key of the connection that pressed the button
     */
    public function __construct(
        public readonly string $acceptKey,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no accept key
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::acceptKey),
        );
    }
}
