<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\Backup\AbstractHilosBackupPage;

/**
 * DTO for the backup_circle_add action payload: the address of the person being named.
 *
 * One field, and deliberately no identity type beside it: the operator types an address, not
 * a way of proving one, and which type it turns out to be is answered by the identity that
 * carries it ({@see AbstractHilosBackupPage}). A payload naming both would let a client pair
 * an address with a type nobody confirmed it under.
 */
final class BackupCircleAddActionDTO extends ActionPayloadDTO
{
    /** Payload key: the address as the operator typed it. */
    public const string identifier = 'identifier';

    /**
     * @param string $identifier Address as the operator typed it
     */
    public function __construct(
        public readonly string $identifier,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_CIRCLE_ADD;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the address is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            identifier: trim(self::requireString($inner, self::identifier)),
        );
    }

    /**
     * @return array<string, mixed> Data with the address
     */
    public function toArray(): array
    {
        return [self::identifier => $this->identifier];
    }
}
