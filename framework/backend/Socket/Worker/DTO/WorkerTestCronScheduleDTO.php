<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Master request to mutate the pause set of a current agent cron rule. */
final class WorkerTestCronScheduleDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_CRON_SCHEDULE;

    /**
     * @param string $correlationId Held command correlation
     * @param string $agentId Agent holding the rule
     * @param string $ruleName Current rule name
     * @param CronScheduleTestOperation $operation Pause mutation to apply
     * @param string $pauseId Exact pause key
     * @throws InvalidFormatException When a required field is empty
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $agentId,
        public readonly string $ruleName,
        public readonly CronScheduleTestOperation $operation,
        public readonly string $pauseId,
    ) {
        if ($correlationId === '' || $agentId === '' || $ruleName === '' || $pauseId === '') {
            throw new InvalidFormatException('Cron schedule requires a nonempty correlationId, agentId, ruleName and pauseId');
        }
    }

    /** @return string Master-to-worker message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete schedule request */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            CommandConstants::FIELD_AGENT_ID => $this->agentId,
            CommandConstants::FIELD_RULE_NAME => $this->ruleName,
            CommandConstants::FIELD_CRON_SCHEDULE_OPERATION => $this->operation->value,
            CommandConstants::FIELD_PAUSE_ID => $this->pauseId,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming schedule request
     * @return static Parsed request
     * @throws InvalidFormatException When a field is absent, mistyped, empty or names an unknown operation
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            self::requireString($data, CommandConstants::FIELD_AGENT_ID),
            self::requireString($data, CommandConstants::FIELD_RULE_NAME),
            CronScheduleTestOperation::tryFrom(self::requireString($data, CommandConstants::FIELD_CRON_SCHEDULE_OPERATION))
                ?? throw new InvalidFormatException('Unknown cron schedule operation'),
            self::requireString($data, CommandConstants::FIELD_PAUSE_ID),
        );
    }
}
