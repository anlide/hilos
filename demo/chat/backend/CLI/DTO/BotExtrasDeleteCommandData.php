<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\DTO;

use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Cleanup of inactive extra bots belonging to one test marker. */
final class BotExtrasDeleteCommandData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $marker Lowercase alphanumeric test marker of 8 to 32 characters
     * @throws InvalidFormatException When the marker is invalid
     */
    public function __construct(public readonly string $marker)
    {
        if (preg_match(ChatCommandConstants::BOT_EXTRA_MARKER_PATTERN, $marker) !== 1) {
            throw new InvalidFormatException('Deleting bot extras needs a marker of 8 to 32 lowercase letters or digits');
        }
    }

    /** @return array{marker: string} Command payload */
    public function toArray(): array
    {
        return [ChatCommandConstants::marker => $this->marker];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed cleanup request
     * @throws InvalidFormatException When the marker is absent, mistyped or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, ChatCommandConstants::marker));
    }
}
