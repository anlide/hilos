<?php

declare(strict_types=1);

namespace Hilos\Runtime\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Source\Interest\SourceInterestRegistry;

/**
 * RtStalenessSignalData - worker -> browser payload of the RT_STALENESS frame.
 *
 * Says whether anything the connection's open page reads has stopped being kept up to date, and
 * since when (HIL-711). Addressed rather than broadcast: the answer is about what THIS page
 * reads, and a mark burning on a screen where everything is in order is a mark readers stop
 * seeing at all.
 *
 * The verdict is over the whole page and not over one collection: the sender walks everything
 * this connection reads ({@see SourceInterestRegistry::collectionsOfConsumer()}) and answers
 * with the earliest frozen moment among them. Reported per collection, lifting the mark on one
 * would put the page back to normal while a second one was still frozen.
 *
 * The moment travels in server milliseconds and is turned into the reader's own timezone on the
 * frontend, through the clock offset the session already carries.
 */
final class RtStalenessSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether anything this page reads is frozen right now. */
    public const string stale = 'stale';

    /** Payload key: server milliseconds the oldest of it froze at; null when nothing is. */
    public const string since = 'since';

    /**
     * @param bool $stale Whether anything the page reads has an unreachable source
     * @param ?int $since Server milliseconds the oldest frozen copy stopped being kept up to
     *     date; null exactly when nothing is frozen
     */
    public function __construct(
        public readonly bool $stale,
        public readonly ?int $since = null,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::stale => $this->stale,
            self::since => $this->since,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $since = $data[self::since] ?? null;

        return new static(
            stale: (bool)($data[self::stale] ?? false),
            since: is_numeric($since) ? (int)$since : null,
        );
    }
}
