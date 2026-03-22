/**
 * Route definitions for Vite SSG and Vue Router.
 * Exported for both prerendering and client-side routing.
 */
import type { RouteRecordRaw } from 'vue-router'
import Layout from '@/components/Layout.vue'
import Home from '@/views/Home.vue'
import Profile from '@/views/Profile.vue'
import Admin from '@/views/Admin.vue'
import AdminUsers from '@/views/AdminUsers.vue'
import AdminModerator from '@/views/AdminModerator.vue'
import AdminBots from '@/views/AdminBots.vue'
import User from '@/views/User.vue'
import Bot from '@/views/Bot.vue'
import Licence from '@/views/Licence.vue'
import Terms from '@/views/Terms.vue'
import Privacy from '@/views/Privacy.vue'
import Agents from '@/views/Agents.vue'
import HilosDashboard from '@/views/Hilos/HilosDashboard.vue'
import HilosSettings from '@/views/Hilos/HilosSettings.vue'
import HilosI18n from '@/views/Hilos/HilosI18n.vue'
import HilosI18nLanguagesList from '@/views/Hilos/I18n/Lists/HilosI18nLanguagesList.vue'
import HilosI18nLanguageDetail from '@/views/Hilos/I18n/Details/HilosI18nLanguageDetail.vue'
import HilosI18nCountriesList from '@/views/Hilos/I18n/Lists/HilosI18nCountriesList.vue'
import HilosI18nCountryDetail from '@/views/Hilos/I18n/Details/HilosI18nCountryDetail.vue'
import HilosI18nEntitiesList from '@/views/Hilos/I18n/Lists/HilosI18nEntitiesList.vue'
import HilosI18nUiPagesList from '@/views/Hilos/I18n/Lists/HilosI18nUiPagesList.vue'
import HilosI18nUiPageDetail from '@/views/Hilos/I18n/Details/HilosI18nUiPageDetail.vue'
import HilosI18nGroupsList from '@/views/Hilos/I18n/Lists/HilosI18nGroupsList.vue'
import HilosI18nGroupDetail from '@/views/Hilos/I18n/Details/HilosI18nGroupDetail.vue'
import HilosI18nActionsList from '@/views/Hilos/I18n/Lists/HilosI18nActionsList.vue'
import HilosI18nActionDetail from '@/views/Hilos/I18n/Details/HilosI18nActionDetail.vue'
import HilosI18nEmailsList from '@/views/Hilos/I18n/Lists/HilosI18nEmailsList.vue'
import HilosI18nTranslateEntity from '@/views/Hilos/I18n/Translate/HilosI18nTranslateEntity.vue'
import HilosI18nTranslateUiPage from '@/views/Hilos/I18n/Translate/HilosI18nTranslateUiPage.vue'
import HilosI18nTranslateUiPageItem from '@/views/Hilos/I18n/Translate/HilosI18nTranslateUiPageItem.vue'
import HilosI18nTranslateGroup from '@/views/Hilos/I18n/Translate/HilosI18nTranslateGroup.vue'
import HilosI18nTranslateGroupItem from '@/views/Hilos/I18n/Translate/HilosI18nTranslateGroupItem.vue'
import HilosI18nTranslateActionError from '@/views/Hilos/I18n/Translate/HilosI18nTranslateActionError.vue'
import HilosI18nTranslateEmail from '@/views/Hilos/I18n/Translate/HilosI18nTranslateEmail.vue'
import HilosGuardian from '@/views/Hilos/HilosGuardian.vue'
import HilosGuardianAgent from '@/views/Hilos/HilosGuardianAgent.vue'
import HilosAnalytics from '@/views/Hilos/HilosAnalytics.vue'
import HilosMcpSkillsDashboard from '@/views/Hilos/McpSkills/HilosMcpSkillsDashboard.vue'
import HilosMcpSkillsMcp from '@/views/Hilos/McpSkills/HilosMcpSkillsMcp.vue'
import HilosMcpSkillsMcpLogs from '@/views/Hilos/McpSkills/HilosMcpSkillsMcpLogs.vue'
import HilosMcpSkillsMcpLogsView from '@/views/Hilos/McpSkills/HilosMcpSkillsMcpLogsView.vue'
import HilosSilDashboard from '@/views/Hilos/Sil/HilosSilDashboard.vue'
import HilosSilRequests from '@/views/Hilos/Sil/HilosSilRequests.vue'
import HilosSilUserHistory from '@/views/Hilos/Sil/HilosSilUserHistory.vue'
import HilosBackup from '@/views/Hilos/Backup/HilosBackup.vue'
import HilosDaemon from '@/views/Hilos/Daemon/HilosDaemon.vue'
import HilosDaemonWorkers from '@/views/Hilos/Daemon/HilosDaemonWorkers.vue'
import HilosDaemonAgents from '@/views/Hilos/Daemon/HilosDaemonAgents.vue'
import HilosDaemonCron from '@/views/Hilos/Daemon/HilosDaemonCron.vue'
import HilosDaemonWebsockets from '@/views/Hilos/Daemon/HilosDaemonWebsockets.vue'
import HilosDaemonHttpServer from '@/views/Hilos/Daemon/HilosDaemonHttpServer.vue'
import HilosLogs from '@/views/Hilos/Logs/HilosLogs.vue'
import HilosLogsKeys from '@/views/Hilos/Logs/HilosLogsKeys.vue'
import HilosLogsWorkers from '@/views/Hilos/Logs/HilosLogsWorkers.vue'
import HilosLogsRotations from '@/views/Hilos/Logs/HilosLogsRotations.vue'
import HilosLogsView from '@/views/Hilos/Logs/HilosLogsView.vue'
import HilosOperations from '@/views/Hilos/HilosOperations.vue'
import HilosUsers from '@/views/Hilos/HilosUsers.vue'
import HilosUserDetail from '@/views/Hilos/HilosUserDetail.vue'
import HilosRoles from '@/views/Hilos/HilosRoles.vue'
import ErrorPage from '@/views/ErrorPage.vue'

