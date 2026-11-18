<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Auth\AccountOperation\Testing\AccountErasureProbeState;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Immediate worker-local probe outcome on the requesting connection. */
final class WorkerTestAccountErasureProbedDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_ACCOUNT_ERASURE_PROBED;
    public const string OK = 'ok';
    public const string STATE = 'state';
    public const string ERROR = 'error';

    /**
     * @param string $correlationId Original command correlation
     * @param bool $ok Whether the test operation completed
     * @param ?AccountErasureProbeState $state Required on success, null on refusal
     * @param ?string $error Safe nonempty refusal, null on success
     * @throws InvalidFormatException When the correlation or outcome is inconsistent
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly bool $ok,
        public readonly ?AccountErasureProbeState $state,
        public readonly ?string $error,
    ) {
        if ($correlationId === '' || ($ok && ($state === null || $error !== null))
            || (!$ok && ($state !== null || $error === null || trim($error) === ''))) {
            throw new InvalidFormatException('Erasure probe outcome requires a correlation and consistent state or error');
        }
    }

    /** @return string Worker-to-master message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete outcome including explicit nulls */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            self::OK => $this->ok,
            self::STATE => $this->state?->toArray(),
            self::ERROR => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming probe outcome
     * @return static Strictly hydrated outcome
     * @throws InvalidFormatException When the envelope, state or outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        if (array_diff(array_keys($data), [self::TYPE, CommandConstants::FIELD_CORRELATION_ID, self::OK, self::STATE, self::ERROR]) !== []
            || self::requireString($data, self::TYPE) !== self::MESSAGE_TYPE) {
            throw new InvalidFormatException('Invalid erasure probe worker outcome envelope');
        }
        $state = self::requireNullableArray($data, self::STATE);
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireBool($data, self::OK),
            $state === null ? null : AccountErasureProbeState::fromArray($state),
            self::requireNullableString($data, self::ERROR),
        );
    }
}
