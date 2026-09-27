<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LegalAcceptancesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Collection\LegalAcceptances as ObjectLegalAcceptances;
use Hilos\Database\View\Item\LegalAcceptance;
use Hilos\Legal\LegalDocument;

/**
 * Acceptance records keyed by their primary id; composite lookups use findOne().
 *
 * @extends DbCollection<LegalAcceptance, ObjectLegalAcceptances>
 * @property-read LegalAcceptancesActions $actions
 */
class LegalAcceptances extends DbCollection
{
    public const string DB_ITEM_CLASS = LegalAcceptance::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLegalAcceptances::class;

    /**
     * @param int $userId Person whose history to read
     * @return list<LegalAcceptance> Acceptance records for a wire projection, in timestamp/id order
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query or object type is invalid
     * @throws ObjectGetIdStringNotImplementedException When a persisted object lacks its primary key
     * @throws LogicException When collection class constants are not configured
     */
    public function ofUser(int $userId): array
    {
        $acceptances = [];
        foreach ($this->objectCollection->ofUser($userId) as $object) {
            $acceptances[] = $this->getOrCreateItemForLoadedObject($object->getIdString(), $object);
        }

        return $acceptances;
    }

    /**
     * @param int $userId Person accepting
     * @param LegalDocument $document Document
     * @param string $revisionId Revision key
     * @return ?LegalAcceptance Prior acceptance of this exact revision
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query or object type is invalid
     * @throws ObjectGetIdStringNotImplementedException When a persisted object lacks its primary key
     * @throws LogicException When collection class constants are not configured
     */
    public function findOne(int $userId, LegalDocument $document, string $revisionId): ?LegalAcceptance
    {
        $object = $this->objectCollection->findOne($userId, $document, $revisionId);

        return $object === null ? null : $this->getOrCreateItemForLoadedObject($object->getIdString(), $object);
    }
}
