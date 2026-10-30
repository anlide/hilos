<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand slot and routes the content slot through HilosView, which renders the
component mapped to the navigator's current page. The brand and the shell's gear
move between the main page and the framework dashboard with no refresh. The live
connection state is the shell's own indicator (an extra status surface allowed
by docs/agents/frontend/core-and-connection.md). -->
<script setup lang="ts">
import { computed, inject } from 'vue'
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosSecondFactorCancelPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosView,
  hilosRouterKey,
  useSignal,
} from '@hilos/vue/base'
import { hilosAdminViews } from '@hilos/vue/admin-views'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
} from '@hilos/core/base'
import type { AuthGate, PageView } from '@hilos/core/base'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserName, currentUserPhoto } from './bootstrap/session'
import { PAGE_ADMIN_BOTS, PAGE_BOT, PAGE_MAIN, PAGE_USER } from './pages/keys'
import { router } from './pages/routes.js'
import About from './views/About/About.vue'
import Bot from './views/Bot/Bot.vue'
import License from './views/License/License.vue'
import Main from './views/Main/Main.vue'
import MainSkeleton from './views/Main/MainSkeleton.vue'
import Privacy from './views/Privacy/Privacy.vue'
import PrivacyHistory from './views/PrivacyHistory/PrivacyHistory.vue'
import Profile from './views/Profile/Profile.vue'
import ProfileSignIn from './views/ProfileSignIn/ProfileSignIn.vue'
import ProfileNotifications from './views/ProfileNotifications/ProfileNotifications.vue'
import ProfileAgreements from './views/ProfileAgreements/ProfileAgreements.vue'
import ProfileAgreementsHistory from './views/ProfileAgreementsHistory/ProfileAgreementsHistory.vue'
import ProfileData from './views/ProfileData/ProfileData.vue'
import ProfileDevices from './views/ProfileDevices/ProfileDevices.vue'
import ProfileSessions from './views/ProfileSessions/ProfileSessions.vue'
import ProfileSecurity from './views/Profile/ProfileSecurity.vue'
import Terms from './views/Terms/Terms.vue'
import TermsHistory from './views/TermsHistory/TermsHistory.vue'
import User from './views/User/User.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from. The app's own pages, then the
// framework admin defaults (hilosAdminViews), then the demo's real admin pages
// overriding the default for their key (and users/user, which the framework's
// default map omits — they need a project context, so the demo mounts them
// directly). Unbuilt framework pages, including guardian, are refused with
// 404 by the router through HILOS_UNBUILT_PAGES.
// Each descriptor is created once for this App instance; selecting a page
// loads only its project wrapper and SDK section. The four prose pages stay
// eager; the public Status file reader loads on entry.
const pages: Record<string, PageView<Component>> = {
  [PAGE_MAIN]: Main,
  [PAGE_USER]: User,
  [PAGE_BOT]: Bot,
  [PAGE_ADMIN_BOTS]: lazyPage(() =>
    import('./views/AdminBots/AdminBots.vue').then((m) => m.default),
  ),
  ...hilosAdminViews(),
  [HilosPages.PROFILE]: Profile,
  [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
  [HilosPages.PROFILE_NOTIFICATIONS]: ProfileNotifications,
  [HilosPages.PROFILE_AGREEMENTS]: ProfileAgreements,
  [HilosPages.PROFILE_AGREEMENTS_HISTORY]: ProfileAgreementsHistory,
  [HilosPages.PROFILE_DATA]: ProfileData,
  [HilosPages.PROFILE_SESSIONS]: ProfileSessions,
  [HilosPages.PROFILE_DEVICES]: ProfileDevices,
  [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.TERMS_HISTORY]: TermsHistory,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
  [HilosPages.LICENSE]: License,
  [HilosPages.PUBLIC_STATUS]: lazyPage(() =>
    import('./views/Status/Status.vue').then((m) => m.default),
  ),
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.vue').then((m) => m.default),
  ),
  [HilosPages.APPEARANCE]: lazyPage(() =>
    import('./views/Hilos/Appearance/Appearance.vue').then((m) => m.default),
  ),
  [HilosPages.USERS]: lazyPage(() =>
    import('./views/Hilos/Users/Users.vue').then((m) => m.default),
  ),
  [HilosPages.USER]: lazyPage(() =>
    import('./views/Hilos/Users/User.vue').then((m) => m.default),
  ),
  [HilosPages.BACKUP]: lazyPage(() =>
    import('./views/Hilos/Backup/Backup.vue').then((m) => m.default),
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
  [HilosPages.STATUS]: lazyPage(() =>
    import('./views/Hilos/Status/Status.vue').then((m) => m.default),
  ),
  [HilosPages.STATUS_COMPONENTS]: lazyPage(() =>
    import('./views/Hilos/Status/Components/Components.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.STATUS_SUBSCRIBERS]: lazyPage(() =>
    import('./views/Hilos/Status/Subscribers/Subscribers.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.STATUS_WORKS]: lazyPage(() =>
    import('./views/Hilos/Status/Works/Works.vue').then((m) => m.default),
  ),
  [HilosPages.STATUS_HISTORY]: lazyPage(() =>
    import('./views/Hilos/Status/History/History.vue').then((m) => m.default),
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
  [HilosPages.SECURITY]: lazyPage(() =>
    import('./views/Hilos/Security/Security.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_OAUTH]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauth.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_OAUTH_PROVIDER]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauthProvider.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_SIGN_IN_METHODS]: lazyPage(() =>
    import('./views/Hilos/Security/SecuritySignInMethods.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_2FA]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityTwoFactor.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_STEP_UP]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityStepUp.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_IMPERSONATION]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityImpersonation.vue').then(
      (m) => m.default,
    ),
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
  [HilosPages.LEGAL]: lazyPage(() =>
    import('./views/Hilos/Legal/Legal.vue').then((m) => m.default),
  ),
  [HilosPages.LEGAL_DOCUMENTS]: lazyPage(() =>
    import('./views/Hilos/Legal/Documents/Documents.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.LEGAL_DOCUMENT]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalDocument.vue').then((m) => m.default),
  ),
  [HilosPages.LEGAL_REVISION]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalRevision.vue').then((m) => m.default),
  ),
  [HilosPages.LEGAL_ACCEPTANCES]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalAcceptances.vue').then((m) => m.default),
  ),
  [HilosPages.LEGAL_SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalSettings.vue').then((m) => m.default),
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
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, Component> = {
  [PAGE_MAIN]: MainSkeleton,
}

// The magic-link confirm route (HIL-283) and the OAuth callback route (HIL-281).
// Neither carries a page of its own — the address stays a route miss and nothing
// is subscribed — so App swaps the framework relay view in for the routed outlet
// while the path matches. The session answer opens the gate the relay dispatches
// through, and the relay navigates home once the session upgrades. The paths come
// from @hilos/core (HIL-409): a mail client and a provider enter them, so both
// halves have to agree on the strings.
const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'App requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const currentPath = useSignal(injectedRouter.currentPath)
const isMagicRoute = computed(() => currentPath.value === AUTH_MAGIC_LINK_PATH)
const isOAuthCallbackRoute = computed(
  () => currentPath.value === AUTH_OAUTH_CALLBACK_PATH,
)
const isSecondFactorCancelRoute = computed(
  () => currentPath.value === AUTH_SECOND_FACTOR_CANCEL_PATH,
)

// The navbar profile entry: the current user's name links to the framework
// profile page (its route owned by the page catalog), shown once the handshake
// names the user.
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
      <i class="bi bi-chat-dots" aria-hidden="true"></i>
      <span class="d-none d-md-inline ms-1">Hilos Chat</span>
      <span class="visually-hidden d-md-none">Hilos Chat</span>
    </template>
    <template #nav>
      <HilosLink
        :to="router.path(PAGE_MAIN) ?? ''"
        class="nav-link"
        data-id="nav-conversations"
      >
        Conversations
      </HilosLink>
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
    </template>
    <HilosMagicLinkPage v-if="isMagicRoute" :context="hilosAuthContext" />
    <HilosOAuthCallbackPage
      v-else-if="isOAuthCallbackRoute"
      :context="hilosAuthContext"
    />
    <HilosSecondFactorCancelPage
      v-else-if="isSecondFactorCancelRoute"
      :context="hilosAuthContext"
    />
    <HilosView
      v-else
      :pages="pages"
      :page-skeletons="pageSkeletons"
      :auth-surface="AuthSurface"
      :auth-gate="props.authGate"
    />
  </HilosLayout>
</template>
