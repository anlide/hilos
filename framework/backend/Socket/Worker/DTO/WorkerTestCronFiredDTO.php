<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Worker outcome of an armed cron rule; the connection supplies the sender's identity. */
final class WorkerTestCronFiredDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_CRON_FIRED;
    public const string FIELD_OK = 'ok';
    public const string FIELD_ERROR = 'error';

    /**
     * @param string $correlationId Original request correlation
     * @param bool $ok Whether the armed rule fired
     * @param ?int $firedAt Moment the rule fired, in epoch milliseconds, on success; null on failure
     * @param ?string $error Nonempty refusal on failure, null on success
     * @throws InvalidFormatException When the correlation or outcome is inconsistent
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly bool $ok,
        public readonly ?int $firedAt,
        public readonly ?string $error,
    ) {
        if ($correlationId === ''
            || ($ok && ($firedAt === null || $error !== null))
            || (!$ok && ($firedAt !== null || $error === null || $error === ''))) {
            throw new InvalidFormatException('Cron fire acknowledgement requires a correlation and a consistent outcome');
        }
    }

    /** @return string Worker-to-master message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete acknowledgement frame */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            self::FIELD_OK => $this->ok,
            CommandConstants::FIELD_FIRED_AT => $this->firedAt,
            self::FIELD_ERROR => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming acknowledgement
     * @return static Parsed outcome
     * @throws InvalidFormatException When a field is missing, mistyped or inconsistent
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(CommandConstants::FIELD_FIRED_AT, $data) || !array_key_exists(self::FIELD_ERROR, $data)) {
            throw new InvalidFormatException('Cron fire acknowledgement requires firedAt and error, each null where it does not apply');
        }
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireBool($data, self::FIELD_OK),
            self::optionalInt($data, CommandConstants::FIELD_FIRED_AT),
            self::optionalString($data, self::FIELD_ERROR),
        );
    }
}
