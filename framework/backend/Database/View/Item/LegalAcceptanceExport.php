<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\LegalAcceptanceExportActions;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\HilosException;

/**
 * Read-facing order of acceptance records; storage names stay inside the backend.
 *
 * @extends DbItem<ObjectLegalAcceptanceExport>
 * @method __construct(ObjectLegalAcceptanceExport $object)
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $state
 * @property-read ?string $document
 * @property-read ?string $revisionId
 * @property-read ?string $search
 * @property-read string $requestedAt
 * @property-read ?string $finishedAt
 * @property-read ?string $expiresAt
 * @property-read ?string $storedName
 * @property-read ?int $sizeBytes
 * @property-read ?int $records
 * @property-read LegalAcceptanceExportActions $actions
 */
class LegalAcceptanceExport extends DbItem
{
    /**
     * @param string $name Persisted property name
     * @return mixed Row value or inherited item property
     * @throws HilosException When an unknown property or an invalid action is requested
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectLegalAcceptanceExport::id => $this->_object->id,
            ObjectLegalAcceptanceExport::userId => $this->_object->userId,
            ObjectLegalAcceptanceExport::state => $this->_object->state,
            ObjectLegalAcceptanceExport::document => $this->_object->document,
            ObjectLegalAcceptanceExport::revisionId => $this->_object->revisionId,
            ObjectLegalAcceptanceExport::search => $this->_object->search,
            ObjectLegalAcceptanceExport::requestedAt => $this->_object->requestedAt,
            ObjectLegalAcceptanceExport::finishedAt => $this->_object->finishedAt,
            ObjectLegalAcceptanceExport::expiresAt => $this->_object->expiresAt,
            ObjectLegalAcceptanceExport::storedName => $this->_object->storedName,
            ObjectLegalAcceptanceExport::sizeBytes => $this->_object->sizeBytes,
            ObjectLegalAcceptanceExport::records => $this->_object->records,
            default => parent::__get($name),
        };
    }
}
