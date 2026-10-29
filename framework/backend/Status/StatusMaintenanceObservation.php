<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusException;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/** Detached facts of one occupied interval; no operation, node or private primary key. */
final readonly class StatusMaintenanceObservation
{
    public const int FORMAT_VERSION = 1;
    private const int PUBLIC_ID_BYTES = 16;
    private const string PUBLIC_ID_PATTERN = '/^[a-f0-9]{32}$/D';
    private const string DEFAULT_TITLE = 'Maintenance';
    private const int MAX_TITLE_CHARACTERS = 200;

    /**
     * @param StatusPublicMaintenancePeriod $period Sanitized observed facts
     * @param ?StatusPublicWork $work Sanitized announcement snapshot when proven
     * @throws StatusException When public identities or the saved announcement disagree
     */
    public function __construct(public StatusPublicMaintenancePeriod $period, public ?StatusPublicWork $work)
    {
        if (preg_match(self::PUBLIC_ID_PATTERN, $period->id) !== 1
            || ($period->workId !== null && preg_match(self::PUBLIC_ID_PATTERN, $period->workId) !== 1)
            || ($work !== null && ($work->id !== $period->workId || $work->title !== $period->title
                || $work->componentKeys !== $period->componentKeys
                || $period->startedAt < $work->startsAt || $period->startedAt >= $work->endsAt))) {
            throw new StatusException('Maintenance transit requires consistent opaque public identities and snapshots.');
        }
        if (!mb_check_encoding($period->title, 'UTF-8') || trim($period->title) === ''
            || mb_strlen($period->title, 'UTF-8') > self::MAX_TITLE_CHARACTERS) {
            throw new StatusException('Maintenance transit requires a valid public title.');
        }
        foreach ($period->componentKeys as $key) {
            if (preg_match(StatusComponentDefinition::KEY_PATTERN, $key) !== 1) {
                throw new StatusException('Maintenance transit contains an invalid component key.');
            }
        }
    }

    /**
     * The caller proves a complete announcement snapshot and the actual start before classifying it.
     *
     * @param int $startedAt First proven occupied moment, UTC epoch milliseconds
     * @param int $observedAt Current occupied observation, UTC epoch milliseconds
     * @param bool $announcementKnown Whether the full snapshot and its relationship to the start are proven
     * @param ?StatusPublicWork $work Announcement whose half-open window covers the start, or null
     * @return self New active observation with a secure public identity
     * @throws StatusException When the supplied facts or announcement window are invalid
     * @throws RandomException When secure entropy is unavailable
     */
    public static function start(int $startedAt, int $observedAt, bool $announcementKnown, ?StatusPublicWork $work): self
    {
        if ($work !== null && (!$announcementKnown || $startedAt < $work->startsAt || $startedAt >= $work->endsAt)) {
            throw new StatusException('Maintenance announcement must be proven and cover the observed start.');
        }
        return new self(new StatusPublicMaintenancePeriod(
            RandomHelper::secureHex(self::PUBLIC_ID_BYTES), $work?->id, $announcementKnown,
            $work?->title ?? self::DEFAULT_TITLE, $work?->componentKeys ?? [], $startedAt, $observedAt, null, null,
        ), $work);
    }

    /**
     * Neither phase changes nor a projected start change creates another period.
     *
     * @param int $nowMs Current occupied observation in UTC epoch milliseconds
     * @return self Same identity and announcement, with a monotone last occupied observation
     * @throws StatusException When called on a completed observation
     */
    public function observe(int $nowMs): self
    {
        if ($this->period->completionObservedAt !== null) {
            throw new StatusException('Completed maintenance cannot become active again.');
        }
        return new self(new StatusPublicMaintenancePeriod(
            $this->period->id, $this->period->workId, $this->period->announcementKnown, $this->period->title,
            $this->period->componentKeys, $this->period->startedAt, max($this->period->lastObservedAt, $nowMs), null, null,
        ), $this->work);
    }

    /**
     * @param int $nowMs First proven inactive observation in UTC epoch milliseconds
     * @param bool $continuous Whether this owner observed the transition without restart or a missing source
     * @return self Completed facts; a gap never invents an exact end
     * @throws StatusException When completion predates the last occupied fact
     */
    public function complete(int $nowMs, bool $continuous): self
    {
        if ($this->period->completionObservedAt !== null) {
            return $this;
        }
        return new self(new StatusPublicMaintenancePeriod(
            $this->period->id, $this->period->workId, $this->period->announcementKnown, $this->period->title,
            $this->period->componentKeys, $this->period->startedAt, $this->period->lastObservedAt,
            $continuous ? $nowMs : null, $nowMs,
        ), $this->work);
    }

    /**
     * @param string $json Private transit contents
     * @return self Strict format-v1 sanitized observation
     * @throws StatusException When JSON, version, fields or facts are malformed
     */
    public static function fromJson(string $json): self
    {
        $data = StatusPublicJson::fields(StatusPublicJson::decode($json), ['formatVersion', 'period', 'work']);
        if ($data['formatVersion'] !== self::FORMAT_VERSION) {
            throw new StatusException('Unsupported maintenance observation format.');
        }
        return new self(
            StatusPublicMaintenancePeriod::fromArray(StatusPublicJson::fields($data['period'], [
                'id', 'workId', 'announcementKnown', 'title', 'componentKeys', 'startedAt', 'lastObservedAt',
                'endedAt', 'completionObservedAt',
            ])),
            $data['work'] === null ? null : StatusPublicWork::fromArray(StatusPublicJson::fields($data['work'], [
                'id', 'title', 'description', 'componentKeys', 'startsAt', 'endsAt',
            ])),
        );
    }

    /** @return array<string, mixed> Private sanitized format-v1 payload */
    public function toArray(): array
    {
        return ['formatVersion' => self::FORMAT_VERSION, 'period' => $this->period->toArray(), 'work' => $this->work?->toArray()];
    }
}
