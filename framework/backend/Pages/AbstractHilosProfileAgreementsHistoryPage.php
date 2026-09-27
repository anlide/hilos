<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevisionComparison;
use Hilos\Legal\LegalWire;
use Hilos\Pages\Legal\DTO\LegalRevisionTextActionDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionChangesActionDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionChangesReplyDTO;

/** Authenticated personal legal revision history (HIL-498). */
abstract class AbstractHilosProfileAgreementsHistoryPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_AGREEMENTS_HISTORY;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_AGREEMENTS_HISTORY,
    ];

    public const array READS_DB = [HilosDbContext::legalAcceptances];

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT => LegalRevisionTextActionDTO::class,
        HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES => LegalRevisionChangesActionDTO::class,
    ];

    /**
     * @param string $acceptKey Requesting connection
     * @param string $action Requested read
     * @param ActionPayloadDTO $dto Parsed revision address
     * @return ?ActionReplyDTO Revision text or comparison with its predecessor
     * @throws AgentUnknownActionException When the action name is not supported
     * @throws InvalidActionPayloadException When the DTO does not match the action
     * @throws ValidationException When the document or revision is unknown, or a first revision is compared
     * @throws HilosException When the declared catalog or a text file cannot be read
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT:
                if (!$dto instanceof LegalRevisionTextActionDTO) {
                    throw new InvalidActionPayloadException($action, LegalRevisionTextActionDTO::class, $dto);
                }

                return $this->revisionText($dto);

            case HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES:
                if (!$dto instanceof LegalRevisionChangesActionDTO) {
                    throw new InvalidActionPayloadException($action, LegalRevisionChangesActionDTO::class, $dto);
                }

                return $this->revisionChanges($dto);

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload Personal state and the page's legal section
     * @throws HilosException When the catalog, text or acceptance read fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            LegalAgreementsProjector::SECTION => LegalAgreementsProjector::stateFor($userId, LegalStandingResolver::today())->toArray(),
            LegalAgreementsProjector::REVISIONS_SECTION => LegalAgreementsProjector::revisions(),
        ]);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @throws InvalidArgumentException When the group join cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId !== null) {
            LegalAgreementsGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        }
    }

    /**
     * @param LegalRevisionTextActionDTO $dto Revision to read
     * @return LegalRevisionTextReplyDTO Composed text
     * @throws ValidationException When the address is unknown
     * @throws HilosException When the catalog or text file is invalid
     */
    private function revisionText(LegalRevisionTextActionDTO $dto): LegalRevisionTextReplyDTO
    {
        $document = $this->requireRevision($dto->document, $dto->revisionId);

        return new LegalRevisionTextReplyDTO(
            $document->value, $dto->revisionId, LegalWire::clauses(LegalCatalogResolver::compose($document, $dto->revisionId)),
        );
    }

    /**
     * @param LegalRevisionChangesActionDTO $dto Later revision to compare
     * @return LegalRevisionChangesReplyDTO Comparison with the previous declaration
     * @throws ValidationException When the address is unknown or has no predecessor
     * @throws HilosException When the catalog or text file is invalid
     */
    private function revisionChanges(LegalRevisionChangesActionDTO $dto): LegalRevisionChangesReplyDTO
    {
        $document = $this->requireRevision($dto->document, $dto->revisionId);
        $previous = LegalCatalogResolver::predecessor($document, $dto->revisionId);
        if ($previous === null) {
            throw new ValidationException('The first revision has nothing to compare with');
        }

        return new LegalRevisionChangesReplyDTO(
            $document->value, $previous->id, $dto->revisionId,
            LegalWire::changes(LegalRevisionComparison::between($document, $previous->id, $dto->revisionId)),
        );
    }

    /**
     * @param string $key Document key supplied by the client
     * @param string $revisionId Revision key supplied by the client
     * @return LegalDocument Known document with a declared revision
     * @throws ValidationException When the document or revision is unknown
     * @throws HilosException When the catalog declaration is faulty
     */
    private function requireRevision(string $key, string $revisionId): LegalDocument
    {
        $document = LegalDocument::tryFrom($key);
        if ($document === null) {
            throw new ValidationException('Unknown legal document');
        }
        try {
            LegalCatalogResolver::revision($document, $revisionId);
        } catch (UnknownRevisionException) {
            throw new ValidationException('Unknown legal revision');
        }

        return $document;
    }
}
