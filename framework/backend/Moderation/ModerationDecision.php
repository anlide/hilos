<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Utils\Helpers\JsonHelper;
use JsonException;
use stdClass;

/**
 * A model's verdict on one check, read against the kind it was asked about (HIL-1625).
 *
 * The model answers one JSON object with two keys: `allow` and `reason`. An allowed check says
 * {@see ModerationReason::OK}; a refused one names a reason the kind declares. Any other answer is
 * no verdict at all, and the moderator turns it into {@see self::unavailable()} - never into a
 * permission, and never into a refusal of the content.
 */
final readonly class ModerationDecision
{
    /** Key of the verdict in the model's answer. */
    public const string allow = 'allow';

    /** Key of the reason code in the model's answer. */
    public const string reason = 'reason';

    /**
     * @param bool $allow Whether the content passed
     * @param string $reason Reason code: {@see ModerationReason::OK}, a reason of the kind, or
     *     {@see ModerationReason::SERVICE_UNAVAILABLE}
     */
    public function __construct(
        public bool $allow,
        public string $reason,
    ) {
    }

    /**
     * Reads the model's answer on a check of one kind.
     *
     * Both keys are required and nothing is defaulted: a missing reason is not read as `ok`, so a
     * model that forgot half the answer gives no verdict rather than a permission.
     *
     * @param string $text Raw model output, which may wrap the object in other text
     * @param ModerationKind $kind Kind the check was asked about
     * @return self Verdict the model gave
     * @throws InvalidFormatException When the answer holds no JSON object, or `allow` is not a boolean or `reason`
     *     not a string
     * @throws ValidationException When an allowed check carries a reason other than ok, or a refused one a reason
     *     the kind does not declare
     */
    public static function fromModelOutput(string $text, ModerationKind $kind): self
    {
        $json = JsonHelper::extractJsonObject($text)
            ?? throw new InvalidFormatException('The moderation answer holds no JSON object');
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidFormatException('The moderation answer is not valid JSON', previous: $e);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidFormatException('The moderation answer is not a JSON object');
        }
        $allow = $decoded->{self::allow} ?? null;
        if (!is_bool($allow)) {
            throw new InvalidFormatException('The moderation answer carries no boolean ' . self::allow);
        }
        $reason = $decoded->{self::reason} ?? null;
        if (!is_string($reason)) {
            throw new InvalidFormatException('The moderation answer carries no string ' . self::reason);
        }
        if ($allow && $reason !== ModerationReason::OK) {
            throw new ValidationException("The moderation answer allows the check with the reason {$reason}");
        }
        if (!$allow && !in_array($reason, $kind->reasons, true)) {
            throw new ValidationException(
                "The moderation answer refuses the check with the reason {$reason}, which {$kind->key} does not declare",
            );
        }

        return new self($allow, $reason);
    }

    /**
     * The verdict given when there is none to give.
     *
     * @return self Refusal for {@see ModerationReason::SERVICE_UNAVAILABLE}
     */
    public static function unavailable(): self
    {
        return new self(false, ModerationReason::SERVICE_UNAVAILABLE);
    }
}
