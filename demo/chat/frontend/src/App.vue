<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand slot and routes the content slot through HilosView, which renders the
component mapped to the navigator's current page. The brand and the shell's gear
move between the main page and the framework dashboard with no refresh. The live
connection state is the shell's own indicator (an extra status surface allowed
by docs/agents/frontend/core-and-connection.md). -->
<script setup lang="ts">
import { HilosLayout, HilosLink, HilosView, useSignal } from '@hilos/vue'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import type { Component } from 'vue'

import { connection } from './bootstrap/connection'
import { currentUserName } from './bootstrap/session'
import { PAGE_BOT, PAGE_MAIN, PAGE_USER } from './pages/keys'
import About from './views/About/About.vue'
import Bot from './views/Bot/Bot.vue'
import Dashboard from './views/Dashboard/Dashboard.vue'
import License from './views/License/License.vue'
import Main from './views/Main/Main.vue'
import Privacy from './views/Privacy/Privacy.vue'
import Profile from './views/Profile/Profile.vue'
import Terms from './views/Terms/Terms.vue'
import User from './views/User/User.vue'
// The Hilos admin section — one module per page (mirroring the backend's
// one-file-per-page classes under Pages/Hilos/), grouped by section. Each view
// is a thin binding of its key to the framework HilosAdminPage shell.
import HilosSettings from './views/Hilos/Settings/Settings.vue'
import Roles from './views/Hilos/Roles/Roles.vue'
import HilosUsers from './views/Hilos/Users/Users.vue'
import HilosUser from './views/Hilos/User/User.vue'
import I18n from './views/Hilos/I18n/I18n.vue'
import I18nLanguages from './views/Hilos/I18nLanguages/I18nLanguages.vue'
import I18nLanguage from './views/Hilos/I18nLanguage/I18nLanguage.vue'
import I18nCountries from './views/Hilos/I18nCountries/I18nCountries.vue'
import I18nCountry from './views/Hilos/I18nCountry/I18nCountry.vue'
import I18nEntities from './views/Hilos/I18nEntities/I18nEntities.vue'
import I18nTranslateEntity from './views/Hilos/I18nTranslateEntity/I18nTranslateEntity.vue'
import I18nUiPages from './views/Hilos/I18nUiPages/I18nUiPages.vue'
import I18nUiPage from './views/Hilos/I18nUiPage/I18nUiPage.vue'
import I18nTranslateUiPage from './views/Hilos/I18nTranslateUiPage/I18nTranslateUiPage.vue'
import I18nTranslateUiPageItem from './views/Hilos/I18nTranslateUiPageItem/I18nTranslateUiPageItem.vue'
import I18nGroups from './views/Hilos/I18nGroups/I18nGroups.vue'
import I18nGroup from './views/Hilos/I18nGroup/I18nGroup.vue'
import I18nTranslateGroup from './views/Hilos/I18nTranslateGroup/I18nTranslateGroup.vue'
import I18nTranslateGroupItem from './views/Hilos/I18nTranslateGroupItem/I18nTranslateGroupItem.vue'
import I18nActions from './views/Hilos/I18nActions/I18nActions.vue'
import I18nAction from './views/Hilos/I18nAction/I18nAction.vue'
import I18nTranslateActionError from './views/Hilos/I18nTranslateActionError/I18nTranslateActionError.vue'
import I18nEmails from './views/Hilos/I18nEmails/I18nEmails.vue'
import I18nTranslateEmail from './views/Hilos/I18nTranslateEmail/I18nTranslateEmail.vue'
import Communications from './views/Hilos/Communications/Communications.vue'
import CommunicationsChannel from './views/Hilos/CommunicationsChannel/CommunicationsChannel.vue'
import CommunicationsDeliveries from './views/Hilos/CommunicationsDeliveries/CommunicationsDeliveries.vue'
import Security from './views/Hilos/Security/Security.vue'
import Security2fa from './views/Hilos/Security2fa/Security2fa.vue'
import SecurityOauth from './views/Hilos/SecurityOauth/SecurityOauth.vue'
import SecurityOauthProvider from './views/Hilos/SecurityOauthProvider/SecurityOauthProvider.vue'
import Billing from './views/Hilos/Billing/Billing.vue'
import BillingProvider from './views/Hilos/BillingProvider/BillingProvider.vue'
import BillingPayments from './views/Hilos/BillingPayments/BillingPayments.vue'
import BillingRefunds from './views/Hilos/BillingRefunds/BillingRefunds.vue'
import Operations from './views/Hilos/Operations/Operations.vue'
import Daemon from './views/Hilos/Daemon/Daemon.vue'
import DaemonWorkers from './views/Hilos/DaemonWorkers/DaemonWorkers.vue'
import DaemonAgents from './views/Hilos/DaemonAgents/DaemonAgents.vue'
import DaemonCron from './views/Hilos/DaemonCron/DaemonCron.vue'
import DaemonWebsockets from './views/Hilos/DaemonWebsockets/DaemonWebsockets.vue'
import DaemonHttpServer from './views/Hilos/DaemonHttpServer/DaemonHttpServer.vue'
import Logs from './views/Hilos/Logs/Logs.vue'
import LogsKeys from './views/Hilos/LogsKeys/LogsKeys.vue'
import LogsWorkers from './views/Hilos/LogsWorkers/LogsWorkers.vue'
import LogsRotations from './views/Hilos/LogsRotations/LogsRotations.vue'
import LogsView from './views/Hilos/LogsView/LogsView.vue'
import ChangeLog from './views/Hilos/ChangeLog/ChangeLog.vue'
import ChangeLogTables from './views/Hilos/ChangeLogTables/ChangeLogTables.vue'
import ChangeLogTable from './views/Hilos/ChangeLogTable/ChangeLogTable.vue'
import Backup from './views/Hilos/Backup/Backup.vue'
import Analytics from './views/Hilos/Analytics/Analytics.vue'
import McpSkills from './views/Hilos/McpSkills/McpSkills.vue'
import McpSkillsMcp from './views/Hilos/McpSkillsMcp/McpSkillsMcp.vue'
import McpSkillsMcpLogs from './views/Hilos/McpSkillsMcpLogs/McpSkillsMcpLogs.vue'
import McpSkillsMcpLogsView from './views/Hilos/McpSkillsMcpLogsView/McpSkillsMcpLogsView.vue'
import Sil from './views/Hilos/Sil/Sil.vue'
import SilRequests from './views/Hilos/SilRequests/SilRequests.vue'
import SilUserHistory from './views/Hilos/SilUserHistory/SilUserHistory.vue'

