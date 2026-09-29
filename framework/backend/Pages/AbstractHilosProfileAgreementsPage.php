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

/** Authenticated personal legal acceptance state (HIL-498). */
abstract class AbstractHilosProfileAgreementsPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_AGREEMENTS;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** An exit of a freeze (HIL-945): what the person agreed to is read before accepting the new terms. */
    public const bool OPEN_WHILE_FROZEN = true;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_AGREEMENTS,
    ];

    public const array READS_DB = [HilosDbContext::legalAcceptances];

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
        $today = LegalStandingResolver::today();

        return new PagePayload(data: [
            LegalAgreementsProjector::SECTION => LegalAgreementsProjector::stateFor($userId, $today)->toArray(),
            LegalAgreementsProjector::TEXTS_SECTION => LegalAgreementsProjector::textsFor($userId, $today),
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
}
