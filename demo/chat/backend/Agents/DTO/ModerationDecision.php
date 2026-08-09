<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Utils\Helpers\JsonHelper;
use JsonException;
use stdClass;

/**
 * Parsed moderation model decision.
 */
final readonly class ModerationDecision
{
    public const string KEY_ALLOW = 'allow';
    public const string KEY_REASON = 'reason';

    /** Default reason label when the model omits one for an allowed message. */
    public const string REASON_ALLOWED = 'ok';

    /** Default reason label when the model omits one for a blocked message. */
    public const string REASON_BLOCKED = 'blocked';

    public function __construct(
        public bool $allow,
        public string $reason,
    ) {
    }

    /**
     * Parses a moderation model output object; the boolean allow decision is
     * authoritative and a missing or blank reason is defaulted to a label.
     *
     * @param string $text Raw model output
     * @throws InvalidArgumentException When output does not contain a valid moderation decision
     */
    public static function fromModelOutput(string $text): self
    {
        $json = JsonHelper::extractJsonObject($text);
        if ($json === null) {
            throw new InvalidArgumentException('Moderation response did not contain a JSON object');
        }

        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Moderation response JSON is invalid', previous: $e);
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Moderation response JSON must be an object');
        }

        if (!property_exists($decoded, self::KEY_ALLOW)) {
            throw new InvalidArgumentException('Moderation response is missing allow decision');
        }

        $allow = $decoded->{self::KEY_ALLOW};
        if (!is_bool($allow)) {
            throw new InvalidArgumentException('Moderation response allow decision must be boolean');
        }

        // The allow decision is authoritative. The reason is only a label and
        // small models often omit it for clear-cut cases, so default it rather
        // than rejecting the whole decision (which would block benign messages).
        $reason = property_exists($decoded, self::KEY_REASON) && is_string($decoded->{self::KEY_REASON})
            ? trim($decoded->{self::KEY_REASON})
            : null;
        if ($reason === null || $reason === '') {
            $reason = $allow ? self::REASON_ALLOWED : self::REASON_BLOCKED;
        }

        return new self(
            allow: $allow,
            reason: $reason,
        );
    }
}
