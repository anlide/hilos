<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\Status\Exception\StatusException;

/** Detached occupied facts; observing completion does not invent an exact end. */
final readonly class StatusPublicMaintenancePeriod
{
    /** @var list<string> Historical catalog set in canonical string order */
    public array $componentKeys;

    /**
     * @param string $id Opaque observation identity
     * @param ?string $workId Historical public work identity, never its integer primary key
     * @param bool $announcementKnown Whether announcement classification is known
     * @param string $title Public title snapshot
     * @param list<string> $componentKeys Historical catalog key snapshot
     * @param int $startedAt First occupied observation in UTC epoch milliseconds
     * @param int $lastObservedAt Last occupied observation in UTC epoch milliseconds
     * @param ?int $endedAt Exact continuous end, or null
     * @param ?int $completionObservedAt Completion detection, null only while active
     * @throws StatusException When identity, keys, relation or observation order is invalid
     */
    public function __construct(
        public string $id,
        public ?string $workId,
        public bool $announcementKnown,
        public string $title,
        array $componentKeys,
        public int $startedAt,
        public int $lastObservedAt,
        public ?int $endedAt,
        public ?int $completionObservedAt,
    ) {
        StatusPublicJson::text($id);
        StatusPublicJson::text($title);
        $this->componentKeys = StatusPublicJson::componentKeys($componentKeys);
        if ($workId !== null) {
            StatusPublicJson::text($workId);
        }
        if ((!$announcementKnown && $workId !== null) || $startedAt < 0 || $lastObservedAt < $startedAt
            || ($completionObservedAt !== null && $completionObservedAt < $lastObservedAt)
            || ($endedAt !== null && ($completionObservedAt === null || $endedAt !== $completionObservedAt))) {
            throw new StatusException('Public maintenance must preserve announcement knowledge and observation order.');
        }
    }

    /**
     * @param StatusMaintenancePeriod $period Temporary View source; only historical scalar values survive
     * @return self Sanitized observation, independent of the current announcement row
     * @throws StatusException When public values are invalid
     */
    public static function fromPeriod(StatusMaintenancePeriod $period): self
    {
        return new self(
            $period->publicId, $period->workPublicId, $period->announcementKnown, $period->title,
            $period->componentKeys, $period->startedAt, $period->lastObservedAt, $period->endedAt, $period->completionObservedAt,
        );
    }

    /**
     * @param array<string, mixed> $data Exact decoded public fields
     * @return self Detached observation
     * @throws StatusException When a public value is invalid
     */
    public static function fromArray(array $data): self
    {
        if (!is_bool($data['announcementKnown'])) {
            throw new StatusException('Public maintenance requires explicit announcement knowledge.');
        }
        return new self(
            StatusPublicJson::text($data['id']), $data['workId'] === null ? null : StatusPublicJson::text($data['workId']),
            $data['announcementKnown'], StatusPublicJson::text($data['title']), StatusPublicJson::componentKeys($data['componentKeys']),
            StatusPublicJson::integer($data['startedAt']), StatusPublicJson::integer($data['lastObservedAt']),
            $data['endedAt'] === null ? null : StatusPublicJson::integer($data['endedAt']),
            $data['completionObservedAt'] === null ? null : StatusPublicJson::integer($data['completionObservedAt']),
        );
    }

    /** @return array<string, mixed> Complete public v2 observation fields, with explicit nulls */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'workId' => $this->workId, 'announcementKnown' => $this->announcementKnown,
            'title' => $this->title, 'componentKeys' => $this->componentKeys, 'startedAt' => $this->startedAt,
            'lastObservedAt' => $this->lastObservedAt, 'endedAt' => $this->endedAt, 'completionObservedAt' => $this->completionObservedAt,
        ];
    }
}
