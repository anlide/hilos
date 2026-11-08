<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Library\DTO\LegalReconsentReplyDTO;
use Hilos\Auth\StepUp\StepUpGate;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalDocumentStanding;
use Hilos\Legal\LegalReconsentProjector;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Users\DTO\UserLegalHoldForgetSignalData;
use Hilos\WiringRefusal;

/**
 * One acceptance write boundary for registration and re-consent (HIL-498).
 * A test hold creates the held acceptance before asking the personal owner to remove later ones (HIL-1899).
 *
 * Registration calls record() inside its account transaction.
 * Re-consent calls acceptCurrent(), which checks the revisions are current and calls accept().
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
     * What the "the terms have changed" screen shows the person on this connection (HIL-500, HIL-1331).
     *
     * Resolves the refusal setting, the waiting documents, and the person's confirmed address for the
     * "Who is signed in" plaque. Under impersonation the address is null: an administrator cannot accept
     * on the person's behalf and the address is not needed by nor exposed to the administrator.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @return LegalReconsentReplyDTO The refusal setting, waiting documents, and confirmed address
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws SettingException When the refusal setting is invalid
     * @throws HilosException When the session lookup, the setting, the acceptance records or the identity cannot be read
     */
    public function reconsent(string $acceptKey): LegalReconsentReplyDTO
    {
        $acting = $this->actingUser($acceptKey);
        $identifier = StepUpGate::isImpersonated($acting->sessionToken)
            ? null
            : Hilos::$db->identities->findConfirmedAddressByUser($acting->userId);

        return new LegalReconsentReplyDTO(
            LegalSettings::refusal(),
            LegalReconsentProjector::documents($acting->userId, LegalStandingResolver::today()),
            $identifier,
        );
    }

    /**
     * The person on this connection accepts the revisions in force of the documents the screen showed (HIL-500).
     *
     * Only the person accepts: under impersonation the acceptance would be a forged record of their
     * consent, so it is refused the way cancelling their deletion is. A revision that is no longer the
     * one in force - a new catalog landed while they read - is refused with the words that send them
     * back to the differences; nothing is written until every document named passes.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param array<string, string> $revisionIdsByDocument Boundary map of document values to the revisions the screen showed
     * @throws ItemNotFoundForUpdateException When the acting connection has no session or is anonymous
     * @throws ValidationException When impersonated, the set is empty, a document is not declared, or a revision is not the one in force
     * @throws LegalException When the catalog declaration is faulty
     * @throws HilosException When the session lookup, the write, the transaction or the state publication fails
     */
    public function acceptCurrent(string $acceptKey, array $revisionIdsByDocument): void
    {
        $acting = $this->actingUser($acceptKey);
        if (StepUpGate::isImpersonated($acting->sessionToken)) {
            throw new ValidationException(StepUpMessages::IMPERSONATED);
        }
        if ($revisionIdsByDocument === []) {
            throw new ValidationException('Name at least one document to accept');
        }

        $declared = LegalCatalogResolver::documents();
        foreach ($revisionIdsByDocument as $key => $revisionId) {
            $document = LegalDocument::tryFrom((string) $key);
            if ($document === null || !in_array($document, $declared, true)) {
                throw new ValidationException("Legal document {$key} is not declared in this installation");
            }
            if ($revisionId !== LegalCatalogResolver::latestRevision($document)->id) {
                throw new ValidationException(AuthMessages::CONSENT_REVISED);
            }
        }

        $this->accept($acting->userId, $revisionIdsByDocument);
    }

    /**
     * Creates the held acceptance without publication, then asks the owner to remove later ones.
     *
     * @param int $userId Person holding the revision
     * @param string $documentKey Document key ('terms' or 'privacy')
     * @param string $revisionId Exact revision to hold
     * @param string $correlationId Parked command id
     * @throws ValidationException When the document is not declared in this installation
     * @throws ItemNotFoundForUpdateException When the person does not exist
     * @throws LegalException When the catalog declaration fails or revision is unknown
     * @throws HilosException When the transaction, acceptance write or request fails
     */
    public function beginHold(int $userId, string $documentKey, string $revisionId, string $correlationId): void
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
            Hilos::$db->legalAcceptances->actions->accept($userId, $document, $revisionId);
            Database::transactionCommit();
        } catch (WiringRefusal $refusal) {
            Database::transactionRollback();
            throw $refusal;
        } catch (HilosException $e) {
            Database::transactionRollback();
            throw $e;
        }

        $this->library->sendToAgent(
            HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET,
            new UserLegalHoldForgetSignalData(
                $userId,
                $documentKey,
                $revisionId,
                HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE,
                $correlationId,
            ),
        );
    }

    /**
     * Prepares standing and state inside the caller's finish transaction; only the prepared state waits for commit.
     *
     * @param int $userId Person holding the revision
     * @param string $documentKey Document key ('terms' or 'privacy')
     * @param string $revisionId Exact revision to hold
     * @return LegalDocumentStanding Standing after the completed hold
     * @throws HilosException When the state cannot be read or published
     */
    public function finishHold(int $userId, string $documentKey, string $revisionId): LegalDocumentStanding
    {
        $document = LegalDocument::from($documentKey);

        $acceptedRevisionIds = [];
        foreach (Hilos::$db->legalAcceptances->ofUser($userId) as $acceptance) {
            if ($acceptance->document === $document->value) {
                $acceptedRevisionIds[] = $acceptance->revisionId;
            }
        }

        $standing = LegalStandingResolver::standingOf($document, $acceptedRevisionIds, LegalStandingResolver::today());
        $state = LegalAgreementsProjector::stateFor($userId, LegalStandingResolver::today());
        Database::afterCommit(fn () => $this->library->sendToGroup(
            HilosSignalConstants::HILOS_LEGAL_AGREEMENTS_STATE,
            LegalAgreementsGroup::forUser($userId),
            $state,
        ));

        return $standing;
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
