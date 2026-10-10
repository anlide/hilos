<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand and user slots and routes the content slot through HilosView, which
renders the component mapped to the navigator's current page. The live connection
state, the language/theme switch and the MCP mark are the shell's own. The demo
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
  hilosAdminViews,
  useSignal,
} from '@hilos/vue'
import { HilosPages, hilosSessionAvatarMark } from '@hilos/core'
import type { AuthGate } from '@hilos/core'
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
import HilosBackup from './views/Hilos/Backup/Backup.vue'
import HilosCommunications from './views/Hilos/Communications/Communications.vue'
import HilosCommunicationsChannel from './views/Hilos/Communications/Channel.vue'
import HilosCommunicationsDeliveries from './views/Hilos/Communications/Deliveries.vue'
import HilosMaintenance from './views/Hilos/Maintenance/Maintenance.vue'
import HilosSettings from './views/Hilos/Settings/Settings.vue'
import HilosAppearance from './views/Hilos/Appearance/Appearance.vue'
import HilosUsers from './views/Hilos/Users/Users.vue'
import HilosUser from './views/Hilos/Users/User.vue'
import HilosI18nLanguage from './views/Hilos/I18n/Language/Language.vue'
import HilosI18nLanguages from './views/Hilos/I18n/Languages/Languages.vue'
import HilosI18nLanguageNames from './views/Hilos/I18n/LanguageNames/LanguageNames.vue'
import HilosI18nLanguageLocales from './views/Hilos/I18n/LanguageLocales/LanguageLocales.vue'
import HilosI18nCountries from './views/Hilos/I18n/Countries/Countries.vue'
import HilosI18nCountry from './views/Hilos/I18n/Country/Country.vue'
import HilosI18nCountryNames from './views/Hilos/I18n/CountryNames/CountryNames.vue'
import HilosLogsOverview from './views/Hilos/Logs/Overview.vue'
import HilosLogsKeys from './views/Hilos/Logs/Keys.vue'
import HilosLogsWorkers from './views/Hilos/Logs/Workers.vue'
import HilosLogsRotations from './views/Hilos/Logs/Rotations.vue'
import HilosLogsSettings from './views/Hilos/Logs/Settings.vue'
import HilosLogsView from './views/Hilos/Logs/View.vue'

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
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  ...hilosAdminViews(),
  [HilosPages.BACKUP]: HilosBackup,
  [HilosPages.MAINTENANCE]: HilosMaintenance,
  [HilosPages.SETTINGS]: HilosSettings,
  [HilosPages.APPEARANCE]: HilosAppearance,
  [HilosPages.USERS]: HilosUsers,
  [HilosPages.USER]: HilosUser,
  [HilosPages.I18N_LANGUAGES]: HilosI18nLanguages,
  [HilosPages.I18N_LANGUAGE]: HilosI18nLanguage,
  [HilosPages.I18N_LANGUAGE_NAMES]: HilosI18nLanguageNames,
  [HilosPages.I18N_LANGUAGE_LOCALES]: HilosI18nLanguageLocales,
  [HilosPages.I18N_COUNTRIES]: HilosI18nCountries,
  [HilosPages.I18N_COUNTRY]: HilosI18nCountry,
  [HilosPages.I18N_COUNTRY_NAMES]: HilosI18nCountryNames,
  [HilosPages.LOGS]: HilosLogsOverview,
  [HilosPages.LOGS_KEYS]: HilosLogsKeys,
  [HilosPages.LOGS_WORKERS]: HilosLogsWorkers,
  [HilosPages.LOGS_ROTATIONS]: HilosLogsRotations,
  [HilosPages.LOGS_SETTINGS]: HilosLogsSettings,
  [HilosPages.LOGS_VIEW]: HilosLogsView,
  [HilosPages.COMMUNICATIONS]: HilosCommunications,
  [HilosPages.COMMUNICATIONS_CHANNEL]: HilosCommunicationsChannel,
  [HilosPages.COMMUNICATIONS_DELIVERIES]: HilosCommunicationsDeliveries,
  [HilosPages.PROFILE_NOTIFICATIONS]: ProfileNotifications,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
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
