<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorTrustRestrictions as ObjectSecondFactorTrustRestrictions;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorTrustRestrictions as DbCollectionSecondFactorTrustRestrictions;
use Hilos\Database\View\Item\SecondFactorTrustRestriction;

/**
 * SecondFactorTrustRestrictionsActions - write operations for the SecondFactorTrustRestrictions collection (HIL-1900).
 *
 * Recording one shortening of browser trust. Rows are never changed or removed afterwards, so
 * adding is the only write there is.
 *
 * @extends DbActions<SecondFactorTrustRestriction, ObjectSecondFactorTrustRestrictions>
 * @property-read DbCollectionSecondFactorTrustRestrictions $collection
 * @property-read ObjectSecondFactorTrustRestrictions $objectCollection
 */
class SecondFactorTrustRestrictionsActions extends DbActions
{
    /**
     * Records that trusts granted no later than a save moment live no longer than a term after it.
     * Joins the caller's transaction when one is open.
     *
     * @param int $savedAt Moment the setting was saved (epoch milliseconds)
     * @param int $termDays Days a trust granted by then may still live; 0 takes it out
     * @return int Primary id of the stored restriction
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws ExternalWriterException When the mounted Entity declares an external database writer
     * @throws InvalidArgumentException When the term is outside 0 to the longest trust term, or the queued DB-sync signal is invalid
     * @throws DatabaseException When the binding is unresolved, a foreign transaction is open, or the insert fails
     * @throws WriteNotAllowedException When no truth source in this process may write the row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function record(int $savedAt, int $termDays): int
    {
        $this->ensureCanCreate();
        if ($termDays < 0 || $termDays > SecondFactorSettings::TRUST_DAYS_MAX) {
            throw new InvalidArgumentException(sprintf(
                'A trust restriction term must be 0 to %d days, %d given',
                SecondFactorSettings::TRUST_DAYS_MAX,
                $termDays,
            ));
        }

        return $this->objectCollection->record($savedAt, $termDays);
    }
}
