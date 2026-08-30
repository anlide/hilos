<?php

declare(strict_types=1);

namespace Hilos\Log\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\LogAggregatorAgent;

/**
 * {@see AbstractHilosLogsAgent} → {@see LogAggregatorAgent} payload for the logs_index_watch signal
 * (HIL-756).
 *
 * One field, because one number says everything the aggregator needs: how many people are looking
 * at the log screens right now. A non-zero count both opens the subscription and renews its lease;
 * zero cancels it. There is no unsubscribe frame to lose, and a subscriber whose process died is
 * forgotten when the lease runs out rather than when it says goodbye.
 *
 * Nothing but the count travels. Which pages the viewers are on does not narrow what the aggregator
 * would send - the picture is handed over whole and the subscriber mirrors it - so a breakdown here
 * would be a field with no reader.
 */
final class LogsIndexWatchSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: number of viewers the sender has on the log screens right now. */
    public const string viewers = 'viewers';

    /**
     * @param int $viewers Number of viewers the sender has on the log screens, zero to cancel
     */
    public function __construct(
        public readonly int $viewers,
    ) {
    }

    /**
     * @return array<string, mixed> Claim as it goes out to the aggregator
     */
    public function toArray(): array
    {
        return [
            self::viewers => $this->viewers,
        ];
    }

    /**
     * Reads a claim back from its wire form.
     *
     * A negative count is refused rather than clamped to zero: the two are opposite instructions -
     * cancel the subscription, or keep it - and guessing which was meant would silence a screen
     * somebody is looking at, or feed one nobody is.
     *
     * @param array<string, mixed> $data Wire form of one claim of interest
     * @return static Restored payload
     * @throws InvalidFormatException When the count is absent, of the wrong type, or negative
     */
    public static function fromArray(array $data): static
    {
        $viewers = self::requireInt($data, self::viewers);
        if ($viewers < 0) {
            throw new InvalidFormatException('Logs index watch carries a negative viewer count');
        }

        return new static(viewers: $viewers);
    }
}
