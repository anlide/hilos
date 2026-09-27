<?php

declare(strict_types=1);

namespace Hilos\DataExport\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DataExport\DataExportNotifier;

/** An erased account whose export row and file must be removed by their owner. */
final class DataExportForgetUserSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';

    /**
     * @param int $userId Person whose account was erased
     */
    public function __construct(public readonly int $userId)
    {
    }

    /**
     * @return array{userId: int} Wire form
     */
    public function toArray(): array
    {
        return [self::userId => $this->userId];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When the user id is absent or not an integer
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::userId));
    }
}
