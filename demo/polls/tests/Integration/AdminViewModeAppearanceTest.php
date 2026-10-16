<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Agents\Hilos\DemoHilosAgent;
use Demo\Polls\Core\Router\PollsSignalRouter;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\AppearancePage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Demo\Polls\Tables\PollsTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTableRow;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The Appearance page as the polls demo activates it: the first subscription of an
 * administrator and of a viewer of the admin view mode, a setting write moving its one row,
 * and the viewer's direct write refused. The writes and the library's answers are the
 * framework's and are covered there; the demo's e2e proves them live.
 *
 * Requires the test DB to be reset before run (composer run test:db-reset).
 */
final class AdminViewModeAppearanceTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string VIEWER_KEY = 'appearance-viewer';
    private const string ADMIN_KEY = 'appearance-admin';

    private ?SignalRouter $previousRouter = null;

    /**
     * Mounts the demo browser, turns the view mode on and opens two connections: a person
     * without admin rights and an administrator.
     *
     * @throws HilosException On database or runtime error
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new PollsSignalRouter();
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $viewerId = (int) Hilos::$db->users->actions->createWithName('Appearance viewer')->id;
        $adminId = (int) Hilos::$db->users->actions->registerAdmin()->id;
        Hilos::$rt->connections->actions->register(self::VIEWER_KEY, $viewerId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        $this->resetThemeSettings();
    }

    /**
     * Puts the two settings back on their catalog defaults and restores the browser,
     * the router and the view mode.
     *
     * @throws HilosException On database or runtime error
     */
    protected function tearDown(): void
    {
        $this->resetThemeSettings();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = $this->previousRouter;
        Hilos::initBrowser();
        parent::tearDown();
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testViewerAndAdminReceiveTheTwoTypedRowsInTheFirstResponse(): void
    {
        foreach ([self::VIEWER_KEY, self::ADMIN_KEY] as $acceptKey) {
            $rows = $this->subscribeRows($acceptKey);
            self::assertSame(ThemeSettingsCatalog::KEYS, array_keys($rows));
            self::assertSame([true, true], $rows[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]);
            self::assertSame(
                [ThemeSettingsCatalog::SYSTEM, ThemeSettingsCatalog::SYSTEM],
                $rows[ThemeSettingsCatalog::DEFAULT_THEME_KEY],
            );
        }
    }

    /**
     * @throws HilosException On database or runtime error
     */
    public function testASettingWriteMovesOnlyItsOwnTypedRow(): void
    {
        $this->subscribeRows(self::VIEWER_KEY);
        $table = Hilos::$table->hilosAppearanceSettings;

        $setting = $this->underSettingsLibrary(static fn () => Hilos::$db->settings->actions->add(
            ThemeSettingsCatalog::DEFAULT_THEME_KEY,
            ThemeSettingsCatalog::DARK,
            Hilos::$setting->catalog(),
        ));
        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::settings,
            (string) $setting->id,
            [ObjectSetting::key => ThemeSettingsCatalog::DEFAULT_THEME_KEY],
        ));
        self::assertSame(ThemeSettingsCatalog::DEFAULT_THEME_KEY, $mutation?->rowKey);
        self::assertSame(
            ThemeSettingsCatalog::DARK,
            $mutation?->row?->toArray()[HilosAppearanceSettingsTableRow::value],
        );
        self::assertSame(
            ThemeSettingsCatalog::SYSTEM,
            $mutation?->row?->toArray()[HilosAppearanceSettingsTableRow::defaultValue],
        );
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::settings,
            '0',
            [ObjectSetting::key => 'some.other.setting'],
        )));

        $this->underSettingsLibrary(
            static fn () => Hilos::$db->settings[ThemeSettingsCatalog::DEFAULT_THEME_KEY]?->actions->delete(),
        );
        $reset = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::settings,
            (string) $setting->id,
            [ObjectSetting::key => ThemeSettingsCatalog::DEFAULT_THEME_KEY],
        ));
        self::assertSame(ThemeSettingsCatalog::DEFAULT_THEME_KEY, $reset?->rowKey);
        self::assertSame(
            ThemeSettingsCatalog::SYSTEM,
            $reset?->row?->toArray()[HilosAppearanceSettingsTableRow::value],
        );
    }

    public function testViewerCannotWriteThemeSwitching(): void
    {
        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(
            AppearancePage::class,
            self::VIEWER_KEY,
            HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET,
        );
    }

    public function testViewerCannotWriteTheDefaultTheme(): void
    {
        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(
            AppearancePage::class,
            self::VIEWER_KEY,
            HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET,
        );
    }

    public function testAdministratorMayWriteBothSettings(): void
    {
        PageAccessGate::assertAction(
            AppearancePage::class,
            self::ADMIN_KEY,
            HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET,
        );
        PageAccessGate::assertAction(
            AppearancePage::class,
            self::ADMIN_KEY,
            HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET,
        );

        $this->addToAssertionCount(2);
    }

    public function testNonAdminIsRefusedWhenViewModeIsOff(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AppearancePage::class, self::VIEWER_KEY);
    }

    /**
     * Subscribes one connection and reads the table's first window out of the one page response.
     *
     * @param string $acceptKey Subscribing connection
     * @return array<string, array{bool|string, bool|string}> Value and default by setting key
     * @throws HilosException On database or runtime error
     */
    private function subscribeRows(string $acceptKey): array
    {
        $this->drainSignals();
        $windows = [PollsTableContext::hilosAppearanceSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AppearancePage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AppearancePage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AppearancePage(new DemoHilosAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $responses[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $responses);
        $window = $responses[0][PageResponseSignalData::payload][PagePayload::windows][PollsTableContext::hilosAppearanceSettings];
        $wireRows = $window[TableWindowSignalData::rows];
        self::assertCount(2, $wireRows);
        $rows = [];
        foreach ($wireRows as $wireRow) {
            $key = $wireRow[BrowserPageSignalData::rowKey];
            $slot = $wireRow[PagePayload::slots]['setting'];
            self::assertSame($key, $slot[HilosAppearanceSettingsTableRow::rowKey]);
            self::assertFalse(HiddenValue::isMark($slot[HilosAppearanceSettingsTableRow::value]));
            self::assertFalse(HiddenValue::isMark($slot[HilosAppearanceSettingsTableRow::defaultValue]));
            $rows[$key] = [
                $slot[HilosAppearanceSettingsTableRow::value],
                $slot[HilosAppearanceSettingsTableRow::defaultValue],
            ];
        }

        return $rows;
    }

    /**
     * Runs a write to the settings table as its one writer, the settings library.
     *
     * @template TReturn
     * @param callable(): TReturn $write The typed write
     * @return TReturn Whatever the write returned
     * @throws HilosException On database or runtime error
     */
    private function underSettingsLibrary(callable $write): mixed
    {
        $library = new SettingsLibraryAgent();
        OwnershipDeclaration::claimAll($library);
        try {
            return ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), $write);
        } finally {
            TruthSourceRegistry::unregisterAgent($library->getId());
            RtTruthSourceRegistry::unregisterAgent($library->getId());
        }
    }

    /**
     * Deletes whichever of the two overrides a case left, through the library's typed action.
     *
     * @throws HilosException On database or runtime error
     */
    private function resetThemeSettings(): void
    {
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            if (Hilos::$db->settings[$key] !== null) {
                $this->underSettingsLibrary(static fn () => Hilos::$db->settings[$key]?->actions->delete());
            }
        }
    }
}
