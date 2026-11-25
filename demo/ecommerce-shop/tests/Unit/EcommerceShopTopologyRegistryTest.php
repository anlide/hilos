<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Users\DTO\UserIdentityAddSignalData;
use Demo\EcommerceShop\Pages\Hilos\LicenseDependenciesPage;
use Demo\EcommerceShop\Database\Pages\PageCatalog;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Pages\Hilos\TermsHistoryPage;
use Demo\EcommerceShop\Pages\Hilos\PrivacyHistoryPage;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Core\Page\PageSignalRouter;
use ReflectionClass;
use ReflectionMethod;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\EcommerceShop\Pages\Hilos\AnalyticsPage;
use Demo\EcommerceShop\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\EcommerceShop\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\EcommerceShop\Pages\Hilos\Analytics\PeoplePage;
use Demo\EcommerceShop\Pages\Hilos\Analytics\PersonPage;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\Hilos\DataExportAgent;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosAgent;
use Demo\EcommerceShop\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\EcommerceShop\Agents\Hilos\NotificationsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Auth\EcommerceShopAccountDataOwnerDirectory;
use Hilos\Users\Agent\AbstractUserAgent;
use Demo\EcommerceShop\Agents\Hilos\UsersLibraryAgent;
use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Agents\StockLibraryAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryDeleteActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartSetQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherApplyActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryMoveUpActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryPicturePublishActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryUpdateActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductPicturePublishActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductAddToCartActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductStockSetUnitsActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductUpdateActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductWarehousePlaceActionDTO;
use Demo\EcommerceShop\Pages\DTO\VoucherCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\VoucherToggleActiveActionDTO;
use Demo\EcommerceShop\Pages\DTO\VoucherUpdateActionDTO;
use Demo\EcommerceShop\Pages\DTO\WarehouseCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\WarehouseDeleteActionDTO;
use Demo\EcommerceShop\Pages\DTO\WarehouseUpdateActionDTO;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Core\Agent\Daemon\EcommerceShopAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\WarehousesLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\CategoriesLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\ProductAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\ProductsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\StockLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\GuestCartsLibraryAgentDaemon;
use Demo\EcommerceShop\Core\Agent\Daemon\VouchersLibraryAgentDaemon;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Settings\EcommerceShopSettingsCatalog;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\ProductWarehousePlaceSignalData;
use Demo\EcommerceShop\Core\Router\DTO\StockSetUnitsSignalData;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Groups\Hilos\NotificationsGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\Hilos\Status\StatusPage;
use Demo\EcommerceShop\Pages\Hilos\Status\StatusComponentsPage;
use Demo\EcommerceShop\Pages\Hilos\Status\StatusSubscribersPage;
use Demo\EcommerceShop\Pages\Hilos\Status\StatusWorksPage;
use Demo\EcommerceShop\Pages\Hilos\Status\StatusHistoryPage;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;
use Hilos\Pages\Status\DTO\HilosStatusIncidentActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusAddUpdateActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusRemoveSubscriberActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusCancelWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusChangeWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusScheduleWorkActionDTO;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Demo\EcommerceShop\Pages\Hilos\AboutPage;
use Demo\EcommerceShop\Pages\Hilos\Backup\BackupPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\EcommerceShop\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\EcommerceShop\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\EcommerceShop\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\EcommerceShop\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\EcommerceShop\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\EcommerceShop\Pages\Hilos\Communications\CommunicationsPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\EcommerceShop\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\EcommerceShop\Pages\Hilos\DashboardPage;
use Demo\EcommerceShop\Pages\Hilos\LicensePage;
use Demo\EcommerceShop\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\EcommerceShop\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\EcommerceShop\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\EcommerceShop\Pages\Hilos\Maintenance\MaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceDisableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserContext;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\List\AdminProductCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainProductsBrowserList;
use Demo\EcommerceShop\Pages\Hilos\PrivacyPage;
use Demo\EcommerceShop\Pages\Hilos\ProfileDataPage;
use Demo\EcommerceShop\Pages\Hilos\ProfileNotificationsPage;
use Demo\EcommerceShop\Pages\Hilos\ProfilePage;
use Demo\EcommerceShop\Pages\Hilos\SettingsPage;
use Demo\EcommerceShop\Pages\Hilos\AppearancePage;
use Demo\EcommerceShop\Pages\Hilos\TermsPage;
use Demo\EcommerceShop\Pages\Hilos\Users\UserPage;
use Demo\EcommerceShop\Pages\Hilos\Users\UsersPage;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\CategoryPage;
use Demo\EcommerceShop\Pages\MainPage;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Pages\AdminCategoriesPage;
use Demo\EcommerceShop\Pages\AdminProductsPage;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\AdminProductStockPage;
use Demo\EcommerceShop\Pages\AdminVouchersPage;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Browser\List\AdminProductWarehousesBrowserList;
use Demo\EcommerceShop\Pages\AdminWarehousesPage;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\CLI\DTO\CategoryDeleteCommandData;
use Demo\EcommerceShop\CLI\DTO\ProductTestDeleteCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCodeCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCreateCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureSetActiveCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureUpdateCommandData;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetQuantityCommandData;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetVoucherCommandData;
use Demo\EcommerceShop\CLI\DTO\WarehouseDeleteCommandData;
use Demo\EcommerceShop\CLI\EcommerceShopCliManager;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Database\Pages\PageCatalogConstants;
use Demo\EcommerceShop\Runtime\View\Collection\ProductViewerCounts;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\Category\CategoriesTable;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\HilosUser\HilosUsersTable;
use Demo\EcommerceShop\Tables\Product\CategoryProductsTable;
use Demo\EcommerceShop\Tables\Product\ProductsTable;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Demo\EcommerceShop\Tables\Voucher\VouchersTable;
use Demo\EcommerceShop\Tables\Warehouse\WarehousesTable;
use Hilos\Auth\AccountOperation\AccountErasureExemption;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Throttle\AuthThrottleSettings;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\ProtectedMode\ProtectedModeSettingsCatalog;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\DataExport\DataExportHttp;
use Hilos\Database\Settings\Validation\PositiveIntegerRule;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Image\ImageVariant;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Demo\EcommerceShop\Agents\Hilos\FilesLibraryAgent;
use Demo\EcommerceShop\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Hilos\HilosException;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Appearance\DTO\HilosAppearanceDefaultThemeSetActionDTO;
use Hilos\Pages\Appearance\DTO\HilosAppearanceThemeSwitchingSetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelTestActionDTO;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogRetention;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Demo\EcommerceShop\Pages\Hilos\I18nPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\EcommerceShop\Pages\Hilos\I18n\Lists\CountriesListPage;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Core\Agent\TopologyAgentFactory;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusScheduleWorkSignalData;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\DTO\StatusCancelWorkSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;

/**
 * Guards the project-level ecommerce-shop topology registry.
 *
 * The smallest complete shape: an app agent with its home page, the Hilos index agent with the
 * dashboard, Maintenance, Backup, Settings, Appearance, Users and the four footer pages, sign-in on the
 * framework libraries, and notifications delivered by email. The snapshots below say so. The
 * agent registry closes on the framework's fleet, claimer and ballast probes, which only its
 * cluster stand runs (HIL-1216).
 */
final class EcommerceShopTopologyRegistryTest extends TestCase
{
    /**
     * The asks for a cart reach its owners: a session's to the guest carts library, a person's to that person's agent.
     * The cart's group is the server's alone: no browser can name it.
     */
    public function testTheCartAsksReachTheCartsOwnersAndTheCartGroupIsTheServers(): void
    {
        self::assertSame(GuestCartSendSignalData::class, Hilos::getAgentSignalDtoRoutes()[EcommerceShopSignalConstants::GUEST_CART_SEND]);
        self::assertArrayNotHasKey(EcommerceShopSignalConstants::GUEST_CART_SEND, Hilos::getAgentSignalIndexFields());
        self::assertSame(AccountCartSendSignalData::userId, Hilos::getAgentSignalIndexFields()[EcommerceShopSignalConstants::ACCOUNT_CART_SEND]);
        self::assertSame(AccountCartSendSignalData::class, Hilos::getAgentSignalDtoRoutes()[EcommerceShopSignalConstants::ACCOUNT_CART_SEND]);
        self::assertArrayNotHasKey(CartGroup::NAME, Hilos::GROUPS);
    }

    /** Each leg of a cart add is declared at the address that handles it. */
    public function testProductCartAddRoutesFromTheBoundProductAddressToTheOwnersAndBack(): void
    {
        $action = 'product_add_to_cart';
        self::assertSame([$action => ProductAddToCartActionDTO::class], ProductPage::ACTIONS);
        self::assertSame(ProductPage::PAGE, Hilos::getPageActionRoutes()[$action]);
        self::assertSame(ProductAddToCartActionDTO::class, Hilos::getActionDtoRoutes()[$action]);

        $signals = Hilos::getAgentSignalRoutes();
        $dtos = Hilos::getAgentSignalDtoRoutes();
        $indices = Hilos::getAgentSignalIndexFields();
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, $signals['guest_cart_add_product']);
        self::assertSame(CartAddProductSignalData::class, $dtos['guest_cart_add_product']);
        self::assertArrayNotHasKey('guest_cart_add_product', $indices);
        self::assertSame(HilosAgentType::HILOS_USER, $signals['account_cart_add_product']);
        self::assertSame(CartAddProductSignalData::class, $dtos['account_cart_add_product']);
        self::assertSame('userId', $indices['account_cart_add_product']);
        self::assertSame(AgentType::PRODUCT, $signals['cart_product_add_done']);
        self::assertSame(CartAddProductDoneSignalData::class, $dtos['cart_product_add_done']);
        self::assertSame('productSlug', $indices['cart_product_add_done']);

