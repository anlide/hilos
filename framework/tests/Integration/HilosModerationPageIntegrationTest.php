<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Pages\Moderation\AbstractHilosModerationPage;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Users\AccountStandingResolver;
use ReflectionProperty;

/**
 * Real root moderation page subscription: head metadata, kinds table window, viewer privacy and access control.
 */
final class HilosModerationPageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_user_merge',
        'hilos_account_deletion',
        'hilos_moderation_rule',
        'hilos_setting',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:moderation-page-integration';

    private ?HilosDbContext $previousDb = null;
    private ?SignalRouter $previousSignalRouter = null;
    private ?SettingsAccessor $previousSettings = null;
    private ?BrowserContext $previousBrowser = null;
    private ?TableContext $previousTables = null;
    private string $boundAppClass = Hilos::class;
    /** @var array<string, mixed> Account-standing memory restored with the original context */
    private array $previousStanding = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);

        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousSettings = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTables = Hilos::$table;
        $this->boundAppClass = Hilos::appClass();
        self::bindAppClass(ModerationPageIntegrationTestHilos::class);
        $db = new ModerationPageIntegrationTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(ModerationPageTestSettingsCatalog::class);
        Hilos::$table = new ModerationPageIntegrationTestTableContext();
        Hilos::$table->configure();

        foreach ([HilosDbContext::moderationRules, HilosDbContext::settings] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$setting = $this->previousSettings;
        Hilos::$table = $this->previousTables;
        $appClass = $this->boundAppClass;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            $appClass::initBrowser($this->previousBrowser);
        }
        self::bindAppClass($this->boundAppClass);
        foreach ($this->previousStanding as $property => $value) {
            new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
        }
        self::runStubs(down: true);
        parent::tearDown();
    }

    public function testAdminSubscriptionDeliversHeadAndCompleteKindsWindow(): void
    {
        $browser = new ModerationPageTestBrowser(userId: 7, admin: true, viewer: false);
        ModerationPageIntegrationTestHilos::initBrowser($browser);

        $router = $this->createPageRouter();
        $outcome = $router->resendPage(ModerationTestPage::PAGE, 'ak-admin', []);
        self::assertSame(PageResendOutcome::Answered, $outcome);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());

        $payload = $signal->data->data->toArray();
        self::assertSame(HilosPageConstants::HILOS_MODERATION, $payload['page']);

        $data = $payload['payload']['data'];
        self::assertSame(HilosPageConstants::HILOS_MODERATION, $data['pageHeadOwner']['page']);
        self::assertSame('Moderation', $data['pageHeadOwner']['label']);
        self::assertSame('What is checked before it is shown, and by which rules.', $data['pageHeadOwner']['lead']);
        self::assertSame('bi-shield-check', $data['pageHeadOwner']['icon']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Moderation'],
        ], $data['pageBreadcrumb']);
        self::assertSame([], $data['pageTabs']);
        self::assertSame([], $data['pageChildren']);

        self::assertArrayHasKey(HilosModerationKindsTable::TABLE, $payload['payload']['windows']);
        $window = $payload['payload']['windows'][HilosModerationKindsTable::TABLE];
        self::assertSame(4, $window[TableWindowSignalData::totalCount]);
        self::assertTrue($window[TableWindowSignalData::totalExact]);

        $rows = $window[TableWindowSignalData::rows];
        self::assertCount(4, $rows);

        $expectedRows = [
            'hilos_profile_name' => ['title' => 'Profile name', 'owner' => 'framework', 'input' => 'text', 'model' => 'text-default'],
            'hilos_profile_photo' => ['title' => 'Profile photo', 'owner' => 'framework', 'input' => 'image', 'model' => 'vision-default'],
            'test_note' => ['title' => 'Project note', 'owner' => 'project', 'input' => 'text_with_files', 'model' => 'text-default'],
            'test_picture' => ['title' => 'Project picture', 'owner' => 'project', 'input' => 'image', 'model' => 'vision-default'],
        ];

        foreach ($rows as $row) {
            $key = $row['rowKey'];
            self::assertArrayHasKey($key, $expectedRows);
            $expected = $expectedRows[$key];
            $slot = $row['slots']['kind'];
            self::assertSame($key, $slot['kind']);
            self::assertSame($expected['title'], $slot['title']);
            self::assertSame($expected['owner'], $slot['owner']);
            self::assertSame($expected['input'], $slot['input']);
            self::assertSame($expected['model'], $slot['model']);
            self::assertSame(0, $slot['ownRuleCount']);
        }
    }

    public function testViewerSubscriptionDeliversHeadAndMasksHiddenModelField(): void
    {
        Hilos::$setting = new SettingsAccessor(ModerationPageTestTextHiddenCatalog::class);

        $browser = new ModerationPageTestBrowser(userId: 5, admin: false, viewer: true);
        ModerationPageIntegrationTestHilos::initBrowser($browser);

        $router = $this->createPageRouter();
        $outcome = $router->resendPage(ModerationTestPage::PAGE, 'ak-viewer', []);
        self::assertSame(PageResendOutcome::Answered, $outcome);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());

        $payload = $signal->data->data->toArray();
        $data = $payload['payload']['data'];
        self::assertSame(HilosPageConstants::HILOS_MODERATION, $data['pageHeadOwner']['page']);

        $window = $payload['payload']['windows'][HilosModerationKindsTable::TABLE];
        $rows = $window[TableWindowSignalData::rows];
        self::assertCount(4, $rows);

        foreach ($rows as $row) {
            $slot = $row['slots']['kind'];
            self::assertSame($row['rowKey'], $slot['kind']);
            self::assertIsString($slot['title']);
            self::assertContains($slot['owner'], ['framework', 'project']);
            self::assertContains($slot['input'], ['text', 'image', 'text_with_files']);
            self::assertSame(0, $slot['ownRuleCount']);

            if (in_array($row['rowKey'], ['hilos_profile_name', 'test_note'], true)) {
                self::assertSame(HiddenValue::mark(), $slot['model']);
            } else {
                self::assertSame('vision-default', $slot['model']);
            }
        }
    }

    public function testGuestSubscriptionIsRefusedAndReceivesNoAdminData(): void
    {
        $browser = new ModerationPageTestBrowser(userId: null, admin: false, viewer: false);
        ModerationPageIntegrationTestHilos::initBrowser($browser);

        $router = $this->createPageRouter();
        $outcome = $router->resendPage(ModerationTestPage::PAGE, 'ak-guest', []);
        self::assertSame(PageResendOutcome::Refused, $outcome);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertNotSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertSame(HttpConstants::HTTP_UNAUTHORIZED, $signal->data->data->httpCode);

        $this->expectException(PageUnauthorizedException::class);
        PageAccessGate::assert(ModerationTestPage::class, 'ak-guest');
    }

    public function testModerationRootPageDeclaresNoWriteActions(): void
    {
        self::assertSame([], AbstractHilosModerationPage::ACTIONS);
        self::assertSame([], ModerationTestPage::ACTIONS);
        self::assertSame([], ModerationTestPage::READING_ACTIONS);
        self::assertSame([], ModerationTestPage::SIGNALS);
    }

    private function createPageRouter(): PageSignalRouter
    {
        $agent = new ModerationPageTestAgent();
        $pageFactory = new HilosPageFactory($agent, ModerationPageIntegrationTestHilos::class);

        return new PageSignalRouter(
            $pageFactory,
            new ActionRouteConfig([]),
            new SignalRouteConfig([]),
        );
    }

    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun((string) file_get_contents(
                dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"
            ));
        }
    }

    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** Stand-in for concrete moderation root page in project. */
