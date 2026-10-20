<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Worker outcome; the connection supplies the sender's identity. */
final class WorkerTestOrmForgottenDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_ORM_FORGOTTEN;
    public const string FIELD_OK = 'ok';
    public const string FIELD_ERROR = 'error';

    /**
     * @param string $correlationId Original request correlation
     * @param bool $ok Whether the local forget completed
     * @param ?string $error Nonempty refusal on failure, null on success
     * @throws InvalidFormatException When the correlation or outcome is inconsistent
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly bool $ok,
        public readonly ?string $error,
    ) {
        if ($correlationId === '' || ($ok && $error !== null) || (!$ok && ($error === null || $error === ''))) {
            throw new InvalidFormatException('ORM forget acknowledgement requires a correlation and a consistent outcome');
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
        if (!array_key_exists(self::FIELD_ERROR, $data)) {
            throw new InvalidFormatException('ORM forget acknowledgement requires error, including null on success');
        }
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireBool($data, self::FIELD_OK),
            self::optionalString($data, self::FIELD_ERROR),
        );
    }
}
