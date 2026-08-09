<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Hilos\Guardian;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * GuardianAgentRunStartActionDTO - DTO for guardian run start action.
 */
final class GuardianAgentRunStartActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates guardian run start DTO.
     *
     * @param string $agentId Guardian agent identifier
     */
    public function __construct(
        public readonly string $agentId,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::GUARDIAN_AGENT_RUN_START;
    }

    /**
     * Create DTO from payload data.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentId: trim(self::requireString($data, 'agentId')),
        );
    }

    /**
     * Convert DTO to array.
     *
     * @return array<string, string> DTO payload
     */
    public function toArray(): array
    {
        return [
            'agentId' => $this->agentId,
        ];
    }
}
