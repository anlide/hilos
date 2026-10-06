<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Chat\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Chat\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Auth\OAuth\OAuthSettingsCatalog;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Log\LogSettingsPresets;
use Hilos\Pages\DTO\HilosSettingPresetsSignalData;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTableRow;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTableRow;
use Hilos\Tables\Security\HilosSecurityImpersonationTableRow;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTableRow;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTableRow;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTableRow;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTableRow;
use Hilos\Tables\Security\HilosSecurityStepUpTableRow;
use Hilos\Tables\Security\HilosSecurityTwoFactorTableRow;
use Hilos\Tables\Settings\HilosSettingTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for settings, communications, and security admin surfaces under the admin view mode (HIL-1255).
 *
 * Verifies that on settings, preset modes, communications, and security administration surfaces declarations,
 * descriptors, and readiness/layer indicators are preserved for both anonymous and signed-in non-admin viewers,
 * explicitly open framework settings are visible, project keys and orphans remain hidden, and an admin sees every value.
 */
final class AdminViewModeSettingsTest extends IntegrationTestCase
{
    private const string ANONYMOUS_KEY = 'admin-view-mode-settings-anonymous';
    private const string VISITOR_KEY = 'admin-view-mode-settings-visitor';
    private const string ADMIN_KEY = 'admin-view-mode-settings-admin';
    private const string TEST_AGENT = 'admin-view-mode-settings-test';

    private const string TOKEN_SETTING_OVERRIDE = 'viewer-must-not-see-7f3c';
    private const string TOKEN_ORPHAN_SETTING = 'orphan-secret-must-not-leak';
    private const string TOKEN_OAUTH_REDIRECT = 'https://oauth.example.com/callback';

    private const string OVERRIDE_SETTING_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;
    private const string ORPHAN_SETTING_KEY = 'chat.test.orphan.setting';

    /** @var list<int> */
    private array $createdUserIds = [];

    /**
     * Initializes the chat browser context, registers truth sources, enables the view mode, and seeds test data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();

        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new AdminViewModeSettingsRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $visitor = Hilos::$db->users->actions->createWithName('Visitor of the node');
        $visitorId = (int) $visitor->id;

        $admin = Hilos::$db->users->actions->createWithName('Admin of the node');
        $admin->actions->setAdmin(true);
        $adminId = (int) $admin->id;

        $this->createdUserIds = [$visitorId, $adminId];

        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);

        $this->cleanSettings();
        $this->withSettingsWriter(function (): void {
            $catalog = Hilos::$setting->catalog();
            Hilos::$db->settings->actions->add(self::OVERRIDE_SETTING_KEY, self::TOKEN_SETTING_OVERRIDE, $catalog);
            Hilos::$db->settings->actions->addOrphan(
                self::ORPHAN_SETTING_KEY,
                SettingsCatalogConstants::TYPE_STRING,
                self::TOKEN_ORPHAN_SETTING,
                $catalog,
            );
            Hilos::$db->settings->actions->add(OAuthSettingsCatalog::REDIRECT_URI_KEY, self::TOKEN_OAUTH_REDIRECT, $catalog);
        });
    }

    /**
     * Disables the view mode, cleans settings, removes created users, and unregisters claims.
     */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::initBrowser();

        $this->cleanSettings();

        if ($this->createdUserIds !== []) {
            Database::sqlRun('DELETE FROM hilos_identity WHERE user_id IN (' . implode(',', $this->createdUserIds) . ')');
            Database::sqlRun('DELETE FROM hilos_session WHERE user_id IN (' . implode(',', $this->createdUserIds) . ')');
            Database::sqlRun('DELETE FROM hilos_user WHERE id IN (' . implode(',', $this->createdUserIds) . ')');
            $this->createdUserIds = [];
        }
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    /**
     * Cleans up settings seeded for the test.
     */
    private function cleanSettings(): void
    {
        Database::sqlRun('DELETE FROM hilos_setting WHERE `key` IN (?, ?, ?)', [
            self::OVERRIDE_SETTING_KEY,
            self::ORPHAN_SETTING_KEY,
            OAuthSettingsCatalog::REDIRECT_URI_KEY,
        ]);
    }

    /**
     * Runs a callback under the settings library agent holding its truth source claims.
     *
     * @param callable():void $body
     */
    private function withSettingsWriter(callable $body): void
    {
        $agent = new SettingsLibraryAgent();
        OwnershipDeclaration::claimAll($agent);

        try {
            $this->underAgent($agent, $body);
        } finally {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
        }
    }

