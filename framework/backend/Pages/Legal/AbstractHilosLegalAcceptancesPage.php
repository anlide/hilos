<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Export\LegalAcceptancesExportProjector;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptancesExportActionDTO;

/**
 * ADMIN subscription for immutable acceptance records; independent of catalog validity.
 *
 * The administrator orders a file of the records the table shows here, and sees its state here: the
 * order is this page's action, so a viewer of the admin view mode is refused it by the mode itself, and
 * the state of the administrator's own export rides the page response (HIL-1234).
 */
abstract class AbstractHilosLegalAcceptancesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_ACCEPTANCES;
    public const PageReach REACH = PageReach::ROUTE;
    public const array READS_DB = [HilosDbContext::legalAcceptances, HilosDbContext::identities, HilosDbContext::legalAcceptanceExports];
    public const array ACTIONS = [
        HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT => HilosLegalAcceptancesExportActionDTO::class,
    ];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES,
    ];

    /**
     * @param string $acceptKey Ordering administrator's connection
     * @param string $action Requested action name
     * @param ActionPayloadDTO $dto Parsed payload
     * @return ?ActionReplyDTO Empty acknowledgement; the state of the export follows as its own frame
     * @throws AgentUnknownActionException When the action is unsupported
     * @throws InvalidActionPayloadException When the DTO does not match its action
     * @throws HilosException When the administrator, the confirmation or the order is refused
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT:
                if (!$dto instanceof HilosLegalAcceptancesExportActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosLegalAcceptancesExportActionDTO::class, $dto);
                }
                LegalAcceptancesExports::order($this->agent, $acceptKey, $dto);
                return null;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /** @param string $acceptKey Connection leaving this page */
    public function onUnsubscribe(string $acceptKey): void
    {
        LegalAdminAudience::removeSubscriber($acceptKey);
    }

    /**
     * The administrator's own export, or its absence; a viewer of the admin view mode has none and gets no key.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload The export section, or null for a viewer and for a connection without a person
     * @throws HilosException When the export cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->userId;
        if ($userId === null || Hilos::$browser?->isAdminViewModeViewer(static::class, $acceptKey) === true) {
            return null;
        }

        return new PagePayload(data: [
            LegalAcceptancesExportProjector::SECTION => LegalAcceptancesExportProjector::nodeFor(
                Hilos::$db->legalAcceptanceExports->ofUser($userId),
            ),
        ]);
    }

    /**
     * Sends the vocabulary before page_response without consuming a pending broadcast.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @throws DatabaseException When recorded revision keys cannot be read
     * @throws InvalidArgumentException When the subscription signal cannot be named
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $frame = static::frameForViewer($acceptKey, LegalAdminAudience::filters(), HilosLegalAcceptanceFiltersSignalData::wireFields());
        if ($frame === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES,
            $acceptKey,
            $frame,
        );
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        LegalAdminAudience::addSubscriber($acceptKey, static::PAGE);
    }
}
