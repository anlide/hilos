<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Page-admitted restart and its server-supplied return address. */
final class DaemonAgentRestartRequestSignalData extends BaseDTO implements SignalDataInterface
{
    public const string agentId = 'agentId';
    public const string nodeId = 'nodeId';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string replyAgentType = 'replyAgentType';
    public const string replyAgentIndex = 'replyAgentIndex';

    /**
     * @param string $agentId Requested agent identity
     * @param string $nodeId Node of the page subscription
     * @param string $acceptKey Initiating connection
     * @param ?string $requestId Tracked submit id, or null for an untracked action
     * @param string $replyAgentType Page agent admitting the action
     * @param ?string $replyAgentIndex Page agent's instance index, or null for a singleton
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $nodeId,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $replyAgentType,
        public readonly ?string $replyAgentIndex,
    ) {
    }

    /** @return array<string, mixed> Restart and return-address fields */
    public function toArray(): array
    {
        return [
            self::agentId => $this->agentId,
            self::nodeId => $this->nodeId,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::replyAgentType => $this->replyAgentType,
            self::replyAgentIndex => $this->replyAgentIndex,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored request
     * @throws InvalidFormatException When a required field is missing or a field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: self::requireString($data, self::agentId),
            nodeId: self::requireString($data, self::nodeId),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            replyAgentType: self::requireString($data, self::replyAgentType),
            replyAgentIndex: self::optionalString($data, self::replyAgentIndex),
        );
    }
}
