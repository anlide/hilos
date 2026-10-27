<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Add request; the rules owner validates the raw text at the write boundary. */
final class HilosModerationRuleAddActionDTO extends ActionPayloadDTO
{
    public const string kind = 'kind';
    public const string rule = 'rule';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $kind Published moderation catalog key
     * @param string $rule Raw rule text, validated by the ORM
     */
    public function __construct(public readonly string $kind, public readonly string $rule)
    {
    }

    /** @return string Browser add action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_MODERATION_RULE_ADD;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Typed kind and unmodified draft
     * @throws InvalidFormatException When the data section, kind or rule has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The rule action needs an object payload.');
        }

        return new static(self::requireString($inner, self::kind), self::requireString($inner, self::rule));
    }

    /** @return array<string, mixed> Add payload without envelope metadata */
    public function toArray(): array
    {
        return [self::kind => $this->kind, self::rule => $this->rule];
    }
}
