<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerSourceInterestDTO - every collection one worker reads, reported to the daemon.
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
 * Both halves travel in the one frame rather than in a frame each (HIL-750). The two lists are
 * moved by the same events - an agent starting, a page subscribing, a feature mounting - so
 * splitting them would only create two reports that have to be kept in agreement with each other.
 */
class WorkerSourceInterestDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_SOURCE_INTEREST;

    /** @var string Payload key: RT collections this worker reads */
    public const string FIELD_RT_COLLECTIONS = 'rtCollections';

    /** @var string Payload key: DB collections this worker reads */
    public const string FIELD_DB_COLLECTIONS = 'dbCollections';

    /**
     * @param list<string> $rtCollections RT collections every consumer of this worker reads, each named once
     * @param list<string> $dbCollections DB collections every consumer of this worker reads, each named once
     */
    public function __construct(
        public readonly array $rtCollections,
        public readonly array $dbCollections,
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
            self::FIELD_DB_COLLECTIONS => $this->dbCollections,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * A missing list reads as an empty one rather than as a malformed frame: "I read nothing"
     * is the ordinary state of a worker running no page and no agent, and it is exactly what a
     * worker of an older build says by not naming the field at all.
     *
     * @param array<string, mixed> $data Source data (rtCollections, dbCollections)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rtCollections: self::collectionList($data, self::FIELD_RT_COLLECTIONS),
            dbCollections: self::collectionList($data, self::FIELD_DB_COLLECTIONS),
        );
    }

    /**
     * Reads one of the two collection lists out of the payload.
     *
     * Shared by both halves so neither can grow its own idea of what a malformed entry is: the
     * lists say the same thing about two kinds of source, and a difference between them here
     * would show up as one kind quietly reporting more than the other.
     *
     * @param array<string, mixed> $data Source data
     * @param string $field Payload key of the list to read
     * @return list<string> Collection keys named in that list, empty when it is absent
     */
    private static function collectionList(array $data, string $field): array
    {
        $collections = [];
        $raw = $data[$field] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $collectionKey) {
                if (is_string($collectionKey) && $collectionKey !== '') {
                    $collections[] = $collectionKey;
                }
            }
        }

        return $collections;
    }
}
