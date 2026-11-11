<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand and user slots and routes the content slot through HilosView, which
renders the component mapped to the navigator's current page. The live connection
state, the theme menu and the MCP mark are the shell's own. The demo has no
navigation of its own yet, so the signed-in region is the notification bell and
the avatar — a link to the profile root, with the person's photo — and a visitor
gets one button that opens the sign-in surface over the page they are standing
on (mockups/framework/layout). -->
<script setup lang="ts">
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosNotificationBell,
  HilosView,
  useSignal,
} from '@hilos/vue/base'
import { hilosAdminViews } from '@hilos/vue/admin-views'
import {
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
} from '@hilos/core/base'
import type { AuthGate, PageView } from '@hilos/core/base'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { connection } from './bootstrap/connection.js'
import { currentUserName, currentUserPhoto } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys.js'
import About from './views/About/About.vue'
import License from './views/License/License.vue'
import LicenseDependencies from './views/LicenseDependencies/LicenseDependencies.vue'
import Main from './views/Main/Main.vue'
import MainSkeleton from './views/Main/MainSkeleton.vue'
import Privacy from './views/Privacy/Privacy.vue'
import PrivacyHistory from './views/PrivacyHistory/PrivacyHistory.vue'
import Profile from './views/Profile/Profile.vue'
import ProfileNotifications from './views/ProfileNotifications/ProfileNotifications.vue'
import Terms from './views/Terms/Terms.vue'
import TermsHistory from './views/TermsHistory/TermsHistory.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place, and the
// shell's Sign in button opens it over the page.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from: the home, the framework admin
// defaults (hilosAdminViews, of which only the dashboard is registered on the
// backend today), the Analytics, Backup, Change Log, Maintenance, Settings,
// Appearance, Users, Logs, Daemon, Communications and Moderation sections —
// mapped by the demo itself, because those pages need the project's context and
// hilosAdminViews leaves them out — the two profile pages, the profile root and
// the person's notification settings, and the footer pages whose text is this
// demo's. Exactly what the backend registers, and nothing beside it.
// Each descriptor is created once for this App instance; selecting a page
// loads only its project wrapper and SDK section. The profile and footer pages
// stay eager.
const pages: Record<string, PageView<Component>> = {
  [PAGE_MAIN]: Main,
  ...hilosAdminViews(),
  [HilosPages.BACKUP]: lazyPage(() =>
    import('./views/Hilos/Backup/Backup.vue').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLog.vue').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_FEED]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogFeed.vue').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_NOISY]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogNoisy.vue').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_TABLES]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTables.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.CHANGE_LOG_TABLE]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTable.vue').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_TABLE_COLUMNS]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableColumns.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.CHANGE_LOG_TABLE_TRIGGERS]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableTriggers.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.CHANGE_LOG_TABLE_HISTORY]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableHistory.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.MAINTENANCE]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Maintenance.vue').then((m) => m.default),
  ),
  [HilosPages.MAINTENANCE_CIRCLE]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Circle.vue').then((m) => m.default),
  ),
  [HilosPages.MAINTENANCE_WORDS]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Words.vue').then((m) => m.default),
  ),
  [HilosPages.MAINTENANCE_NODES]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Nodes.vue').then((m) => m.default),
  ),
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.vue').then((m) => m.default),
  ),
  [HilosPages.APPEARANCE]: lazyPage(() =>
    import('./views/Hilos/Appearance/Appearance.vue').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS]: lazyPage(() =>
    import('./views/Hilos/Analytics/Analytics.vue').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_BROWSER_SESSIONS]: lazyPage(() =>
    import('./views/Hilos/Analytics/BrowserSessions.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.ANALYTICS_PEOPLE]: lazyPage(() =>
    import('./views/Hilos/Analytics/People.vue').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_BROWSER_SESSION]: lazyPage(() =>
    import('./views/Hilos/Analytics/BrowserSession.vue').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_PERSON]: lazyPage(() =>
    import('./views/Hilos/Analytics/Person.vue').then((m) => m.default),
  ),
  [HilosPages.USERS]: lazyPage(() =>
    import('./views/Hilos/Users/Users.vue').then((m) => m.default),
  ),
  [HilosPages.USER]: lazyPage(() =>
    import('./views/Hilos/Users/User.vue').then((m) => m.default),
  ),
  [HilosPages.USER_SESSIONS]: lazyPage(() =>
    import('./views/Hilos/Users/Sessions.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS]: lazyPage(() =>
    import('./views/Hilos/Logs/Overview.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS_KEYS]: lazyPage(() =>
    import('./views/Hilos/Logs/Keys.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS_WORKERS]: lazyPage(() =>
    import('./views/Hilos/Logs/Workers.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS_ROTATIONS]: lazyPage(() =>
    import('./views/Hilos/Logs/Rotations.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS_SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Logs/Settings.vue').then((m) => m.default),
  ),
  [HilosPages.LOGS_VIEW]: lazyPage(() =>
    import('./views/Hilos/Logs/View.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON]: lazyPage(() =>
    import('./views/Hilos/Daemon/Daemon.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_PLACEMENT]: lazyPage(() =>
    import('./views/Hilos/Daemon/Placement.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_NODE]: lazyPage(() =>
    import('./views/Hilos/Daemon/Node.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_WORKERS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Workers.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_AGENTS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Agents.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_CRON]: lazyPage(() =>
    import('./views/Hilos/Daemon/Cron.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_WEBSOCKETS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Websockets.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_HTTP_SERVER]: lazyPage(() =>
    import('./views/Hilos/Daemon/Http.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV]: lazyPage(() =>
    import('./views/Hilos/Daemon/Env.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV_ORPHANS]: lazyPage(() =>
    import('./views/Hilos/Daemon/EnvOrphans.vue').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV_MISMATCH]: lazyPage(() =>
    import('./views/Hilos/Daemon/EnvMismatch.vue').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS]: lazyPage(() =>
    import('./views/Hilos/Communications/Communications.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL]: lazyPage(() =>
    import('./views/Hilos/Communications/Channel.vue').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION]: lazyPage(() =>
    import('./views/Hilos/Communications/Configuration.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL_FRAME]: lazyPage(() =>
    import('./views/Hilos/Communications/Frame.vue').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS_DELIVERIES]: lazyPage(() =>
    import('./views/Hilos/Communications/Deliveries.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.MODERATION]: lazyPage(() =>
    import('./views/Hilos/Moderation/Moderation.vue').then((m) => m.default),
  ),
  [HilosPages.MODERATION_KIND]: lazyPage(() =>
    import('./views/Hilos/Moderation/ModerationKind.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.MODERATION_KIND_RULES]: lazyPage(() =>
    import('./views/Hilos/Moderation/Rules/Rules.vue').then((m) => m.default),
  ),
  [HilosPages.PROFILE]: Profile,
  [HilosPages.PROFILE_NOTIFICATIONS]: ProfileNotifications,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.TERMS_HISTORY]: TermsHistory,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
  [HilosPages.LICENSE]: License,
  [HilosPages.LICENSE_DEPENDENCIES]: LicenseDependencies,
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, Component> = {
  [PAGE_MAIN]: MainSkeleton,
}

const userName = useSignal(currentUserName)
const userPhoto = useSignal(currentUserPhoto)
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]
// The standing mark by the avatar (HIL-945): a takeover, or the session's own
// scheduled deletion, in the color of the strip that says it in words.
const avatarMark = useSignal(hilosSessionAvatarMark)
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>
      <i class="bi bi-controller" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there (mockups/framework/layout, "На узком экране"). -->
      <span class="d-none d-md-inline ms-1">Roblox Game Server</span>
      <span class="visually-hidden d-md-none">Roblox Game Server</span>
    </template>
    <template #user>
      <HilosNotificationBell v-if="userName" :connection="connection" />
      <HilosLink
        v-if="userName"
        :to="profileHref"
        class="nav-link d-inline-flex align-items-center p-0"
        data-id="nav-profile"
        :title="userName"
      >
        <HilosAvatar :name="userName" :photo="userPhoto" :mark="avatarMark" />
        <span class="visually-hidden">{{ userName }}</span>
      </HilosLink>
      <button
        v-else
        type="button"
        class="btn btn-sm btn-primary"
        data-id="nav-signin"
        @click="props.authGate.requireAuth()"
      >
        Sign in
      </button>
    </template>
    <HilosView
      :pages="pages"
      :page-skeletons="pageSkeletons"
      :auth-surface="AuthSurface"
      :auth-gate="props.authGate"
    />
  </HilosLayout>
</template>
