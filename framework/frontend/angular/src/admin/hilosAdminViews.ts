// The default Hilos admin views: a page-key → component map over the whole Hilos
// admin section. Each entry is a real, one-per-file page component under admin/
// (the folders mirror the backend Pages/Hilos/ section grouping) — a thin default
// that renders the shared HilosAdminPage shell for its key until the page grows
// its own content. A project spreads this map into its app page map, then
// overrides only the keys it implements itself.
//
// The users / user / settings / backup pages are intentionally absent: they are real
// framework pages (HilosUsersPage / HilosUserPage / HilosSettingsPage / HilosBackupPage) that
// require a project-supplied context, so a project mounts them directly rather
// than through this default map. The dashboard is the real HilosDashboardPage
// (self-contained, no project context), reused here as its own default.
//
// This is the sanctioned registry form, not a God-map (page-module-structure.md):
// every page is its own module file, the catalog of identity stays in @hilos/core
// (HILOS_ADMIN_PAGES), each component renders the page-agnostic HilosAdminPage
// shell, and HilosView is the one component that reads the navigator.
import type { Type } from '@angular/core'
import { HilosPages } from '@hilos/core'

import { HilosDashboardPage } from './dashboard/HilosDashboardPage.js'
import { HilosRolesPage } from './roles/HilosRolesPage.js'
import { HilosI18nPage } from './i18n/HilosI18nPage.js'
import { HilosI18nLanguagesPage } from './i18n/lists/HilosI18nLanguagesPage.js'
import { HilosI18nCountriesPage } from './i18n/lists/HilosI18nCountriesPage.js'
import { HilosI18nEntitiesPage } from './i18n/lists/HilosI18nEntitiesPage.js'
import { HilosI18nUiPagesPage } from './i18n/lists/HilosI18nUiPagesPage.js'
import { HilosI18nGroupsPage } from './i18n/lists/HilosI18nGroupsPage.js'
import { HilosI18nActionsPage } from './i18n/lists/HilosI18nActionsPage.js'
import { HilosI18nEmailsPage } from './i18n/lists/HilosI18nEmailsPage.js'
import { HilosI18nLanguagePage } from './i18n/details/HilosI18nLanguagePage.js'
import { HilosI18nCountryPage } from './i18n/details/HilosI18nCountryPage.js'
import { HilosI18nUiPagePage } from './i18n/details/HilosI18nUiPagePage.js'
import { HilosI18nGroupPage } from './i18n/details/HilosI18nGroupPage.js'
import { HilosI18nActionPage } from './i18n/details/HilosI18nActionPage.js'
import { HilosI18nTranslateEntityPage } from './i18n/translate/HilosI18nTranslateEntityPage.js'
import { HilosI18nTranslateUiPagePage } from './i18n/translate/HilosI18nTranslateUiPagePage.js'
import { HilosI18nTranslateUiPageItemPage } from './i18n/translate/HilosI18nTranslateUiPageItemPage.js'
import { HilosI18nTranslateGroupPage } from './i18n/translate/HilosI18nTranslateGroupPage.js'
import { HilosI18nTranslateGroupItemPage } from './i18n/translate/HilosI18nTranslateGroupItemPage.js'
import { HilosI18nTranslateActionErrorPage } from './i18n/translate/HilosI18nTranslateActionErrorPage.js'
import { HilosI18nTranslateEmailPage } from './i18n/translate/HilosI18nTranslateEmailPage.js'
import { HilosCommunicationsPage } from './communications/HilosCommunicationsPage.js'
import { HilosCommunicationsChannelPage } from './communications/HilosCommunicationsChannelPage.js'
import { HilosCommunicationsDeliveriesPage } from './communications/HilosCommunicationsDeliveriesPage.js'
import { HilosSecurityPage } from './security/HilosSecurityPage.js'
import { HilosSecurity2faPage } from './security/HilosSecurity2faPage.js'
import { HilosSecurityOauthPage } from './security/HilosSecurityOauthPage.js'
import { HilosSecurityOauthProviderPage } from './security/HilosSecurityOauthProviderPage.js'
import { HilosBillingPage } from './billing/HilosBillingPage.js'
import { HilosBillingProviderPage } from './billing/HilosBillingProviderPage.js'
import { HilosBillingPaymentsPage } from './billing/HilosBillingPaymentsPage.js'
import { HilosBillingRefundsPage } from './billing/HilosBillingRefundsPage.js'
import { HilosOperationsPage } from './operations/HilosOperationsPage.js'
import { HilosDaemonPage } from './daemon/HilosDaemonPage.js'
import { HilosDaemonWorkersPage } from './daemon/HilosDaemonWorkersPage.js'
import { HilosDaemonAgentsPage } from './daemon/HilosDaemonAgentsPage.js'
import { HilosDaemonCronPage } from './daemon/HilosDaemonCronPage.js'
import { HilosDaemonWebsocketsPage } from './daemon/HilosDaemonWebsocketsPage.js'
import { HilosDaemonHttpServerPage } from './daemon/HilosDaemonHttpServerPage.js'
import { HilosLogsPage } from './logs/HilosLogsPage.js'
import { HilosLogsKeysPage } from './logs/HilosLogsKeysPage.js'
import { HilosLogsWorkersPage } from './logs/HilosLogsWorkersPage.js'
import { HilosLogsRotationsPage } from './logs/HilosLogsRotationsPage.js'
import { HilosLogsViewPage } from './logs/HilosLogsViewPage.js'
import { HilosChangeLogPage } from './changeLog/HilosChangeLogPage.js'
import { HilosChangeLogTablesPage } from './changeLog/HilosChangeLogTablesPage.js'
import { HilosChangeLogTablePage } from './changeLog/HilosChangeLogTablePage.js'
import { HilosAnalyticsPage } from './analytics/HilosAnalyticsPage.js'
import { HilosMcpSkillsPage } from './mcpSkills/HilosMcpSkillsPage.js'
import { HilosMcpSkillsMcpPage } from './mcpSkills/HilosMcpSkillsMcpPage.js'
import { HilosMcpSkillsMcpLogsPage } from './mcpSkills/HilosMcpSkillsMcpLogsPage.js'
import { HilosMcpSkillsMcpLogsViewPage } from './mcpSkills/HilosMcpSkillsMcpLogsViewPage.js'
import { HilosSilPage } from './sil/HilosSilPage.js'
import { HilosSilRequestsPage } from './sil/HilosSilRequestsPage.js'
import { HilosSilUserHistoryPage } from './sil/HilosSilUserHistoryPage.js'

