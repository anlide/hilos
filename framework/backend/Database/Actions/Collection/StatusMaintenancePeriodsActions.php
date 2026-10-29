<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\View\Collection\StatusMaintenancePeriods as DbCollectionStatusMaintenancePeriods;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;
use Random\RandomException;

/**
 * Joins the caller's transaction; no external side effects occur here.
 *
 * @extends DbActions<StatusMaintenancePeriod, ObjectStatusMaintenancePeriods>
 * @property-read DbCollectionStatusMaintenancePeriods $collection
 * @property-read ObjectStatusMaintenancePeriods $objectCollection
 */
class StatusMaintenancePeriodsActions extends DbActions
{

    private const int PUBLIC_ID_BYTES = 16;

    /**
     * @param ?int $workId Original work primary id, null when absent
     * @param ?string $workPublicId Historical public identity, null when unknown or unannounced
     * @param bool $announcementKnown Whether the announcement relation is known
     * @param string $title Public period title snapshot
     * @param list<string> $componentKeys Code-key snapshot at the JSON storage boundary
     * @param int $startedAt First occupied fact in UTC epoch milliseconds
     * @return StatusMaintenancePeriod New open observed period
     * @throws RandomException When secure entropy is unavailable
     * @throws HilosException When validation, ownership or persistence refuses creation
     * @throws JsonException When snapshot serialization fails
     */
    public function create(
        ?int $workId,
        ?string $workPublicId,
        bool $announcementKnown,
        string $title,
        array $componentKeys,
        int $startedAt,
    ): StatusMaintenancePeriod {
        return $this->import(
            RandomHelper::secureHex(self::PUBLIC_ID_BYTES), $workId, $workPublicId, $announcementKnown,
            $title, $componentKeys, $startedAt, $startedAt, null, null,
        );
    }

    /**
     * Public identity makes replay idempotent; imported observations may only advance.
     *
     * @param string $publicId Stable observation identity from the import boundary
     * @param ?int $workId Original work primary id, null when absent
     * @param ?string $workPublicId Historical work identity surviving deletion or restore
     * @param bool $announcementKnown Whether the announcement relation is known
     * @param string $title Public title snapshot
     * @param list<string> $componentKeys Code-key snapshot at the JSON storage boundary
     * @param int $startedAt First occupied fact in UTC epoch milliseconds
     * @param int $lastObservedAt Last occupied fact in UTC epoch milliseconds
     * @param ?int $endedAt Exact end when continuously observed
     * @param ?int $completionObservedAt Proven completion detection, null while unfinished
     * @return StatusMaintenancePeriod Persisted observation, existing on replay
     * @throws HilosException When validation, identity, ownership or persistence refuses import
     * @throws JsonException When snapshot serialization fails
     */
    public function import(
        string $publicId,
        ?int $workId,
        ?string $workPublicId,
        bool $announcementKnown,
        string $title,
        array $componentKeys,
        int $startedAt,
        int $lastObservedAt,
        ?int $endedAt,
        ?int $completionObservedAt,
    ): StatusMaintenancePeriod {
        $this->ensureCanCreate();
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)
            || ($workPublicId !== null && !preg_match('/^[a-f0-9]{32}$/D', $workPublicId))
            || (!$announcementKnown && ($workPublicId !== null || $workId !== null))
            || ($workId !== null && $workPublicId === null)) {
            throw new ValidationException('Maintenance must retain its original announcement identity.');
        }
        if (!mb_check_encoding($title, 'UTF-8') || trim($title) === '' || mb_strlen($title, 'UTF-8') > 200) {
            throw new ValidationException('Enter a maintenance title of 1 to 200 characters.');
        }
        ObjectStatusMaintenancePeriod::validateObservation($startedAt, $lastObservedAt, $endedAt, $completionObservedAt);
        $snapshot = json_encode(ObjectStatusWorkComponent::normalizeKeys($componentKeys), JSON_THROW_ON_ERROR);
        $existing = $this->collection->findByPublicId($publicId);
        if ($existing !== null) {
            if ($existing->workPublicId !== $workPublicId || $existing->announcementKnown !== $announcementKnown
                || $existing->title !== $title || $existing->componentKeysJson !== $snapshot || $existing->startedAt !== $startedAt) {
                throw new ValidationException('This maintenance identity belongs to a different observation.');
            }
            $existing->actions->reconcile($lastObservedAt, $endedAt, $completionObservedAt);
            return $existing;
        }
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $period = $objectClass::create();
        $period->public_id = $publicId;
        $period->work_id = $workId;
        $period->work_public_id = $workPublicId;
        $period->announcement_known = $announcementKnown;
        $period->title = $title;
        $period->component_keys_json = $snapshot;
        $period->started_at = $startedAt;
        $period->last_observed_at = $lastObservedAt;
        $period->ended_at = $endedAt;
        $period->completion_observed_at = $completionObservedAt;
        $period->sync();
        $this->addObjectToCollection($period);
        return $this->createDbItemFromObject($period);
    }
}
