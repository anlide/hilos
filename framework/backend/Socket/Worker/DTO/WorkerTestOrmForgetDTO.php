<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Master request addressed to one worker connection. */
final class WorkerTestOrmForgetDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_ORM_FORGET;

    /**
     * @param string $correlationId Held command correlation
     * @throws InvalidFormatException When the correlation is empty
     */
    public function __construct(public readonly string $correlationId)
    {
        if ($correlationId === '') {
            throw new InvalidFormatException('ORM forget requires a nonempty correlationId');
        }
    }

    /** @return string Master-to-worker message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete request frame */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, CommandConstants::FIELD_CORRELATION_ID => $this->correlationId];
    }

    /**
     * @param array<string, mixed> $data Incoming request frame
     * @return static Parsed request
     * @throws InvalidFormatException When correlationId is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, CommandConstants::FIELD_CORRELATION_ID));
    }
}
