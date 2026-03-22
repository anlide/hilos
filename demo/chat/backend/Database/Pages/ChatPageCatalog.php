<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Pages;

use Demo\Chat\Constants\PageConstants;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogStub;

/**
 * ChatPageCatalog - Page catalog for chat demo.
 *
 * path_template uses {paramName} placeholders; param names are derived from the template on the client.
 */
final class ChatPageCatalog extends PageCatalogStub
{
    /**
     * Returns page catalog for this project.
     *
     * @return array<string, array{
     *     parent_id: ?string,
     *     label: string,
     *     path_template?: string,
     *     hide_breadcrumb?: bool,
     * }>
     */
    public static function getCatalog(): array
    {
        return array_merge(parent::getCatalog(), [
            PageConstants::MAIN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => null,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Home',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/',
                PageCatalogConstants::CATALOG_ENTRY_HIDE_BREADCRUMB => true,
            ],
            PageConstants::PROFILE => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Profile',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/profile',
                PageCatalogConstants::CATALOG_ENTRY_HIDE_BREADCRUMB => true,
            ],
            PageConstants::ADMIN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => null,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Admin',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/admin',
            ],
            PageConstants::ADMIN_USERS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Users',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/admin/users',
            ],
            PageConstants::USER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/user/{id}',
            ],
            PageConstants::ADMIN_MODERATOR => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Moderator',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/admin/moderator',
            ],
            PageConstants::ADMIN_BOTS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bots',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/admin/bots',
            ],
            PageConstants::BOT => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::MAIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Bot',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/bot/{id}',
            ],
            PageConstants::HILOS_DASHBOARD => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::ADMIN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Hilos',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos',
            ],
            PageConstants::HILOS_SETTINGS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/settings',
            ],
            PageConstants::HILOS_I18N => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Internationalization',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n',
            ],
            PageConstants::HILOS_GUARDIAN => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Guardian',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/guardian',
            ],
            PageConstants::HILOS_GUARDIAN_AGENT => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_GUARDIAN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Guardian Agent',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/guardian/{agentId}',
            ],
            PageConstants::HILOS_ANALYTICS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Analytics',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/analytics',
            ],
            PageConstants::HILOS_I18N_LANGUAGES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Languages',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/languages',
            ],
            PageConstants::HILOS_I18N_COUNTRIES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Countries',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/countries',
            ],
            PageConstants::HILOS_I18N_ENTITIES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Entities',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/entities',
            ],
            PageConstants::HILOS_I18N_UI_PAGES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'UI pages',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/ui-pages',
            ],
            PageConstants::HILOS_I18N_GROUPS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Groups',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/groups',
            ],
            PageConstants::HILOS_I18N_ACTIONS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Actions',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/actions',
            ],
            PageConstants::HILOS_I18N_EMAILS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Emails',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/emails',
            ],
            PageConstants::HILOS_I18N_LANGUAGE => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_LANGUAGES,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Language',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/languages/{languageId}',
            ],
            PageConstants::HILOS_I18N_COUNTRY => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_COUNTRIES,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Country',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/countries/{countryId}',
            ],
            PageConstants::HILOS_I18N_UI_PAGE => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_UI_PAGES,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'UI page',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/ui-pages/{uiPageId}',
            ],
            PageConstants::HILOS_I18N_GROUP => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_GROUPS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Group',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/groups/{groupId}',
            ],
            PageConstants::HILOS_I18N_ACTION => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_ACTIONS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Action',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/actions/{actionId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_ENTITY => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_ENTITIES,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate entity',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/entity/{entityId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_UI_PAGE => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_UI_PAGES,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate UI page',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/ui-page/{uiPageId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_UI_PAGE,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate UI page item',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/ui-page/{uiPageId}/item/{itemId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_GROUP => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_GROUPS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate group',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/group/{groupId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_GROUP,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate group item',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/group/{groupId}/item/{itemId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_ACTION,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate action error',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/action/{actionId}/error/{errorId}',
            ],
            PageConstants::HILOS_I18N_TRANSLATE_EMAIL => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_I18N_EMAILS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Translate email',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/i18n/translate/email/{emailId}',
            ],
            PageConstants::HILOS_BACKUP => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Backups',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/backup',
            ],
            PageConstants::HILOS_DAEMON => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Daemon',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon',
            ],
            PageConstants::HILOS_DAEMON_WORKERS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DAEMON,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Workers',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon/workers',
            ],
            PageConstants::HILOS_DAEMON_AGENTS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DAEMON,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Agents',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon/agents',
            ],
            PageConstants::HILOS_DAEMON_CRON => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DAEMON,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Cron',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon/cron',
            ],
            PageConstants::HILOS_DAEMON_WEBSOCKETS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DAEMON,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'WebSocket connections',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon/websockets',
            ],
            PageConstants::HILOS_DAEMON_HTTP_SERVER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DAEMON,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'HTTP server',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/daemon/http/{serverId}',
            ],
            PageConstants::HILOS_LOGS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Logs',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/logs',
            ],
            PageConstants::HILOS_LOGS_KEYS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_LOGS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By log key',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/logs/keys',
            ],
            PageConstants::HILOS_LOGS_WORKERS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_LOGS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By worker',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/logs/workers',
            ],
            PageConstants::HILOS_LOGS_ROTATIONS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_LOGS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Rotations',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/logs/rotations',
            ],
            PageConstants::HILOS_LOGS_VIEW => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_LOGS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Viewer',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/logs/view',
            ],
            PageConstants::HILOS_OPERATIONS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Operations',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/operations',
            ],
            PageConstants::HILOS_USERS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Users',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/users',
            ],
            PageConstants::HILOS_USER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_USERS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'User',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/users/{userId}',
            ],
            PageConstants::HILOS_ROLES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Roles',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/roles',
            ],
            PageConstants::HILOS_MCP_SKILLS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP and Skills',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/mcp-skills',
            ],
            PageConstants::HILOS_MCP_SKILLS_MCP => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_MCP_SKILLS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/mcp-skills/{mcpId}',
            ],
            PageConstants::HILOS_MCP_SKILLS_MCP_LOGS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_MCP_SKILLS_MCP,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP logs overview',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/mcp-skills/{mcpId}/logs',
            ],
            PageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_MCP_SKILLS_MCP_LOGS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'MCP log viewer',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/mcp-skills/{mcpId}/logs/view',
            ],
            PageConstants::HILOS_SIL => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'System Intelligence Layer',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/sil',
            ],
            PageConstants::HILOS_SIL_REQUESTS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_SIL,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'SIL requests',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/sil/requests',
            ],
            PageConstants::HILOS_SIL_USER_HISTORY => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_SIL,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'SIL user history',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/sil/users/{userId}',
            ],
            PageConstants::HILOS_COMMUNICATIONS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Communications',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/communications',
            ],
            PageConstants::HILOS_COMMUNICATIONS_CHANNEL => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_COMMUNICATIONS,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Channel',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/communications/{channelId}',
            ],
            PageConstants::HILOS_COMMUNICATIONS_DELIVERIES => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_COMMUNICATIONS_CHANNEL,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Deliveries',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/communications/{channelId}/deliveries',
            ],
            PageConstants::HILOS_SECURITY => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Security Center',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/security',
            ],
            PageConstants::HILOS_SECURITY_2FA => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_SECURITY,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Two-factor authentication',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/security/2fa',
            ],
            PageConstants::HILOS_SECURITY_OAUTH => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_SECURITY,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'OAuth providers',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/security/oauth',
            ],
            PageConstants::HILOS_SECURITY_OAUTH_PROVIDER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_SECURITY_OAUTH,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'OAuth provider',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/security/oauth/{providerId}',
            ],
            PageConstants::HILOS_BILLING => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_DASHBOARD,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Billing',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/billing',
            ],
            PageConstants::HILOS_BILLING_PROVIDER => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_BILLING,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Payment provider',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/billing/{providerId}',
            ],
            PageConstants::HILOS_BILLING_PAYMENTS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_BILLING_PROVIDER,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Payments',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/billing/{providerId}/payments',
            ],
            PageConstants::HILOS_BILLING_REFUNDS => [
                PageCatalogConstants::CATALOG_ENTRY_PARENT_ID => PageConstants::HILOS_BILLING_PROVIDER,
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Refunds',
                PageCatalogConstants::CATALOG_ENTRY_PATH_TEMPLATE => '/hilos/billing/{providerId}/refunds',
            ],
        ]);
    }
}
