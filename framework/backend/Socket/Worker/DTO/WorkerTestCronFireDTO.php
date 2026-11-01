<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Master request to the worker running one agent: arm that agent's cron rule for its next check. */
final class WorkerTestCronFireDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_CRON_FIRE;

    /**
     * @param string $correlationId Held command correlation
     * @param string $agentId Agent holding the rule
     * @param string $ruleName Name of the rule to arm
     * @throws InvalidFormatException When a field is empty
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $agentId,
        public readonly string $ruleName,
    ) {
        if ($correlationId === '' || $agentId === '' || $ruleName === '') {
            throw new InvalidFormatException('Cron fire requires a nonempty correlationId, agentId and ruleName');
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
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            CommandConstants::FIELD_AGENT_ID => $this->agentId,
            CommandConstants::FIELD_RULE_NAME => $this->ruleName,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming request frame
     * @return static Parsed request
     * @throws InvalidFormatException When a field is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireString($data, CommandConstants::FIELD_AGENT_ID),
            self::requireString($data, CommandConstants::FIELD_RULE_NAME),
        );
    }
}
