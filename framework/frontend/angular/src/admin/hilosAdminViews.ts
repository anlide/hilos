// The default Hilos admin views: a page-key → component map over the whole Hilos
// admin section. Each entry is a real, one-per-file page component under admin/
// (the folders mirror the backend Pages/Hilos/ section grouping) — a thin default
// that renders the shared HilosAdminPage shell for its key until the page grows
// its own content. A project spreads this map into its app page map, then
// overrides only the keys it implements itself, also naming unbuilt keys in
// bootHilos projectViews. HILOS_UNBUILT_PAGES hides pages the layer has not built:
// the router answers 404 and no card links to them.
//
// The users / user / settings / backup / maintenance, communications hub / channel
// and Change Log pages are intentionally absent: they are real framework pages
// (HilosUsersPage / HilosUserPage / HilosSettingsPage / HilosBackupPage /
// HilosMaintenancePage / HilosCommunicationsPage / HilosCommunicationsChannelPage /
// HilosChangeLogPage / HilosChangeLogTablesPage / HilosChangeLogTablePage) that
// require a project-supplied context, so a project mounts them directly rather
// than through this default map.
// HilosAnalyticsBrowserSessionPage and HilosAnalyticsPersonPage likewise need the
// project's Analytics context and are mounted explicitly under
// HilosPages.ANALYTICS_BROWSER_SESSION and HilosPages.ANALYTICS_PERSON. The
// dashboard is the real HilosDashboardPage (self-contained, no project context),
// reused here as its own default.
//
// This is the sanctioned registry form, not a God-map (page-module-structure.md):
// every page is its own module file, the catalog of identity stays in @hilos/core
// (HILOS_ADMIN_PAGES), each component renders the page-agnostic HilosAdminPage
// shell, and HilosView is the one component that reads the navigator.
import type { Type } from '@angular/core'
import { HilosPages, lazyPage, type PageView } from '@hilos/core/base'

/**
 * The default admin view map: every framework admin page key (except users/user)
 * bound to its one-per-file default page component. Spread it into the app page
 * map ahead of the project's own overrides. HILOS_UNBUILT_PAGES hides unbuilt
 * pages with a 404 and no cards; a project implementing one overrides its key
 * and names it in bootHilos projectViews.
 */
