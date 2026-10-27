<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand and user slots and routes the content slot through HilosView, which
renders the component mapped to the navigator's current page. The live connection
state, the theme menu and the MCP mark are the shell's own. The demo
has no navigation of its own yet and no profile root — its one profile page is
the notification settings — so the signed-in region is the notification bell and
the avatar, which is not a link, and a visitor gets one button that opens the
sign-in surface over the page they are standing on (mockups/framework/layout). -->
<script setup lang="ts">
import {
  HilosAvatar,
  HilosLayout,
  HilosNotificationBell,
  HilosView,
  useSignal,
} from '@hilos/vue/base'
import { hilosAdminViews } from '@hilos/vue/admin-views'
import { HilosPages, hilosSessionAvatarMark, lazyPage } from '@hilos/core/base'
import type { AuthGate, PageView } from '@hilos/core/base'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { connection } from './bootstrap/connection'
import { currentUserName } from './bootstrap/session'
import { PAGE_MAIN } from './pages/keys'
import About from './views/About/About.vue'
import License from './views/License/License.vue'
import Main from './views/Main/Main.vue'
import MainSkeleton from './views/Main/MainSkeleton.vue'
import Privacy from './views/Privacy/Privacy.vue'
import ProfileNotifications from './views/ProfileNotifications/ProfileNotifications.vue'
import Terms from './views/Terms/Terms.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place, and the
// shell's Sign in button opens it over the page.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from: the home, the framework admin
// defaults (hilosAdminViews, of which only the dashboard is registered on the
// backend today), the Backup, Maintenance, Settings, Users, Logs and
// Communications sections — mapped by the demo itself, because those pages need
// the project's context and hilosAdminViews leaves them out — the one profile
// page, the person's notification settings, and the four footer pages whose text
// is this demo's.
// Each descriptor is created once for this App instance; selecting a page
// loads only its project wrapper and SDK section. The four prose pages stay
// eager; the public Status file reader loads on entry.
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
  [HilosPages.MAINTENANCE]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Maintenance.vue').then((m) => m.default),
  ),
  [HilosPages.MAINTENANCE_CIRCLE]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Circle.vue').then((m) => m.default),
  ),
  [HilosPages.STATUS]: lazyPage(() =>
    import('./views/Hilos/Status/Status.vue').then((m) => m.default),
  ),
  [HilosPages.STATUS_COMPONENTS]: lazyPage(() =>
    import('./views/Hilos/Status/Components/Components.vue').then(
      (m) => m.default,
    ),
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
  [HilosPages.I18N]: lazyPage(() =>
    import('./views/Hilos/I18n/I18n.vue').then((m) => m.default),
  ),
  [HilosPages.I18N_LANGUAGES]: lazyPage(() =>
    import('./views/Hilos/I18n/Languages/Languages.vue').then((m) => m.default),
  ),
  [HilosPages.I18N_LANGUAGE]: lazyPage(() =>
    import('./views/Hilos/I18n/Language/Language.vue').then((m) => m.default),
  ),
  [HilosPages.I18N_LANGUAGE_NAMES]: lazyPage(() =>
    import('./views/Hilos/I18n/LanguageNames/LanguageNames.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.I18N_LANGUAGE_LOCALES]: lazyPage(() =>
    import('./views/Hilos/I18n/LanguageLocales/LanguageLocales.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.I18N_COUNTRIES]: lazyPage(() =>
    import('./views/Hilos/I18n/Countries/Countries.vue').then((m) => m.default),
  ),
  [HilosPages.I18N_COUNTRY]: lazyPage(() =>
    import('./views/Hilos/I18n/Country/Country.vue').then((m) => m.default),
  ),
  [HilosPages.I18N_COUNTRY_NAMES]: lazyPage(() =>
    import('./views/Hilos/I18n/CountryNames/CountryNames.vue').then(
      (m) => m.default,
    ),
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
  [HilosPages.PROFILE_NOTIFICATIONS]: ProfileNotifications,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
  [HilosPages.PUBLIC_STATUS]: lazyPage(() =>
    import('./views/Status/Status.vue').then((m) => m.default),
  ),
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, Component> = {
  [PAGE_MAIN]: MainSkeleton,
}

const userName = useSignal(currentUserName)
// The standing mark by the avatar (HIL-945): a takeover, or the session's own
// scheduled deletion, in the color of the strip that says it in words.
const avatarMark = useSignal(hilosSessionAvatarMark)
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>
      <i class="bi bi-currency-bitcoin" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there (mockups/framework/layout, "На узком экране"). -->
      <span class="d-none d-md-inline ms-1">BTC Tracker</span>
      <span class="visually-hidden d-md-none">BTC Tracker</span>
    </template>
    <template #user>
      <HilosNotificationBell v-if="userName" :connection="connection" />
      <span
        v-if="userName"
        class="d-inline-flex align-items-center"
        data-id="nav-profile-name"
        :title="userName"
      >
        <HilosAvatar :name="userName" :mark="avatarMark" />
        <span class="visually-hidden">{{ userName }}</span>
      </span>
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
