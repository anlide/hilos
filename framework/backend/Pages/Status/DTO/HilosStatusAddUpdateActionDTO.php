<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Append request; the Status owner validates plain text at the write boundary. */
final class HilosStatusAddUpdateActionDTO extends ActionPayloadDTO
{
    public const string incidentId = 'incidentId';
    public const string text = 'text';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $incidentId Persisted incident primary id
     * @param string $text Plain team text, validated by StatusUpdateText at the owner
     * @throws InvalidFormatException When the target is not positive
     */
    public function __construct(public readonly int $incidentId, public readonly string $text)
    {
        if ($incidentId <= 0) {
            throw new InvalidFormatException('Choose an incident.');
        }
    }

    /** @return string Browser append action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STATUS_ADD_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Typed target and unmodified draft
     * @throws InvalidFormatException When the data section, target or text has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The update action needs an object payload.');
        }

        return new static(self::requireInt($inner, self::incidentId), self::requireString($inner, self::text));
    }

    /** @return array<string, mixed> Append payload without envelope metadata */
    public function toArray(): array
    {
        return [self::incidentId => $this->incidentId, self::text => $this->text];
    }
}
