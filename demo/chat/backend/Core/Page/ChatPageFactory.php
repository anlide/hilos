<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\DTO\FileModerationDismissActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStartActionDTO;
use Demo\Chat\Core\Page\DTO\GuardianAgentRunStopActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Page\DTO\RenameActionDTO;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\Hilos\AnalyticsPage;
use Demo\Chat\Pages\Hilos\Backup\BackupPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPage;
use Demo\Chat\Pages\Hilos\Billing\BillingPaymentsPage;
use Demo\Chat\Pages\Hilos\Billing\BillingProviderPage;
use Demo\Chat\Pages\Hilos\Billing\BillingRefundsPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Chat\Pages\Hilos\DashboardPage;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Chat\Pages\Hilos\Logs\LogsViewPage;
use Demo\Chat\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsDashboardPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpLogsViewPage;
use Demo\Chat\Pages\Hilos\McpSkills\McpSkillsMcpPage;
use Demo\Chat\Pages\Hilos\Operations\OperationsPage;
use Demo\Chat\Pages\Hilos\Roles\RolesPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Pages\Hilos\Security\SecurityPage;
use Demo\Chat\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Chat\Pages\Hilos\Sil\SilDashboardPage;
use Demo\Chat\Pages\Hilos\Sil\SilRequestsPage;
use Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Pages\Hilos\I18n\Details\ActionDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\GroupDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\UiPageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\ActionsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\EmailsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\EntitiesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\GroupsListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\UiPagesListPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateActionErrorPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateEmailPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateEntityPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupItemPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateUiPageItemPage;
use Demo\Chat\Pages\Hilos\I18n\Translate\TranslateUiPagePage;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ModeratorPage;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Pages\UserPage;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotDeleteActionDTO;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Demo\Chat\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceDeleteActionDTO;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingAddActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingDeleteActionDTO;
use Demo\Chat\Tables\Settings\DTO\SettingUpdateActionDTO;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ChatPageFactory - Factory for creating chat page instances.
 *
 * Creates and manages chat page instances.
 * Extends HilosPageFactory to inherit framework-level Hilos admin pages.
 *
 * @extends HilosPageFactory<PageAgentInterface>
 */
