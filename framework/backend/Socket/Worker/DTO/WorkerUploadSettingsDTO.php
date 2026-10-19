<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Effective upload byte budget read by a worker and reported to its master. */
final class WorkerUploadSettingsDTO extends WorkerDTO
{
    public const string TYPE = 'type';
    public const string MEMORY_POOL_BYTES = 'memoryPoolBytes';
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_UPLOAD_SETTINGS;

    /**
     * @param int $memoryPoolBytes Positive budget in bytes
     * @throws InvalidFormatException When the budget is not positive
     */
    public function __construct(public readonly int $memoryPoolBytes)
    {
        if ($memoryPoolBytes <= 0) {
            throw new InvalidFormatException('Upload memory pool bytes must be positive');
        }
    }

    /**
     * @return string Worker message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> Worker wire payload
     */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::MEMORY_POOL_BYTES => $this->memoryPoolBytes];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Decoded settings report
     * @throws InvalidFormatException When the budget is missing or is not a positive integer
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::MEMORY_POOL_BYTES));
    }
}
