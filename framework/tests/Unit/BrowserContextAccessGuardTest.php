<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

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
        $context->subscribeSnapshot(
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
        $context->subscribeSnapshot(
            AccessGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    public function testAccessGuardAllowsAnAdmin(): void
    {
        $this->seed();
        $context = new AccessGuardTestBrowserContext(7);

        // The guard passes, so subscribeSnapshot runs to completion without
        // throwing; the page has no table bindings, so it queues nothing.
        $context->subscribeSnapshot(
            AccessGuardTestBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
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

    public function __construct(private readonly ?int $currentUserId)
    {
        parent::__construct();
    }

    /**
     * Returns the injected current user id, standing in for the project's
     * acceptKey -> user mapping.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ?int Injected current user id
     */
    protected function resolveCurrentUserId(string $acceptKey): ?int
    {
        return $this->currentUserId;
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
            BrowserConfigKey::SIGNAL => self::SIGNAL,
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
    protected function createRtItem(RtState &$state): RtItem
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
