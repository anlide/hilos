<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalStandingResolver;

/**
 * One acceptance write boundary for registration and re-consent (HIL-498).
 *
 * TODO(HIL-499, HIL-500): registration calls record() inside its transaction; re-consent calls accept().
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