final class ChatPageFactory extends HilosPageFactory
{
    /**
     * Create page instance by name.
     *
     * @param string $pageName Page constant (e.g. PageConstants::MAIN)
     * @return AbstractPage Page instance
     * @throws PageNotFoundException When page cannot be created
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            PageConstants::MAIN => new MainPage($this->agent),
            PageConstants::PROFILE => new ProfilePage($this->agent),
            PageConstants::USER => new UserPage($this->agent),
            PageConstants::BOT => new BotPage($this->agent),
            PageConstants::MODERATOR => new ModeratorPage($this->agent),
            PageConstants::ADMIN => new AdminPage($this->agent),
            PageConstants::ADMIN_USERS => new AdminUsersPage($this->agent),
            PageConstants::ADMIN_MODERATOR => new AdminModeratorPage($this->agent),
            PageConstants::ADMIN_BOTS => new AdminBotsPage($this->agent),
            HilosPageConstants::HILOS_DASHBOARD => new DashboardPage($this->agent),
            HilosPageConstants::HILOS_SETTINGS => new SettingsPage($this->agent),
            HilosPageConstants::HILOS_I18N => new I18nPage($this->agent),
            HilosPageConstants::HILOS_GUARDIAN => new GuardianPage($this->agent),
            HilosPageConstants::HILOS_GUARDIAN_AGENT => new GuardianAgentPage($this->agent),
            HilosPageConstants::HILOS_ANALYTICS => new AnalyticsPage($this->agent),
            HilosPageConstants::HILOS_I18N_LANGUAGES => new LanguagesListPage($this->agent),
            HilosPageConstants::HILOS_I18N_COUNTRIES => new CountriesListPage($this->agent),
            HilosPageConstants::HILOS_I18N_ENTITIES => new EntitiesListPage($this->agent),
            HilosPageConstants::HILOS_I18N_UI_PAGES => new UiPagesListPage($this->agent),
            HilosPageConstants::HILOS_I18N_GROUPS => new GroupsListPage($this->agent),
            HilosPageConstants::HILOS_I18N_ACTIONS => new ActionsListPage($this->agent),
            HilosPageConstants::HILOS_I18N_EMAILS => new EmailsListPage($this->agent),
            HilosPageConstants::HILOS_I18N_LANGUAGE => new LanguageDetailPage($this->agent),
            HilosPageConstants::HILOS_I18N_COUNTRY => new CountryDetailPage($this->agent),
            HilosPageConstants::HILOS_I18N_UI_PAGE => new UiPageDetailPage($this->agent),
            HilosPageConstants::HILOS_I18N_GROUP => new GroupDetailPage($this->agent),
            HilosPageConstants::HILOS_I18N_ACTION => new ActionDetailPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_ENTITY => new TranslateEntityPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE => new TranslateUiPagePage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM => new TranslateUiPageItemPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP => new TranslateGroupPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM => new TranslateGroupItemPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR => new TranslateActionErrorPage($this->agent),
            HilosPageConstants::HILOS_I18N_TRANSLATE_EMAIL => new TranslateEmailPage($this->agent),
            HilosPageConstants::HILOS_BACKUP => new BackupPage($this->agent),
            HilosPageConstants::HILOS_DAEMON => new DaemonPage($this->agent),
            HilosPageConstants::HILOS_DAEMON_WORKERS => new DaemonWorkersPage($this->agent),
            HilosPageConstants::HILOS_DAEMON_AGENTS => new DaemonAgentsPage($this->agent),
            HilosPageConstants::HILOS_DAEMON_CRON => new DaemonCronPage($this->agent),
            HilosPageConstants::HILOS_DAEMON_WEBSOCKETS => new DaemonWebsocketsPage($this->agent),
            HilosPageConstants::HILOS_DAEMON_HTTP_SERVER => new DaemonHttpServerPage($this->agent),
            HilosPageConstants::HILOS_LOGS => new LogsOverviewPage($this->agent),
            HilosPageConstants::HILOS_LOGS_KEYS => new LogsKeysPage($this->agent),
            HilosPageConstants::HILOS_LOGS_WORKERS => new LogsWorkersPage($this->agent),
            HilosPageConstants::HILOS_LOGS_ROTATIONS => new LogsRotationsPage($this->agent),
            HilosPageConstants::HILOS_LOGS_VIEW => new LogsViewPage($this->agent),
            HilosPageConstants::HILOS_OPERATIONS => new OperationsPage($this->agent),
            HilosPageConstants::HILOS_USERS => new UsersPage($this->agent),
            HilosPageConstants::HILOS_USER => new HilosUserPage($this->agent),
            HilosPageConstants::HILOS_ROLES => new RolesPage($this->agent),
            HilosPageConstants::HILOS_MCP_SKILLS => new McpSkillsDashboardPage($this->agent),
            HilosPageConstants::HILOS_MCP_SKILLS_MCP => new McpSkillsMcpPage($this->agent),
            HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS => new McpSkillsMcpLogsPage($this->agent),
            HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW => new McpSkillsMcpLogsViewPage($this->agent),
            HilosPageConstants::HILOS_SIL => new SilDashboardPage($this->agent),
            HilosPageConstants::HILOS_SIL_REQUESTS => new SilRequestsPage($this->agent),
            HilosPageConstants::HILOS_SIL_USER_HISTORY => new SilUserHistoryPage($this->agent),
            HilosPageConstants::HILOS_COMMUNICATIONS => new CommunicationsPage($this->agent),
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => new CommunicationsChannelPage($this->agent),
            HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES => new CommunicationsDeliveriesPage($this->agent),
            HilosPageConstants::HILOS_SECURITY => new SecurityPage($this->agent),
            HilosPageConstants::HILOS_SECURITY_2FA => new SecurityTwoFactorPage($this->agent),
            HilosPageConstants::HILOS_SECURITY_OAUTH => new SecurityOAuthPage($this->agent),
            HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER => new SecurityOAuthProviderPage($this->agent),
            HilosPageConstants::HILOS_BILLING => new BillingPage($this->agent),
            HilosPageConstants::HILOS_BILLING_PROVIDER => new BillingProviderPage($this->agent),
            HilosPageConstants::HILOS_BILLING_PAYMENTS => new BillingPaymentsPage($this->agent),
            HilosPageConstants::HILOS_BILLING_REFUNDS => new BillingRefundsPage($this->agent),
            HilosPageConstants::HILOS_CHANGE_LOG => new ChangeLogDashboardPage($this->agent),
            HilosPageConstants::HILOS_CHANGE_LOG_TABLES => new ChangeLogTablesPage($this->agent),
            HilosPageConstants::HILOS_CHANGE_LOG_TABLE => new ChangeLogTablePage($this->agent),
            default => parent::createPage($pageName),
        };
    }

    /**
     * Check if page name is supported.
     *
     * @param string $pageName Page name constant
     * @return bool True if page exists
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [
            PageConstants::MAIN,
            PageConstants::PROFILE,
            PageConstants::USER,
            PageConstants::BOT,
            PageConstants::MODERATOR,
            PageConstants::ADMIN,
            PageConstants::ADMIN_USERS,
            PageConstants::ADMIN_MODERATOR,
            PageConstants::ADMIN_BOTS,
        ], true) || parent::hasPage($pageName);
    }

    /**
     * Creates action payload DTO for the given action and data.
     *
     * @param string $action Action name (e.g. ChatSignalConstants::MESSAGE)
     * @param array<string, mixed> $data Action payload data
     * @return ActionPayloadDTO DTO instance
     */
    public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
    {
        return match ($action) {
            ChatSignalConstants::MESSAGE => MessageActionDTO::fromArray($data),
            ChatSignalConstants::RENAME => RenameActionDTO::fromArray($data),
            ChatSignalConstants::FILE_UPLOAD_INIT => FileUploadInitActionDTO::fromArray($data),
            ChatSignalConstants::FILE_MODERATION_DISMISS => FileModerationDismissActionDTO::fromArray($data),
            ChatSignalConstants::USER_UPDATE => AdminUserUpdateActionDTO::fromArray($data),
            ChatSignalConstants::HILOS_USER_UPDATE => HilosUserUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_CREATE => BotCreateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_UPDATE => BotUpdateActionDTO::fromArray($data),
            ChatSignalConstants::BOT_DELETE => BotDeleteActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_CREATE => ModeratorPieceCreateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_UPDATE => ModeratorPieceUpdateActionDTO::fromArray($data),
            ChatSignalConstants::MODERATOR_PIECE_DELETE => ModeratorPieceDeleteActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_ADD => SettingAddActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_UPDATE => SettingUpdateActionDTO::fromArray($data),
            ChatSignalConstants::SETTING_DELETE => SettingDeleteActionDTO::fromArray($data),
            ChatSignalConstants::GUARDIAN_AGENT_RUN_START => GuardianAgentRunStartActionDTO::fromArray($data),
            ChatSignalConstants::GUARDIAN_AGENT_RUN_STOP => GuardianAgentRunStopActionDTO::fromArray($data),
            default => parent::createActionPayloadDTO($action, $data),
        };
    }
}
