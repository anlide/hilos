<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Addresses a receipt and the last entry of an optional preceding portion. */
final class HilosChangeLogReceiptReadActionDTO extends ActionPayloadDTO
{
    public const string receiptId = 'receiptId';
    public const string afterEntryId = 'afterEntryId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $receiptId Positive receipt number
     * @param ?int $afterEntryId Positive entry number, or null to read from the start
     * @throws InvalidFormatException When either address is not positive
     */
    public function __construct(public readonly int $receiptId, public readonly ?int $afterEntryId = null)
    {
        if ($receiptId < 1 || ($afterEntryId !== null && $afterEntryId < 1)) {
            throw new InvalidFormatException('Receipt and cursor ids must be positive');
        }
    }

    /** @return string Receipt reading action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CHANGE_LOG_RECEIPT_READ;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed receipt address
     * @throws InvalidFormatException When an id is absent, mistyped, or not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::receiptId), self::optionalInt($data, self::afterEntryId));
    }

    /** @return array<string, int|null> Receipt request on the wire */
    public function toArray(): array
    {
        return [self::receiptId => $this->receiptId, self::afterEntryId => $this->afterEntryId];
    }
}