final class ModerationTestPage extends AbstractHilosModerationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}

/** Test agent for page signal router. */
final class ModerationPageTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'test-agent:moderation-page';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/** Browser context supporting admin, viewer and guest modes. */
final class ModerationPageTestBrowser extends BrowserContext
{
    public function __construct(
        private readonly ?int $userId,
        private readonly bool $admin,
        private readonly bool $viewer = false,
    ) {
        parent::__construct();
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }
}

/** Test directory with two framework and two project kinds. */
final class ModerationPageTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'test_note';
    public const string PICTURE = 'test_picture';

    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Project note', ModerationInput::TEXT_WITH_FILES, ['rude'], ['Polite notes.'], 'test_owner'),
            new ModerationKind(self::PICTURE, 'Project picture', ModerationInput::IMAGE, ['nudity'], ['Safe pictures.'], 'test_owner'),
        ];
    }
}

/** Settings catalog with visible model settings for tests. */
final class ModerationPageTestSettingsCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return [
            ModerationSettingsCatalog::TEXT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'text-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            ModerationSettingsCatalog::IMAGE_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'vision-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
        ];
    }
}

/** Settings catalog with text model hidden in admin view mode. */
final class ModerationPageTestTextHiddenCatalog implements CatalogProviderInterface
{
    public static function getCatalog(): array
    {
        return array_replace_recursive(ModerationPageTestSettingsCatalog::getCatalog(), [
            ModerationSettingsCatalog::TEXT_MODEL => [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        ]);
    }
}

/** Test database context. */
final class ModerationPageIntegrationTestDbContext extends HilosDbContext
{
}

/** Test table context registering the kinds table. */
final class ModerationPageIntegrationTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosModerationKindsTable::TABLE, new HilosModerationKindsTable());
    }
}

/** Test application facade. */
abstract class ModerationPageIntegrationTestHilos extends Hilos
{
    public const array PAGES = [
        ModerationTestPage::PAGE => ModerationTestPage::class,
    ];

    public const array TABLES = [
        HilosModerationKindsTable::TABLE => HilosModerationKindsTable::class,
    ];

    public const array PAGE_TABLES = [
        ModerationTestPage::PAGE => [
            HilosModerationKindsTable::TABLE => [],
        ],
    ];

    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
    protected const string MODERATION_KIND_DIRECTORY = ModerationPageTestDirectory::class;
}