// The page-key → view map HilosView renders from. The main page, the dashboard,
// and the static footer pages have their own components; the Hilos admin section
// is one module per page, each rendered through the framework HilosAdminPage
// shell. Pages without a mapped view (guardian, which gets its own real page)
// render nothing.
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  [PAGE_USER]: User,
  [PAGE_BOT]: Bot,
  [HilosPages.DASHBOARD]: Dashboard,
  [HilosPages.SETTINGS]: HilosSettings,
  [HilosPages.PROFILE]: Profile,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
  [HilosPages.ROLES]: Roles,
  [HilosPages.USERS]: HilosUsers,
  [HilosPages.USER]: HilosUser,
  [HilosPages.I18N]: I18n,
  [HilosPages.I18N_LANGUAGES]: I18nLanguages,
  [HilosPages.I18N_LANGUAGE]: I18nLanguage,
  [HilosPages.I18N_COUNTRIES]: I18nCountries,
  [HilosPages.I18N_COUNTRY]: I18nCountry,
  [HilosPages.I18N_ENTITIES]: I18nEntities,
  [HilosPages.I18N_TRANSLATE_ENTITY]: I18nTranslateEntity,
  [HilosPages.I18N_UI_PAGES]: I18nUiPages,
  [HilosPages.I18N_UI_PAGE]: I18nUiPage,
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: I18nTranslateUiPage,
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: I18nTranslateUiPageItem,
  [HilosPages.I18N_GROUPS]: I18nGroups,
  [HilosPages.I18N_GROUP]: I18nGroup,
  [HilosPages.I18N_TRANSLATE_GROUP]: I18nTranslateGroup,
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: I18nTranslateGroupItem,
  [HilosPages.I18N_ACTIONS]: I18nActions,
  [HilosPages.I18N_ACTION]: I18nAction,
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: I18nTranslateActionError,
  [HilosPages.I18N_EMAILS]: I18nEmails,
  [HilosPages.I18N_TRANSLATE_EMAIL]: I18nTranslateEmail,
  [HilosPages.COMMUNICATIONS]: Communications,
  [HilosPages.COMMUNICATIONS_CHANNEL]: CommunicationsChannel,
  [HilosPages.COMMUNICATIONS_DELIVERIES]: CommunicationsDeliveries,
  [HilosPages.SECURITY]: Security,
  [HilosPages.SECURITY_2FA]: Security2fa,
  [HilosPages.SECURITY_OAUTH]: SecurityOauth,
  [HilosPages.SECURITY_OAUTH_PROVIDER]: SecurityOauthProvider,
  [HilosPages.BILLING]: Billing,
  [HilosPages.BILLING_PROVIDER]: BillingProvider,
  [HilosPages.BILLING_PAYMENTS]: BillingPayments,
  [HilosPages.BILLING_REFUNDS]: BillingRefunds,
  [HilosPages.OPERATIONS]: Operations,
  [HilosPages.DAEMON]: Daemon,
  [HilosPages.DAEMON_WORKERS]: DaemonWorkers,
  [HilosPages.DAEMON_AGENTS]: DaemonAgents,
  [HilosPages.DAEMON_CRON]: DaemonCron,
  [HilosPages.DAEMON_WEBSOCKETS]: DaemonWebsockets,
  [HilosPages.DAEMON_HTTP_SERVER]: DaemonHttpServer,
  [HilosPages.LOGS]: Logs,
  [HilosPages.LOGS_KEYS]: LogsKeys,
  [HilosPages.LOGS_WORKERS]: LogsWorkers,
  [HilosPages.LOGS_ROTATIONS]: LogsRotations,
  [HilosPages.LOGS_VIEW]: LogsView,
  [HilosPages.CHANGE_LOG]: ChangeLog,
  [HilosPages.CHANGE_LOG_TABLES]: ChangeLogTables,
  [HilosPages.CHANGE_LOG_TABLE]: ChangeLogTable,
  [HilosPages.BACKUP]: Backup,
  [HilosPages.ANALYTICS]: Analytics,
  [HilosPages.MCP_SKILLS]: McpSkills,
  [HilosPages.MCP_SKILLS_MCP]: McpSkillsMcp,
  [HilosPages.MCP_SKILLS_MCP_LOGS]: McpSkillsMcpLogs,
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: McpSkillsMcpLogsView,
  [HilosPages.SIL]: Sil,
  [HilosPages.SIL_REQUESTS]: SilRequests,
  [HilosPages.SIL_USER_HISTORY]: SilUserHistory,
}

// The navbar profile entry: the current user's name links to the framework
// profile page (its route owned by the page catalog), shown once the handshake
// names the user.
const userName = useSignal(currentUserName)
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>Hilos Chat</template>
    <template #user>
      <HilosLink
        v-if="userName"
        :to="profileHref"
        class="nav-link d-inline-flex align-items-center p-0"
        data-id="nav-profile"
      >
        <i class="bi bi-person-circle me-1" aria-hidden="true"></i
        >{{ userName }}
      </HilosLink>
    </template>
    <HilosView :pages="pages" />
  </HilosLayout>
</template>
