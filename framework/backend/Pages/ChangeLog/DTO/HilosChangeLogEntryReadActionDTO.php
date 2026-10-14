<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Addresses one change written past the application. */
final class HilosChangeLogEntryReadActionDTO extends ActionPayloadDTO
{
    public const string entryId = 'entryId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $entryId Positive journal entry number
     * @throws InvalidFormatException When the address is not positive
     */
    public function __construct(public readonly int $entryId)
    {
        if ($entryId < 1) {
            throw new InvalidFormatException('Entry id must be positive');
        }
    }

    /** @return string Bare-entry reading action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CHANGE_LOG_ENTRY_READ;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed entry address
     * @throws InvalidFormatException When the id is absent, mistyped, or not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::entryId));
    }

    /** @return array<string, int> Entry request on the wire */
    public function toArray(): array
    {
        return [self::entryId => $this->entryId];
    }
}
