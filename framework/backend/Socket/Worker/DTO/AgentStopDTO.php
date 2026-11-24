<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * AgentStopDTO - DTO for agent stop signal.
 *
 * Used when daemon sends agent_stop signal to worker.
 */
class AgentStopDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_AGENT_STOP;

    public const string KEEP_RESTART_MIRRORS = 'keep_for_restart';

    public const string DISCARD_RESTART_MIRRORS = 'discard_restart_mirrors';

    private const string PAGE_MIRRORS = 'pageMirrors';

    /**
     * Creates agent stop DTO.
     *
     * @param string $agentId Agent unique ID to stop
     * @param ?string $pageMirrors Restart disposition, or null for an ordinary stop
     */
    public function __construct(
        public readonly string $agentId,
        public readonly ?string $pageMirrors = null,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            ...($this->pageMirrors === null ? [] : [self::PAGE_MIRRORS => $this->pageMirrors]),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Agent identity and optional restart disposition
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id or an invalid restart disposition
     */
    public static function fromArray(array $data): static
    {
        $pageMirrors = self::optionalString($data, self::PAGE_MIRRORS);
        if ($pageMirrors !== null && $pageMirrors !== self::KEEP_RESTART_MIRRORS && $pageMirrors !== self::DISCARD_RESTART_MIRRORS) {
            throw new InvalidFormatException('Unknown agent stop disposition for restart mirrors');
        }

        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            pageMirrors: $pageMirrors,
        );
    }
}
