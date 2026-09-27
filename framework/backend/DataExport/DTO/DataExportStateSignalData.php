<?php

declare(strict_types=1);

namespace Hilos\DataExport\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** One person's archive state; moments are server epoch milliseconds. */
final class DataExportStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string dataExport = 'dataExport';
    public const string state = 'state';
    public const string requestedAt = 'requestedAt';
    public const string finishedAt = 'finishedAt';
    public const string expiresAt = 'expiresAt';
    public const string sizeBytes = 'sizeBytes';

    /**
     * @param ?array{state: string, requestedAt: int, finishedAt: ?int, expiresAt: ?int, sizeBytes: ?int} $dataExport Archive state
     */
    public function __construct(public readonly ?array $dataExport)
    {
    }

    /**
     * @return array<string, mixed> Wire form without any storage path
     */
    public function toArray(): array
    {
        return [self::dataExport => $this->dataExport];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored state
     * @throws InvalidFormatException When a state field has the wrong type or a required field is missing
     */
    public static function fromArray(array $data): static
    {
        $node = self::optionalArray($data, self::dataExport);

        return new static($node === null ? null : [
            self::state => self::requireString($node, self::state),
            self::requestedAt => self::requireInt($node, self::requestedAt),
            self::finishedAt => self::optionalInt($node, self::finishedAt),
            self::expiresAt => self::optionalInt($node, self::expiresAt),
            self::sizeBytes => self::optionalInt($node, self::sizeBytes),
        ]);
    }
}