    /**
     * A viewer receives open framework values, while project values and orphans remain hidden.
     */
    public function testAViewerSeesSettingsAndSecuritySurfacesWithPerKeyValueVisibility(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $settingsFrames = $this->subscribe(SettingsPage::class, [], $acceptKey, [
                ChatTableContext::settings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $settingsRows = $this->window($settingsFrames, ChatTableContext::settings)[TableWindowSignalData::rows];
            $overrideRow = null;
            $orphanRow = null;
            foreach ($settingsRows as $row) {
                $slot = self::rowSlot($row);
                if ($slot[HilosSettingTableRow::key] === self::OVERRIDE_SETTING_KEY) {
                    $overrideRow = $slot;
                } elseif ($slot[HilosSettingTableRow::key] === self::ORPHAN_SETTING_KEY) {
                    $orphanRow = $slot;
                }
            }
            self::assertNotNull($overrideRow);
            self::assertNotNull($orphanRow);
            self::assertSame(self::OVERRIDE_SETTING_KEY, $overrideRow[HilosSettingTableRow::key]);
            self::assertFalse(HiddenValue::isMark($overrideRow[HilosSettingTableRow::type]));
            self::assertSame(HilosSettingTableRow::VALUE_SOURCE_OVERRIDE, $overrideRow[HilosSettingTableRow::valueSource]);
            self::assertTrue(HiddenValue::isMark($overrideRow[HilosSettingTableRow::value]));
            self::assertTrue(HiddenValue::isMark($overrideRow[HilosSettingTableRow::overrideValue]));
            self::assertTrue(HiddenValue::isMark($overrideRow[HilosSettingTableRow::defaultValue]));
            self::assertTrue(HiddenValue::isMark($overrideRow[HilosSettingTableRow::defaultReferenceKey]));

            self::assertSame(self::ORPHAN_SETTING_KEY, $orphanRow[HilosSettingTableRow::key]);
            self::assertSame(HilosSettingTableRow::VALUE_SOURCE_ORPHAN, $orphanRow[HilosSettingTableRow::valueSource]);
            self::assertTrue(HiddenValue::isMark($orphanRow[HilosSettingTableRow::value]));
            self::assertTrue(HiddenValue::isMark($orphanRow[HilosSettingTableRow::overrideValue]));

            $logsFrames = $this->subscribe(LogsSettingsPage::class, [], $acceptKey);
            $logsFrameIndex = array_search(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS, array_column($logsFrames, 'name'), true);
            self::assertIsInt($logsFrameIndex);
            $logsFrameData = $logsFrames[$logsFrameIndex]['data'];
            self::assertNotInstanceOf(HilosSettingPresetsSignalData::class, $logsFrameData);
            $logsData = $logsFrameData->toArray();
            self::assertSame(LogSettingsPresets::GROUP, $logsData[HilosSettingPresetsSignalData::group]);
            self::assertSame(LogSettingsPresets::NORMAL, $logsData[HilosSettingPresetsSignalData::selected]);
            self::assertIsArray($logsData[HilosSettingPresetsSignalData::differences]);
            self::assertNotEmpty($logsData[HilosSettingPresetsSignalData::presets]);
            foreach ($logsData[HilosSettingPresetsSignalData::presets] as $preset) {
                self::assertContains($preset[HilosSettingPresetsSignalData::name], [
                    LogSettingsPresets::FRUGAL,
                    LogSettingsPresets::NORMAL,
                    LogSettingsPresets::INVESTIGATION,
                ]);
                self::assertFalse(HiddenValue::isMark($preset[HilosSettingPresetsSignalData::name]));
                self::assertIsArray($preset[HilosSettingPresetsSignalData::values]);
                foreach ($preset[HilosSettingPresetsSignalData::values] as $value) {
                    self::assertFalse(HiddenValue::isMark($value));
                }
            }

            $commFrames = $this->subscribe(CommunicationsPage::class, [], $acceptKey, [
                ChatTableContext::hilosCommunicationsChannels => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $channelRows = $this->window($commFrames, ChatTableContext::hilosCommunicationsChannels)[TableWindowSignalData::rows];
            self::assertNotEmpty($channelRows);
            foreach ($channelRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelsTableRow::channel]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelsTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelsTableRow::driver]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelsTableRow::configured]));
                self::assertIsInt($slot[HilosCommunicationsChannelsTableRow::missingFields]);
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelsTableRow::missingFields]));
                self::assertIsBool($slot[HilosCommunicationsChannelsTableRow::enabled]);
            }

            $channelFieldsFrames = $this->subscribe(CommunicationsChannelPage::class, [], $acceptKey, [
                ChatTableContext::hilosCommunicationsChannelFields => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $fieldRows = $this->window($channelFieldsFrames, ChatTableContext::hilosCommunicationsChannelFields)[TableWindowSignalData::rows];
            self::assertNotEmpty($fieldRows);
            foreach ($fieldRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::rowKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::channel]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::field]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::type]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::valueSource]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::secret]));
                self::assertFalse(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::editable]));
                self::assertTrue(HiddenValue::isMark($slot[HilosCommunicationsChannelFieldsTableRow::value]));
            }

            $signInFrames = $this->subscribe(SecuritySignInMethodsPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecuritySignInMethods => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $signInRows = $this->window($signInFrames, ChatTableContext::hilosSecuritySignInMethods)[TableWindowSignalData::rows];
            self::assertNotEmpty($signInRows);
            foreach ($signInRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecuritySignInMethodsTableRow::methodKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecuritySignInMethodsTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecuritySignInMethodsTableRow::ready]));
                if ($slot[HilosSecuritySignInMethodsTableRow::providerKey] !== null) {
                    self::assertFalse(HiddenValue::isMark($slot[HilosSecuritySignInMethodsTableRow::providerKey]));
                }
                self::assertIsBool($slot[HilosSecuritySignInMethodsTableRow::enabled]);
            }

            $stepUpFrames = $this->subscribe(SecurityStepUpPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecurityStepUp => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $stepUpRows = $this->window($stepUpFrames, ChatTableContext::hilosSecurityStepUp)[TableWindowSignalData::rows];
            self::assertNotEmpty($stepUpRows);
            foreach ($stepUpRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityStepUpTableRow::operationKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityStepUpTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityStepUpTableRow::owner]));
                self::assertIsBool($slot[HilosSecurityStepUpTableRow::enabled]);
            }

            $twoFactorFrames = $this->subscribe(SecurityTwoFactorPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecurityTwoFactor => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $twoFactorRows = $this->window($twoFactorFrames, ChatTableContext::hilosSecurityTwoFactor)[TableWindowSignalData::rows];
            self::assertNotEmpty($twoFactorRows);
            foreach ($twoFactorRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityTwoFactorTableRow::rowKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityTwoFactorTableRow::value]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityTwoFactorTableRow::defaultValue]));
            }

            $oauthFrames = $this->subscribe(SecurityOAuthPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecurityOauthRedirect => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ChatTableContext::hilosSecurityOauthProviders => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $redirectRows = $this->window($oauthFrames, ChatTableContext::hilosSecurityOauthRedirect)[TableWindowSignalData::rows];
            self::assertCount(1, $redirectRows);
            $redirectSlot = self::rowSlot($redirectRows[0]);
            self::assertSame(OAuthSettingsCatalog::REDIRECT_URI_KEY, $redirectSlot[HilosSecurityOAuthRedirectTableRow::rowKey]);
            self::assertFalse(HiddenValue::isMark($redirectSlot[HilosSecurityOAuthRedirectTableRow::source]));
            self::assertFalse(HiddenValue::isMark($redirectSlot[HilosSecurityOAuthRedirectTableRow::setState]));
            self::assertSame(self::TOKEN_OAUTH_REDIRECT, $redirectSlot[HilosSecurityOAuthRedirectTableRow::value]);

            $providerRows = $this->window($oauthFrames, ChatTableContext::hilosSecurityOauthProviders)[TableWindowSignalData::rows];
            self::assertNotEmpty($providerRows);
            foreach ($providerRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::providerKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::builtIn]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::configured]));
                self::assertIsInt($slot[HilosSecurityOAuthProvidersTableRow::missingFields]);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::missingFields]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::secretSet]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::clientIdSource]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::authorizeUrl]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::tokenUrl]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::userInfoUrl]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::subjectKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::emailKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProvidersTableRow::nameKey]));
            }

            $oauthProviderFrames = $this->subscribe(SecurityOAuthProviderPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecurityOauthProviderFields => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $oauthFieldRows = $this->window($oauthProviderFrames, ChatTableContext::hilosSecurityOauthProviderFields)[TableWindowSignalData::rows];
            self::assertNotEmpty($oauthFieldRows);
            foreach ($oauthFieldRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::rowKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::providerKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::field]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::label]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::type]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::secret]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::source]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::setState]));
                if ($slot[HilosSecurityOAuthProviderFieldsTableRow::field] === 'client_id') {
                    self::assertFalse(HiddenValue::isMark($slot[HilosSecurityOAuthProviderFieldsTableRow::value]));
                }
            }

            $impersonationFrames = $this->subscribe(SecurityImpersonationPage::class, [], $acceptKey, [
                ChatTableContext::hilosSecurityImpersonation => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $impersonationRows = $this->window($impersonationFrames, ChatTableContext::hilosSecurityImpersonation)[TableWindowSignalData::rows];
            self::assertNotEmpty($impersonationRows);
            foreach ($impersonationRows as $row) {
                $slot = self::rowSlot($row);
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityImpersonationTableRow::rowKey]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityImpersonationTableRow::value]));
                self::assertFalse(HiddenValue::isMark($slot[HilosSecurityImpersonationTableRow::defaultValue]));
            }

            $allViewerFrames = [
                ...$settingsFrames,
                ...$logsFrames,
                ...$commFrames,
                ...$channelFieldsFrames,
                ...$signInFrames,
                ...$stepUpFrames,
                ...$twoFactorFrames,
                ...$oauthFrames,
                ...$oauthProviderFrames,
                ...$impersonationFrames,
            ];
            $json = json_encode(self::payloads($allViewerFrames), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::assertStringNotContainsString(self::TOKEN_SETTING_OVERRIDE, $json);
            self::assertStringNotContainsString(self::TOKEN_ORPHAN_SETTING, $json);
            self::assertStringContainsString(self::TOKEN_OAUTH_REDIRECT, $json);
        }
    }

    /**
     * An administrator is sent the settings and security surfaces with no hidden marks and full values.
     */
    public function testAnAdminIsSentSettingsAndSecuritySurfacesWithoutASingleMark(): void
    {
        $allAdminFrames = [
            ...$this->subscribe(SettingsPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::settings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(LogsSettingsPage::class, [], self::ADMIN_KEY),
            ...$this->subscribe(CommunicationsPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosCommunicationsChannels => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(CommunicationsChannelPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosCommunicationsChannelFields => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecuritySignInMethodsPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecuritySignInMethods => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecurityStepUpPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecurityStepUp => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecurityTwoFactorPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecurityTwoFactor => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecurityOAuthPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecurityOauthRedirect => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ChatTableContext::hilosSecurityOauthProviders => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecurityOAuthProviderPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecurityOauthProviderFields => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(SecurityImpersonationPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosSecurityImpersonation => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
        ];

        $json = json_encode(self::payloads($allAdminFrames), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(HiddenValue::KEY, $json);
        self::assertStringContainsString(self::TOKEN_SETTING_OVERRIDE, $json);
        self::assertStringContainsString(self::TOKEN_ORPHAN_SETTING, $json);
        self::assertStringContainsString(self::TOKEN_OAUTH_REDIRECT, $json);

        $logsFrameIndex = array_search(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS, array_column($allAdminFrames, 'name'), true);
        self::assertIsInt($logsFrameIndex);
        $logsFrameData = $allAdminFrames[$logsFrameIndex]['data'];
        self::assertInstanceOf(HilosSettingPresetsSignalData::class, $logsFrameData);
        self::assertSame(LogSettingsPresets::GROUP, $logsFrameData->group);
        self::assertSame(LogSettingsPresets::NORMAL, $logsFrameData->selected);
    }

    /**
     * Extracts the primary row slot payload from a browser row.
     *
     * @param array<string, mixed> $row Table browser row
     * @return array<string, mixed> Primary slot payload
     */
    private static function rowSlot(array $row): array
    {
        $slots = $row[PagePayload::slots] ?? [];

        return is_array($slots) ? (reset($slots) ?: []) : [];
    }

    /**
     * Opens a page under a WebSocket connection and drains the resulting queue of signals.
     *
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @param string $acceptKey Connection accept key
     * @param array<string, TableWindowDescriptorDTO> $tableWindows Windows the tab holds, by table key
     * @return list<array{name: string, data: SignalDataInterface}> Queued browser frames in delivery order
     */
    private function subscribe(
        string $pageClass,
        array $params = [],
        string $acceptKey = self::ANONYMOUS_KEY,
        array $tableWindows = [],
    ): array {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params, $tableWindows));
        if ($tableWindows !== []) {
            Hilos::$sr->reportTableWindows($acceptKey, $tableWindows);
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($pageClass, $params, $acceptKey): void {
            new $pageClass(new DemoHilosAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
        });
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }

        return $frames;
    }

    /**
     * Extracts the window payload of a viewport table from page response payloads.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $tableKey Viewport table whose window is read
     * @return array<string, mixed> The window section of that table
     */
    private function window(array $frames, string $tableKey): array
    {
        foreach (self::payloads($frames) as $payload) {
            $window = $payload[PageResponseSignalData::payload][PagePayload::windows][$tableKey] ?? null;
            if (is_array($window)) {
                return $window;
            }
        }
        self::fail("No page answer carried the window of {$tableKey}");
    }

    /**
     * Extracts wire payload arrays from page response frames.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return list<array<string, mixed>> Wire arrays of the page answers among them
     */
    private static function payloads(array $frames): array
    {
        return array_values(array_map(
            static fn(array $frame): array => $frame['data']->toArray(),
            array_filter($frames, static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE),
        ));
    }
}

/**
 * Router answering from the chat demo's topology rather than the framework's bare one.
 */
final class AdminViewModeSettingsRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
