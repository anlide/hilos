<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Profile;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosProfileAgreementsHistoryPage;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;
use Hilos\Pages\AbstractHilosProfileDataPage;
use Hilos\Pages\AbstractHilosProfileDevicesPage;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Pages\AbstractHilosProfileSectionPage;
use Hilos\Pages\AbstractHilosProfileSecurityPage;
use Hilos\Pages\AbstractHilosProfileSessionsPage;
use Hilos\Pages\AbstractHilosProfileSignInPage;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The common profile contract cannot be bypassed by a section or a project body hook. */
final class HilosProfileSectionPageTest extends TestCase
{
    public function testEveryFrameworkSectionInheritsCommonAccessSourcesAndFinalWrappers(): void
    {
        foreach ([
            AbstractHilosProfilePage::class,
            AbstractHilosProfileSignInPage::class,
            AbstractHilosProfileNotificationsPage::class,
            AbstractHilosProfileSessionsPage::class,
            AbstractHilosProfileDevicesPage::class,
            AbstractHilosProfileSecurityPage::class,
            AbstractHilosProfileAgreementsPage::class,
            AbstractHilosProfileAgreementsHistoryPage::class,
            AbstractHilosProfileDataPage::class,
        ] as $page) {
            self::assertTrue(is_subclass_of($page, AbstractHilosProfileSectionPage::class));
            self::assertSame(PageAccessLevel::AUTHENTICATED, $page::ACCESS_LEVEL);
            self::assertSame(PageReach::ROUTE, $page::REACH);
            self::assertSame([HilosProfileIdentitiesBrowserList::class], $page::REQUIRED_LISTS);
            foreach ([
                HilosDbContext::secondFactors,
                HilosDbContext::secondFactorBackupCodes,
                HilosDbContext::secondFactorResets,
                HilosDbContext::secondFactorSettings,
            ] as $collection) {
                self::assertSame(1, count(array_keys($page::READS_DB, $collection, true)), $page . ': ' . $collection);
            }
            foreach (['buildPagePayload', 'onSubscribeAfterResponse'] as $method) {
                self::assertTrue(new ReflectionMethod($page, $method)->isFinal());
            }
        }
        self::assertSame('profilePhoto', AbstractHilosProfilePage::PROFILE_PHOTO_SECTION);
        self::assertSame('secondFactor', AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION);
    }

    public function testAGuestCannotReachEitherPersonalProjectHookOrJoinAGroup(): void
    {
        $previousBrowser = Hilos::$browser;
        $previousRouter = Hilos::$sr;
        try {
            Hilos::$browser = $this->createMock(BrowserContext::class);
            Hilos::$browser->method('resolveActionUserId')->willReturn(null);
            Hilos::$sr = new SignalRouter();
            $page = new ProfileSectionGuestTestPage($this->createMock(PageAgentInterface::class));
            self::assertNull($page->snapshot());
            $page->afterResponse();
            self::assertFalse($page->bodyCalled);
            self::assertFalse($page->afterCalled);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        } finally {
            Hilos::$browser = $previousBrowser;
            Hilos::$sr = $previousRouter;
        }
    }
}

final class ProfileSectionGuestTestPage extends AbstractHilosProfileSectionPage
{
    public bool $bodyCalled = false;
    public bool $afterCalled = false;

    /** @return ?PagePayload Common wrapper's guest result */
    public function snapshot(): ?PagePayload
    {
        return $this->buildPagePayload('guest', new PageRouteParams([]));
    }

    /** Runs the common after-response wrapper for a guest. */
    public function afterResponse(): void
    {
        $this->onSubscribeAfterResponse('guest', new PageRouteParams([]));
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters
     * @return ?PagePayload Deliberately personal project body
     */
    protected function buildProfilePagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $this->bodyCalled = true;

        return new PagePayload(data: ['personal' => 'must stay private']);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters
     */
    protected function onProfileSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->afterCalled = true;
    }
}
