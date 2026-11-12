<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\StatusSilencePeriods as ObjectStatusSilencePeriods;
use Hilos\Database\View\Collection\StatusSilencePeriods as DbCollectionStatusSilencePeriods;
use Hilos\Database\View\Item\StatusSilencePeriod;
use Hilos\HilosException;

/**
 * Append-only imports of proven closed publication silences.
 *
 * @extends DbActions<StatusSilencePeriod, ObjectStatusSilencePeriods>
 * @property-read DbCollectionStatusSilencePeriods $collection
 * @property-read ObjectStatusSilencePeriods $objectCollection
 */
class StatusSilencePeriodsActions extends DbActions
{
    /**
     * A replay preserves both boundaries; the archive has no update operation.
     *
     * @param string $publicId Opaque secureHex(16) identity retained by the importer
     * @param int $startedAt Gap start in signed UTC epoch milliseconds
     * @param int $endedAt Exclusive gap end in signed UTC epoch milliseconds
     * @return StatusSilencePeriod Persisted closed gap, existing on an identical replay
     * @throws HilosException When validation, identity, ownership or persistence refuses import
     */
    public function import(string $publicId, int $startedAt, int $endedAt): StatusSilencePeriod
    {
        $this->ensureCanCreate();
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new ValidationException('The silence period identity must contain 32 lowercase hexadecimal characters.');
        }
        if ($endedAt <= $startedAt) {
            throw new ValidationException('The silence period must end after it starts.');
        }
        $existing = $this->collection->findByPublicId($publicId);
        if ($existing !== null) {
            if ($existing->startedAt !== $startedAt || $existing->endedAt !== $endedAt) {
                throw new ValidationException('This silence period identity belongs to a different interval.');
            }
            return $existing;
        }
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $period = $objectClass::create();
        $period->public_id = $publicId;
        $period->started_at = $startedAt;
        $period->ended_at = $endedAt;
        $period->sync();
        $this->addObjectToCollection($period);
        return $this->createDbItemFromObject($period);
    }
}
