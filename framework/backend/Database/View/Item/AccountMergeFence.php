<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\AccountMergeFenceActions;
use Hilos\Database\Object\Item\AccountMergeFence as ObjectAccountMergeFence;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectAccountMergeFence>
 * @property-read AccountMergeFenceActions $actions
 * @property-read string $collectionKey
 * @property-read int $rowId
 * @property-read int $operationId
 * @property-read int $assignmentId
 * @property-read int $ownerUserId
 * @property-read int $revision
 * @property-read bool $deleted
 */
class AccountMergeFence extends DbItem
{
    /**
     * @param string $name Metadata field
     * @return mixed Stored scalar or base property
     * @throws HilosException When the property is unavailable
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAccountMergeFence::collectionKey => $this->_object->collectionKey,
            ObjectAccountMergeFence::rowId => $this->_object->rowId,
            ObjectAccountMergeFence::operationId => $this->_object->operationId,
            ObjectAccountMergeFence::assignmentId => $this->_object->assignmentId,
            ObjectAccountMergeFence::ownerUserId => $this->_object->ownerUserId,
            ObjectAccountMergeFence::revision => $this->_object->revision,
            ObjectAccountMergeFence::deleted => $this->_object->deleted,
            default => parent::__get($name),
        };
    }
}
