<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Unit tests for the ACCESS browser guard: an anonymous session is denied a
 * guarded page with a 401, a non-admin authenticated user with a 403, and an
 * admin passes. The current user behind the accept key is resolved through the
 * project hook (resolveCurrentUserId).
 */
final class BrowserContextAccessGuardTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAccessGuardDeniesAGuest(): void
    {
        $this->seed();
        $context = new AccessGuardTestBrowserContext(null);

        $this->expectException(PageUnauthorizedException::class);
        $context->assertSubscriptionAccess(
            AccessGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    public function testAccessGuardDeniesANonAdmin(): void
    {
        $this->seed();
        $context = new AccessGuardTestBrowserContext(5);

        $this->expectException(PageForbiddenException::class);
        $context->assertSubscriptionAccess(
            AccessGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    public function testAccessGuardAllowsAnAdmin(): void
    {
        $this->seed();
        $context = new AccessGuardTestBrowserContext(7);

        // The guard passes, so the judge returns without throwing; judging a
        // subscription sends nothing, whichever way it goes.
        $context->assertSubscriptionAccess(
            AccessGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testProjectSubclassCanReportFailureWithTheProtectedPageAddress(): void
    {
        Hilos::$sr = new SignalRouter();
        $page = AccessGuardTestBrowserContext::PAGE;
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('ak-1', $page));
        $browser = new class extends BrowserContext {
            public function reportFailure(string $page, string $acceptKey, Throwable $failure): void
            {
                $this->failPublication(page: $page, acceptKey: $acceptKey, failure: $failure);
            }
        };

        $browser->reportFailure($page, 'ak-1', new RuntimeException('Publisher failed'));
        $browser->reportFailure($page, 'ak-1', new RuntimeException('Publisher failed again'));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testOwnPageChangeResendsOnlyWithAccessAndOncePerFlush(): void
    {
        $this->seed();
        $page = AccessGuardTestBrowserContext::PAGE;
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('ak-1', $page));
        $fact = SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, '7', []);

        $denied = new AccessGuardTestBrowserContext(null);
        $blocked = $this->createMock(PageResender::class);
        $blocked->expects(self::never())->method('resendPage');
        $denied->bindPageResender($blocked);
        $denied->record($fact);
        self::assertSame([], $denied->flushToSignalRouter());

        $allowed = new AccessGuardTestBrowserContext(7);
        $resender = $this->createMock(PageResender::class);
        $resender->expects(self::once())->method('resendPage')->with($page, 'ak-1', [], false)->willReturn(PageResendOutcome::Answered);
        $allowed->bindPageResender($resender);
        $allowed->record($fact);
        $allowed->record(SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, '5', []));
        self::assertSame([], $allowed->flushToSignalRouter());
    }

    public function testGuardOnlyDenialSkipsTheOwnPageObserver(): void
    {
        $this->seed();
        $page = AccessGuardTestBrowserContext::PAGE;
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('ak-1', $page));
        $context = new AccessGuardTestBrowserContext(null, guardOnly: true, observe: static function (): never {
            throw new RuntimeException('An access refusal must stop the observer');
        });

        $context->record(SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, '7', []));

        self::assertSame([], $context->flushToSignalRouter());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAnObserverThrowAfterReplacementLeavesTheNewSubscriptionEligible(): void
    {
        $this->seed();
        $page = AccessGuardTestBrowserContext::PAGE;
        $subscribe = new WebSocketPageSubscribeSignalDTO('ak-1', $page);
        Hilos::$sr->subscribeToPage($page, $subscribe);
        $original = Hilos::$sr->pageSubscription('ak-1');
        $context = new AccessGuardTestBrowserContext(7, observe: static function () use ($page, $subscribe, $original): bool {
            if (Hilos::$sr->pageSubscription('ak-1') === $original) {
                Hilos::$sr->subscribeToPage($page, $subscribe);
                throw new RuntimeException('Failure from the replaced subscription');
            }

            return true;
        });
        $resender = $this->createMock(PageResender::class);
        $resender->expects(self::once())->method('resendPage')->with($page, 'ak-1', [], false)->willReturn(PageResendOutcome::Answered);
        $context->bindPageResender($resender);
        $context->record(SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, '7', []));
        $context->record(SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, '5', []));

        self::assertSame([], $context->flushToSignalRouter());
        self::assertNotSame($original, Hilos::$sr->pageSubscription('ak-1'));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testAWholeAnswerCannotPayOrDeduplicateAReplacementWithTheSameParams(): void
    {
        $this->seed();
        $page = AccessGuardTestBrowserContext::PAGE;
        $subscribe = new WebSocketPageSubscribeSignalDTO('ak-1', $page, ['scope' => 'same']);
        Hilos::$sr->subscribeToPage($page, $subscribe);
        $original = Hilos::$sr->pageSubscription('ak-1');
        $context = new AccessGuardTestBrowserContext(7);
        $resender = $this->createMock(PageResender::class);
        $attempts = [];
        $resender->expects(self::exactly(2))->method('resendPage')->with($page, 'ak-1', ['scope' => 'same'], false)
            ->willReturnCallback(static function () use ($page, $subscribe, $original, &$attempts): PageResendOutcome {
                $current = Hilos::$sr->pageSubscription('ak-1');
                $attempts[] = [$current, Hilos::$sr->hasPageDeliveryFailure('ak-1')];
                if ($current === $original) {
                    Hilos::$sr->subscribeToPage($page, $subscribe);
                    Hilos::$sr->markPageDeliveryFailure('ak-1');
                }

                return PageResendOutcome::Answered;
            });
        $context->bindPageResender($resender);
        foreach (['7', '5', '9'] as $rowId) {
            $context->record(SourceChange::rtUpdated(AccessGuardTestRtContext::USERS, $rowId, []));
        }

        self::assertSame([], $context->flushToSignalRouter());
        $replacement = Hilos::$sr->pageSubscription('ak-1');
        self::assertNotSame($original, $replacement);
        self::assertSame([[$original, false], [$replacement, true]], $attempts);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    private function seed(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new AccessGuardTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addUser(AccessGuardTestUserState::create('5', false));
        Hilos::$rt->addUser(AccessGuardTestUserState::create('7', true));
    }
}

final class AccessGuardTestBrowserContext extends BrowserContext
{
    public const string PAGE = 'access_guard_page';
    public const string SIGNAL = 'access_guard_signal';

    private const array USERS_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => AccessGuardTestRtContext::USERS,
    ];

    /**
     * @param ?int $currentUserId Connection identity for the access guard
     * @param bool $guardOnly Whether the page declares guards without a browser signal
     * @param ?Closure(): bool $observe Scripted observer result, or null to match changes to users
     */
    public function __construct(
        private readonly ?int $currentUserId,
        private readonly bool $guardOnly = false,
        private readonly ?Closure $observe = null,
    ) {
        parent::__construct();
    }

    /**
     * @param string $page Subscribed page
     * @param string $acceptKey Subscriber connection
     * @param SourceChange $change Grouped source fact
     * @return bool Whether the fixture wants a whole answer
     */
    protected function observesOwnPageChange(string $page, string $acceptKey, SourceChange $change): bool
    {
        if ($this->observe !== null) {
            return ($this->observe)();
        }

        return $page === self::PAGE && $change->sourceKey === AccessGuardTestRtContext::USERS;
    }

    /**
     * Returns the injected user id as a settled identity, standing in for the project's acceptKey -> user mapping.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user id
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->currentUserId);
    }

    /**
     * Resolves the access-guarded test page config.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guarded page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            ...($this->guardOnly ? [] : [BrowserConfigKey::SIGNAL => self::SIGNAL]),
            BrowserConfigKey::GUARDS => [
                [
                    BrowserGuardKey::TYPE => BrowserGuardType::ACCESS,
                    BrowserGuardKey::SOURCE => self::USERS_SOURCE,
                    BrowserGuardKey::FIELD => 'admin',
                ],
            ],
        ]);
    }

    /**
     * The guarded test page has no table bindings.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}

final class AccessGuardTestRtContext extends RtContext
{
    public const string USERS = 'accessGuardUsers';

    public function configure(): void
    {
        $this->_stateCollections[self::USERS] = AccessGuardTestUserStates::init();
        $this->setRepresent(self::USERS, AccessGuardTestUsers::class);
    }

    public function addUser(AccessGuardTestUserState $user): void
    {
        $this->_stateCollections[self::USERS]->add($user);
    }
}

final class AccessGuardTestUserStates extends RtStates
{
    public const string STATE_CLASS = AccessGuardTestUserState::class;
}

final class AccessGuardTestUserState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly bool $admin,
    ) {
        parent::__construct();
    }

    public static function create(string $id, bool $admin): self
    {
        return new self($id, $admin);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public static function fromRow(array $row): static
    {
        return new static(
            (string) $row['id'],
            (bool) ($row['admin'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'admin' => $this->admin,
        ];
    }
}

final class AccessGuardTestUsers extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new AccessGuardTestUserItem($state);
    }
}

final class AccessGuardTestUserItem extends RtItem
{
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
