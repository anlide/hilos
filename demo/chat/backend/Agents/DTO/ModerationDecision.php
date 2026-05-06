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

    public function __construct(
        public bool $allow,
        public string $reason,
    ) {
    }

    /**
     * Parses a moderation model output object with bool allow and non-empty string reason.
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

        $reason = property_exists($decoded, self::KEY_REASON)
            ? $decoded->{self::KEY_REASON}
            : throw new InvalidArgumentException('Moderation response reason must exist');
        if (!is_string($reason)) {
            throw new InvalidArgumentException('Moderation response reason must be a string');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Moderation response reason must be a non-empty string');
        }

        return new self(
            allow: $allow,
            reason: trim($reason),
        );
    }
}
