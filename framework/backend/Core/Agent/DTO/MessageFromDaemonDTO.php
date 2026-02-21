<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;

/**
 * MessageFromDaemonDTO - DTO for messages from daemon to agent
 *
 * Represents a message sent from daemon process to worker agent.
 */
class MessageFromDaemonDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string ACTION = 'action';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly string $action,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::ACTION => $this->action,
        ];

        if (!empty($this->payload)) {
            $result[self::PAYLOAD] = $this->payload;
        }

        return $result;
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            action: $data[self::ACTION] ?? '',
            payload: $data[self::PAYLOAD] ?? [],
        );
    }
}
