<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;
use Hilos\Pages\Users\HilosUserSessionsBrowserList;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Contract tests for the list of one person's sessions on the person's card (HIL-1662).
 *
 * The list is the one surface that shows an administrator another person's sign-ins, so what is
 * pinned here is what a project binds and relies on: whose they are - the page's person, never the
 * subscriber - what never travels, what nothing opens to a viewer, that the Sessions tab cannot be
 * registered without the list, and that the card can tell an unbound list from an empty one. What
 * the list delivers - its items, their tabs and moments, the administrator acting in a session, and
 * how each follows a change - is pinned through the browser: in the chat demo's sessions page test,
 * and for the row joined by the impersonator field in BrowserContextAnchorFieldJoinTest.
 */
final class HilosUserSessionsBrowserListTest extends TestCase
{
    private string $previousApp;

    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousSettings = Hilos::$setting;
        Hilos::$setting = null;
    }

    protected function tearDown(): void
    {
        self::bindAppClass($this->previousApp);
        Hilos::$setting = $this->previousSettings;
        parent::tearDown();
    }

    /** The binding hands the list the person of the page's route, the same positive id the page itself is opened with. */
    public function testTheListReadsThePersonOfThePageRouteNotTheSubscriber(): void
    {
        $param = HilosPageRouteParams::HILOS_USER_USER_ID;

        self::assertSame(
            [$param => [BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM, BrowserRefKey::KEY => $param]],
            HilosUserSessionsBrowserList::BINDING[BrowserParamKey::PARAMS],
        );
        $declared = HilosUserSessionsBrowserList::BROWSER[BrowserListConfigKey::PARAMS];
        self::assertSame([$param], array_keys($declared));
        self::assertSame(BrowserParamType::POSITIVE_INT, $declared[$param][BrowserParamKey::TYPE]);
        self::assertTrue($declared[$param][BrowserParamKey::REQUIRED]);
        self::assertSame(
            AbstractHilosUserSessionsPage::BROWSER[BrowserConfigKey::PARAMS][$param],
            $declared[$param],
            'The list param is the page param it is bound to',
        );
        self::assertStringNotContainsString(BrowserRefType::ACCEPT_KEY, json_encode(HilosUserSessionsBrowserList::BROWSER));
    }

    /** Neither the session's token, nor its address, nor a sign-in step under way travels, and no tab carries a token. */
    public function testNoSecretOrAddressIsAProjectedField(): void
    {
        $sessionFields = $this->sessionsItem()[BrowserListFieldKey::FIELDS];
        foreach ([
            Session::token,
            Session::ipAddress,
            Session::pendingAck,
            Session::pendingRegistrationIdentifier,
            Session::pendingSecondFactorUserId,
            Session::blockedUserId,
        ] as $hidden) {
            self::assertNotContains($hidden, $sessionFields);
        }
        self::assertNotContains(HilosSessionConnection::sessionToken, $this->tabsItem()[BrowserListFieldKey::FIELDS]);
    }

    /** Nothing in the list opens a field to a viewer of the admin view mode: the columns' verdicts and the hidden tabs stand. */
    public function testNothingIsOpenedToAViewer(): void
    {
        foreach (HilosUserSessionsBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            self::assertArrayNotHasKey(BrowserListFieldKey::NOT_PERSONAL, $item);
        }
    }

    /** The Sessions tab cannot be registered without the list bound: the start refuses it instead of drawing nothing. */
    public function testTheSessionsTabRequiresTheList(): void
    {
        self::assertContains(HilosUserSessionsBrowserList::class, AbstractHilosUserSessionsPage::REQUIRED_LISTS);
        self::assertNotContains(HilosUserSessionsBrowserList::class, AbstractHilosUserPage::REQUIRED_LISTS);
    }

    /**
     * @return array<string, array{class-string<Hilos>, bool, bool}> Facade, the card's flag, the Sessions tab's flag
     */
    public static function declarations(): array
    {
        return [
            'nothing declared' => [UserSessionsFlagNothingHilos::class, false, false],
            'the tab without a binding' => [UserSessionsFlagTabOnlyHilos::class, false, false],
            'a binding without the tab' => [UserSessionsFlagBindingOnlyHilos::class, false, false],
            'the tab, bound on the card only' => [UserSessionsFlagCardBoundHilos::class, true, false],
            'the tab, bound on both' => [UserSessionsFlagBothBoundHilos::class, true, true],
        ];
    }

    /**
     * The flag is the project's declaration: the Sessions tab registered, and the list bound to the page answering.
     *
     * @param class-string<Hilos> $facade Project facade under test
     * @param bool $card Expected flag on the card's Overview
     * @param bool $tab Expected flag on the Sessions tab
     */
    #[DataProvider('declarations')]
    public function testTheFlagSaysWhetherThisPageIsBoundToTheList(string $facade, bool $card, bool $tab): void
    {
        self::bindAppClass($facade);

        self::assertSame($card, new UserSessionsFlagCardPage()->flag());
        self::assertSame($tab, new UserSessionsFlagTabPage()->flag());
    }

    /** The flag is configuration, nobody's data, and a viewer of the admin view mode is shown it. */
    public function testTheFlagIsOpenToAViewer(): void
    {
        self::assertEquals(WireField::notPersonal(), new UserSessionsFlagCardPage()->fields()[AbstractHilosUserPage::USER_SESSIONS_ENABLED]);
        self::assertEquals(WireField::notPersonal(), new UserSessionsFlagTabPage()->fields()[AbstractHilosUserPage::USER_SESSIONS_ENABLED]);
    }

    /** @return array<string, mixed> The sessions item, the list's anchor */
    private function sessionsItem(): array
    {
        return $this->itemOf(HilosUserSessionsBrowserList::DB_SESSIONS);
    }

    /** @return array<string, mixed> The tabs item, joined to each session */
    private function tabsItem(): array
    {
        return $this->itemOf(HilosUserSessionsBrowserList::RT_CONNECTIONS);
    }

    /**
     * @param array<string, string> $source Source declaration of the item
     * @return array<string, mixed> The one item reading that source
     */
    private function itemOf(array $source): array
    {
        $items = array_values(array_filter(
            HilosUserSessionsBrowserList::BROWSER[BrowserListConfigKey::ITEMS],
            static fn (array $item): bool => $item[BrowserListFieldKey::SOURCE] === $source,
        ));
        self::assertCount(1, $items);

        return $items[0];
    }

    /** @param string $hilosClass Application facade to bind */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** Reads the flag and the viewer declaration of a page of the card. */
