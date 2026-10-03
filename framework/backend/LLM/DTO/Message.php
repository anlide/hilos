<?php

declare(strict_types=1);

namespace Hilos\LLM\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\LLM\Constants\LLMApiConstants;
use Hilos\LLM\Exception\LLMMessageContentMissingException;

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
     * @param list<MessageImage> $images Pictures attached to this message
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly array $images = [],
    ) {
    }

    /**
     * Converts DTO to array for provider use.
     *
     * @return array<string, mixed> Role, content and optional images
     */
    public function toArray(): array
    {
        $data = [
            LLMApiConstants::KEY_ROLE => $this->role,
            LLMApiConstants::KEY_CONTENT => $this->content,
        ];

        if ($this->images !== []) {
            $data[LLMApiConstants::KEY_IMAGES] = array_map(
                static fn (MessageImage $image): array => $image->toArray(),
                $this->images,
            );
        }

        return $data;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with role and content keys
     * @return static DTO instance
     * @throws LLMMessageContentMissingException When the payload carries no content
     * @throws InvalidFormatException When an image entry is malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            role: $data[LLMApiConstants::KEY_ROLE] ?? self::ROLE_USER,
            content: self::requireContent($data),
            images: self::readImages($data),
        );
    }

    /**
     * Normalize message to array format for provider use.
     *
     * Accepts either Message instance or associative array.
     *
     * @param Message|array{role: string, content: string} $message Message instance or array
     * @return array<string, mixed> Normalized role, content and optional images
     * @throws LLMMessageContentMissingException When an array message carries no content
     */
    public static function toProviderFormat(Message|array $message): array
    {
        if ($message instanceof self) {
            return $message->toArray();
        }

        return [
            LLMApiConstants::KEY_ROLE => $message[LLMApiConstants::KEY_ROLE] ?? self::ROLE_USER,
            LLMApiConstants::KEY_CONTENT => self::requireContent($message),
        ];
    }

    /**
     * @param array<string, mixed> $data Serialized message
     * @return list<MessageImage> Pictures attached to the message
     * @throws InvalidFormatException When an image entry is malformed
     */
    private static function readImages(array $data): array
    {
        $serialized = self::optionalArray($data, LLMApiConstants::KEY_IMAGES);
        if ($serialized === null) {
            return [];
        }

        $images = [];
        foreach ($serialized as $image) {
            if (!is_array($image)) {
                throw new InvalidFormatException('Message images must be objects');
            }
            $images[] = MessageImage::fromArray($image);
        }

        return $images;
    }

    /**
     * Reads the content a chat turn is made of, refusing a turn that says nothing.
     *
     * @param array<string, mixed> $data Message payload
     * @return string Non-empty message content
     * @throws LLMMessageContentMissingException When the payload carries no content
     */
    private static function requireContent(array $data): string
    {
        $content = $data[LLMApiConstants::KEY_CONTENT] ?? null;
        if (!is_scalar($content) || (string)$content === '') {
            throw new LLMMessageContentMissingException('Chat message carries no content');
        }

        return (string)$content;
    }
}
