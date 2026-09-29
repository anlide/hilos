<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests the one place the server asks "is this connection a viewer in the admin view mode?" (HIL-1250).
 *
 * Two things are on trial. The question: an ADMIN page, the mode of the node on, a user who is not an
 * admin - and with the mode off nothing else is read, which is what keeps a node without the mode sending
 * every frame as before; a failed admin lookup closes the bridge rather than opening it. And the column
 * verdict under it: a field of a collection is shown only when its column is in `_piiNotPersonal` of its
 * entity, and a registry that cannot be collected hides every column with one line in the journal.
 */
final class BrowserContextAdminViewModeViewerTest extends TestCase
{
    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** Temporary main log file the journal lines are read back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new ViewerTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-admin-view-mode-viewer');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testANodeWithoutTheRowHasNoViewer(): void
    {
        Hilos::$rt = null;
        $browser = new ViewerTestBrowser(null, false);

        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
        $this->assertSame(0, $browser->identityReads);
    }

    public function testWithTheModeOffNothingElseIsRead(): void
    {
        $this->switchMode(false);
        $browser = new ViewerTestBrowser(7, false);

        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
        $this->assertSame(0, $browser->identityReads);
        $this->assertSame(0, $browser->adminReads);
    }

    public function testAPublicOrAuthenticatedPageHasNoViewer(): void
    {
        $this->switchMode(true);
        $browser = new ViewerTestBrowser(7, false);

        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestPublicPage::class, 'ak-1'));
        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAuthenticatedPage::class, 'ak-1'));
        $this->assertSame(0, $browser->adminReads);
    }

    public function testASessionWithoutAnAccountIsAViewer(): void
    {
        $this->switchMode(true);
        $browser = new ViewerTestBrowser(null, false);

        $this->assertTrue($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
        $this->assertSame(0, $browser->adminReads);
    }

    public function testAUserWhoIsNotAnAdminIsAViewer(): void
    {
        $this->switchMode(true);

        $this->assertTrue((new ViewerTestBrowser(7, false))->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
    }

    public function testAnAdminIsNotAViewer(): void
    {
        $this->switchMode(true);
        $browser = new ViewerTestBrowser(7, true);

        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
        $this->assertSame(1, $browser->adminReads);
    }

    public function testTheAnswerIsAskedAgainOnEveryDelivery(): void
    {
        $this->switchMode(true);
        $browser = new ViewerTestBrowser(7, true);
        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));

        $browser->admin = false;

        $this->assertTrue($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
        $this->switchMode(false);
        $this->assertFalse($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));
    }

    public function testAFailedAdminLookupClosesTheBridgeAndSaysSo(): void
    {
        $this->switchMode(true);
        $browser = new ViewerTestBrowser(7, true, adminLookupFails: true);

        $this->assertTrue($browser->isAdminViewModeViewer(ViewerTestAdminPage::class, 'ak-1'));

        $lines = $this->writtenLines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString(
            'Admin view mode: whether the user of ak-1 is an admin could not be read (the users table is gone), '
            . 'so the connection is treated as a viewer.',
            $lines[0],
        );
    }

    public function testAColumnIsShownOnlyWhenItsVerdictSaysNotPersonal(): void
    {
        $this->mountUsers();
        $browser = new ViewerTestBrowser(7, false, registry: new PiiRegistry(
            [0 => ['hilos_user' => ['name' => AnonymizationStrategy::FAKE_NAME]]],
            [0 => ['hilos_user' => ['id', 'last_activity']]],
        ));

        $hidden = $browser->hideForViewer(
            ['name' => 'Olena', 'lastActivity' => '2026-09-29 10:00:00', 'admin' => false, 'nick' => 'olena'],
            [
                'name' => WireField::column(HilosDbContext::users, 'name'),
                'lastActivity' => WireField::column(HilosDbContext::users, 'lastActivity'),
                'admin' => WireField::column(HilosDbContext::users, 'admin'),
                'nick' => WireField::column(HilosDbContext::users, 'nick'),
            ],
        );

        // name is personal, last_activity is not, admin is in neither list, nick is no column at all.
        $this->assertSame(
            [
                'name' => HiddenValue::mark(),
                'lastActivity' => '2026-09-29 10:00:00',
                'admin' => HiddenValue::mark(),
                'nick' => HiddenValue::mark(),
            ],
            $hidden,
        );
    }

    public function testAnUnclassifiedTableAndAnUnmountedCollectionAreHidden(): void
    {
        $this->mountUsers();
        $browser = new ViewerTestBrowser(7, false, registry: new PiiRegistry([0 => []], [0 => []]));

        $hidden = $browser->hideForViewer(
            ['lastActivity' => '2026-09-29 10:00:00', 'title' => 'Kyiv'],
            [
                'lastActivity' => WireField::column(HilosDbContext::users, 'lastActivity'),
                'title' => WireField::column('noSuchCollection', 'title'),
            ],
        );

        $this->assertSame(['lastActivity' => HiddenValue::mark(), 'title' => HiddenValue::mark()], $hidden);
    }

    public function testAPurgedTableHidesEveryColumn(): void
    {
        $this->mountUsers();
        $browser = new ViewerTestBrowser(7, false, registry: new PiiRegistry(
            [0 => ['hilos_user' => AnonymizationStrategy::PURGE]],
            // What the collection keeps for a purged table: no column survives it to be judged.
            [0 => ['hilos_user' => []]],
        ));

        $hidden = $browser->hideForViewer(
            ['lastActivity' => '2026-09-29 10:00:00'],
            ['lastActivity' => WireField::column(HilosDbContext::users, 'lastActivity')],
        );

        $this->assertSame(['lastActivity' => HiddenValue::mark()], $hidden);
    }

    public function testVerdictsThatCannotBeCollectedHideEveryColumnWithOneLine(): void
    {
        $this->mountUsers();
        ViewerTestNotAProviderHilos::initBrowser();
        $browser = new ViewerTestBrowser(7, false);
        $fields = ['lastActivity' => WireField::column(HilosDbContext::users, 'lastActivity')];

        $first = $browser->hideForViewer(['lastActivity' => '2026-09-29 10:00:00'], $fields);
        $second = $browser->hideForViewer(['lastActivity' => '2026-09-29 11:00:00'], $fields);

        $this->assertSame(['lastActivity' => HiddenValue::mark()], $first);
        $this->assertSame(['lastActivity' => HiddenValue::mark()], $second);
        // One line, not one per question: the failure is remembered, not retried.
        $lines = $this->writtenLines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Admin view mode: the personal-data verdicts could not be collected (', $lines[0]);
        $this->assertStringContainsString(BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY, $lines[0]);
        $this->assertStringContainsString('so every column is hidden from a viewer.', $lines[0]);
    }

    public function testTheVerdictsOfTheInstallationAreCollectedOnce(): void
    {
        $this->mountUsers();
        $browser = new ViewerTestBrowser(7, false);
        $fields = ['lastActivity' => WireField::column(HilosDbContext::users, 'lastActivity')];

        $first = $browser->hideForViewer(['lastActivity' => '2026-09-29 10:00:00'], $fields);
        // A collection asked again now would fail; the answer below proves it was not asked.
        ViewerTestNotAProviderHilos::initBrowser();
        $second = $browser->hideForViewer(['lastActivity' => '2026-09-29 11:00:00'], $fields);

        // The framework's own verdict on the person table: last_activity is not personal.
        $this->assertSame(['lastActivity' => '2026-09-29 10:00:00'], $first);
        $this->assertSame(['lastActivity' => '2026-09-29 11:00:00'], $second);
        $this->assertSame([], $this->writtenLines());
    }

    /**
     * Turns the node's mode on or off the way the master does.
     *
     * @param bool $enabled Whether the mode is on
     */
    private function switchMode(bool $enabled): void
    {
        Hilos::$rt?->hilosAdminViewModeRuntime?->actions->set($enabled);
    }

    /**
     * Mounts the framework's collections, the person table among them, without a database behind them.
     */
    private function mountUsers(): void
    {
        $db = new ViewerTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /**
     * @return list<string> Lines the logger wrote during the test
     */
    private function writtenLines(): array
    {
        $contents = (string)file_get_contents($this->logFile);

        return $contents === '' ? [] : explode("\n", trim($contents));
    }
}

final class ViewerTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class ViewerTestDbContext extends HilosDbContext
{
}

final class ViewerTestPublicPage extends AbstractPage
{
    public const string PAGE = 'viewer_test_public';
}

final class ViewerTestAuthenticatedPage extends AbstractPage
{
    public const string PAGE = 'viewer_test_authenticated';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}

final class ViewerTestAdminPage extends AbstractPage
{
    public const string PAGE = 'viewer_test_admin';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

/**
 * A browser context with an injected connection user, admin flag and personal-data registry.
 */
final class ViewerTestBrowser extends BrowserContext
{
    /** How many times the connection's identity was read */
    public int $identityReads = 0;

    /** How many times the admin flag was read */
    public int $adminReads = 0;

    /**
     * @param ?int $userId User behind every connection, or null for a session without an account
     * @param bool $admin Whether that user is an admin
     * @param bool $adminLookupFails Whether reading the admin flag fails
     * @param ?PiiRegistry $registry Registry to judge columns by instead of the collected one
     */
    public function __construct(
        private readonly ?int $userId,
        public bool $admin,
        private readonly bool $adminLookupFails = false,
        private readonly ?PiiRegistry $registry = null,
    ) {
        parent::__construct();
    }

    /**
     * Returns the injected user as a settled identity.
     *
     * @param string $acceptKey Acting connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity carrying the injected user
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        $this->identityReads++;

        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * Returns the injected admin flag, or fails the way a lost table does.
     *
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Injected admin flag
     * @throws DatabaseException When the fixture is told the lookup fails
     */
    public function isAdmin(int $userId): bool
    {
        $this->adminReads++;
        if ($this->adminLookupFails) {
            throw new DatabaseException('the users table is gone');
        }

        return $this->admin;
    }

    /**
     * Returns the injected registry, or collects the installation's own.
     *
     * @return ?PiiRegistry Registry the columns are judged by
     */
    protected function viewerPiiRegistry(): ?PiiRegistry
    {
        return $this->registry ?? parent::viewerPiiRegistry();
    }
}

/**
 * Backup catalog naming a class that provides nothing of the sort, so the verdicts cannot be collected.
 */
final class ViewerTestNotAProviderCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => ViewerTestAdminPage::class,
            ],
        ];
    }
}

/**
 * Project facade naming the broken catalog.
 */
final class ViewerTestNotAProviderHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = ViewerTestNotAProviderCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new ViewerTestDbContext();
    }
}
