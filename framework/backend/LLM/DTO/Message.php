<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\LLM\Constants\LLMApiConstants;

/**
 * Message - Chat message DTO for LLM.
 *
 * Represents a single message in chat format (role + content).
 * Roles: system, user, assistant.
 *
 * @extends BaseDTO
 */
class Message extends BaseDTO
{
    /** Role: system instruction */
    public const string ROLE_SYSTEM = 'system';

    /** Role: user message */
    public const string ROLE_USER = 'user';

    /** Role: assistant reply */
    public const string ROLE_ASSISTANT = 'assistant';

    /**
     * Creates chat message instance.
     *
     * @param string $role Message role (ROLE_SYSTEM, ROLE_USER, ROLE_ASSISTANT)
     * @param string $content Message text content
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
    }

    /**
     * Converts DTO to array for provider use.
     *
     * @return array{role: string, content: string} Role and content keys
     */
    public function toArray(): array
    {
        return [
            LLMApiConstants::KEY_ROLE => $this->role,
            LLMApiConstants::KEY_CONTENT => $this->content,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with role and content keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            role: $data[LLMApiConstants::KEY_ROLE] ?? self::ROLE_USER,
            content: (string) ($data[LLMApiConstants::KEY_CONTENT] ?? ''),
        );
    }

    /**
     * Normalize message to array format for provider use.
     *
     * Accepts either Message instance or associative array.
     *
     * @param Message|array{role: string, content: string} $message Message instance or array
     * @return array{role: string, content: string} Normalized array with role and content
     */
    public static function toProviderFormat(Message|array $message): array
    {
        if ($message instanceof self) {
            return $message->toArray();
        }

        return [
            LLMApiConstants::KEY_ROLE => $message[LLMApiConstants::KEY_ROLE] ?? self::ROLE_USER,
            LLMApiConstants::KEY_CONTENT => (string) ($message[LLMApiConstants::KEY_CONTENT] ?? ''),
        ];
    }
}
