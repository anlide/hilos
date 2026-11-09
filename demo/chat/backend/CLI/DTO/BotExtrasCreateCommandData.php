<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\DTO;

use Demo\Chat\Constants\ChatCommandConstants;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Inactive extra bots belonging to one test marker. */
final class BotExtrasCreateCommandData extends BaseDTO implements SignalDataInterface
{
    private const int MAX_EXTRAS_COUNT = 20;

    /**
     * @param string $marker Lowercase alphanumeric test marker of 8 to 32 characters
     * @param int $count Number of inactive bots to create
     * @throws InvalidFormatException When the marker is invalid or the count is outside 1 to 20
     */
    public function __construct(public readonly string $marker, public readonly int $count)
    {
        if (preg_match(ChatCommandConstants::BOT_EXTRA_MARKER_PATTERN, $marker) !== 1) {
            throw new InvalidFormatException('Creating bot extras needs a marker of 8 to 32 lowercase letters or digits');
        }
        if ($count < 1 || $count > self::MAX_EXTRAS_COUNT) {
            throw new InvalidFormatException('Bot extras count must be between 1 and ' . self::MAX_EXTRAS_COUNT);
        }
    }

    /** @return array{marker: string, count: int} Command payload */
    public function toArray(): array
    {
        return [ChatCommandConstants::marker => $this->marker, ChatCommandConstants::count => $this->count];
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Strictly parsed creation request
     * @throws InvalidFormatException When a field is absent, mistyped or outside its allowed range
     */
    public static function fromArray(array $data): static
    {
        return new static(
            marker: self::requireString($data, ChatCommandConstants::marker),
            count: self::requireInt($data, ChatCommandConstants::count),
        );
    }
}