trait UserSessionsFlagReader
{
    /** @return bool The flag the page's first answer carries */
    public function flag(): bool
    {
        $payload = $this->buildPagePayload(
            'accept-flag',
            new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => '12']),
        );
        TestCase::assertNotNull($payload);

        return $payload->data[AbstractHilosUserPage::USER_SESSIONS_ENABLED];
    }

    /** @return array<string, WireField> What of the page's data a viewer is shown */
    public function fields(): array
    {
        return $this->dataFields();
    }
}

final class UserSessionsFlagCardPage extends AbstractHilosUserPage
{
    use UserSessionsFlagReader;

    public function __construct()
    {
        parent::__construct(new UserSessionsFlagAgent());
    }
}

final class UserSessionsFlagTabPage extends AbstractHilosUserSessionsPage
{
    use UserSessionsFlagReader;

    public function __construct()
    {
        parent::__construct(new UserSessionsFlagAgent());
    }
}

final class UserSessionsFlagAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'user-sessions-flag-test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'user-sessions-flag-test');
    }
}

abstract class UserSessionsFlagNothingHilos extends Hilos
{
}

abstract class UserSessionsFlagTabOnlyHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_USER_SESSIONS => UserSessionsFlagTabPage::class];
}

abstract class UserSessionsFlagBindingOnlyHilos extends Hilos
{
    public const array PAGE_LISTS = [
        HilosPageConstants::HILOS_USER => [HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING],
    ];
}

abstract class UserSessionsFlagCardBoundHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_USER_SESSIONS => UserSessionsFlagTabPage::class];

    public const array PAGE_LISTS = [
        HilosPageConstants::HILOS_USER => [HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING],
    ];
}

abstract class UserSessionsFlagBothBoundHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_USER_SESSIONS => UserSessionsFlagTabPage::class];

    public const array PAGE_LISTS = [
        HilosPageConstants::HILOS_USER => [HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING],
        HilosPageConstants::HILOS_USER_SESSIONS => [HilosUserSessionsBrowserList::LIST => HilosUserSessionsBrowserList::BINDING],
    ];
}