export function hilosAdminViews(): Record<string, PageView<Type<unknown>>> {
  return {
    [HilosPages.DASHBOARD]: lazyPage(() =>
      import('@hilos/angular/admin/dashboard').then(
        (m) => m.HilosDashboardPage,
      ),
    ),
    [HilosPages.ROLES]: lazyPage(() =>
      import('@hilos/angular/admin/roles').then((m) => m.HilosRolesPage),
    ),
    [HilosPages.I18N]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nPage),
    ),
    // I18N_LANGUAGES, I18N_LANGUAGE, I18N_LANGUAGE_NAMES, I18N_LANGUAGE_LOCALES,
    // I18N_COUNTRIES, I18N_COUNTRY and I18N_COUNTRY_NAMES are real framework
    // pages since HIL-1503: the lists, the names and the locales read a live
    // connection for their table, every card page reads one for its page
    // datum, and all of them send their tracked actions, so a project mounts
    // HilosI18nLanguagesPage, HilosI18nLanguagePage, HilosI18nLanguageNamesPage
    // and HilosI18nLanguageLocalesPage directly with its language context, and
    // HilosI18nCountriesPage, HilosI18nCountryPage and HilosI18nCountryNamesPage
    // with its country context. The section root above stays self-contained.
    [HilosPages.I18N_ENTITIES]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nEntitiesPage),
    ),
    [HilosPages.I18N_UI_PAGES]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nUiPagesPage),
    ),
    [HilosPages.I18N_GROUPS]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nGroupsPage),
    ),
    [HilosPages.I18N_ACTIONS]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nActionsPage),
    ),
    [HilosPages.I18N_EMAILS]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nEmailsPage),
    ),
    [HilosPages.I18N_UI_PAGE]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nUiPagePage),
    ),
    [HilosPages.I18N_GROUP]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nGroupPage),
    ),
    [HilosPages.I18N_ACTION]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then((m) => m.HilosI18nActionPage),
    ),
    [HilosPages.I18N_TRANSLATE_ENTITY]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateEntityPage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_UI_PAGE]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateUiPagePage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateUiPageItemPage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_GROUP]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateGroupPage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateGroupItemPage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateActionErrorPage,
      ),
    ),
    [HilosPages.I18N_TRANSLATE_EMAIL]: lazyPage(() =>
      import('@hilos/angular/admin/i18n').then(
        (m) => m.HilosI18nTranslateEmailPage,
      ),
    ),
    // COMMUNICATIONS, COMMUNICATIONS_CHANNEL, and COMMUNICATIONS_DELIVERIES are real
    // framework pages that need a project-supplied context (like settings / backup):
    // the deliveries journal reads a live connection for its table, so a project
    // mounts them directly rather than through this context-free default map.
    [HilosPages.SECURITY]: lazyPage(() =>
      import('@hilos/angular/admin/security').then((m) => m.HilosSecurityPage),
    ),
    // SECURITY_2FA is a real framework page since HIL-494: its table reads a
    // live connection, so a project mounts HilosSecurity2faPage directly with its
    // context. So is its child SECURITY_STEP_UP since HIL-1204: a project mounts
    // HilosSecurityStepUpPage with the same two-factor context.
    // SECURITY_IMPERSONATION is a real framework page since HIL-1170: its table
    // reads a live connection, so a project mounts
    // HilosSecurityImpersonationPage directly with its impersonation context.
    // SECURITY_OAUTH and SECURITY_OAUTH_PROVIDER are real framework pages since
    // HIL-286: their tables read a live connection, so a project mounts
    // HilosSecurityOauthPage and HilosSecurityOauthProviderPage directly with its context.
    // SECURITY_SIGN_IN_METHODS is one too since HIL-427: its table and its live
    // method set read the connection and the session scope, so a project mounts
    // HilosSecuritySignInMethodsPage directly with its context.
    [HilosPages.BILLING]: lazyPage(() =>
      import('@hilos/angular/admin/billing').then((m) => m.HilosBillingPage),
    ),
    [HilosPages.BILLING_PROVIDER]: lazyPage(() =>
      import('@hilos/angular/admin/billing').then(
        (m) => m.HilosBillingProviderPage,
      ),
    ),
    [HilosPages.BILLING_PAYMENTS]: lazyPage(() =>
      import('@hilos/angular/admin/billing').then(
        (m) => m.HilosBillingPaymentsPage,
      ),
    ),
    [HilosPages.BILLING_REFUNDS]: lazyPage(() =>
      import('@hilos/angular/admin/billing').then(
        (m) => m.HilosBillingRefundsPage,
      ),
    ),
    // Built Daemon pages require a project context and mount directly.
    // LOGS, LOGS_KEYS, LOGS_WORKERS, LOGS_ROTATIONS, LOGS_SETTINGS and LOGS_VIEW are
    // absent for the same reason as the deliveries journal: the stream lists and the
    // rotation history each read a live connection for their table and their header,
    // the viewer reads one for its catalog and one action for its lines, the logging
    // modes read one for their frame and one action to apply a mode, and the section
    // root reads one for the overview frame its tiles and its per-node table are
    // drawn from. So a project mounts HilosLogsPage, HilosLogsKeysPage,
    // HilosLogsWorkersPage, HilosLogsRotationsPage, HilosLogsSettingsPage and
    // HilosLogsViewPage directly with its own context.
    // The three pages of the change-log section are mounted with context too:
    // CHANGE_LOG, CHANGE_LOG_TABLES and CHANGE_LOG_TABLE read a live connection
    // and page scope, so a project mounts HilosChangeLogPage,
    // HilosChangeLogTablesPage and HilosChangeLogTablePage directly.
    [HilosPages.ANALYTICS]: lazyPage(() =>
      import('@hilos/angular/admin/analytics').then(
        (m) => m.HilosAnalyticsPage,
      ),
    ),
    [HilosPages.MCP_SKILLS]: lazyPage(() =>
      import('@hilos/angular/admin/mcp-skills').then(
        (m) => m.HilosMcpSkillsPage,
      ),
    ),
    [HilosPages.MCP_SKILLS_MCP]: lazyPage(() =>
      import('@hilos/angular/admin/mcp-skills').then(
        (m) => m.HilosMcpSkillsMcpPage,
      ),
    ),
    [HilosPages.MCP_SKILLS_MCP_LOGS]: lazyPage(() =>
      import('@hilos/angular/admin/mcp-skills').then(
        (m) => m.HilosMcpSkillsMcpLogsPage,
      ),
    ),
    [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: lazyPage(() =>
      import('@hilos/angular/admin/mcp-skills').then(
        (m) => m.HilosMcpSkillsMcpLogsViewPage,
      ),
    ),
    [HilosPages.SIL]: lazyPage(() =>
      import('@hilos/angular/admin/sil').then((m) => m.HilosSilPage),
    ),
    [HilosPages.SIL_REQUESTS]: lazyPage(() =>
      import('@hilos/angular/admin/sil').then((m) => m.HilosSilRequestsPage),
    ),
    [HilosPages.SIL_USER_HISTORY]: lazyPage(() =>
      import('@hilos/angular/admin/sil').then((m) => m.HilosSilUserHistoryPage),
    ),
  }
}
