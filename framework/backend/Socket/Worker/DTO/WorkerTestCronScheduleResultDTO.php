<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Immediate outcome of a worker's mutation of a cron pause set. */
final class WorkerTestCronScheduleResultDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_CRON_SCHEDULE_RESULT;
    public const string FIELD_OK = 'ok';
    public const string FIELD_ERROR = 'error';

    /**
     * @param string $correlationId Original command correlation
     * @param bool $ok Whether the mutation was applied
     * @param ?string $error Nonempty refusal on failure, null on success
     * @throws InvalidFormatException When the correlation or outcome is inconsistent
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly bool $ok,
        public readonly ?string $error,
    ) {
        if ($correlationId === '' || ($ok && $error !== null) || (!$ok && ($error === null || $error === ''))) {
            throw new InvalidFormatException('Cron schedule acknowledgement requires a correlation and a consistent outcome');
        }
    }

    /** @return string Worker-to-master message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete schedule outcome */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            self::FIELD_OK => $this->ok,
            self::FIELD_ERROR => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming schedule outcome
     * @return static Parsed acknowledgement
     * @throws InvalidFormatException When a field is absent, mistyped or inconsistent
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(self::FIELD_ERROR, $data)) {
            throw new InvalidFormatException('Cron schedule acknowledgement requires error, null on success');
        }
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireBool($data, self::FIELD_OK),
            self::optionalString($data, self::FIELD_ERROR),
        );
    }
}
