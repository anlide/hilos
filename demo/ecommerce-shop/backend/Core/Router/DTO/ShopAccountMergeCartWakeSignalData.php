<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Prompts the sessions library to reconsider Give from the saved merge facts. */
final class ShopAccountMergeCartWakeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string operationId = 'operationId';

    /**
     * @param int $operationId Merge operation to reconsider
     * @throws InvalidFormatException When the operation ID is not positive
     */
    public function __construct(
        public readonly int $operationId,
    ) {
        if ($operationId <= 0) {
            throw new InvalidFormatException('A shop account merge cart wake requires a positive operation ID.');
        }
    }

    /**
     * @param array<string, mixed> $data Exact transport payload
     * @return static Wake frame
     * @throws InvalidFormatException When the operation ID or key set is invalid
     */
    public static function fromArray(array $data): static
    {
        if (count($data) !== 1) {
            throw new InvalidFormatException('A shop account merge cart wake requires exactly the operation ID.');
        }

        return new static(self::requireInt($data, self::operationId));
    }

    /** @return array<string, int> Exact transport payload */
    public function toArray(): array
    {
        return [self::operationId => $this->operationId];
    }
}
