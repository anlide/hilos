<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Removal of the captured rule with its literal confirmation text. */
final class HilosModerationRuleDeleteActionDTO extends ActionPayloadDTO
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string expectedRule = 'expectedRule';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $id Captured rule primary ID
     * @param string $kind Published moderation catalog key
     * @param string $expectedRule Exact confirmation text, without normalization
     * @throws InvalidFormatException When the rule ID is not positive
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $expectedRule,
    ) {
        if ($id <= 0) {
            throw new InvalidFormatException('Invalid rule id: ' . $id);
        }
    }

    /** @return string Browser delete action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_MODERATION_RULE_DELETE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Typed target and unmodified confirmation
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
            self::requireString($inner, self::expectedRule),
        );
    }

    /** @return array<string, int|string> Delete payload without envelope metadata */
    public function toArray(): array
    {
        return [self::id => $this->id, self::kind => $this->kind, self::expectedRule => $this->expectedRule];
    }
}
