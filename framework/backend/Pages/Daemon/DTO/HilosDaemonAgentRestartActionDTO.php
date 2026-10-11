<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Browser request naming one agent on the currently subscribed node. */
final class HilosDaemonAgentRestartActionDTO extends ActionPayloadDTO
{
    public const string agentId = 'agentId';
    public const string nodeId = 'nodeId';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $agentId Agent identity to restart
     * @param string $nodeId Node currently being viewed
     * @throws EmptyValueException When either identity is empty
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $nodeId,
    ) {
        if (trim($agentId) === '' || trim($nodeId) === '') {
            throw new EmptyValueException('Agent restart requires non-empty agentId and nodeId');
        }
    }

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART;
    }

    /**
     * @param array<string, mixed> $data Raw or FIELD_DATA-wrapped action payload
     * @return static Parsed request
     * @throws InvalidFormatException When an identity is missing or mistyped, or data is not an object
     * @throws EmptyValueException When either identity is empty
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('Agent restart payload carries a non-object data section');
        }

        return new static(
            agentId: self::requireString($inner, self::agentId),
            nodeId: self::requireString($inner, self::nodeId),
        );
    }

    /** @return array<string, mixed> Browser request fields */
    public function toArray(): array
    {
        return [self::agentId => $this->agentId, self::nodeId => $this->nodeId];
    }
}