export const demoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: Layout,
    children: [
      {
        path: '',
        name: 'home',
        component: Home,
        meta: { page: 'main' },
      },
      {
        path: 'profile',
        name: 'profile',
        component: Profile,
        meta: { page: 'profile' },
      },
      {
        path: 'admin',
        name: 'admin',
        component: Admin,
        meta: { page: 'admin' },
      },
      {
        path: 'admin/users',
        name: 'admin_users',
        component: AdminUsers,
        meta: { page: 'admin_users' },
      },
      {
        path: 'admin/moderator',
        name: 'admin_moderator',
        component: AdminModerator,
        meta: { page: 'admin_moderator' },
      },
      {
        path: 'admin/bots',
        name: 'admin_bots',
        component: AdminBots,
        meta: { page: 'admin_bots' },
      },
      {
        path: 'user/:id',
        name: 'user',
        component: User,
        meta: { page: 'user' },
      },
      {
        path: 'bot/:id',
        name: 'bot',
        component: Bot,
        meta: { page: 'bot' },
      },
      {
        path: 'licence',
        name: 'licence',
        component: Licence,
      },
      {
        path: 'terms',
        name: 'terms',
        component: Terms,
      },
      {
        path: 'privacy',
        name: 'privacy',
        component: Privacy,
      },
      {
        path: 'hilos',
        name: 'hilos',
        component: HilosDashboard,
        meta: { page: 'hilos' },
      },
      {
        path: 'hilos/users/:userId',
        name: 'hilos_user',
        component: HilosUserDetail,
        meta: { page: 'hilos_user' },
      },
      {
        path: 'hilos/users',
        name: 'hilos_users',
        component: HilosUsers,
        meta: { page: 'hilos_users' },
      },
      {
        path: 'hilos/operations',
        name: 'hilos_operations',
        component: HilosOperations,
        meta: { page: 'hilos_operations' },
      },
      {
        path: 'hilos/roles',
        name: 'hilos_roles',
        component: HilosRoles,
        meta: { page: 'hilos_roles' },
      },
      {
        path: 'hilos/backup',
        name: 'hilos_backup',
        component: HilosBackup,
        meta: { page: 'hilos_backup' },
      },
      {
        path: 'hilos/daemon/http/:serverId',
        name: 'hilos_daemon_http_server',
        component: HilosDaemonHttpServer,
        meta: { page: 'hilos_daemon_http_server' },
      },
      {
        path: 'hilos/daemon/workers',
        name: 'hilos_daemon_workers',
        component: HilosDaemonWorkers,
        meta: { page: 'hilos_daemon_workers' },
      },
      {
        path: 'hilos/daemon/agents',
        name: 'hilos_daemon_agents',
        component: HilosDaemonAgents,
        meta: { page: 'hilos_daemon_agents' },
      },
      {
        path: 'hilos/daemon/cron',
        name: 'hilos_daemon_cron',
        component: HilosDaemonCron,
        meta: { page: 'hilos_daemon_cron' },
      },
      {
        path: 'hilos/daemon/websockets',
        name: 'hilos_daemon_websockets',
        component: HilosDaemonWebsockets,
        meta: { page: 'hilos_daemon_websockets' },
      },
      {
        path: 'hilos/daemon',
        name: 'hilos_daemon',
        component: HilosDaemon,
        meta: { page: 'hilos_daemon' },
      },
      {
        path: 'hilos/logs/view',
        name: 'hilos_logs_view',
        component: HilosLogsView,
        meta: { page: 'hilos_logs_view' },
      },
      {
        path: 'hilos/logs/keys',
        name: 'hilos_logs_keys',
        component: HilosLogsKeys,
        meta: { page: 'hilos_logs_keys' },
      },
      {
        path: 'hilos/logs/workers',
        name: 'hilos_logs_workers',
        component: HilosLogsWorkers,
        meta: { page: 'hilos_logs_workers' },
      },
      {
        path: 'hilos/logs/rotations',
        name: 'hilos_logs_rotations',
        component: HilosLogsRotations,
        meta: { page: 'hilos_logs_rotations' },
      },
      {
        path: 'hilos/logs',
        name: 'hilos_logs',
        component: HilosLogs,
        meta: { page: 'hilos_logs' },
      },
      {
        path: 'hilos/settings',
        name: 'hilos_settings',
        component: HilosSettings,
        meta: { page: 'hilos_settings' },
      },
      {
        path: 'hilos/i18n',
        name: 'hilos_i18n',
        component: HilosI18n,
        meta: { page: 'hilos_i18n' },
      },
      {
        path: 'hilos/i18n/translate/ui-page/:uiPageId/item/:itemId',
        name: 'hilos_i18n_translate_ui_page_item',
        component: HilosI18nTranslateUiPageItem,
        meta: { page: 'hilos_i18n_translate_ui_page_item' },
      },
      {
        path: 'hilos/i18n/translate/group/:groupId/item/:itemId',
        name: 'hilos_i18n_translate_group_item',
        component: HilosI18nTranslateGroupItem,
        meta: { page: 'hilos_i18n_translate_group_item' },
      },
      {
        path: 'hilos/i18n/translate/action/:actionId/error/:errorId',
        name: 'hilos_i18n_translate_action_error',
        component: HilosI18nTranslateActionError,
        meta: { page: 'hilos_i18n_translate_action_error' },
      },
      {
        path: 'hilos/i18n/translate/entity/:entityId',
        name: 'hilos_i18n_translate_entity',
        component: HilosI18nTranslateEntity,
        meta: { page: 'hilos_i18n_translate_entity' },
      },
      {
        path: 'hilos/i18n/translate/ui-page/:uiPageId',
        name: 'hilos_i18n_translate_ui_page',
        component: HilosI18nTranslateUiPage,
        meta: { page: 'hilos_i18n_translate_ui_page' },
      },
      {
        path: 'hilos/i18n/translate/group/:groupId',
        name: 'hilos_i18n_translate_group',
        component: HilosI18nTranslateGroup,
        meta: { page: 'hilos_i18n_translate_group' },
      },
      {
        path: 'hilos/i18n/translate/email/:emailId',
        name: 'hilos_i18n_translate_email',
        component: HilosI18nTranslateEmail,
        meta: { page: 'hilos_i18n_translate_email' },
      },
      {
        path: 'hilos/i18n/languages/:languageId',
        name: 'hilos_i18n_language',
        component: HilosI18nLanguageDetail,
        meta: { page: 'hilos_i18n_language' },
      },
      {
        path: 'hilos/i18n/languages',
        name: 'hilos_i18n_languages',
        component: HilosI18nLanguagesList,
        meta: { page: 'hilos_i18n_languages' },
      },
      {
        path: 'hilos/i18n/countries/:countryId',
        name: 'hilos_i18n_country',
        component: HilosI18nCountryDetail,
        meta: { page: 'hilos_i18n_country' },
      },
      {
        path: 'hilos/i18n/countries',
        name: 'hilos_i18n_countries',
        component: HilosI18nCountriesList,
        meta: { page: 'hilos_i18n_countries' },
      },
      {
        path: 'hilos/i18n/entities',
        name: 'hilos_i18n_entities',
        component: HilosI18nEntitiesList,
        meta: { page: 'hilos_i18n_entities' },
      },
      {
        path: 'hilos/i18n/ui-pages/:uiPageId',
        name: 'hilos_i18n_ui_page',
        component: HilosI18nUiPageDetail,
        meta: { page: 'hilos_i18n_ui_page' },
      },
      {
        path: 'hilos/i18n/ui-pages',
        name: 'hilos_i18n_ui_pages',
        component: HilosI18nUiPagesList,
        meta: { page: 'hilos_i18n_ui_pages' },
      },
      {
        path: 'hilos/i18n/groups/:groupId',
        name: 'hilos_i18n_group',
        component: HilosI18nGroupDetail,
        meta: { page: 'hilos_i18n_group' },
      },
      {
        path: 'hilos/i18n/groups',
        name: 'hilos_i18n_groups',
        component: HilosI18nGroupsList,
        meta: { page: 'hilos_i18n_groups' },
      },
      {
        path: 'hilos/i18n/actions/:actionId',
        name: 'hilos_i18n_action',
        component: HilosI18nActionDetail,
        meta: { page: 'hilos_i18n_action' },
      },
      {
        path: 'hilos/i18n/actions',
        name: 'hilos_i18n_actions',
        component: HilosI18nActionsList,
        meta: { page: 'hilos_i18n_actions' },
      },
      {
        path: 'hilos/i18n/emails',
        name: 'hilos_i18n_emails',
        component: HilosI18nEmailsList,
        meta: { page: 'hilos_i18n_emails' },
      },
      {
        path: 'hilos/guardian',
        name: 'hilos_guardian',
        component: HilosGuardian,
        meta: { page: 'hilos_guardian' },
      },
      {
        path: 'hilos/guardian/:agentId',
        name: 'hilos_guardian_agent',
        component: HilosGuardianAgent,
        meta: { page: 'hilos_guardian_agent' },
      },
      {
        path: 'hilos/analytics',
        name: 'hilos_analytics',
        component: HilosAnalytics,
        meta: { page: 'hilos_analytics' },
      },
      {
        path: 'hilos/mcp-skills/:mcpId/logs/view',
        name: 'hilos_mcp_skills_mcp_logs_view',
        component: HilosMcpSkillsMcpLogsView,
        meta: { page: 'hilos_mcp_skills_mcp_logs_view' },
      },
      {
        path: 'hilos/mcp-skills/:mcpId/logs',
        name: 'hilos_mcp_skills_mcp_logs',
        component: HilosMcpSkillsMcpLogs,
        meta: { page: 'hilos_mcp_skills_mcp_logs' },
      },
      {
        path: 'hilos/mcp-skills/:mcpId',
        name: 'hilos_mcp_skills_mcp',
        component: HilosMcpSkillsMcp,
        meta: { page: 'hilos_mcp_skills_mcp' },
      },
      {
        path: 'hilos/mcp-skills',
        name: 'hilos_mcp_skills',
        component: HilosMcpSkillsDashboard,
        meta: { page: 'hilos_mcp_skills' },
      },
      {
        path: 'hilos/sil/requests',
        name: 'hilos_sil_requests',
        component: HilosSilRequests,
        meta: { page: 'hilos_sil_requests' },
      },
      {
        path: 'hilos/sil/users/:userId',
        name: 'hilos_sil_user_history',
        component: HilosSilUserHistory,
        meta: { page: 'hilos_sil_user_history' },
      },
      {
        path: 'hilos/sil',
        name: 'hilos_sil',
        component: HilosSilDashboard,
        meta: { page: 'hilos_sil' },
      },
      {
        path: 'agents',
        name: 'agents',
        component: Agents,
      },
    ],
  },
  // Error pages for prerendering (4xx, 5xx)
  { path: '/400', name: 'error-400', component: ErrorPage, meta: { errorCode: 400 } },
  { path: '/401', name: 'error-401', component: ErrorPage, meta: { errorCode: 401 } },
  { path: '/402', name: 'error-402', component: ErrorPage, meta: { errorCode: 402 } },
  { path: '/403', name: 'error-403', component: ErrorPage, meta: { errorCode: 403 } },
  { path: '/404', name: 'error-404', component: ErrorPage, meta: { errorCode: 404 } },
  { path: '/405', name: 'error-405', component: ErrorPage, meta: { errorCode: 405 } },
  { path: '/408', name: 'error-408', component: ErrorPage, meta: { errorCode: 408 } },
  { path: '/409', name: 'error-409', component: ErrorPage, meta: { errorCode: 409 } },
  { path: '/410', name: 'error-410', component: ErrorPage, meta: { errorCode: 410 } },
  { path: '/429', name: 'error-429', component: ErrorPage, meta: { errorCode: 429 } },
  { path: '/500', name: 'error-500', component: ErrorPage, meta: { errorCode: 500 } },
  { path: '/502', name: 'error-502', component: ErrorPage, meta: { errorCode: 502 } },
  { path: '/503', name: 'error-503', component: ErrorPage, meta: { errorCode: 503 } },
  { path: '/504', name: 'error-504', component: ErrorPage, meta: { errorCode: 504 } },
]
