<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalDocumentStanding;
use Hilos\Legal\LegalStandingResolver;

/**
 * One acceptance write boundary for registration and re-consent (HIL-498).
 * A test holds a person on a named revision with hold() (test:legal:hold, HIL-324).
 *
 * Registration calls record() inside its account transaction.
 * TODO(HIL-500): re-consent calls accept().
 */
final class LegalAcceptanceCommands extends AbstractLibraryCommands
{
    /**
     * Participates in the caller's transaction without committing or publishing state.
     * The entire map is validated before writing its first row.
     *
     * @param int $userId Person accepting
     * @param array<string, string> $revisionIdsByDocument Boundary map of document values to exact revision ids
     * @throws HilosException When a declaration or acceptance write is refused
     */
    public function record(int $userId, array $revisionIdsByDocument): void
    {
        foreach ($revisionIdsByDocument as $key => $revisionId) {
            $document = LegalDocument::tryFrom($key);
            if ($document === null) {
                throw new UnknownRevisionException("Unknown legal document {$key}");
            }
            LegalCatalogResolver::revision($document, $revisionId);
        }
        foreach ($revisionIdsByDocument as $key => $revisionId) {
            Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::from($key), $revisionId);
        }
    }

    /**
     * Writes the exact named revisions atomically, then publishes the committed state.
     *
     * @param int $userId Person accepting
     * @param array<string, string> $revisionIdsByDocument Boundary map of document values to exact revision ids
     * @throws HilosException When the write, transaction or state publication fails
     */
    public function accept(int $userId, array $revisionIdsByDocument): void
    {
        Database::transactionStart();
        try {
            $this->record($userId, $revisionIdsByDocument);
            Database::transactionCommit();
        } catch (HilosException $e) {
            Database::transactionRollback();
            throw $e;
        }
        $this->publishState($userId);
    }

    /**
     * Test-only hold of an exact revision: forgets later acceptances, records the revision if missing,
     * and returns the resulting document standing.
     *
     * @param int $userId Person holding the revision
     * @param string $documentKey Document key ('terms' or 'privacy')
     * @param string $revisionId Exact revision to hold
     * @return LegalDocumentStanding Standing of the document after the update
     * @throws ValidationException When the document is not declared in this installation
     * @throws ItemNotFoundForUpdateException When the person does not exist
     * @throws LegalException When the catalog declaration fails or revision is unknown
     * @throws HilosException When the transaction or write fails
     */
    public function hold(int $userId, string $documentKey, string $revisionId): LegalDocumentStanding
    {
        $document = LegalDocument::tryFrom($documentKey);
        if ($document === null || !in_array($document, LegalCatalogResolver::documents(), true)) {
            throw new ValidationException("Legal document {$documentKey} is not declared in this installation");
        }

        if ($userId <= 0 || (Hilos::$db->users[$userId] ?? null) === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        LegalCatalogResolver::revision($document, $revisionId);

        Database::transactionStart();
        try {
            Hilos::$db->legalAcceptances->actions->forgetLaterThan($userId, $document, $revisionId);
            Hilos::$db->legalAcceptances->actions->accept($userId, $document, $revisionId);
            Database::transactionCommit();
        } catch (HilosException $e) {
            Database::transactionRollback();
            throw $e;
        }

        $this->publishState($userId);

        $acceptedRevisionIds = [];
        foreach (Hilos::$db->legalAcceptances->ofUser($userId) as $acceptance) {
            if ($acceptance->document === $document->value) {
                $acceptedRevisionIds[] = $acceptance->revisionId;
            }
        }

        return LegalStandingResolver::standingOf($document, $acceptedRevisionIds, LegalStandingResolver::today());
    }

    /**
     * @param int $userId Person whose new state to publish
     * @throws HilosException When the state cannot be read or queued
     */
    public function publishState(int $userId): void
    {
        $this->library->sendToGroup(
            HilosSignalConstants::HILOS_LEGAL_AGREEMENTS_STATE,
            LegalAgreementsGroup::forUser($userId),
            LegalAgreementsProjector::stateFor($userId, LegalStandingResolver::today()),
        );
    }
}
