<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalWire;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Pages\Legal\DTO\TermsRevisionTextActionDTO;

/**
 * AbstractHilosTermsPage - Abstract base for the Hilos public Terms page (HIL-501).
 *
 * The body of the page is the text of the Terms revision in force, read from the project's
 * legal catalog; the project's own prose is only an introduction the frontend slots above it.
 * The answer depends on the reader: a guest receives the Terms section (the revision in force,
 * its text and the history), a signed-in reader receives their acceptance state besides it and,
 * when the revision they hold is not the one in force, the comparison of the two. Any reader,
 * a guest included, reads an older revision through the page's own action. Projects implement
 * a concrete class (e.g. Demo\Chat\Pages\Hilos\TermsPage) binding the owning agent type.
 */
abstract class AbstractHilosTermsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_TERMS;

    public const PageReach REACH = PageReach::ROUTE;

    /** Public footer page: readable without a session ({@see PageAccessLevel}). */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_TERMS,
    ];

    public const array READS_DB = [HilosDbContext::legalAcceptances];

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_TERMS_REVISION_TEXT => TermsRevisionTextActionDTO::class,
    ];

    /**
     * @param string $acceptKey Requesting connection
     * @param string $action Requested read
     * @param ActionPayloadDTO $dto Parsed revision address
     * @return ?ActionReplyDTO Text of the Terms revision
     * @throws AgentUnknownActionException When the action name is not supported
     * @throws InvalidActionPayloadException When the DTO does not match the action
     * @throws ValidationException When the document is not Terms or the revision is unknown
     * @throws HilosException When the declared catalog or a text file cannot be read
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_TERMS_REVISION_TEXT:
                if (!$dto instanceof TermsRevisionTextActionDTO) {
                    throw new InvalidActionPayloadException($action, TermsRevisionTextActionDTO::class, $dto);
                }

                return $this->revisionText($dto);

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload The Terms section, and a signed-in reader's acceptance state
     * @throws HilosException When the catalog or acceptance read fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        $today = LegalStandingResolver::today();
        $terms = LegalAgreementsProjector::terms($userId, $today);
        $data = [LegalAgreementsProjector::TERMS_SECTION => $terms];
        if ($userId !== null && $terms !== null) {
            $data[LegalAgreementsProjector::SECTION] = LegalAgreementsProjector::stateFor($userId, $today)->toArray();
        }

        return new PagePayload(data: $data);
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
     * @param TermsRevisionTextActionDTO $dto Revision to read
     * @return LegalRevisionTextReplyDTO Composed text
     * @throws ValidationException When the document is not Terms or the revision is unknown
     * @throws HilosException When the catalog or text file is invalid
     */
    private function revisionText(TermsRevisionTextActionDTO $dto): LegalRevisionTextReplyDTO
    {
        $document = LegalDocument::tryFrom($dto->document);
        if ($document !== LegalDocument::TERMS) {
            throw new ValidationException('Unknown legal document');
        }
        try {
            LegalCatalogResolver::revision($document, $dto->revisionId);
        } catch (UnknownRevisionException) {
            throw new ValidationException('Unknown legal revision');
        }

        return new LegalRevisionTextReplyDTO(
            $document->value, $dto->revisionId, LegalWire::clauses(LegalCatalogResolver::compose($document, $dto->revisionId)),
        );
    }
}
