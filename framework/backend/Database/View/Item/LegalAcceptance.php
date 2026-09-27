<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\LegalAcceptance as ObjectLegalAcceptance;
use Hilos\HilosException;

/**
 * Read-only acceptance; deliberately carries no item actions.
 *
 * @extends DbItem<ObjectLegalAcceptance>
 * @method __construct(ObjectLegalAcceptance $object)
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $document
 * @property-read string $revisionId
 * @property-read string $acceptedAt
 */
final class LegalAcceptance extends DbItem
{
    /**
     * @param string $name Scalar field
     * @return mixed Field value
     * @throws HilosException When the inherited getter refuses a field
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectLegalAcceptance::id => $this->_object->id,
            ObjectLegalAcceptance::userId => $this->_object->userId,
            ObjectLegalAcceptance::document => $this->_object->document,
            ObjectLegalAcceptance::revisionId => $this->_object->revisionId,
            ObjectLegalAcceptance::acceptedAt => $this->_object->acceptedAt,
            default => parent::__get($name),
        };
    }
}
