<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

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
     * Parses a moderation model output object into a typed decision.
     *
     * @param string $text Raw model output
     */
    public static function fromModelOutput(string $text): ?self
    {
        $json = JsonHelper::extractJsonObject($text);
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$decoded instanceof stdClass || !property_exists($decoded, self::KEY_ALLOW)) {
            return null;
        }

        $allow = $decoded->{self::KEY_ALLOW};
        if (!is_bool($allow)) {
            return null;
        }

        $reason = property_exists($decoded, self::KEY_REASON)
            ? $decoded->{self::KEY_REASON}
            : '';
        if (!is_string($reason)) {
            return null;
        }

        return new self(
            allow: $allow,
            reason: $reason,
        );
    }
}