        $router = new EcommerceShopSignalRouter();
        $source = new SignalSource(SignalSource::WEBSOCKET);
        foreach (['one' => 'morning-meadow', 'two' => 'spring-roses'] as $acceptKey => $slug) {
            $subscribe = new WebSocketPageSubscribeSignalDTO($acceptKey, ProductPage::PAGE, ['slug' => $slug]);
            $router->subscribeToPage(ProductPage::PAGE, $subscribe);
            $router->bindPageAgent($acceptKey, ProductPage::PAGE, AgentType::PRODUCT, $slug);
            $frame = new SignalDTO(
                $source,
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName($action),
                new WebSocketActionSignalDTO($acceptKey, $action, [CartStateSignalData::PRODUCT_ID => 31]),
            );
            self::assertEquals([new AgentDestination(AgentType::PRODUCT, $slug)], $router->getDestinations($frame));
        }
    }

    public function testIdentityAdditionIsDeliveredToTheDeclaredOwnerAndCaller(): void
    {
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(UserIdentityAddSignalData::userId, Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(UserIdentityAddSignalData::class, Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(
            HilosAgentType::HILOS_USERS_LIBRARY,
            Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE],
        );
        self::assertSame(
            UserIdentityAddDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE],
        );
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE, Hilos::getAgentSignalIndexFields());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE, Hilos::getAgentSignalRoutes());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE, Hilos::getAgentSignalRoutes());
        self::assertSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_AGENTS_GONE]);
    }

    /** The dependency snapshot stays a build input on an ordinary public page. */
    public function testLicenseDependenciesUsesThePublicIndexPageWithoutServerData(): void
    {
        self::assertSame('hilos_license_dependencies', LicenseDependenciesPage::PAGE);
        self::assertSame(LicenseDependenciesPage::class, Hilos::PAGES[LicenseDependenciesPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[LicenseDependenciesPage::PAGE]);
        self::assertSame(PageAccessLevel::PUBLIC, LicenseDependenciesPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, LicenseDependenciesPage::REACH);
        self::assertSame([], LicenseDependenciesPage::ACTIONS);
        self::assertSame([], LicenseDependenciesPage::SIGNALS);
        self::assertSame([], LicenseDependenciesPage::BROWSER);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, Hilos::PAGE_TABLES);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, HilosPageCatalog::CATALOG);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, PageCatalog::pages());
        $factory = new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class);
        self::assertInstanceOf(LicenseDependenciesPage::class, $factory->getPage(LicenseDependenciesPage::PAGE));
    }

    /** Every served profile section binds the common personal identity source once. */
    public function testEveryProfileSectionBindsTheIdentityList(): void
    {
        self::assertSame(
            HilosProfileIdentitiesBrowserList::class,
            Hilos::BROWSER_LISTS[HilosProfileIdentitiesBrowserList::LIST],
        );
        foreach ([
            ProfilePage::class,
            ProfileNotificationsPage::class,
            ProfileDataPage::class,
        ] as $page) {
            self::assertSame([HilosProfileIdentitiesBrowserList::class], $page::REQUIRED_LISTS);
            self::assertSame(
                HilosProfileIdentitiesBrowserList::BINDING,
                Hilos::PAGE_LISTS[$page::PAGE][HilosProfileIdentitiesBrowserList::LIST],
            );
        }
    }

    public function testProfileAndProfileDataPagesAreServedByEcommerceShopAgentWithTheirSources(): void
    {
        self::assertSame(ProfilePage::class, Hilos::PAGES[ProfilePage::PAGE]);
        self::assertSame(ProfileDataPage::class, Hilos::PAGES[ProfileDataPage::PAGE]);
        self::assertSame(AgentType::ECOMMERCE_SHOP, ProfilePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::ECOMMERCE_SHOP, ProfileDataPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::ECOMMERCE_SHOP, Hilos::getPageRoutes()[ProfilePage::PAGE]);
        self::assertSame(AgentType::ECOMMERCE_SHOP, Hilos::getPageRoutes()[ProfileDataPage::PAGE]);
        self::assertSame(PageAccessLevel::AUTHENTICATED, ProfilePage::ACCESS_LEVEL);
        self::assertSame(PageAccessLevel::AUTHENTICATED, ProfileDataPage::ACCESS_LEVEL);
        self::assertSame([HilosProfileIdentitiesBrowserList::class], ProfilePage::REQUIRED_LISTS);
        self::assertSame([HilosProfileIdentitiesBrowserList::class], ProfileDataPage::REQUIRED_LISTS);
        self::assertSame(
            [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
            Hilos::PAGE_LISTS[ProfilePage::PAGE],
        );
        self::assertSame(
            [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
            Hilos::PAGE_LISTS[ProfileDataPage::PAGE],
        );
        self::assertContains(HilosDbContext::accountDeletions, ProfilePage::READS_DB);
        self::assertContains(HilosDbContext::dataExports, ProfileDataPage::READS_DB);

        $browser = new EcommerceShopBrowserContext();
        $browser->bindHilosFacade(Hilos::class);

        $profileDbSources = $browser->dbSourceKeysOfPage(ProfilePage::PAGE);
        self::assertContains(EcommerceShopDbContext::identities, $profileDbSources);
        self::assertContains(EcommerceShopDbContext::passkeyCredentials, $profileDbSources);
        self::assertSame([EcommerceShopRtContext::connections], $browser->rtSourceKeysOfPage(ProfilePage::PAGE));

        $dataDbSources = $browser->dbSourceKeysOfPage(ProfileDataPage::PAGE);
        self::assertContains(EcommerceShopDbContext::identities, $dataDbSources);
        self::assertContains(EcommerceShopDbContext::passkeyCredentials, $dataDbSources);
        self::assertSame([EcommerceShopRtContext::connections], $browser->rtSourceKeysOfPage(ProfileDataPage::PAGE));
    }

    public function testPublicLegalHistoryUsesIndexPagesAndSeparatePublicTables(): void
    {
        self::assertSame(TermsHistoryPage::class, Hilos::PAGES[TermsHistoryPage::PAGE]);
        self::assertSame(PrivacyHistoryPage::class, Hilos::PAGES[PrivacyHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[TermsHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[PrivacyHistoryPage::PAGE]);
        self::assertSame(HilosTermsHistoryTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosTermsHistory]);
        self::assertSame(HilosPrivacyHistoryTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosPrivacyHistory]);
        self::assertSame([EcommerceShopTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);
        self::assertSame([], TermsHistoryPage::ACTIONS);
        self::assertSame([], PrivacyHistoryPage::ACTIONS);
        self::assertSame('hilos_terms', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame('hilos_privacy', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
    }

    public function testSessionNameChangesHaveOneAuthSessionOwner(): void
    {
        self::assertContains(HilosFeature::AUTH, Hilos::features());
        $owners = [];
        foreach (Hilos::AGENTS as $type => $entry) {
            $workerClass = AgentRegistry::workerClass($entry);
            if ($workerClass === null) {
                continue;
            }
            $claims = OwnershipDeclaration::rtCollectionsOf($workerClass);
            if (!array_key_exists(HilosProfileNameChange::RT_COLLECTION, $claims)) {
                continue;
            }
            self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
            $owners[$type] = $claims[HilosProfileNameChange::RT_COLLECTION]->asList();
        }
        self::assertSame([
            HilosAgentType::HILOS_SESSIONS_LIBRARY => TruthSourceOperation::ALL,
        ], $owners);
    }

    public function testLetterFrameUsesTheExistingIndexOwnerWithoutTablesOrMutations(): void
    {
        self::assertSame(CommunicationsChannelFramePage::class, Hilos::PAGES[CommunicationsChannelFramePage::PAGE]);
        self::assertSame(CommunicationsChannelConfigurationPage::SUBSCRIPTION_AGENT_TYPE,
            Hilos::getPageRoutes()[CommunicationsChannelFramePage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ], CommunicationsChannelFramePage::BROWSER);
        self::assertSame([], CommunicationsChannelFramePage::ACTIONS);
        self::assertSame([], CommunicationsChannelFramePage::SIGNALS);
        self::assertArrayNotHasKey(CommunicationsChannelFramePage::PAGE, Hilos::PAGE_TABLES);
    }

    /** The shared Status owner is a permanent singleton placed on a regular worker. */
    public function testStatusOwnerUsesTheFrameworkPairAndFactories(): void
    {
        self::assertContains(HilosFeature::STATUS, Hilos::featuresOf(Hilos::class));
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_STATUS];
        self::assertSame([
            AgentRegistryKey::WORKER => StatusAgent::class,
            AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $entry);
        self::assertFalse(AgentRegistry::requiresIndex($entry));
        self::assertNull(AgentRegistry::idleTimeout($entry));
        self::assertSame(StatusAgent::class, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_STATUS, null)::class);
        $daemon = TopologyAgentFactory::createDaemon(Hilos::class, HilosAgentType::HILOS_STATUS, null);
        self::assertSame(StatusAgentDaemon::class, $daemon::class);
        self::assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testWorkerFactoryReadsEachParkingAgentsVerdictDeclaration(): void
    {
        $manager = new ReflectionClass(EcommerceShopWorkerManager::class)->newInstanceWithoutConstructor();
        $factory = new ReflectionMethod(EcommerceShopWorkerManager::class, 'createPageSignalRouter');
        $name = new ReflectionProperty(PageSignalRouter::class, 'throttleVerdictSignal');
        $status = $factory->invoke($manager, new StatusAgent());
        $users = $factory->invoke($manager, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_USERS_LIBRARY, null));
        self::assertInstanceOf(PageSignalRouter::class, $status);
        self::assertInstanceOf(PageSignalRouter::class, $users);
        self::assertSame(HilosSignalConstants::STATUS_THROTTLE_VERDICT, $name->getValue($status));
        self::assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, $name->getValue($users));
    }

    /** Status owns typed guest actions beside the collector response and internal writes. */
    public function testStatusConsumerAndInternalOperationsHaveTypedRoutes(): void
    {
        self::assertSame([
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => StatusRemoveSubscriberSignalData::class,
            HilosSignalConstants::STATUS_SCHEDULE_WORK => StatusScheduleWorkSignalData::class,
            HilosSignalConstants::STATUS_CHANGE_WORK => StatusChangeWorkSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_WORK => StatusCancelWorkSignalData::class,
            HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
        ], StatusAgent::AGENT_SIGNALS);
        self::assertSame(DaemonPictureWatchSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH]);
        self::assertSame(DaemonClusterPicturePortionSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION]);
        self::assertSame(StatusCancelPublicationSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_CANCEL_PUBLICATION]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusPublishNowSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_PUBLISH_NOW]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_PUBLISH_NOW, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_PUBLISH_NOW, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusAddUpdateSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_ADD_UPDATE]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_ADD_UPDATE, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_ADD_UPDATE, StatusAgent::AUTH_ACTIONS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
        ], StatusAgent::AGENT_ACTIONS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW,
            HilosSignalConstants::STATUS_UNSUBSCRIBE,
        ], StatusAgent::THROTTLED_ACTIONS);
        self::assertSame([], StatusAgent::AUTH_ACTIONS);
        self::assertSame([HilosDbContext::sessions], StatusAgent::READS_DB);
        self::assertSame([HilosClusterNode::RT_COLLECTION], StatusAgent::READS_RT);
        foreach ([
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
        ] as $action => $dto) {
            self::assertSame(HilosAgentType::HILOS_STATUS, Hilos::getAgentActionRoutes()[$action]);
            self::assertSame($dto, Hilos::getAgentActionDtoRoutes()[$action]);
        }
    }

    /** The project inherits Service and merges the live publication-delay setting. */
    public function testStatusComponentsAndSettingsUseFrameworkDefaults(): void
    {
        $components = iterator_to_array(Hilos::statusComponentsOf(Hilos::class));
        self::assertSame(['service'], array_keys($components));
        $service = $components['service'];
        self::assertSame([
            'service', 'Service', null, HilosAgentType::HILOS_INDEX, AgentScope::CLUSTER, AgentPlacement::LEADER,
        ], [
            $service->componentKey, $service->name, $service->description, $service->agentType, $service->scope, $service->placement,
        ]);
        self::assertSame([
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 300,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
        ], EcommerceShopSettingsCatalog::getCatalog()[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]);
    }

    /** Status requires its storage and all project-bound admin pages. */
    public function testStatusRequirementsAndSchemaSelectionAreComplete(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::STATUS);
        $requirements = $definition->requirements();
        self::assertSame(
            [HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY, HilosFeature::AUTH_THROTTLE], $requirements->requires,
        );
        self::assertSame([HilosAgentType::HILOS_STATUS], $requirements->requiredAgents);
        self::assertSame([HilosAgentType::HILOS_MAIL], $requirements->requiredSharedAgents);
        self::assertSame([StatusSettingsCatalog::class], $requirements->requiredCatalogFragments);
        self::assertSame([
            'hilos_status_incident', 'hilos_status_incident_update', 'hilos_status_subscriber',
            'hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period', 'hilos_status_silence_period',
            'hilos_status_observed_component',
        ], $requirements->requiredDbTables);
        self::assertSame([AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class,
                AbstractHilosStatusSubscribersPage::class, AbstractHilosStatusWorksPage::class, AbstractHilosStatusHistoryPage::class],
            $requirements->requiredPages);
        self::assertSame([HilosStatusComponentsTable::class, HilosStatusSubscribersTable::class,
                HilosStatusWorksTable::class, HilosStatusHistoryTable::class],
            $requirements->requiredTables);
        self::assertSame([AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class,
            AbstractHilosStatusSubscribersPage::class => HilosStatusSubscribersTable::class,
            AbstractHilosStatusWorksPage::class => HilosStatusWorksTable::class,
            AbstractHilosStatusHistoryPage::class => HilosStatusHistoryTable::class], $requirements->requiredPageTables);
        self::assertTrue($definition->mountsRuntime());

        $statusUnits = [];
        foreach ((new FrameworkSchemaCatalog())->selectedUnits(Hilos::class) as $unit) {
            if (str_starts_with($unit->key, 'status_')) {
                $statusUnits[$unit->key] = [$unit->tables, $unit->dependencies, $unit->connection];
            }
        }
        self::assertSame([
            'status_incident' => [['hilos_status_incident'], [], 0],
            'status_incident_update' => [['hilos_status_incident_update'], ['status_incident'], 0],
            'status_subscriber' => [['hilos_status_subscriber'], [], 0],
            'status_work' => [['hilos_status_work'], [], 0],
            'status_work_component' => [['hilos_status_work_component'], ['status_work'], 0],
            'status_maintenance_period' => [['hilos_status_maintenance_period'], ['status_work'], 0],
            'status_silence_period' => [['hilos_status_silence_period'], [], 0],
            'status_observed_component' => [['hilos_status_observed_component'], [], 0],
        ], $statusUnits);
    }

    /** All three journal pages use the cluster reader and the framework tables. */
    public function testChangeLogSectionIsFullyActivated(): void
    {
        self::assertTrue(Hilos::hasFeature(HilosFeature::CHANGE_LOG));
        foreach ([ChangeLogDashboardPage::class, ChangeLogFeedPage::class, ChangeLogTablesPage::class,
            ChangeLogNoisyPage::class, ChangeLogTablePage::class,
            ChangeLogTableColumnsPage::class, ChangeLogTableTriggersPage::class, ChangeLogTableHistoryPage::class] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_CHANGE_LOG, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        self::assertSame([
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ], Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]);
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope(Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]));
        self::assertTrue((new DemoHilosChangeLogAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(HilosChangeLogFeedTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogFeed]);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogFeed => []], Hilos::PAGE_TABLES[ChangeLogFeedPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogDashboardPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogFeedPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogNoisyPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
            ChangeLogFeedPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
            ChangeLogNoisyPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame([], ChangeLogDashboardPage::ACTIONS);
        self::assertSame(ChangeLogFeedPage::class, Hilos::PAGES[ChangeLogFeedPage::PAGE]);
        self::assertSame(ChangeLogNoisyPage::class, Hilos::PAGES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogNoisyColumnsTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogNoisyColumns]);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogNoisyColumns => []], Hilos::PAGE_TABLES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogTablesTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogTables]);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogTables => []], Hilos::PAGE_TABLES[ChangeLogTablesPage::PAGE]);
        self::assertSame(HilosChangeLogHistoryTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogHistory]);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogHistory => []], Hilos::PAGE_TABLES[ChangeLogTableHistoryPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogTablePage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogTableColumns => []],
            Hilos::PAGE_TABLES[ChangeLogTableColumnsPage::PAGE]);
        self::assertSame(HilosChangeLogTableColumnsTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogTableColumns]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTableColumnsPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame([EcommerceShopTableContext::hilosChangeLogTableTriggers => []],
            Hilos::PAGE_TABLES[ChangeLogTableTriggersPage::PAGE]);
        self::assertSame(HilosChangeLogTableTriggersTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosChangeLogTableTriggers]);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogTableTriggersPage::SUBSCRIPTION_AGENT_TYPE);
        foreach ([
            ChangeLogTablePage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
            ChangeLogTableColumnsPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_COLUMNS,
            ChangeLogTableTriggersPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_TRIGGERS,
            ChangeLogTableHistoryPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_HISTORY,
        ] as $page => $signal) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_CHANGE_LOG, Hilos::getPageRoutes()[$page::PAGE]);
            self::assertSame($signal, $page::BROWSER[BrowserConfigKey::SIGNAL]);
            self::assertSame([], $page::ACTIONS);
        }
    }

    /** The archive address is answered by the export owner, a published file by the files library. */
    public function testFileAndDataExportHttpRoutes(): void
    {
        self::assertSame([
            HttpConstants::METHOD_GET => [
                DataExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_DATA_EXPORT,
                HilosFiles::DOWNLOAD_PATH => HilosAgentType::HILOS_FILES_LIBRARY,
            ],
        ], Hilos::getHttpAgentRoutes());
    }

    /** The export runs once on the policy-selected node and has a worker of its own. */
    public function testDataExportAgentIsPolicyPlacedAndMonopolistic(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_DATA_EXPORT];
        self::assertSame(DataExportAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(DataExportAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertTrue((new DataExportAgentDaemon())->requiresMonopolisticProcess());
    }

    /** A catalog picture is the one upload; four 4:3 copies, three policy-placed agents and framework settings serve it. */
    public function testCatalogPicturesAreActivated(): void
    {
        self::assertSame([
            'catalog_picture' => CatalogPictureUploadTarget::class,
        ], Hilos::UPLOAD_TARGETS);
        self::assertSame(Hilos::UPLOAD_TARGETS, Hilos::uploadTargets());
        self::assertSame([
            'catalog_xs' => [
                ImageVariant::WIDTH => 72,
                ImageVariant::HEIGHT => 54,
                ImageVariant::FIT => ImageFit::COVER,
                ImageVariant::FORMAT => ImageFormat::WEBP,
            ],
            'catalog_sm' => [
                ImageVariant::WIDTH => 128,
                ImageVariant::HEIGHT => 96,
                ImageVariant::FIT => ImageFit::COVER,
                ImageVariant::FORMAT => ImageFormat::WEBP,
            ],
            'catalog_tile' => [
                ImageVariant::WIDTH => 840,
                ImageVariant::HEIGHT => 630,
                ImageVariant::FIT => ImageFit::COVER,
                ImageVariant::FORMAT => ImageFormat::WEBP,
            ],
            'catalog_lg' => [
                ImageVariant::WIDTH => 1200,
                ImageVariant::HEIGHT => 900,
                ImageVariant::FIT => ImageFit::COVER,
                ImageVariant::FORMAT => ImageFormat::WEBP,
            ],
        ], Hilos::IMAGE_VARIANTS);
        self::assertSame(Hilos::IMAGE_VARIANTS, Hilos::imageVariants());

        $target = new CatalogPictureUploadTarget();
        self::assertSame(10485760, $target->maxBytes());
        self::assertSame(['image/jpeg', 'image/png'], $target->acceptedMimeTypes());
        self::assertSame(PageAccessLevel::ADMIN, $target->accessLevel());
        self::assertTrue($target->sniffsContent());
        self::assertSame(FileVisibility::PUBLIC, $target->visibility());
        self::assertFalse($target->requiresUploadHoldForPublication());
        self::assertSame([], $target->extraChecks());

        foreach ([
            HilosAgentType::HILOS_FILES_LIBRARY => [FilesLibraryAgent::class, FilesLibraryAgentDaemon::class],
            HilosAgentType::HILOS_UPLOADS => [UploadsAgent::class, UploadsAgentDaemon::class],
            HilosAgentType::HILOS_IMAGES => [ImagesAgent::class, ImagesAgentDaemon::class],
        ] as $agentType => [$workerClass, $daemonClass]) {
            $entry = Hilos::AGENTS[$agentType];
            self::assertSame($workerClass, AgentRegistry::workerClass($entry));
            self::assertSame($daemonClass, AgentRegistry::daemonClass($entry));
            self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        }

        $catalog = EcommerceShopSettingsCatalog::getCatalog();
        foreach ([
            FilesSettingsCatalog::UNBOUND_TTL_HOURS_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 24,
            ],
            FilesSettingsCatalog::MAX_TOTAL_BYTES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 0,
            ],
            UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 2147483648,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => PositiveIntegerRule::class,
            ],
        ] as $key => $entry) {
            self::assertSame($entry, $catalog[$key]);
        }
    }

    public function testPageRegistryIsTheHomeTheDashboardTheAdminSectionsAndTheFooter(): void
    {
        $this->assertSame([
            MainPage::PAGE => MainPage::class,
            CategoryPage::PAGE => CategoryPage::class,
            ProductPage::PAGE => ProductPage::class,
            CartPage::PAGE => CartPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            AnalyticsPage::PAGE => AnalyticsPage::class,
            BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
            BrowserSessionPage::PAGE => BrowserSessionPage::class,
            PeoplePage::PAGE => PeoplePage::class,
            PersonPage::PAGE => PersonPage::class,
            BackupPage::PAGE => BackupPage::class,
            ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
            ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
            ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
            ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
            ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
            ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
            ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
            ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
            MaintenancePage::PAGE => MaintenancePage::class,
            MaintenanceCirclePage::PAGE => MaintenanceCirclePage::class,
            MaintenanceNodesPage::PAGE => MaintenanceNodesPage::class,
            MaintenanceWordsPage::PAGE => MaintenanceWordsPage::class,
            DaemonPage::PAGE => DaemonPage::class,
            DaemonPlacementPage::PAGE => DaemonPlacementPage::class,
            DaemonNodePage::PAGE => DaemonNodePage::class,
            DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
            DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
            DaemonCronPage::PAGE => DaemonCronPage::class,
            DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
            DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
            DaemonEnvPage::PAGE => DaemonEnvPage::class,
            DaemonEnvOrphansPage::PAGE => DaemonEnvOrphansPage::class,
            DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
            SettingsPage::PAGE => SettingsPage::class,
            AppearancePage::PAGE => AppearancePage::class,
            CommunicationsPage::PAGE => CommunicationsPage::class,
            CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
            CommunicationsChannelConfigurationPage::PAGE => CommunicationsChannelConfigurationPage::class,
            CommunicationsChannelFramePage::PAGE => CommunicationsChannelFramePage::class,
            CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
            ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
            ProfilePage::PAGE => ProfilePage::class,
            ProfileDataPage::PAGE => ProfileDataPage::class,
            I18nPage::PAGE => I18nPage::class,
            LanguagesListPage::PAGE => LanguagesListPage::class,
            CountriesListPage::PAGE => CountriesListPage::class,
            LanguageDetailPage::PAGE => LanguageDetailPage::class,
            LanguageNamesPage::PAGE => LanguageNamesPage::class,
            LanguageLocalesPage::PAGE => LanguageLocalesPage::class,
            CountryDetailPage::PAGE => CountryDetailPage::class,
            CountryNamesPage::PAGE => CountryNamesPage::class,
            UsersPage::PAGE => UsersPage::class,
            UserPage::PAGE => UserPage::class,
            AdminWarehousesPage::PAGE => AdminWarehousesPage::class,
            AdminCategoriesPage::PAGE => AdminCategoriesPage::class,
            AdminProductsPage::PAGE => AdminProductsPage::class,
            AdminProductPage::PAGE => AdminProductPage::class,
            AdminProductStockPage::PAGE => AdminProductStockPage::class,
            AdminVouchersPage::PAGE => AdminVouchersPage::class,
            AboutPage::PAGE => AboutPage::class,
            TermsPage::PAGE => TermsPage::class,
            PrivacyPage::PAGE => PrivacyPage::class,
            TermsHistoryPage::PAGE => TermsHistoryPage::class,
            PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
            LicensePage::PAGE => LicensePage::class,
            LicenseDependenciesPage::PAGE => LicenseDependenciesPage::class,
            StatusPage::PAGE => StatusPage::class,
            StatusComponentsPage::PAGE => StatusComponentsPage::class,
            StatusSubscribersPage::PAGE => StatusSubscribersPage::class,
            StatusWorksPage::PAGE => StatusWorksPage::class,
            StatusHistoryPage::PAGE => StatusHistoryPage::class,
        ], Hilos::PAGES);
    }

    /** Circle alone binds the verifier table and its mutations; Words binds the words table without actions; Overview keeps the manual actions. */
    public function testMaintenanceCircleUsesTheIndexAgentAndOwnsTheTableBinding(): void
    {
        $this->assertSame(MaintenanceCirclePage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_CIRCLE]);
        $this->assertSame(MaintenanceWordsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_WORDS]);
        $this->assertSame(MaintenanceNodesPage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_NODES]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceCirclePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceWordsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceNodesPage::PAGE]);
        $this->assertSame([], MaintenanceWordsPage::ACTIONS);
        $this->assertSame([], MaintenanceNodesPage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
        ], MaintenanceCirclePage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_ENABLE => MaintenanceEnableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_DISABLE => MaintenanceDisableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_PASS => MaintenancePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ], MaintenancePage::ACTIONS);
        $this->assertSame(
            [EcommerceShopTableContext::hilosVerifierCircle => []],
            Hilos::PAGE_TABLES[MaintenanceCirclePage::PAGE],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosMaintenanceWords => []],
            Hilos::PAGE_TABLES[MaintenanceWordsPage::PAGE],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosMaintenanceNodes => []],
            Hilos::PAGE_TABLES[MaintenanceNodesPage::PAGE],
        );
        $this->assertArrayNotHasKey(MaintenancePage::PAGE, Hilos::PAGE_TABLES);
    }

    /** Status uses the existing index agent, one read-only window and the ADMIN handover route. */
    public function testStatusPagesTablesActionsAndReplyAreWired(): void
    {
        self::assertSame(StatusPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS]);
        self::assertSame(StatusComponentsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_COMPONENTS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusComponentsPage::PAGE]);
        self::assertSame(HilosStatusComponentsTable::class, Hilos::TABLES[HilosStatusComponentsTable::TABLE]);
        self::assertSame([HilosStatusComponentsTable::TABLE => []], Hilos::PAGE_TABLES[StatusComponentsPage::PAGE]);
        self::assertArrayNotHasKey(StatusPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([], StatusComponentsPage::ACTIONS);
        self::assertSame(StatusHistoryPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_HISTORY]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusHistoryPage::PAGE]);
        self::assertSame(HilosStatusHistoryTable::class, Hilos::TABLES[HilosStatusHistoryTable::TABLE]);
        self::assertSame([HilosStatusHistoryTable::TABLE => []], Hilos::PAGE_TABLES[StatusHistoryPage::PAGE]);
        self::assertSame([], StatusHistoryPage::ACTIONS);
        self::assertSame([], StatusHistoryPage::SIGNALS);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_HISTORY,
            StatusHistoryPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusWorksPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_WORKS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusWorksPage::PAGE]);
        self::assertSame(HilosStatusWorksTable::class, Hilos::TABLES[HilosStatusWorksTable::TABLE]);
        self::assertSame([HilosStatusWorksTable::TABLE => []], Hilos::PAGE_TABLES[StatusWorksPage::PAGE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE => HilosStatusScheduleWorkActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_WORK_CHANGE => HilosStatusChangeWorkActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_WORK_CANCEL => HilosStatusCancelWorkActionDTO::class,
        ], StatusWorksPage::ACTIONS);
        foreach (StatusWorksPage::ACTIONS as $action => $dto) {
            self::assertSame(StatusWorksPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
            self::assertNotContains($action, StatusWorksPage::READING_ACTIONS);
        }
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_WORKS,
            StatusWorksPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusWorksPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE]);
        self::assertSame(StatusSubscribersPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_SUBSCRIBERS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusSubscribersPage::PAGE]);
        self::assertSame(HilosStatusSubscribersTable::class, Hilos::TABLES[HilosStatusSubscribersTable::TABLE]);
        self::assertSame([HilosStatusSubscribersTable::TABLE => []], Hilos::PAGE_TABLES[StatusSubscribersPage::PAGE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE => HilosStatusRemoveSubscriberActionDTO::class,
        ], StatusSubscribersPage::ACTIONS);
        self::assertSame(StatusSubscribersPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertSame(HilosStatusRemoveSubscriberActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE]);
        self::assertNotContains(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, StatusSubscribersPage::READING_ACTIONS);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_SUBSCRIBERS,
            StatusSubscribersPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusSubscribersPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE]);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => HilosStatusAddUpdateActionDTO::class,
        ], StatusPage::ACTIONS);
        foreach (StatusPage::ACTIONS as $action => $dto) {
            self::assertSame(StatusPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
            self::assertNotContains($action, StatusPage::READING_ACTIONS);
        }
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS, StatusPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_COMPONENTS,
            StatusComponentsPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /**
     * Product uses the slug, Cart uses the connection's owner, and Daemon uses the named node.
     */
    public function testIndexedPagesUseTheAddressDeclaredByTheirRoute(): void
    {
        $routes = Hilos::getPageAgentIndexRoutes();
        $this->assertSame([ProductPage::PAGE, CartPage::PAGE, DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE], array_keys($routes));
        $this->assertSame(AgentType::PRODUCT, Hilos::getPageRoutes()[ProductPage::PAGE]);
        $this->assertSame(PageAccessLevel::PUBLIC, ProductPage::ACCESS_LEVEL);
        $this->assertSame('param', $routes[ProductPage::PAGE]->source->value);
        $this->assertSame('slug', $routes[ProductPage::PAGE]->param);
        $this->assertSame(AgentType::ECOMMERCE_SHOP, $routes[ProductPage::PAGE]->fallbackAgentType);

        self::assertSame('cart', CartPage::PAGE);
        self::assertSame(PageAccessLevel::PUBLIC, CartPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, CartPage::REACH);
        $remove = EcommerceShopSignalConstants::CART_REMOVE_LINE;
        self::assertSame('cart_remove_line', $remove);
        self::assertSame(CartRemoveLineActionDTO::class, Hilos::getActionDtoRoutes()[$remove]);
        self::assertSame(CartPage::PAGE, Hilos::getPageActionRoutes()[$remove]);
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getActionAgentRoutes()[$remove]);
        self::assertNotContains($remove, CartPage::READING_ACTIONS);
        self::assertNotContains($remove, CartPage::AUTH_ACTIONS);
        self::assertArrayNotHasKey($remove, Hilos::getAgentActionRoutes());
        self::assertArrayNotHasKey($remove, Hilos::getAgentSignalRoutes());

        $cartStep = EcommerceShopSignalConstants::CART_STEP_QUANTITY;
        $cartSet = EcommerceShopSignalConstants::CART_SET_QUANTITY;
        self::assertSame('cart_step_quantity', $cartStep);
        self::assertSame('cart_set_quantity', $cartSet);
        self::assertSame(CartPage::PAGE, Hilos::getPageActionRoutes()[$cartStep]);
        self::assertSame(CartStepQuantityActionDTO::class, Hilos::getActionDtoRoutes()[$cartStep]);
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getActionAgentRoutes()[$cartStep]);
        self::assertArrayNotHasKey($cartStep, Hilos::getAgentActionRoutes());
        self::assertArrayNotHasKey($cartStep, Hilos::getAgentActionDtoRoutes());
        self::assertArrayNotHasKey($cartStep, Hilos::getAgentSignalRoutes());
        self::assertSame(CartPage::PAGE, Hilos::getPageActionRoutes()[$cartSet]);
        self::assertSame(CartSetQuantityActionDTO::class, Hilos::getActionDtoRoutes()[$cartSet]);
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getActionAgentRoutes()[$cartSet]);
        self::assertArrayNotHasKey($cartSet, Hilos::getAgentActionRoutes());
        self::assertArrayNotHasKey($cartSet, Hilos::getAgentActionDtoRoutes());
        self::assertArrayNotHasKey($cartSet, Hilos::getAgentSignalRoutes());
        self::assertNotContains($cartSet, CartPage::AUTH_ACTIONS);
        self::assertNotContains($cartSet, CartPage::READING_ACTIONS);

        foreach ([
            EcommerceShopDbContext::sessionVoucherCarts,
            EcommerceShopDbContext::userVoucherCarts,
            EcommerceShopDbContext::vouchers,
        ] as $collection) {
            self::assertContains($collection, CartPage::READS_DB);
        }
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getPageRoutes()[CartPage::PAGE]);
        self::assertSame('session_user_or_guest', $routes[CartPage::PAGE]->source->value);
        self::assertNull($routes[CartPage::PAGE]->param);
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, $routes[CartPage::PAGE]->fallbackAgentType);

        foreach ([DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE] as $page) {
            $this->assertSame('node_param', $routes[$page]->source->value);
            $this->assertSame('nodeId', $routes[$page]->param);
            $this->assertSame(HilosAgentType::HILOS_DAEMON, $routes[$page]->fallbackAgentType);
        }
    }

    /** Applying a voucher uses the live subscription to name the owner for either cart kind. */
    public function testCartVoucherApplicationRoutesOnlyToTheBoundCartOwner(): void
    {
        $action = EcommerceShopSignalConstants::CART_APPLY_VOUCHER;
        self::assertSame('cart_apply_voucher', $action);
        self::assertSame([
            EcommerceShopSignalConstants::CART_REMOVE_LINE => CartRemoveLineActionDTO::class,
            EcommerceShopSignalConstants::CART_STEP_QUANTITY => CartStepQuantityActionDTO::class,
            EcommerceShopSignalConstants::CART_SET_QUANTITY => CartSetQuantityActionDTO::class,
            $action => CartVoucherApplyActionDTO::class,
        ], CartPage::ACTIONS);
        self::assertSame(CartPage::PAGE, Hilos::getPageActionRoutes()[$action]);
        self::assertSame(CartVoucherApplyActionDTO::class, Hilos::getActionDtoRoutes()[$action]);
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getActionAgentRoutes()[$action]);
        self::assertNotContains($action, CartPage::AUTH_ACTIONS);
        self::assertNotContains($action, CartPage::READING_ACTIONS);
        self::assertArrayNotHasKey($action, Hilos::getAgentActionRoutes());
        self::assertArrayNotHasKey($action, Hilos::getAgentSignalRoutes());

        $router = new EcommerceShopSignalRouter();
        foreach ([
            'guest' => [AgentType::GUEST_CARTS_LIBRARY, null],
            'account' => [HilosAgentType::HILOS_USER, '42'],
        ] as $ownerKind => [$agentType, $agentIndex]) {
            $frame = new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::ACTION),
                new SignalName($action),
                new WebSocketActionSignalDTO($ownerKind, $action, ['code' => 'SPRING25', 'ownerKind' => $ownerKind]),
            );
            self::assertSame([], $router->getDestinations($frame));
            $router->subscribeToPage(CartPage::PAGE, new WebSocketPageSubscribeSignalDTO($ownerKind, CartPage::PAGE));
            self::assertSame([], $router->getDestinations($frame));
            $router->bindPageAgent($ownerKind, CartPage::PAGE, $agentType, $agentIndex);
            self::assertEquals([new AgentDestination($agentType, $agentIndex)], $router->getDestinations($frame));
        }
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    /** The person agent is addressed by id and sleeps after the standard idle window. */
    public function testPersonAgentHasTheIndexedPolicyRegistryEntry(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_USER];

        $this->assertSame(UserAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(UserAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertSame(AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC, AgentRegistry::idleTimeout($entry));
        $daemon = new UserAgentDaemon('42');
        $this->assertSame('42', $daemon->getIndex());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    /**
     * The person agent owns its person's cart whole beside the framework's sets, reads the products and the stock
     * a cart line is checked against, and the directory names the cart as the person's.
     */
    public function testPersonAgentOwnsTheCartReadsProductsAndStockAndTheDirectoryNamesTheCart(): void
    {
        self::assertSame(
            [
                ...AbstractUserAgent::OWNS_DB_SET,
                EcommerceShopDbContext::userProductCarts => TruthSourceOperation::ALL,
                EcommerceShopDbContext::userVoucherCarts => TruthSourceOperation::ALL,
            ],
            UserAgent::OWNS_DB_SET,
        );
        self::assertSame(
            [
                ...AbstractUserAgent::READS_DB,
                EcommerceShopDbContext::products,
                EcommerceShopDbContext::productWarehouseStocks,
                EcommerceShopDbContext::vouchers,
            ],
            UserAgent::READS_DB,
        );
        self::assertSame([...AbstractUserAgent::READS_RT, EcommerceShopRtContext::connections], UserAgent::READS_RT);
        self::assertSame(
            [EcommerceShopDbContext::userProductCarts, EcommerceShopDbContext::userVoucherCarts],
            EcommerceShopAccountDataOwnerDirectory::declaredPersonal(),
        );
    }

    /** The agent of a product address reads the catalog and connections and owns that address's viewer count. */
    public function testProductAgentHasTheIndexedPolicyRegistryEntryAndOwnsItsViewerCount(): void
    {
        $entry = Hilos::AGENTS[AgentType::PRODUCT];

        $this->assertSame(ProductAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(ProductAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertSame(AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC, AgentRegistry::idleTimeout($entry));
        $daemon = new ProductAgentDaemon('morning-meadow');
        $this->assertSame('morning-meadow', $daemon->getIndex());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
        $this->assertSame([
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::productSlugs,
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::categories,
            EcommerceShopDbContext::sessionProductCarts,
            EcommerceShopDbContext::userProductCarts,
        ], ProductAgent::READS_DB);
        $this->assertSame([], ProductAgent::OWNS_DB);
        $this->assertSame([], ProductAgent::OWNS_DB_ROWS);
        $this->assertSame([], ProductAgent::OWNS_DB_SET);
        $this->assertSame([], ProductAgent::OWNS_RT);
        $this->assertSame([EcommerceShopRtContext::connections], ProductAgent::READS_RT);
        $this->assertSame([EcommerceShopRtContext::productViewerCounts => TruthSourceOperation::BY_KIND], ProductAgent::OWNS_RT_ROWS);
        $agent = new ProductAgent('morning-meadow');
        $this->assertSame(['morning-meadow'], $agent->ownedRtRowKeys(EcommerceShopRtContext::productViewerCounts));
        $this->assertSame([], $agent->ownedRtRowKeys(EcommerceShopRtContext::connections));
    }

    public function testProductViewerCountsIsMountedUnderItsRuntimeKey(): void
    {
        $context = new EcommerceShopRtContext();
        $context->configure();

        self::assertInstanceOf(ProductViewerCounts::class, $context->getRtCollection(EcommerceShopRtContext::productViewerCounts));
    }

    public function testAgentRegistryEntriesAreConcreteAndConsistent(): void
    {
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $daemonClass = AgentRegistry::daemonClass($registryEntry);

            $this->assertIsString($workerClass);
            $this->assertIsString($daemonClass);
            $this->assertTrue(class_exists($workerClass), "{$workerClass} must be a concrete worker class");
            $this->assertTrue(class_exists($daemonClass), "{$daemonClass} must be a concrete daemon class");
            $this->assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    public function testAgentRegistryIsTheAppItsCatalogLibrariesTheIndexSignInTheAdminSectionsTheFilesAndTheClusterProbes(): void
    {
        $this->assertSame([
            AgentType::ECOMMERCE_SHOP,
            AgentType::WAREHOUSES_LIBRARY,
            AgentType::CATEGORIES_LIBRARY,
            AgentType::PRODUCTS_LIBRARY,
            AgentType::STOCK_LIBRARY,
            AgentType::GUEST_CARTS_LIBRARY,
            AgentType::VOUCHERS_LIBRARY,
            AgentType::PRODUCT,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_ANALYTICS,
            AgentType::HILOS_CHANGE_LOG,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            AgentType::HILOS_DAEMON,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosAgentType::HILOS_USER,
            HilosAgentType::HILOS_USERS_LIBRARY,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_I18N_LIBRARY,
            HilosAgentType::HILOS_FILES_LIBRARY,
            HilosAgentType::HILOS_UPLOADS,
            HilosAgentType::HILOS_IMAGES,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_AUTH_THROTTLE,
            HilosAgentType::HILOS_BACKUP,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_STATUS,
            HilosAgentType::HILOS_PROBE_FLEET,
            HilosAgentType::HILOS_PROBE_CLAIMER,
            HilosAgentType::HILOS_PROBE_BALLAST,
        ], array_keys(Hilos::AGENTS));
    }

    /** The warehouses library runs once on the policy-selected node, in a worker of its own, and owns the warehouses whole. */
    public function testWarehousesLibraryIsPolicyPlacedMonopolisticAndOwnsWarehouses(): void
    {
        $entry = Hilos::AGENTS[AgentType::WAREHOUSES_LIBRARY];
        self::assertSame(WarehousesLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(WarehousesLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new WarehousesLibraryAgentDaemon())->requiresMonopolisticProcess());
        $claims = OwnershipDeclaration::dbCollectionsOf(WarehousesLibraryAgent::class);
        self::assertSame([EcommerceShopDbContext::warehouses], array_keys($claims));
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::warehouses]->asList());
        self::assertSame(
            [EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE => WarehouseDeleteCommandData::class],
            WarehousesLibraryAgent::AGENT_COMMANDS,
        );
        self::assertSame('test:ecommerce-shop:warehouse-delete', EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE);
    }

    /** The categories library runs once on the policy-selected node, in a worker of its own, and owns the categories and their addresses whole. */
    public function testCategoriesLibraryIsPolicyPlacedMonopolisticAndOwnsCategoriesAndTheirAddresses(): void
    {
        $entry = Hilos::AGENTS[AgentType::CATEGORIES_LIBRARY];
        self::assertSame(CategoriesLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(CategoriesLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new CategoriesLibraryAgentDaemon())->requiresMonopolisticProcess());
        $claims = OwnershipDeclaration::dbCollectionsOf(CategoriesLibraryAgent::class);
        self::assertSame([EcommerceShopDbContext::categories, EcommerceShopDbContext::categorySlugs], array_keys($claims));
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::categories]->asList());
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::categorySlugs]->asList());
        self::assertSame([HilosDbContext::files], CategoriesLibraryAgent::readDbCollections());
        self::assertSame(
            [EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED => FilesPublishedSignalData::class],
            CategoriesLibraryAgent::AGENT_SIGNALS,
        );
        self::assertSame('category_picture_published', EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED);
        self::assertSame(
            [EcommerceShopCommandConstants::CATEGORY_TEST_DELETE => CategoryDeleteCommandData::class],
            CategoriesLibraryAgent::AGENT_COMMANDS,
        );
        self::assertSame('test:ecommerce-shop:category-delete', EcommerceShopCommandConstants::CATEGORY_TEST_DELETE);
    }

    /**
     * The products library holds the products and their addresses whole. It reads categories and files
     * and answers picture publications and fixture cleanup.
     */
    public function testProductsLibraryPlacementOwnershipAndRoutes(): void
    {
        $entry = Hilos::AGENTS[AgentType::PRODUCTS_LIBRARY];
        self::assertSame(ProductsLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(ProductsLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new ProductsLibraryAgentDaemon())->requiresMonopolisticProcess());
        $claims = OwnershipDeclaration::dbCollectionsOf(ProductsLibraryAgent::class);
        self::assertSame([EcommerceShopDbContext::products, EcommerceShopDbContext::productSlugs], array_keys($claims));
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::products]->asList());
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::productSlugs]->asList());
        self::assertSame([EcommerceShopDbContext::categories, HilosDbContext::files], ProductsLibraryAgent::readDbCollections());
        self::assertSame(
            [EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED => FilesPublishedSignalData::class],
            ProductsLibraryAgent::AGENT_SIGNALS,
        );
        self::assertSame('product_picture_published', EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED);
        self::assertSame(
            [EcommerceShopCommandConstants::PRODUCT_TEST_DELETE => ProductTestDeleteCommandData::class],
            ProductsLibraryAgent::AGENT_COMMANDS,
        );
        self::assertSame('test:ecommerce-shop:product-delete', EcommerceShopCommandConstants::PRODUCT_TEST_DELETE);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getCommandAgentRoutes()[EcommerceShopCommandConstants::PRODUCT_TEST_DELETE]);
        self::assertSame(
            ProductTestDeleteCommandData::class,
            Hilos::getCommandDtoRoutes()[EcommerceShopCommandConstants::PRODUCT_TEST_DELETE],
        );
    }

    /**
     * The stock library runs once on the policy-selected node, in a worker of its own, owns the stock whole and reads
     * the products and the warehouses.
     */
    public function testStockLibraryIsPolicyPlacedMonopolisticOwnsTheStockAndReadsProductsAndWarehouses(): void
    {
        $entry = Hilos::AGENTS[AgentType::STOCK_LIBRARY];
        self::assertSame(StockLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(StockLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new StockLibraryAgentDaemon())->requiresMonopolisticProcess());
        $claims = OwnershipDeclaration::dbCollectionsOf(StockLibraryAgent::class);
        self::assertSame([EcommerceShopDbContext::productWarehouseStocks], array_keys($claims));
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::productWarehouseStocks]->asList());
        self::assertSame([EcommerceShopDbContext::products, EcommerceShopDbContext::warehouses], StockLibraryAgent::readDbCollections());
        $ask = 'stock_set_units';
        self::assertSame(AgentType::STOCK_LIBRARY, Hilos::getAgentSignalRoutes()[$ask]);
        self::assertSame(StockSetUnitsSignalData::class, Hilos::getAgentSignalDtoRoutes()[$ask]);
        self::assertArrayNotHasKey($ask, Hilos::getActionAgentRoutes());

        $signal = EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE;
        self::assertSame('stock_place_unique', $signal);
        self::assertSame(
            [$signal => ProductWarehousePlaceSignalData::class, $ask => StockSetUnitsSignalData::class],
            StockLibraryAgent::AGENT_SIGNALS,
        );
        self::assertSame(AgentType::STOCK_LIBRARY, Hilos::getAgentSignalRoutes()[$signal]);
        self::assertSame(ProductWarehousePlaceSignalData::class, Hilos::getAgentSignalDtoRoutes()[$signal]);
    }

    /**
     * The guest carts library runs once on the policy-selected node, in a worker of its own, owns the guests' carts
     * whole, reads the products and the stock, and is the one addressee of the sessions sweep.
     */
    public function testGuestCartsLibraryIsPolicyPlacedMonopolisticOwnsTheCartsReadsProductsAndStockAndTakesTheSweep(): void
    {
        $entry = Hilos::AGENTS[AgentType::GUEST_CARTS_LIBRARY];
        self::assertSame(GuestCartsLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(GuestCartsLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new GuestCartsLibraryAgentDaemon())->requiresMonopolisticProcess());
        $claims = OwnershipDeclaration::dbCollectionsOf(GuestCartsLibraryAgent::class);
        self::assertSame([EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::sessionVoucherCarts], array_keys($claims));
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::sessionProductCarts]->asList());
        self::assertSame(TruthSourceOperation::ALL, $claims[EcommerceShopDbContext::sessionVoucherCarts]->asList());
        self::assertSame(
            [
                EcommerceShopDbContext::products,
                EcommerceShopDbContext::productWarehouseStocks,
                EcommerceShopDbContext::vouchers,
                HilosDbContext::sessions,
            ],
            GuestCartsLibraryAgent::readDbCollections(),
        );
        self::assertSame([EcommerceShopRtContext::connections], GuestCartsLibraryAgent::READS_RT);
        self::assertSame(
            AccountErasureExemption::OutsideAccount,
            EcommerceShopAccountDataOwnerDirectory::declaredExemptions()[EcommerceShopDbContext::sessionVoucherCarts],
        );
        self::assertSame(
            [
                HilosSignalConstants::HILOS_SESSIONS_SWEPT => SessionsSweptSignalData::class,
                EcommerceShopSignalConstants::GUEST_CART_SEND => GuestCartSendSignalData::class,
                EcommerceShopSignalConstants::GUEST_CART_ADD_PRODUCT => CartAddProductSignalData::class,
            ],
            GuestCartsLibraryAgent::AGENT_SIGNALS,
        );
    }

    /** The socket routes the guest cart fixture straight to the guest carts library. */
    public function testGuestCartFixtureCommandIsRoutedWithItsPayload(): void
    {
        $command = 'test:ecommerce-shop:guest-cart-set-quantity';
        self::assertSame(
            [
                $command => GuestCartSetQuantityCommandData::class,
                'test:ecommerce-shop:guest-cart-set-voucher' => GuestCartSetVoucherCommandData::class,
            ],
            GuestCartsLibraryAgent::AGENT_COMMANDS,
        );
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, Hilos::getCommandAgentRoutes()[$command]);
        self::assertSame(GuestCartSetQuantityCommandData::class, Hilos::getCommandDtoRoutes()[$command]);
    }

    /** The voucher fixture has a direct socket route and no executable CLI entry. */
    public function testGuestCartVoucherFixtureIsRoutedWithItsPayload(): void
    {
        $command = 'test:ecommerce-shop:guest-cart-set-voucher';
        self::assertSame(AgentType::GUEST_CARTS_LIBRARY, Hilos::getCommandAgentRoutes()[$command]);
        self::assertSame(GuestCartSetVoucherCommandData::class, Hilos::getCommandDtoRoutes()[$command]);
        self::assertArrayNotHasKey($command, (new EcommerceShopCliManager(['cli.php']))->commandClasses());
    }

    /** The vouchers library runs once on the policy-selected node and gains Remove only in the test environment. */
    public function testVouchersLibraryOwnsVouchersWithEnvironmentSpecificOperations(): void
    {
        $entry = Hilos::AGENTS[AgentType::VOUCHERS_LIBRARY];
        self::assertSame(VouchersLibraryAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(VouchersLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertTrue((new VouchersLibraryAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame([], VouchersLibraryAgent::readDbCollections());

        $ordinaryOperations = [TruthSourceOperation::Add, TruthSourceOperation::Update];
        $previousEnv = Hilos::$env;
        try {
            Hilos::$env = null;
            $claims = OwnershipDeclaration::dbCollectionsOf(VouchersLibraryAgent::class);
            self::assertSame([EcommerceShopDbContext::vouchers], array_keys($claims));
            self::assertSame($ordinaryOperations, $claims[EcommerceShopDbContext::vouchers]->asList());

            foreach ([AppEnv::DEV->value, AppEnv::PROD->value, 'unrecognized', null, AppEnv::TEST->value] as $environment) {
                $env = $this->createPartialMock(EnvAccessor::class, ['effectiveValueFor']);
                $env->method('effectiveValueFor')->with(EnvConstants::APP_ENV->name)->willReturn($environment);
                Hilos::$env = $env;

                $claims = OwnershipDeclaration::dbCollectionsOf(VouchersLibraryAgent::class);
                self::assertSame(
                    $environment === AppEnv::TEST->value ? [...$ordinaryOperations, TruthSourceOperation::Remove] : $ordinaryOperations,
                    $claims[EcommerceShopDbContext::vouchers]->asList(),
                );
            }
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    /** Each exact CLI name goes to the existing library under its own typed command route. */
    public function testVoucherFixtureCommandsAreCliRegisteredAndDaemonRouted(): void
    {
        $routes = [
            'test:ecommerce-shop:voucher-fixture-create' => VoucherFixtureCreateCommandData::class,
            'test:ecommerce-shop:voucher-fixture-get' => VoucherFixtureCodeCommandData::class,
            'test:ecommerce-shop:voucher-fixture-update' => VoucherFixtureUpdateCommandData::class,
            'test:ecommerce-shop:voucher-fixture-set-active' => VoucherFixtureSetActiveCommandData::class,
            'test:ecommerce-shop:voucher-fixture-delete' => VoucherFixtureCodeCommandData::class,
        ];
        self::assertSame($routes, VouchersLibraryAgent::AGENT_COMMANDS);
        $cli = new EcommerceShopCliManager(['cli.php']);
        $classes = $cli->commandClasses();
        $executions = $cli->executions();
        foreach ($routes as $name => $dto) {
            self::assertArrayHasKey($name, $classes);
            self::assertFalse($cli->requiresDatabase($name));
            self::assertSame(CommandExecutionSite::DAEMON, $executions[$name]->site);
            self::assertSame(AgentType::VOUCHERS_LIBRARY, Hilos::getCommandAgentRoutes()[$name]);
            self::assertSame($dto, Hilos::getCommandDtoRoutes()[$name]);
        }
    }

    /** The project CLI refuses these fixtures in dev as well as in production, before reading a channel address. */
    public function testVoucherFixtureCliRefusesOutsideTest(): void
    {
        $code = 'ZZE2EV000000000000000000000000';
        $commands = [
            [EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE, $code, '--discount-percent=25', '--active=on'],
            [EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, $code],
            [EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE, $code, '--discount-percent=50'],
            [EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE, $code, '--active=off'],
            [EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, $code],
        ];
        $previousEnv = Hilos::$env;
        try {
            foreach ([AppEnv::DEV, AppEnv::PROD] as $environment) {
                $env = $this->createPartialMock(EnvAccessor::class, ['effectiveValueFor']);
                $env->method('effectiveValueFor')->with(EnvConstants::APP_ENV->name)->willReturn($environment->value);
                Hilos::$env = $env;

                foreach ($commands as $args) {
                    self::assertSame(ExitCode::ERROR, new EcommerceShopCliManager(['cli.php', ...$args])->run());
                }
            }
            $this->expectOutputString('');
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    /** The warehouses table is registered under its key, for the page that binds it. */
    public function testTheWarehousesTableIsRegistered(): void
    {
        self::assertSame(WarehousesTable::class, Hilos::TABLES[EcommerceShopTableContext::warehouses]);
    }

    /** The vouchers table is registered under its key, with the voucher collection as its only source. */
    public function testTheVouchersTableIsRegistered(): void
    {
        self::assertSame(VouchersTable::class, Hilos::TABLES[EcommerceShopTableContext::vouchers]);
        self::assertSame(
            [EcommerceShopBrowserSource::DB_VOUCHERS],
            VouchersTable::BROWSER[BrowserTableConfigKey::SOURCES],
        );
    }

    /**
     * The vouchers admin page: an admin-level route served by the vouchers library, binding the
     * vouchers table, answering on its own signal, and reached from the dashboard's shop group.
     */
    public function testTheVouchersAdminPageIsServedByTheLibraryAndHangsOffTheDashboard(): void
    {
        self::assertSame('admin_vouchers', AdminVouchersPage::PAGE);
        self::assertSame(PageReach::ROUTE, AdminVouchersPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AdminVouchersPage::ACCESS_LEVEL);
        self::assertSame(AgentType::VOUCHERS_LIBRARY, Hilos::getPageRoutes()[AdminVouchersPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::vouchers => []], Hilos::PAGE_TABLES[AdminVouchersPage::PAGE]);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_VOUCHERS],
            AdminVouchersPage::BROWSER,
        );
        self::assertSame('subscription_page_admin_vouchers', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_VOUCHERS);
        $create = EcommerceShopSignalConstants::VOUCHER_CREATE;
        $update = EcommerceShopSignalConstants::VOUCHER_UPDATE;
        $toggle = EcommerceShopSignalConstants::VOUCHER_TOGGLE_ACTIVE;
        self::assertSame('voucher_create', $create);
        self::assertSame('voucher_update', $update);
        self::assertSame('voucher_toggle_active', $toggle);
        self::assertSame([
            $create => VoucherCreateActionDTO::class,
            $update => VoucherUpdateActionDTO::class,
            $toggle => VoucherToggleActiveActionDTO::class,
        ], AdminVouchersPage::ACTIONS);
        self::assertSame('admin_vouchers', Hilos::getPageActionRoutes()[$create]);
        self::assertSame('vouchers_library', Hilos::getActionAgentRoutes()[$create]);
        self::assertSame(VoucherCreateActionDTO::class, Hilos::getActionDtoRoutes()[$create]);
        self::assertSame('admin_vouchers', Hilos::getPageActionRoutes()[$update]);
        self::assertSame('vouchers_library', Hilos::getActionAgentRoutes()[$update]);
        self::assertSame(VoucherUpdateActionDTO::class, Hilos::getActionDtoRoutes()[$update]);
        self::assertSame('admin_vouchers', Hilos::getPageActionRoutes()[$toggle]);
        self::assertSame('vouchers_library', Hilos::getActionAgentRoutes()[$toggle]);
        self::assertSame(VoucherToggleActiveActionDTO::class, Hilos::getActionDtoRoutes()[$toggle]);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Vouchers',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Discount codes buyers enter in the cart.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Vouchers',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], PageCatalog::pages()[PageConstants::ADMIN_VOUCHERS]);
    }

    /**
     * The warehouses admin page: an admin-level route served by the warehouses library, binding the
     * warehouses table, answering on its own signal, and reached from the dashboard's shop group.
     */
    public function testTheWarehousesAdminPageIsServedByTheLibraryAndHangsOffTheDashboard(): void
    {
        self::assertSame('admin_warehouses', AdminWarehousesPage::PAGE);
        self::assertSame(PageAccessLevel::ADMIN, AdminWarehousesPage::ACCESS_LEVEL);
        self::assertSame(AgentType::WAREHOUSES_LIBRARY, Hilos::getPageRoutes()[AdminWarehousesPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::warehouses => []], Hilos::PAGE_TABLES[AdminWarehousesPage::PAGE]);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES],
            AdminWarehousesPage::BROWSER,
        );
        self::assertSame('subscription_page_admin_warehouses', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Warehouses',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Where the stock is kept.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Warehouses',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], PageCatalog::pages()[PageConstants::ADMIN_WAREHOUSES]);
        self::assertSame(
            [
                EcommerceShopSignalConstants::WAREHOUSE_CREATE => WarehouseCreateActionDTO::class,
                EcommerceShopSignalConstants::WAREHOUSE_UPDATE => WarehouseUpdateActionDTO::class,
                EcommerceShopSignalConstants::WAREHOUSE_DELETE => WarehouseDeleteActionDTO::class,
            ],
            AdminWarehousesPage::ACTIONS,
        );
        self::assertSame('admin_warehouses', Hilos::getPageActionRoutes()[EcommerceShopSignalConstants::WAREHOUSE_CREATE]);
        self::assertSame(
            AgentType::WAREHOUSES_LIBRARY,
            Hilos::getActionAgentRoutes()[EcommerceShopSignalConstants::WAREHOUSE_CREATE],
        );
        self::assertSame('warehouse_create', EcommerceShopSignalConstants::WAREHOUSE_CREATE);
        self::assertSame('admin_warehouses', Hilos::getPageActionRoutes()[EcommerceShopSignalConstants::WAREHOUSE_UPDATE]);
        self::assertSame(
            AgentType::WAREHOUSES_LIBRARY,
            Hilos::getActionAgentRoutes()[EcommerceShopSignalConstants::WAREHOUSE_UPDATE],
        );
        self::assertSame('warehouse_update', EcommerceShopSignalConstants::WAREHOUSE_UPDATE);
        self::assertSame('admin_warehouses', Hilos::getPageActionRoutes()[EcommerceShopSignalConstants::WAREHOUSE_DELETE]);
        self::assertSame(
            AgentType::WAREHOUSES_LIBRARY,
            Hilos::getActionAgentRoutes()[EcommerceShopSignalConstants::WAREHOUSE_DELETE],
        );
        self::assertSame('warehouse_delete', EcommerceShopSignalConstants::WAREHOUSE_DELETE);
    }

    /** The dashboard's shop group lists the shop's admin pages in the order their cards stand. */
    public function testTheShopAdministrationSectionListsItsPagesInOrder(): void
    {
        self::assertSame([
            [
                PageCatalogConstants::SECTION_TITLE => 'Shop administration',
                PageCatalogConstants::SECTION_DESCRIPTION => 'Warehouses, catalog, and vouchers.',
                PageCatalogConstants::SECTION_ITEMS => [
                    PageConstants::ADMIN_WAREHOUSES,
                    PageConstants::ADMIN_CATEGORIES,
                    PageConstants::ADMIN_PRODUCTS,
                    PageConstants::ADMIN_VOUCHERS,
                ],
            ],
        ], PageCatalog::dashboardSections());
    }

    /** The categories table is registered under its key, for the page that binds it. */
    public function testTheCategoriesTableIsRegistered(): void
    {
        self::assertSame(CategoriesTable::class, Hilos::TABLES[EcommerceShopTableContext::categories]);
    }

    /**
     * The categories admin page: an admin-level route served by the categories library, binding the
     * categories table, answering on its own signal, and reached from the dashboard's shop group.
     */
    public function testTheCategoriesAdminPageIsServedByTheLibraryAndHangsOffTheDashboard(): void
    {
        self::assertSame('admin_categories', AdminCategoriesPage::PAGE);
        self::assertSame(PageAccessLevel::ADMIN, AdminCategoriesPage::ACCESS_LEVEL);
        self::assertSame(AgentType::CATEGORIES_LIBRARY, Hilos::getPageRoutes()[AdminCategoriesPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::categories => []], Hilos::PAGE_TABLES[AdminCategoriesPage::PAGE]);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_CATEGORIES],
            AdminCategoriesPage::BROWSER,
        );
        self::assertSame('subscription_page_admin_categories', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_CATEGORIES);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Categories',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'How the catalog is grouped on the shop front.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Categories',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], PageCatalog::pages()[PageConstants::ADMIN_CATEGORIES]);
        self::assertSame(
            [
                EcommerceShopSignalConstants::CATEGORY_MOVE_UP => CategoryMoveUpActionDTO::class,
                EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH => CategoryPicturePublishActionDTO::class,
                EcommerceShopSignalConstants::CATEGORY_CREATE => CategoryCreateActionDTO::class,
                EcommerceShopSignalConstants::CATEGORY_UPDATE => CategoryUpdateActionDTO::class,
                EcommerceShopSignalConstants::CATEGORY_DELETE => CategoryDeleteActionDTO::class,
            ],
            AdminCategoriesPage::ACTIONS,
        );
        foreach (
            [
                EcommerceShopSignalConstants::CATEGORY_MOVE_UP,
                EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH,
                EcommerceShopSignalConstants::CATEGORY_CREATE,
                EcommerceShopSignalConstants::CATEGORY_UPDATE,
                EcommerceShopSignalConstants::CATEGORY_DELETE,
            ] as $action
        ) {
            self::assertSame('admin_categories', Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::CATEGORIES_LIBRARY, Hilos::getActionAgentRoutes()[$action]);
        }
        self::assertSame('category_move_up', EcommerceShopSignalConstants::CATEGORY_MOVE_UP);
        self::assertSame('category_picture_publish', EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH);
        self::assertSame('category_create', EcommerceShopSignalConstants::CATEGORY_CREATE);
        self::assertSame('category_update', EcommerceShopSignalConstants::CATEGORY_UPDATE);
        self::assertSame('category_delete', EcommerceShopSignalConstants::CATEGORY_DELETE);
    }

    /** The products table is registered under its key, for the page that binds it. */
    public function testTheProductsTableIsRegistered(): void
    {
        self::assertSame(ProductsTable::class, Hilos::TABLES[EcommerceShopTableContext::products]);
    }

    /** The public category uses the categories library and binds its products and stock window. */
    public function testThePublicCategoryBindsTheCategoryProductsTable(): void
    {
        self::assertSame('category', CategoryPage::PAGE);
        self::assertSame(PageReach::ROUTE, CategoryPage::REACH);
        self::assertSame(PageAccessLevel::PUBLIC, CategoryPage::ACCESS_LEVEL);
        self::assertSame('subscription_page_category', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_CATEGORY);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_CATEGORY],
            CategoryPage::BROWSER,
        );
        self::assertSame(AgentType::CATEGORIES_LIBRARY, Hilos::getPageRoutes()[CategoryPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::categoryProducts => []], Hilos::PAGE_TABLES[CategoryPage::PAGE]);
        self::assertSame('categoryProducts', EcommerceShopTableContext::categoryProducts);
        self::assertSame(CategoryProductsTable::class, Hilos::TABLES[EcommerceShopTableContext::categoryProducts]);
        self::assertSame(
            [EcommerceShopBrowserSource::DB_PRODUCTS, EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS],
            CategoryProductsTable::BROWSER[BrowserTableConfigKey::SOURCES],
        );
    }

    /** The categories library gates the cart add and receives the writer's unindexed reply for the page. */
    public function testCategoryCartAddRoutesFromTheOpenCategoryToTheCartOwnerAndBack(): void
    {
        $action = 'category_add_to_cart';
        $reply = 'cart_category_add_done';
        $signalType = SignalTypeConstants::AGENT_SIGNAL;

        self::assertSame($action, EcommerceShopSignalConstants::CATEGORY_ADD_TO_CART);
        self::assertSame($reply, EcommerceShopSignalConstants::CART_CATEGORY_ADD_DONE);
        self::assertSame([$action => ProductAddToCartActionDTO::class], CategoryPage::ACTIONS);
        self::assertSame(CategoryPage::PAGE, Hilos::getPageActionRoutes()[$action]);
        self::assertSame(AgentType::CATEGORIES_LIBRARY, Hilos::getActionAgentRoutes()[$action]);
        self::assertSame(ProductAddToCartActionDTO::class, Hilos::getActionDtoRoutes()[$action]);
        self::assertNotContains($action, CategoryPage::READING_ACTIONS);
        self::assertArrayNotHasKey($action, Hilos::getAgentActionRoutes());

        self::assertSame([$signalType => [$reply => CartAddProductDoneSignalData::class]], CategoryPage::SIGNALS);
        self::assertSame(CategoryPage::PAGE, Hilos::getPageSignalRoutes()[$signalType][$reply]);
        self::assertSame(AgentType::CATEGORIES_LIBRARY, Hilos::getPageSignalAgentRoutes()[$signalType][$reply]);
        self::assertSame(CartAddProductDoneSignalData::class, Hilos::getPageSignalDtoRoutes()[$signalType][$reply]);
        self::assertArrayNotHasKey($reply, Hilos::getAgentSignalRoutes());
        self::assertArrayNotHasKey($reply, Hilos::getAgentSignalIndexFields());
        self::assertArrayNotHasKey(CategoryPage::PAGE, Hilos::getPageAgentIndexRoutes());
        self::assertSame([EcommerceShopRtContext::connections], CategoryPage::READS_RT);
    }

    /**
     * The products admin page is served by the products library, binds its table and live
     * category filter options, and is reached from the dashboard's shop group.
     */
    public function testTheProductsAdminPageIsServedByTheLibraryAndHangsOffTheDashboard(): void
    {
        self::assertSame('admin_products', AdminProductsPage::PAGE);
        self::assertSame(PageReach::ROUTE, AdminProductsPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AdminProductsPage::ACCESS_LEVEL);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getPageRoutes()[AdminProductsPage::PAGE]);
        self::assertSame([
            EcommerceShopSignalConstants::PRODUCT_CREATE => ProductCreateActionDTO::class,
            EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH => ProductPicturePublishActionDTO::class,
        ], AdminProductsPage::ACTIONS);
        self::assertSame('product_create', EcommerceShopSignalConstants::PRODUCT_CREATE);
        self::assertSame('product_picture_publish', EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH);
        foreach ([EcommerceShopSignalConstants::PRODUCT_CREATE, EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH] as $action) {
            self::assertSame(AdminProductsPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getActionAgentRoutes()[$action]);
        }
        self::assertSame([EcommerceShopTableContext::products => []], Hilos::PAGE_TABLES[AdminProductsPage::PAGE]);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCTS],
            AdminProductsPage::BROWSER,
        );
        self::assertSame('subscription_page_admin_products', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCTS);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Products',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Products, prices, and stock by warehouse.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Products',
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], PageCatalog::pages()[PageConstants::ADMIN_PRODUCTS]);
        self::assertSame('adminProductCategories', EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES);
        self::assertSame(
            AdminProductCategoriesBrowserList::class,
            Hilos::BROWSER_LISTS[AdminProductCategoriesBrowserList::LIST],
        );
        self::assertSame(
            [AdminProductCategoriesBrowserList::LIST => []],
            Hilos::PAGE_LISTS[AdminProductsPage::PAGE],
        );

        $browser = new EcommerceShopBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        self::assertContains(EcommerceShopDbContext::categories, $browser->dbSourceKeysOfPage('admin_products'));
    }

    /**
     * One product's admin page is served by the products library, carries the product's card and
     * the live categories and warehouses its names are read from, and hangs off the products page.
     */
    public function testTheProductAdminPageIsServedByTheLibraryAndHangsOffProducts(): void
    {
        self::assertSame('admin_product', AdminProductPage::PAGE);
        self::assertSame(PageReach::ROUTE, AdminProductPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AdminProductPage::ACCESS_LEVEL);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getPageRoutes()[AdminProductPage::PAGE]);
        $action = EcommerceShopSignalConstants::PRODUCT_UPDATE;
        $place = EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE;
        self::assertSame('product_update', $action);
        self::assertSame('product_warehouse_place', $place);
        self::assertSame(
            [$action => ProductUpdateActionDTO::class, $place => ProductWarehousePlaceActionDTO::class],
            AdminProductPage::ACTIONS,
        );
        self::assertSame(ProductUpdateActionDTO::class, Hilos::getActionDtoRoutes()[$action]);
        self::assertSame(AdminProductPage::PAGE, Hilos::getPageActionRoutes()[$action]);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getActionAgentRoutes()[$action]);
        self::assertSame(ProductWarehousePlaceActionDTO::class, Hilos::getActionDtoRoutes()[$place]);
        self::assertSame(AdminProductPage::PAGE, Hilos::getPageActionRoutes()[$place]);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getActionAgentRoutes()[$place]);
        self::assertArrayNotHasKey($place, Hilos::getAgentActionRoutes());
        self::assertNotContains($place, AdminProductPage::READING_ACTIONS);

        $reply = EcommerceShopSignalConstants::ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE;
        self::assertSame('admin_product_warehouse_place_done', $reply);
        self::assertSame(
            [SignalTypeConstants::AGENT_SIGNAL => [$reply => HandoverAnswerSignalData::class]],
            AdminProductPage::SIGNALS,
        );
        self::assertSame(AdminProductPage::PAGE, Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][$reply]);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][$reply]);
        self::assertSame(HandoverAnswerSignalData::class, Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][$reply]);

        self::assertArrayNotHasKey(AdminProductPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCT],
            AdminProductPage::BROWSER,
        );
        self::assertSame('subscription_page_admin_product', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCT);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Product',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'One product of the catalog: its kind, picture, category, price and stock.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => PageConstants::ADMIN_PRODUCTS,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Product',
            PageCatalogConstants::CATALOG_ENTRY_ENTITY => true,
            PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true,
        ], PageCatalog::pages()[PageConstants::ADMIN_PRODUCT]);
        self::assertSame('adminProduct', EcommerceShopBrowserData::ADMIN_PRODUCT);
        self::assertSame(AdminProductBrowserData::class, Hilos::BROWSER_DATA[AdminProductBrowserData::DATA]);
        self::assertSame(
            [AdminProductBrowserData::DATA => AdminProductBrowserData::BINDING],
            Hilos::PAGE_DATA[AdminProductPage::PAGE],
        );
        self::assertSame('adminProductWarehouses', EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES);
        self::assertSame(
            AdminProductWarehousesBrowserList::class,
            Hilos::BROWSER_LISTS[AdminProductWarehousesBrowserList::LIST],
        );
        self::assertSame(
            [AdminProductCategoriesBrowserList::LIST => [], AdminProductWarehousesBrowserList::LIST => []],
            Hilos::PAGE_LISTS[AdminProductPage::PAGE],
        );

        $browser = new EcommerceShopBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        $sources = $browser->dbSourceKeysOfPage('admin_product');
        foreach ([
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::productWarehouseStocks,
            EcommerceShopDbContext::categories,
            EcommerceShopDbContext::warehouses,
        ] as $source) {
            self::assertContains($source, $sources);
        }
        self::assertSame([EcommerceShopRtContext::productViewerCounts], $browser->rtSourceKeysOfPage(AdminProductPage::PAGE));
    }

    /** The products library serves the Stock tab with the product card and the warehouse stock window. */
    public function testTheProductStockPageUsesTheCardAndStockWindowUnderTheProductHead(): void
    {
        self::assertSame('admin_product_stock', AdminProductStockPage::PAGE);
        self::assertSame(PageReach::ROUTE, AdminProductStockPage::REACH);
        self::assertSame(PageAccessLevel::ADMIN, AdminProductStockPage::ACCESS_LEVEL);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getPageRoutes()[AdminProductStockPage::PAGE]);
        self::assertSame([BrowserConfigKey::SIGNAL => 'subscription_page_admin_product_stock'], AdminProductStockPage::BROWSER);
        self::assertSame([
            PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Stock',
            PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Units of this product by warehouse.',
            PageCatalogConstants::CATALOG_ENTRY_PARENT => PageConstants::ADMIN_PRODUCT,
            PageCatalogConstants::CATALOG_ENTRY_BREADCRUMB => 'Stock',
        ], PageCatalog::pages()[AdminProductStockPage::PAGE]);
        self::assertSame(
            [AdminProductBrowserData::DATA => AdminProductBrowserData::BINDING],
            Hilos::PAGE_DATA[AdminProductStockPage::PAGE],
        );
        self::assertSame([EcommerceShopTableContext::productStock => []], Hilos::PAGE_TABLES[AdminProductStockPage::PAGE]);
        self::assertArrayNotHasKey(AdminProductStockPage::PAGE, Hilos::PAGE_LISTS);

        $action = 'product_stock_set_units';
        self::assertSame(AdminProductStockPage::PAGE, Hilos::getPageActionRoutes()[$action]);
        self::assertSame(ProductStockSetUnitsActionDTO::class, Hilos::getActionDtoRoutes()[$action]);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getActionAgentRoutes()[$action]);
        $answer = 'admin_product_stock_set_units_done';
        self::assertSame(AdminProductStockPage::PAGE, Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][$answer]);
        self::assertSame(AgentType::PRODUCTS_LIBRARY, Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][$answer]);
        self::assertSame(HandoverAnswerSignalData::class, Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][$answer]);
        self::assertArrayNotHasKey($answer, Hilos::getActionAgentRoutes());

        $browser = new EcommerceShopBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        self::assertEqualsCanonicalizing([
            EcommerceShopDbContext::products,
            EcommerceShopDbContext::warehouses,
            EcommerceShopDbContext::productWarehouseStocks,
        ], $browser->dbSourceKeysOfPage(AdminProductStockPage::PAGE));
        self::assertSame([EcommerceShopRtContext::productViewerCounts], $browser->rtSourceKeysOfPage(AdminProductStockPage::PAGE));
    }

    /** The stock table of one product is registered under its key, for the page that binds it. */
    public function testTheProductStockTableIsRegistered(): void
    {
        self::assertSame(ProductStockTable::class, Hilos::TABLES[EcommerceShopTableContext::productStock]);
    }

    public function testTheClusterProbesAreListedAsTheFrameworkWroteThem(): void
    {
        // The rows are the framework's records, not this demo's copy of them: the flags every
        // cluster scenario stands on are pinned once, in the framework's own registry test.
        foreach ([HilosAgentType::HILOS_PROBE_FLEET, HilosAgentType::HILOS_PROBE_CLAIMER, HilosAgentType::HILOS_PROBE_BALLAST] as $agentType) {
            $this->assertSame(ClusterProbe::AGENTS[$agentType], Hilos::AGENTS[$agentType], "{$agentType} is listed as the framework wrote it");
        }
    }

    /** The i18n section and five detail pages route to one placed framework library. */
    public function testI18nSectionUsesFrameworkLibrary(): void
    {
        $this->assertContains(HilosFeature::I18N, Hilos::features());
        $this->assertSame([
            I18nPage::PAGE,
            LanguagesListPage::PAGE,
            CountriesListPage::PAGE,
            LanguageDetailPage::PAGE,
            LanguageNamesPage::PAGE,
            LanguageLocalesPage::PAGE,
            CountryDetailPage::PAGE,
            CountryNamesPage::PAGE,
        ], array_values(array_filter(
            array_keys(Hilos::PAGES),
            static fn (string $page): bool => str_starts_with($page, I18nPage::PAGE),
        )));

        foreach ([
            I18nPage::class, LanguagesListPage::class, CountriesListPage::class,
            LanguageDetailPage::class,
            LanguageNamesPage::class,
            LanguageLocalesPage::class,
            CountryDetailPage::class,
            CountryNamesPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(HilosAgentType::HILOS_I18N_LIBRARY, Hilos::getPageRoutes()[$page::PAGE]);
        }

        $this->assertSame(HilosI18nLanguageLocalesTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosI18nLanguageLocales]);
        $this->assertSame([EcommerceShopTableContext::hilosI18nLanguageLocales => []], Hilos::PAGE_TABLES[LanguageLocalesPage::PAGE]);
        $this->assertSame(HilosI18nLanguagesTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosI18nLanguages]);
        $this->assertSame([EcommerceShopTableContext::hilosI18nLanguages => []], Hilos::PAGE_TABLES[LanguagesListPage::PAGE]);
        $this->assertSame(HilosI18nCountriesTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosI18nCountries]);
        $this->assertSame([EcommerceShopTableContext::hilosI18nCountries => []], Hilos::PAGE_TABLES[CountriesListPage::PAGE]);
        $this->assertSame(LanguageCardBrowserData::class, Hilos::BROWSER_DATA[LanguageCardBrowserData::DATA]);
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageDetailPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageNamesPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageLocalesPage::PAGE],
        );
        $this->assertSame(CountryCardBrowserData::class, Hilos::BROWSER_DATA[CountryCardBrowserData::DATA]);
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryDetailPage::PAGE],
        );
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryNamesPage::PAGE],
        );

        $entry = Hilos::AGENTS[HilosAgentType::HILOS_I18N_LIBRARY];
        $this->assertSame(I18nLibraryAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(I18nLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertTrue((new I18nLibraryAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testMainPageIsOwnedByTheAppAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::ECOMMERCE_SHOP, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[AgentType::ECOMMERCE_SHOP];
        $this->assertSame(EcommerceShopAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(EcommerceShopAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new EcommerceShopAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testTheHomeBindsItsTwoListsAndReadsTheCatalog(): void
    {
        self::assertSame(
            [BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_MAIN],
            MainPage::BROWSER,
        );
        self::assertSame('subscription_page_main', EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_MAIN);
        self::assertSame('mainCategories', EcommerceShopBrowserList::MAIN_CATEGORIES);
        self::assertSame('mainProducts', EcommerceShopBrowserList::MAIN_PRODUCTS);
        self::assertSame(MainCategoriesBrowserList::class, Hilos::BROWSER_LISTS[MainCategoriesBrowserList::LIST]);
        self::assertSame(MainProductsBrowserList::class, Hilos::BROWSER_LISTS[MainProductsBrowserList::LIST]);
        self::assertSame(
            [MainCategoriesBrowserList::LIST => [], MainProductsBrowserList::LIST => []],
            Hilos::PAGE_LISTS[MainPage::PAGE],
        );

        $browser = new EcommerceShopBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        $dbSources = $browser->dbSourceKeysOfPage('main');
        self::assertContains(EcommerceShopDbContext::categories, $dbSources);
        self::assertContains(EcommerceShopDbContext::products, $dbSources);
        self::assertContains(EcommerceShopDbContext::productWarehouseStocks, $dbSources);
    }

    public function testHilosPagesAreOwnedByTheIndexAgent(): void
    {
        foreach ([
            DashboardPage::class,
            BackupPage::class,
            MaintenancePage::class,
            MaintenanceCirclePage::class,
            MaintenanceWordsPage::class,
            SettingsPage::class,
            CommunicationsPage::class,
            CommunicationsChannelPage::class,
            CommunicationsChannelConfigurationPage::class,
            CommunicationsChannelFramePage::class,
            CommunicationsDeliveriesPage::class,
            UsersPage::class,
            UserPage::class,
            AboutPage::class,
            TermsPage::class,
            PrivacyPage::class,
            TermsHistoryPage::class,
            PrivacyHistoryPage::class,
            LicensePage::class,
            LicenseDependenciesPage::class,
        ] as $page) {
            $this->assertSame(AgentType::HILOS_INDEX, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        $entry = Hilos::AGENTS[AgentType::HILOS_INDEX];
        $this->assertSame(DemoHilosAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(DemoHilosAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
    }

    public function testAppOwnSurfaceStaysTransportOnly(): void
    {
        // The application's OWN surface is transport-only: its main page and worker push no
        // server-driven data. The one browser table is the card of the framework's people
        // (HIL-1225), the one group is the framework's notification group, and the tables and
        // page actions the admin sections bring are the framework's too; each pins its own above.
        //
        // The one frame the worker is addressed by is not its surface but the seam the sessions
        // moved behind (HIL-710): the library says what a session became, and this agent updates
        // the connection rows that belong to the project. The sweep frame is the guest carts
        // library's, pinned above: the guest's cart is what of the project hangs on a session row.
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);
        $this->assertSame([HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class], Hilos::BROWSER_TABLES);
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame(
            HilosPageConstants::HILOS_TERMS,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_PRIVACY,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT],
        );
        $this->assertSame(
            [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class],
            EcommerceShopAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::ECOMMERCE_SHOP,
                EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISHED => AgentType::CATEGORIES_LIBRARY,
                EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISHED => AgentType::PRODUCTS_LIBRARY,
                EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE => AgentType::STOCK_LIBRARY,
                EcommerceShopSignalConstants::STOCK_SET_UNITS => AgentType::STOCK_LIBRARY,
                HilosSignalConstants::HILOS_SESSIONS_SWEPT => AgentType::GUEST_CARTS_LIBRARY,
                EcommerceShopSignalConstants::GUEST_CART_SEND => AgentType::GUEST_CARTS_LIBRARY,
                EcommerceShopSignalConstants::GUEST_CART_ADD_PRODUCT => AgentType::GUEST_CARTS_LIBRARY,
                EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE => AgentType::PRODUCT,
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
                HilosSignalConstants::HILOS_DATA_EXPORT_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_DATA_EXPORT,
                // The other half of the seam, and the endings the users library hands over:
                // what a sign-in became reaches the library that owns the session.
                HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_CANCELED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SESSION_REBIND => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The merge is mounted here as everywhere else and refuses, because this demo
                // wires neither of its seams (HIL-729).
                HilosSignalConstants::HILOS_ACCOUNT_MERGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The one frame with no fixed sender: whoever finished something a person is
                // waiting on raises a toast on their session (HIL-768).
                HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_CODE_SEND_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AGENTS_GONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_IMPERSONATION_OFF_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_RENAME => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_ADD => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_TEST_CLEANUP => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_RELEASE => HilosAgentType::HILOS_USER,
                EcommerceShopSignalConstants::ACCOUNT_CART_SEND => HilosAgentType::HILOS_USER,
                EcommerceShopSignalConstants::ACCOUNT_CART_ADD_PRODUCT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USERS_LIBRARY_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_CODE_MINT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_THEME_PICK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_CREATE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_RENAME_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                // The notifications library's own frames (HIL-1225 activates the feature here,
                // with the notification e2e): an emit from any worker, the retry of a delivery,
                // the hand-over of a person's rows and their erasure. The push frame is mounted
                // with the library whether or not the push channel is registered.
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                // Every settings write goes through the library that owns the rows (HIL-1225).
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // The catalog pictures' frames (HIL-2155): the files library binds, removes and
                // publishes a file and hears that a copy was drawn; the uploads agent hands a
                // complete upload over; the images agent draws a copy.
                HilosSignalConstants::HILOS_FILE_BIND => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_FILE_REMOVE => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_FILE_PUBLISH => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_IMAGE_RENDERED => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_UPLOAD_PUBLISH => HilosAgentType::HILOS_UPLOADS,
                HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE => HilosAgentType::HILOS_UPLOADS,
                HilosSignalConstants::HILOS_IMAGE_RENDER => HilosAgentType::HILOS_IMAGES,
                HilosSignalConstants::HILOS_MAIL_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => HilosAgentType::HILOS_AUTH_THROTTLE,
                // The backup section's own frames: the page hands every operation to the
                // monopoly agent, and the carry-over receipts of a restore come back to it
                // (HIL-1225 activates the feature here, with the backup e2e).
                HilosSignalConstants::BACKUP_AGENT_CREATE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_DELETE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SET_KEEP => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_RESTORE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::MAINTENANCE_AGENT_RESTORE_CONTROL => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SESSIONS_CARRIED => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_STANDING => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_HTTP => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_REPORT_NOW => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_CANCEL_PUBLICATION => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_PUBLISH_NOW => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_ADD_UPDATE => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_SCHEDULE_WORK => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_CHANGE_WORK => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_CANCEL_WORK => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_THROTTLE_VERDICT => HilosAgentType::HILOS_STATUS,
            ],
            Hilos::getAgentSignalRoutes(),
        );
    }

    public function testSignInIsActivatedOnTheFrameworkLibrary(): void
    {
        // Sign-in is an activation, not a build (HIL-623): the demo declares the two features
        // and registers the library pairs, and every command name behind the surface is the
        // framework's. The snapshot is the whole action map on purpose - a command that silently
        // stopped being routed here would otherwise look like a working surface until somebody
        // submitted the form it belongs to. The other features are backup, settings and the
        // people (HIL-1225), whose actions are their pages' and so stay out of this map, and
        // notifications with delivery, whose actions close the map. The files, uploads and
        // images that carry the catalog pictures (HIL-2155) add the two upload actions, which
        // the uploads agent answers.
        $this->assertSame([
            HilosFeature::ANALYTICS,
            HilosFeature::AUTH,
            HilosFeature::AUTH_THROTTLE,
            HilosFeature::BACKUP,
            HilosFeature::CHANGE_LOG,
            HilosFeature::DAEMON,
            HilosFeature::STATUS,
            HilosFeature::SETTINGS,
            HilosFeature::I18N,
            HilosFeature::HILOS_USERS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::NOTIFICATION_DELIVERY,
            HilosFeature::FILES,
            HilosFeature::UPLOADS,
            HilosFeature::IMAGES,
        ], Hilos::features());

        $this->assertSame(UsersLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(UsersLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(SessionsLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SESSIONS_LIBRARY],
        ));
        $this->assertSame(SessionsLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SESSIONS_LIBRARY],
        ));

        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => HilosAgentType::HILOS_DATA_EXPORT,
            // Signing out, dismissing an ack and leaving a takeover all write a session, so
            // the sessions library owns them (HIL-710, HIL-729) - this demo adds an action of
            // its own for none of them.
            HilosSignalConstants::HILOS_LOGOUT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSIONS_END_OTHERS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_BROWSER_ERASE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_IMPERSONATE_STOP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            // The tabs of one session answering about the toasts the server raised for it
            // (HIL-768): the stack they answer about stands on the session.
            HilosSignalConstants::HILOS_TOAST_DISMISS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_READING => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_RESUME => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_ADOPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_TIME_ZONE_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_CONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LOGIN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SET_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_NAME_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
            // The bell writes the rows the notifications library owns (HIL-1225); the push
            // actions come with the library, as in every demo that switches notifications on.
            NotificationAction::MARK_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationAction::MARK_ALL_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationPreferenceAction::CHANNEL_SET => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::SUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::UNSUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::REMOVE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_UPLOAD_INIT => HilosAgentType::HILOS_UPLOADS,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL => HilosAgentType::HILOS_UPLOADS,
            HilosSignalConstants::STATUS_SUBSCRIBE => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => HilosAgentType::HILOS_STATUS,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => HilosAgentType::HILOS_STATUS,
        ], Hilos::getAgentActionRoutes());
        $this->assertSame(ThemePickActionDTO::class,
            Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_THEME_PICK]);
        $this->assertSame(TimeZonePickActionDTO::class,
            Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_TIME_ZONE_PICK]);
        $this->assertSame(UserThemePickWriteDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_THEME_PICK_DONE]);
        $this->assertSame(UserTimeZoneWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE]);
        $this->assertSame(UserLastBrowserTimeZoneWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE]);
        $this->assertSame(UserIdentityProviderNameWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE]);
        $this->assertSame(UserTimeZoneWriteDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE]);
    }

    public function testBackupAdminFeatureIsActivated(): void
    {
        // Backup is a configure-only framework feature with a monopoly agent behind it. It is
        // activated here because the backup e2e moved onto this demo (HIL-1225): the page
        // answered by the index agent, the agent pair, and its archive table. The verifier
        // circle is drawn in Maintenance.
        $this->assertSame(BackupPage::class, Hilos::PAGES[BackupPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, BackupPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(BackupAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        $this->assertSame(BackupAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        // Placed by policy, not hosted by the leader: the agent owns a directory on one node's
        // disk, and following leadership would move it away from its archives (HIL-940).
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        $this->assertSame(
            HilosBackupHistoryTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosBackups],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosBackups => []],
            Hilos::PAGE_TABLES[BackupPage::PAGE],
        );
    }

    /** Maintenance has no feature switch: page and table registration activate it (HIL-1225). */
    public function testMaintenanceSectionIsActivated(): void
    {
        $this->assertSame(MaintenancePage::class, Hilos::PAGES[MaintenancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, MaintenancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosVerifierCircleTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosVerifierCircle],
        );
        $this->assertSame(
            HilosMaintenanceWordsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosMaintenanceWords],
        );
        $this->assertSame(
            HilosMaintenanceNodesTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosMaintenanceNodes],
        );
        $this->assertArrayNotHasKey(MaintenancePage::PAGE, Hilos::PAGE_TABLES);
        $this->assertSame(
            [EcommerceShopTableContext::hilosMaintenanceWords => []],
            Hilos::PAGE_TABLES[MaintenanceWordsPage::PAGE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_ADD],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_ENABLE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_DISABLE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_PASS],
        );
        foreach ([
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ] as $action => $dto) {
            $this->assertSame(HilosPageConstants::HILOS_MAINTENANCE, Hilos::getPageActionRoutes()[$action]);
            $this->assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            $this->assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
        }
    }

    /** Settings is configure-only; its catalog carries the three example keys and nothing a feature requires. */
    public function testSettingsAdminFeatureIsActivated(): void
    {
        $this->assertSame(SettingsPage::class, Hilos::PAGES[SettingsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, SettingsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(SettingsLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(SettingsLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(
            HilosSettingsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::settings],
        );
        $this->assertSame(
            [EcommerceShopTableContext::settings => []],
            Hilos::PAGE_TABLES[SettingsPage::PAGE],
        );
        $catalog = EcommerceShopSettingsCatalog::getCatalog();
        $this->assertSame([
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
            DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_FROM_ADDRESS),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_FROM_NAME),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_HOST),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_PORT),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_SMTP_SECURITY),
            DeliveryChannelSettings::fieldKey(MailDeliveryChannel::NAME, MailDeliveryChannel::FIELD_TIMEOUT_MS),
            MailLetterFrameSettings::SETTING_KEY,
            DeliveryLogRetention::RETENTION_SETTING_KEY,
            ThemeSettingsCatalog::SWITCHING_ENABLED_KEY,
            ThemeSettingsCatalog::DEFAULT_THEME_KEY,
            StatusSettingsCatalog::PUBLISH_DELAY_SECONDS,
            AnalyticsSettingsCatalog::JOURNAL_MAX_BYTES,
            ProtectedModeSettingsCatalog::MANUAL_RESTART_IS_NORMAL,
            AuthThrottleSettings::OUTAGE_GRACE_SECONDS_KEY,
            FilesSettingsCatalog::UNBOUND_TTL_HOURS_KEY,
            FilesSettingsCatalog::MAX_TOTAL_BYTES_KEY,
            UploadsSettingsCatalog::MEMORY_POOL_BYTES_KEY,
        ], array_keys($catalog));
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $this->assertSame(ThemeSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
    }

    /** Appearance reuses the index agent; its actions, answer and admin level come from the framework page. */
    public function testAppearanceAdminFeatureIsActivated(): void
    {
        $this->assertSame(AppearancePage::class, Hilos::PAGES[AppearancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, AppearancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(PageAccessLevel::ADMIN, AppearancePage::ACCESS_LEVEL);
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            HilosAppearanceThemeSwitchingSetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            HilosAppearanceDefaultThemeSetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HilosAppearanceSettingsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosAppearanceSettings],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosAppearanceSettings => []],
            Hilos::PAGE_TABLES[AppearancePage::PAGE],
        );
    }

    /** The people are a bound framework feature: the demo binds its presence source and the card's table (HIL-1225). */
    public function testHilosUsersAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::HILOS_USERS, Hilos::features());
        $this->assertSame(UsersPage::class, Hilos::PAGES[UsersPage::PAGE]);
        $this->assertSame(UserPage::class, Hilos::PAGES[UserPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, UsersPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, UserPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosUsersTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosUsers],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosUsers => []],
            Hilos::PAGE_TABLES[UsersPage::PAGE],
        );
        // The card is the framework's browser-only table, bound to the page's user id by its own binding;
        // no merge-candidates window is bound, because this demo wires no account merge.
        $this->assertSame(
            [
                HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            ],
            Hilos::PAGE_TABLES[UserPage::PAGE],
        );
        $this->assertSame(
            [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );
    }

    public function testNotificationsAreActivated(): void
    {
        $this->assertContains(HilosFeature::NOTIFICATIONS, Hilos::features());
        $this->assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);

        $library = Hilos::AGENTS[AgentType::HILOS_NOTIFICATIONS_LIBRARY];
        $this->assertSame(NotificationsLibraryAgent::class, AgentRegistry::workerClass($library));
        $this->assertSame(NotificationsLibraryAgentDaemon::class, AgentRegistry::daemonClass($library));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($library));
    }

    public function testCommunicationsAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame(CommunicationsPage::class, Hilos::PAGES[CommunicationsPage::PAGE]);
        $this->assertSame(CommunicationsChannelPage::class, Hilos::PAGES[CommunicationsChannelPage::PAGE]);
        $this->assertSame(CommunicationsChannelConfigurationPage::class, Hilos::PAGES[CommunicationsChannelConfigurationPage::PAGE]);
        $this->assertArrayNotHasKey(CommunicationsChannelPage::PAGE, Hilos::PAGE_TABLES);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[CommunicationsChannelConfigurationPage::PAGE]);
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            CommunicationsChannelConfigurationPage::BROWSER[BrowserConfigKey::SIGNAL]);
        $this->assertSame([], CommunicationsChannelConfigurationPage::ACTIONS);
        $this->assertSame([], CommunicationsChannelConfigurationPage::SIGNALS);
        $this->assertSame(CommunicationsDeliveriesPage::class, Hilos::PAGES[CommunicationsDeliveriesPage::PAGE]);
        $this->assertSame(ProfileNotificationsPage::class, Hilos::PAGES[ProfileNotificationsPage::PAGE]);

        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsChannelPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsChannelConfigurationPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsDeliveriesPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::ECOMMERCE_SHOP, ProfileNotificationsPage::SUBSCRIPTION_AGENT_TYPE);

        $this->assertSame(
            HilosCommunicationsChannelsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosCommunicationsChannels],
        );
        $this->assertSame(
            HilosCommunicationsChannelFieldsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosCommunicationsChannelFields],
        );
        $this->assertSame(
            HilosNotificationDeliveriesTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosNotificationDeliveries],
        );

        $this->assertSame(
            [EcommerceShopTableContext::hilosCommunicationsChannels => []],
            Hilos::PAGE_TABLES[CommunicationsPage::PAGE],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosCommunicationsChannelFields => []],
            Hilos::PAGE_TABLES[CommunicationsChannelConfigurationPage::PAGE],
        );
        $this->assertSame(
            [EcommerceShopTableContext::hilosNotificationDeliveries => []],
            Hilos::PAGE_TABLES[CommunicationsDeliveriesPage::PAGE],
        );

        $this->assertSame(
            HilosChannelSettingUpdateActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET],
        );
        $this->assertSame(
            HilosChannelSettingResetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET],
        );
        $this->assertSame(
            HilosChannelTestActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST],
        );
        $this->assertSame(
            HilosDeliveryRetryActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY],
        );

        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE],
        );

        foreach ([
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST,
        ] as $action) {
            $this->assertSame(CommunicationsChannelPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            $this->assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
        }
        $this->assertSame(CommunicationsChannelPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE]);

        $catalog = EcommerceShopSettingsCatalog::getCatalog();
        $emailKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        $this->assertArrayHasKey($emailKey, $catalog);
        $this->assertTrue($catalog[$emailKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
    }

    public function testTimeZoneChoiceRequiresAuthentication(): void
    {
        $agentClass = AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY]);
        self::assertNotNull($agentClass);
        self::assertContains(HilosSignalConstants::HILOS_TIME_ZONE_PICK, $agentClass::AUTH_ACTIONS);
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    /**
     * The independent ceiling permits only prescribed DB transitions to retire; startup checks the exact vector.
     * The runtime receipt for the cluster stand's deliberate split in scenario 14 remains.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $ceiling = [];
        foreach (Hilos::SHARED_DB_OWNERS as $collection => $pending) {
            $this->assertArrayHasKey($collection, $ceiling);
            $this->assertNotEmpty($pending);
            $this->assertSame(array_values(array_intersect($ceiling[$collection], $pending)), $pending);
        }
        Hilos::validateTopology();
        $this->assertLessThanOrEqual(1, count(Hilos::SHARED_RT_OWNERS));
    }

    /** Checks the project's complete activation of the framework Analytics section. */
    public function testAnalyticsFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::ANALYTICS, Hilos::features());
        $this->assertSame(AnalyticsPage::class, Hilos::PAGES[AnalyticsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_ANALYTICS, AnalyticsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosAnalyticsActionsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosAnalyticsActions],
        );
        $this->assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosAnalyticsBrowserSessions],
        );

        $reader = Hilos::AGENTS[AgentType::HILOS_ANALYTICS];
        $this->assertSame(DemoHilosAnalyticsAgent::class, AgentRegistry::workerClass($reader));
        $this->assertSame(DemoHilosAnalyticsAgentDaemon::class, AgentRegistry::daemonClass($reader));
        $this->assertTrue((new DemoHilosAnalyticsAgentDaemon())->requiresMonopolisticProcess());
        $this->assertArrayNotHasKey(AgentRegistryKey::SCOPE, $reader);
        $this->assertArrayNotHasKey(AgentRegistryKey::PLACEMENT, $reader);
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($reader));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($reader));

        $journal = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_JOURNAL];
        $this->assertSame(AnalyticsJournalAgent::class, AgentRegistry::workerClass($journal));
        $this->assertSame(AnalyticsJournalAgentDaemon::class, AgentRegistry::daemonClass($journal));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($journal));

        $writer = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_WRITER];
        $this->assertSame(AnalyticsWriterAgent::class, AgentRegistry::workerClass($writer));
        $this->assertSame(AnalyticsWriterAgentDaemon::class, AgentRegistry::daemonClass($writer));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($writer));

        $journalDtos = Hilos::getAgentSignalDtoRoutes();
        $this->assertSame(AnalyticsJournalAppendSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_APPEND]);
        $this->assertSame(AnalyticsJournalReadSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_READ]);
        $this->assertSame(AnalyticsJournalLoadedSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_LOADED]);
        $this->assertSame(AnalyticsJournalPortionSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_PORTION]);
        $this->assertSame(AnalyticsJournalReadySignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_READY]);
        $nodeFields = Hilos::getAgentSignalNodeFields();
        $this->assertSame(AnalyticsJournalReadSignalData::nodeId, $nodeFields[HilosSignalConstants::ANALYTICS_JOURNAL_READ]);
        $this->assertSame(AnalyticsJournalLoadedSignalData::nodeId, $nodeFields[HilosSignalConstants::ANALYTICS_JOURNAL_LOADED]);
    }

    /** The numeric browser-session page uses the Analytics owner and its existing action table. */
    public function testAnalyticsBrowserSessionPageIsWired(): void
    {
        self::assertSame(BrowserSessionPage::class, Hilos::PAGES[BrowserSessionPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::hilosAnalyticsActions => []], Hilos::PAGE_TABLES[BrowserSessionPage::PAGE]);
    }

    /** The period sessions list uses the Analytics owner and its own table, beside the person's sessions table. */
    public function testAnalyticsBrowserSessionsPageIsWired(): void
    {
        self::assertSame(BrowserSessionsPage::class, Hilos::PAGES[BrowserSessionsPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, BrowserSessionsPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionsPage::PAGE]);
        self::assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSIONS,
            BrowserSessionsPage::BROWSER[BrowserConfigKey::SIGNAL],
        );
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::TABLE,
            EcommerceShopTableContext::hilosAnalyticsBrowserSessionList,
        );
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosAnalyticsBrowserSessionList],
        );
        self::assertSame(
            [EcommerceShopTableContext::hilosAnalyticsBrowserSessionList => []],
            Hilos::PAGE_TABLES[BrowserSessionsPage::PAGE],
        );
        self::assertSame(
            [EcommerceShopTableContext::hilosAnalyticsBrowserSessions => []],
            Hilos::PAGE_TABLES[PersonPage::PAGE],
        );
    }

    /** The People list uses its existing Analytics owner, signal and framework table. */
    public function testAnalyticsPeoplePageIsWired(): void
    {
        self::assertSame(PeoplePage::class, Hilos::PAGES[PeoplePage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, PeoplePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PeoplePage::PAGE]);
        self::assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PEOPLE,
            PeoplePage::BROWSER[BrowserConfigKey::SIGNAL],
        );
        self::assertSame(HilosAnalyticsPeopleTable::TABLE, EcommerceShopTableContext::hilosAnalyticsPeople);
        self::assertSame(
            HilosAnalyticsPeopleTable::class,
            Hilos::TABLES[EcommerceShopTableContext::hilosAnalyticsPeople],
        );
        self::assertSame(
            [EcommerceShopTableContext::hilosAnalyticsPeople => []],
            Hilos::PAGE_TABLES[PeoplePage::PAGE],
        );
    }

    /** The person's sessions page uses the Analytics owner and its existing sessions table. */
    public function testAnalyticsPersonPageIsWired(): void
    {
        self::assertSame(PersonPage::class, Hilos::PAGES[PersonPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PersonPage::PAGE]);
        self::assertSame([EcommerceShopTableContext::hilosAnalyticsBrowserSessions => []], Hilos::PAGE_TABLES[PersonPage::PAGE]);
    }

    /** Pins the Daemon feature, eight pages and all three agent placements. */
    public function testDaemonAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::DAEMON, Hilos::features());
        foreach ([
            DaemonPage::class,
            DaemonPlacementPage::class,
            DaemonNodePage::class,
            DaemonWorkersPage::class,
            DaemonAgentsPage::class,
            DaemonCronPage::class,
            DaemonWebsocketsPage::class,
            DaemonHttpServerPage::class,
            DaemonEnvMismatchPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::HILOS_DAEMON, Hilos::getPageRoutes()[$page::PAGE]);
        }
        $this->assertArrayNotHasKey(DaemonNodePage::PAGE, Hilos::PAGE_TABLES);
        $this->assertArrayNotHasKey(DaemonPage::PAGE, Hilos::PAGE_TABLES);
        $this->assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        $this->assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);
        $this->assertSame(DaemonEnvOrphansPage::class, Hilos::PAGES[DaemonEnvOrphansPage::PAGE]);
        $this->assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvOrphansPage::PAGE]);

        $pageAgent = Hilos::AGENTS[AgentType::HILOS_DAEMON];
        $this->assertSame(DemoHilosDaemonAgent::class, AgentRegistry::workerClass($pageAgent));
        $this->assertSame(DemoHilosDaemonAgentDaemon::class, AgentRegistry::daemonClass($pageAgent));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($pageAgent));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($pageAgent));
        $this->assertFalse((new DemoHilosDaemonAgentDaemon())->requiresMonopolisticProcess());

        $nodeAgent = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_NODE];
        $this->assertSame(DaemonNodeAgent::class, AgentRegistry::workerClass($nodeAgent));
        $this->assertSame(DaemonNodeAgentDaemon::class, AgentRegistry::daemonClass($nodeAgent));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($nodeAgent));
        $this->assertFalse((new DaemonNodeAgentDaemon())->requiresMonopolisticProcess());

        $collector = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_COLLECTOR];
        $this->assertSame(DaemonCollectorAgent::class, AgentRegistry::workerClass($collector));
        $this->assertSame(DaemonCollectorAgentDaemon::class, AgentRegistry::daemonClass($collector));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($collector));
        $this->assertFalse((new DaemonCollectorAgentDaemon())->requiresMonopolisticProcess());

        $this->assertSame(HilosDaemonCronTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonCron]);
        $this->assertSame(HilosDaemonWorkersTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonWorkers]);
        $this->assertSame(HilosDaemonEnvKeysTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonEnvKeys]);
        $this->assertSame(HilosDaemonEnvOrphansTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonEnvOrphans]);
        $this->assertSame(HilosDaemonAgentsTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonAgents]);
        $this->assertSame(HilosDaemonEnvMismatchTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonEnvMismatch]);
        $this->assertSame(HilosDaemonHttpRoutesTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonHttpRoutes]);
        $this->assertSame(HilosDaemonPlacementTable::class, Hilos::TABLES[EcommerceShopTableContext::hilosDaemonPlacement]);

        $this->assertSame([EcommerceShopTableContext::hilosDaemonPlacement => []], Hilos::PAGE_TABLES[DaemonPlacementPage::PAGE]);
        $this->assertSame([EcommerceShopTableContext::hilosDaemonWorkers => []], Hilos::PAGE_TABLES[DaemonWorkersPage::PAGE]);
        $this->assertSame([EcommerceShopTableContext::hilosDaemonAgents => []], Hilos::PAGE_TABLES[DaemonAgentsPage::PAGE]);
        $this->assertSame([EcommerceShopTableContext::hilosDaemonCron => []], Hilos::PAGE_TABLES[DaemonCronPage::PAGE]);
        $this->assertSame([
            EcommerceShopTableContext::hilosDaemonEnvKeys => [],
        ], Hilos::PAGE_TABLES[DaemonEnvPage::PAGE]);
        $this->assertSame([
            EcommerceShopTableContext::hilosDaemonEnvOrphans => [],
        ], Hilos::PAGE_TABLES[DaemonEnvOrphansPage::PAGE]);
        $this->assertSame([
            EcommerceShopTableContext::hilosDaemonEnvMismatch => [],
        ], Hilos::PAGE_TABLES[DaemonEnvMismatchPage::PAGE]);
        $this->assertSame([EcommerceShopTableContext::hilosDaemonHttpRoutes => []], Hilos::PAGE_TABLES[DaemonHttpServerPage::PAGE]);

        $this->assertSame(
            DaemonAgentsPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART],
        );
        $this->assertSame(
            AgentType::HILOS_DAEMON,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_DAEMON_AGENT_RESTART],
        );
        $this->assertSame(
            DaemonAgentsPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::DAEMON_AGENT_RESTART_DONE],
        );
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The other half of the activation check, the half no starting process can make: the SQL
        // tables a declared feature reads live in migrations applied as a separate step.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            EcommerceShopRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testFrameworkExtensionsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. No framework key is extended here, so
        // every key passes as the framework's own; the day one is, this names the refusal in
        // seconds instead of a stand that did not come up.
        $previous = Hilos::$db;
        $databaseMetadata = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $databaseMetadata[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            // The mounted journal chains need physical schema identities; configuration alone opens no connection.
            Database::configure(database: 'topology_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            Hilos::$db = new EcommerceShopDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
        } finally {
            Hilos::$db = $previous;
            foreach ($databaseMetadata as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
        }

        $this->addToAssertionCount(1);
    }
}
