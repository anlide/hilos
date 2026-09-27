<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\DataExportActions;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\HilosException;

/**
 * Read-facing data copy; storage names stay inside the backend.
 *
 * @extends DbItem<ObjectDataExport>
 * @method __construct(ObjectDataExport $object)
 * @property-read ?int $id
 * @property-read int $userId
 * @property-read string $state
 * @property-read string $requestedAt
 * @property-read ?string $finishedAt
 * @property-read ?string $expiresAt
 * @property-read ?string $storedName
 * @property-read ?int $sizeBytes
 * @property-read DataExportActions $actions
 */
class DataExport extends DbItem
{
    /**
     * @param string $name Persisted property name
     * @return mixed Row value or inherited item property
     * @throws HilosException When an unknown property or an invalid action is requested
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectDataExport::id => $this->_object->id,
            ObjectDataExport::userId => $this->_object->userId,
            ObjectDataExport::state => $this->_object->state,
            ObjectDataExport::requestedAt => $this->_object->requestedAt,
            ObjectDataExport::finishedAt => $this->_object->finishedAt,
            ObjectDataExport::expiresAt => $this->_object->expiresAt,
            ObjectDataExport::storedName => $this->_object->storedName,
            ObjectDataExport::sizeBytes => $this->_object->sizeBytes,
            default => parent::__get($name),
        };
    }
}
