<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\LegalAcceptances as ObjectLegalAcceptances;
use Hilos\Database\View\Collection\LegalAcceptances as DbCollectionLegalAcceptances;
use Hilos\Database\View\Item\LegalAcceptance;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Immutable acceptance records: create once per person/document/revision, erase with the account.
 * Besides erasure, the one removal is test-only: forgetLaterThan() backs test:legal:hold (HIL-324).
 *
 * @extends DbActions<LegalAcceptance, ObjectLegalAcceptances>
 * @property-read DbCollectionLegalAcceptances $collection
 * @property-read ObjectLegalAcceptances $objectCollection
 */
class LegalAcceptancesActions extends DbActions
{
    /**
     * @param int $userId Person accepting
     * @param LegalDocument $document Document accepted
     * @param string $revisionId Exact declared revision
     * @return LegalAcceptance First acceptance of this revision, retaining its original timestamp
     * @throws HilosException When the revision, insert, ownership or representation is invalid
     */
    public function accept(int $userId, LegalDocument $document, string $revisionId): LegalAcceptance
    {
        LegalCatalogResolver::revision($document, $revisionId);
        $existing = $this->collection->findOne($userId, $document, $revisionId);
        if ($existing !== null) {
            return $existing;
        }
        $this->ensureCanCreateInSet((string)$userId);

        return $this->createDbItemFromObject($this->objectCollection->record(
            $userId, $document, $revisionId, TimeHelper::getSqlDateTime(),
        ));
    }

    /**
     * @param int $userId Person being erased
     * @throws HilosException When the removal is refused or fails
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $this->objectCollection->deleteForUser($userId);
    }

    /**
     * Test-only removal for test:legal:hold (HIL-324).
     *
     * Acceptances declared later than the named revision are forgotten, so that
     * the person holds the named revision.
     *
     * @param int $userId Person whose acceptances are modified
     * @param LegalDocument $document Declared document
     * @param string $revisionId Revision to hold
     * @return list<string> Deleted revision ids
     * @throws HilosException When the revision is unknown or the removal fails
     */
    public function forgetLaterThan(int $userId, LegalDocument $document, string $revisionId): array
    {
        LegalCatalogResolver::revision($document, $revisionId);
        $revisions = LegalCatalogResolver::revisions($document);
        $found = false;
        $later = [];
        foreach ($revisions as $rev) {
            if ($found) {
                $later[] = $rev->id;
            } elseif ($rev->id === $revisionId) {
                $found = true;
            }
        }
        if ($later === []) {
            return [];
        }
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        return $this->objectCollection->deleteOfRevisions($userId, $document, $later);
    }
}