/**
 * The default admin view map: every framework admin page key (except users/user)
 * bound to its one-per-file default page component. Spread it into the app page
 * map ahead of the project's own overrides, so an un-implemented admin page
 * renders the framework default and an implemented one (mapped after the spread)
 * wins.
 */
export function hilosAdminViews(): Record<string, Type<unknown>> {
  return {
    [HilosPages.DASHBOARD]: HilosDashboardPage,
    [HilosPages.ROLES]: HilosRolesPage,
    [HilosPages.I18N]: HilosI18nPage,
    [HilosPages.I18N_LANGUAGES]: HilosI18nLanguagesPage,
    [HilosPages.I18N_COUNTRIES]: HilosI18nCountriesPage,
    [HilosPages.I18N_ENTITIES]: HilosI18nEntitiesPage,
    [HilosPages.I18N_UI_PAGES]: HilosI18nUiPagesPage,
    [HilosPages.I18N_GROUPS]: HilosI18nGroupsPage,
    [HilosPages.I18N_ACTIONS]: HilosI18nActionsPage,
    [HilosPages.I18N_EMAILS]: HilosI18nEmailsPage,
    [HilosPages.I18N_LANGUAGE]: HilosI18nLanguagePage,
    [HilosPages.I18N_COUNTRY]: HilosI18nCountryPage,
    [HilosPages.I18N_UI_PAGE]: HilosI18nUiPagePage,
    [HilosPages.I18N_GROUP]: HilosI18nGroupPage,
    [HilosPages.I18N_ACTION]: HilosI18nActionPage,
    [HilosPages.I18N_TRANSLATE_ENTITY]: HilosI18nTranslateEntityPage,
    [HilosPages.I18N_TRANSLATE_UI_PAGE]: HilosI18nTranslateUiPagePage,
    [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: HilosI18nTranslateUiPageItemPage,
    [HilosPages.I18N_TRANSLATE_GROUP]: HilosI18nTranslateGroupPage,
    [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: HilosI18nTranslateGroupItemPage,
    [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: HilosI18nTranslateActionErrorPage,
    [HilosPages.I18N_TRANSLATE_EMAIL]: HilosI18nTranslateEmailPage,
    [HilosPages.COMMUNICATIONS]: HilosCommunicationsPage,
    [HilosPages.COMMUNICATIONS_CHANNEL]: HilosCommunicationsChannelPage,
    [HilosPages.COMMUNICATIONS_DELIVERIES]: HilosCommunicationsDeliveriesPage,
    [HilosPages.SECURITY]: HilosSecurityPage,
    [HilosPages.SECURITY_2FA]: HilosSecurity2faPage,
    [HilosPages.SECURITY_OAUTH]: HilosSecurityOauthPage,
    [HilosPages.SECURITY_OAUTH_PROVIDER]: HilosSecurityOauthProviderPage,
    [HilosPages.BILLING]: HilosBillingPage,
    [HilosPages.BILLING_PROVIDER]: HilosBillingProviderPage,
    [HilosPages.BILLING_PAYMENTS]: HilosBillingPaymentsPage,
    [HilosPages.BILLING_REFUNDS]: HilosBillingRefundsPage,
    [HilosPages.OPERATIONS]: HilosOperationsPage,
    [HilosPages.DAEMON]: HilosDaemonPage,
    [HilosPages.DAEMON_WORKERS]: HilosDaemonWorkersPage,
    [HilosPages.DAEMON_AGENTS]: HilosDaemonAgentsPage,
    [HilosPages.DAEMON_CRON]: HilosDaemonCronPage,
    [HilosPages.DAEMON_WEBSOCKETS]: HilosDaemonWebsocketsPage,
    [HilosPages.DAEMON_HTTP_SERVER]: HilosDaemonHttpServerPage,
    [HilosPages.LOGS]: HilosLogsPage,
    [HilosPages.LOGS_KEYS]: HilosLogsKeysPage,
    [HilosPages.LOGS_WORKERS]: HilosLogsWorkersPage,
    [HilosPages.LOGS_ROTATIONS]: HilosLogsRotationsPage,
    [HilosPages.LOGS_VIEW]: HilosLogsViewPage,
    [HilosPages.CHANGE_LOG]: HilosChangeLogPage,
    [HilosPages.CHANGE_LOG_TABLES]: HilosChangeLogTablesPage,
    [HilosPages.CHANGE_LOG_TABLE]: HilosChangeLogTablePage,
    [HilosPages.ANALYTICS]: HilosAnalyticsPage,
    [HilosPages.MCP_SKILLS]: HilosMcpSkillsPage,
    [HilosPages.MCP_SKILLS_MCP]: HilosMcpSkillsMcpPage,
    [HilosPages.MCP_SKILLS_MCP_LOGS]: HilosMcpSkillsMcpLogsPage,
    [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: HilosMcpSkillsMcpLogsViewPage,
    [HilosPages.SIL]: HilosSilPage,
    [HilosPages.SIL_REQUESTS]: HilosSilRequestsPage,
    [HilosPages.SIL_USER_HISTORY]: HilosSilUserHistoryPage,
  }
}
