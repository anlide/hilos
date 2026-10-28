<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Edit the captured rule only while its saved text still matches. */
final class HilosModerationRuleUpdateActionDTO extends ActionPayloadDTO
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string rule = 'rule';
    public const string expectedRule = 'expectedRule';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Captured rule primary ID
     * @param string $kind Published moderation catalog key
     * @param string $rule New rule text, without normalization
     * @param string $expectedRule Last saved text, without normalization
     * @throws InvalidFormatException When the rule ID is not positive
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $rule,
        public readonly string $expectedRule,
    ) {
        if ($id <= 0) {
            throw new InvalidFormatException('Invalid rule id: ' . $id);
        }
    }

    /** @return string Browser update action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Typed target, raw draft and saved text
     * @throws InvalidFormatException When a required field or wrapper is absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireInt($inner, self::id),
            self::requireString($inner, self::kind),
            self::requireString($inner, self::rule),
            self::requireString($inner, self::expectedRule),
        );
    }

    /** @return array<string, int|string> Update payload without envelope metadata */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::kind => $this->kind,
            self::rule => $this->rule,
            self::expectedRule => $this->expectedRule,
        ];
    }
}
