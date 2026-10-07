<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\AppearancePage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
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
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTableRow;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Integration contract for the read-only Appearance page and its two live setting rows. */
final class AdminViewModeAppearanceTest extends IntegrationTestCase
{
    private const string VISITOR_KEY = 'appearance-viewer';
    private const string ADMIN_KEY = 'appearance-admin';
    private const string TEST_AGENT = 'appearance-test';

    /** @var list<int> */
    private array $createdUserIds = [];

    /** Mounts the demo browser and two connections with different admin rights. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new AppearanceTestRouter();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $viewer = Hilos::$db->users->actions->createWithName('Appearance viewer');
        $admin = Hilos::$db->users->actions->createWithName('Appearance admin');
        $admin->actions->setAdmin(true);
        $this->createdUserIds = [(int) $viewer->id, (int) $admin->id];
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, (int) $viewer->id);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, (int) $admin->id);
        $this->clearThemeOverrides();
    }

    /** Removes fixture rows and restores browser state. */
    protected function tearDown(): void
    {
        $this->clearThemeOverrides();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::initBrowser();
        if ($this->createdUserIds !== []) {
            Database::sqlRun('DELETE FROM hilos_session WHERE user_id IN (' . implode(',', $this->createdUserIds) . ')');
            Database::sqlRun('DELETE FROM hilos_user WHERE id IN (' . implode(',', $this->createdUserIds) . ')');
        }
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testViewerAndAdminReceiveTwoTypedVisibleRowsInFirstResponse(): void
    {
        foreach ([self::VISITOR_KEY, self::ADMIN_KEY] as $acceptKey) {
            $rows = $this->subscribeRows($acceptKey);
            self::assertSame(ThemeSettingsCatalog::KEYS, array_keys($rows));
            self::assertSame([true, true], $rows[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]);
            self::assertSame(
                [ThemeSettingsCatalog::SYSTEM, ThemeSettingsCatalog::SYSTEM],
                $rows[ThemeSettingsCatalog::DEFAULT_THEME_KEY],
            );
        }
    }

    public function testSettingSourceUpdateAndResetRebuildOnlyItsTypedRow(): void
    {
        $this->subscribeRows(self::VISITOR_KEY);
        $table = Hilos::$table->hilosAppearanceSettings;
        $writer = new SettingsLibraryAgent();
        OwnershipDeclaration::claimAll($writer);
        try {
            $setting = $this->underAgent($writer, static fn() => Hilos::$db->settings->actions->add(
                ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
                false,
                Hilos::$setting->catalog(),
            ));
            $change = SourceChange::dbCreated(HilosDbContext::settings, (string) $setting->id, [
                ObjectSetting::key => ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
            ]);
            $mutation = $table->buildMutationForSourceEvent($change);
            self::assertSame(ThemeSettingsCatalog::SWITCHING_ENABLED_KEY, $mutation?->rowKey);
            self::assertSame(false, $mutation?->row?->toArray()[HilosAppearanceSettingsTableRow::value]);
            self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbCreated(
                HilosDbContext::settings,
                '0',
                [ObjectSetting::key => 'some.other.setting'],
            )));

            $this->underAgent($writer, static fn() => Hilos::$db->settings[ThemeSettingsCatalog::SWITCHING_ENABLED_KEY]?->actions->delete());
            $reset = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(
                HilosDbContext::settings,
                (string) $setting->id,
                [ObjectSetting::key => ThemeSettingsCatalog::SWITCHING_ENABLED_KEY],
            ));
            self::assertSame(ThemeSettingsCatalog::SWITCHING_ENABLED_KEY, $reset?->rowKey);
            self::assertSame(true, $reset?->row?->toArray()[HilosAppearanceSettingsTableRow::value]);
        } finally {
            TruthSourceRegistry::unregisterAgent($writer->getId());
            RtTruthSourceRegistry::unregisterAgent($writer->getId());
        }
    }

    public function testViewerCannotWriteAndNonAdminCannotOpenWithoutViewMode(): void
    {
        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(SettingsPage::class, self::VISITOR_KEY, HilosSignalConstants::SETTING_UPDATE);
    }

    public function testNonAdminIsRefusedWhenViewModeIsOff(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AppearancePage::class, self::VISITOR_KEY);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @return array<string, array{bool|string, bool|string}> Values by setting key
     */
    private function subscribeRows(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [ChatTableContext::hilosAppearanceSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
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
        $window = $responses[0][PageResponseSignalData::payload][PagePayload::windows][ChatTableContext::hilosAppearanceSettings];
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

    /** Removes only the two override rows owned by this fixture. */
    private function clearThemeOverrides(): void
    {
        Database::sqlRun('DELETE FROM hilos_setting WHERE `key` IN (?, ?)', ThemeSettingsCatalog::KEYS);
    }
}

/** Uses the chat topology for a page response assembled in the test process. */
final class AppearanceTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> The chat facade */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
