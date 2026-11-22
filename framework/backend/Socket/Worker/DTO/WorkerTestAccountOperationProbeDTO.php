<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeRequest;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Test-only master request to the worker already holding the local source. */
final class WorkerTestAccountOperationProbeDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_TEST_ACCOUNT_OPERATION_PROBE;
    public const string REQUEST = 'request';

    /**
     * @param string $correlationId Held command correlation
     * @param AccountOperationProbeRequest $request Strict request including its local source
     * @throws InvalidFormatException When the correlation is empty
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly AccountOperationProbeRequest $request,
    ) {
        if ($correlationId === '') {
            throw new InvalidFormatException('Account operation probe requires a nonempty correlation');
        }
    }

    /** @return string Master-to-worker message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Request with its source address only in the outer envelope */
    public function toArray(): array
    {
        $payload = $this->request->toArray();
        unset($payload[AgentConstants::FIELD_AGENT_ID]);
        return [
            self::TYPE => self::MESSAGE_TYPE,
            CommandConstants::FIELD_CORRELATION_ID => $this->correlationId,
            AgentConstants::FIELD_AGENT_ID => $this->request->agentId,
            self::REQUEST => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $data Incoming worker request
     * @return static Strictly hydrated test request
     * @throws InvalidFormatException When the envelope, nested request or captured frame is malformed
     */
    public static function fromArray(array $data): static
    {
        if (array_diff(array_keys($data), [
            self::TYPE, CommandConstants::FIELD_CORRELATION_ID, AgentConstants::FIELD_AGENT_ID, self::REQUEST,
        ]) !== [] || self::requireString($data, self::TYPE) !== self::MESSAGE_TYPE) {
            throw new InvalidFormatException('Invalid account operation probe worker request envelope');
        }
        $payload = self::requireArray($data, self::REQUEST);
        if (array_key_exists(AgentConstants::FIELD_AGENT_ID, $payload)) {
            throw new InvalidFormatException('Account operation probe source belongs only to the outer worker envelope');
        }
        $payload[AgentConstants::FIELD_AGENT_ID] = self::requireString($data, AgentConstants::FIELD_AGENT_ID);
        return new static(
            self::requireString($data, CommandConstants::FIELD_CORRELATION_ID),
            AccountOperationProbeRequest::fromArray($payload),
        );
    }
}
