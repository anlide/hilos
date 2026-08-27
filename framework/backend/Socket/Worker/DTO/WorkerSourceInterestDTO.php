<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerSourceInterestDTO - every RT collection one worker reads, reported to the daemon.
 *
 * The reading counterpart of {@see WorkerRtSourceRegisteredDTO}, and the master needs it for the
 * same reason: what a worker holds is known in that worker alone ({@see SourceInterestRegistry}),
 * while who a frame is worth sending to is decided in the master.
 *
 * The list is the WHOLE of what this worker reads and replaces whatever it reported before. A
 * delta would need an acknowledgement to be safe - a lost one would leave the master addressing
 * frames by a list neither side agrees on - and there is nothing on this path that acknowledges.
 * An empty list is therefore meaningful and is sent: it says the last reader here has gone.
 *
 * Only RT is named. The database half has no worker report at all today, and a field standing
 * empty on the wire would claim a worker reads no DB collection rather than that nobody asked
 * (HIL-750 adds it).
 */
class WorkerSourceInterestDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_SOURCE_INTEREST;

    /** @var string Payload key: RT collections this worker reads */
    public const string FIELD_RT_COLLECTIONS = 'rtCollections';

    /**
     * @param list<string> $rtCollections RT collections every consumer of this worker reads, each named once
     */
    public function __construct(
        public readonly array $rtCollections,
    ) {
    }

    /**
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_RT_COLLECTIONS => $this->rtCollections,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * A missing list reads as an empty one rather than as a malformed frame: "I read nothing"
     * is the ordinary state of a worker running no page and no agent, and it is exactly what a
     * worker of an older build says by not naming the field at all.
     *
     * @param array<string, mixed> $data Source data (rtCollections)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rtCollections = [];
        $raw = $data[self::FIELD_RT_COLLECTIONS] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $collectionKey) {
                if (is_string($collectionKey) && $collectionKey !== '') {
                    $rtCollections[] = $collectionKey;
                }
            }
        }

        return new static(rtCollections: $rtCollections);
    }
}
