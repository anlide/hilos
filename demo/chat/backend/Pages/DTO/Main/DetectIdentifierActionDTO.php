<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DetectIdentifierActionDTO - DTO for the live identifier-lookup action payload.
 *
 * The public (anonymous-reachable) lookup the identifier-first surface fires while
 * somebody types (HIL-414). One field, and it is carried VERBATIM - not trimmed,
 * not lowercased, not normalized in any way. The reply echoes this value back and
 * the surface matches an answer to its field by that echo alone, comparing it to
 * the raw input; the machine's own gate trims before deciding a value is worth a
 * lookup, so a pasted "  a@b.com  " does reach here, and stripping it would make
 * every such answer unmatchable - the field would stay revealing nothing, with no
 * error to explain it. Surrounding whitespace is the detector's to ignore, which
 * it does while classifying and normalizing.
 */
final class DetectIdentifierActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates detect-identifier action DTO.
     *
     * @param string $identifier Submitted identifier verbatim, an email address or a phone number
     */
    public function __construct(
        public readonly string $identifier,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::DETECT_IDENTIFIER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Detect-identifier DTO instance
     * @throws InvalidFormatException When the identifier is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identifier: self::requireString($data, 'identifier'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{identifier: string} Detect-identifier payload
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
        ];
    }
}
