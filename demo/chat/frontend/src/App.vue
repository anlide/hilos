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
  hilosAdminViews,
  hilosRouterKey,
  useSignal,
} from '@hilos/vue'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
} from '@hilos/core'
import type { AuthGate } from '@hilos/core'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserName, currentUserPhoto } from './bootstrap/session'
import { PAGE_ADMIN_BOTS, PAGE_BOT, PAGE_MAIN, PAGE_USER } from './pages/keys'
import About from './views/About/About.vue'
import AdminBots from './views/AdminBots/AdminBots.vue'
import Bot from './views/Bot/Bot.vue'
import License from './views/License/License.vue'
import Main from './views/Main/Main.vue'
import MainSkeleton from './views/Main/MainSkeleton.vue'
import Privacy from './views/Privacy/Privacy.vue'
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
import User from './views/User/User.vue'
// The Hilos admin section. The framework ships a real default page for every
// admin key (hilosAdminViews), so the demo maps only the pages it implements
// itself — the rest render the framework default, never recopied per project
// (page-module-structure.md). Each page is still its own module file.
import HilosSettings from './views/Hilos/Settings/Settings.vue'
import HilosAppearance from './views/Hilos/Appearance/Appearance.vue'
import HilosUsers from './views/Hilos/Users/Users.vue'
import HilosUser from './views/Hilos/Users/User.vue'
import HilosBackup from './views/Hilos/Backup/Backup.vue'
import HilosMaintenance from './views/Hilos/Maintenance/Maintenance.vue'
import HilosCommunications from './views/Hilos/Communications/Communications.vue'
import HilosCommunicationsChannel from './views/Hilos/Communications/Channel.vue'
import HilosCommunicationsDeliveries from './views/Hilos/Communications/Deliveries.vue'
import HilosSecurityOauth from './views/Hilos/Security/SecurityOauth.vue'
import HilosSecurityOauthProvider from './views/Hilos/Security/SecurityOauthProvider.vue'
import HilosSecuritySignInMethods from './views/Hilos/Security/SecuritySignInMethods.vue'
import HilosSecurityTwoFactor from './views/Hilos/Security/SecurityTwoFactor.vue'
import HilosSecurityStepUp from './views/Hilos/Security/SecurityStepUp.vue'
import HilosSecurityImpersonation from './views/Hilos/Security/SecurityImpersonation.vue'
import HilosLegal from './views/Hilos/Legal/Legal.vue'
import HilosLegalDocument from './views/Hilos/Legal/LegalDocument.vue'
import HilosLegalRevision from './views/Hilos/Legal/LegalRevision.vue'
import HilosLegalAcceptances from './views/Hilos/Legal/LegalAcceptances.vue'
import HilosLegalSettings from './views/Hilos/Legal/LegalSettings.vue'
import HilosI18nLanguage from './views/Hilos/I18n/Language/Language.vue'
import HilosLogsOverview from './views/Hilos/Logs/Overview.vue'
import HilosLogsKeys from './views/Hilos/Logs/Keys.vue'
import HilosLogsWorkers from './views/Hilos/Logs/Workers.vue'
import HilosLogsRotations from './views/Hilos/Logs/Rotations.vue'
import HilosLogsSettings from './views/Hilos/Logs/Settings.vue'
import HilosLogsView from './views/Hilos/Logs/View.vue'

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
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  [PAGE_USER]: User,
  [PAGE_BOT]: Bot,
  [PAGE_ADMIN_BOTS]: AdminBots,
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
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
  [HilosPages.SETTINGS]: HilosSettings,
  [HilosPages.APPEARANCE]: HilosAppearance,
  [HilosPages.USERS]: HilosUsers,
  [HilosPages.USER]: HilosUser,
  [HilosPages.BACKUP]: HilosBackup,
  [HilosPages.MAINTENANCE]: HilosMaintenance,
  [HilosPages.COMMUNICATIONS]: HilosCommunications,
  [HilosPages.COMMUNICATIONS_CHANNEL]: HilosCommunicationsChannel,
  [HilosPages.COMMUNICATIONS_DELIVERIES]: HilosCommunicationsDeliveries,
  [HilosPages.SECURITY_OAUTH]: HilosSecurityOauth,
  [HilosPages.SECURITY_OAUTH_PROVIDER]: HilosSecurityOauthProvider,
  [HilosPages.SECURITY_SIGN_IN_METHODS]: HilosSecuritySignInMethods,
  [HilosPages.SECURITY_2FA]: HilosSecurityTwoFactor,
  [HilosPages.SECURITY_STEP_UP]: HilosSecurityStepUp,
  [HilosPages.SECURITY_IMPERSONATION]: HilosSecurityImpersonation,
  [HilosPages.LEGAL]: HilosLegal,
  [HilosPages.LEGAL_DOCUMENT]: HilosLegalDocument,
  [HilosPages.LEGAL_REVISION]: HilosLegalRevision,
  [HilosPages.LEGAL_ACCEPTANCES]: HilosLegalAcceptances,
  [HilosPages.LEGAL_SETTINGS]: HilosLegalSettings,
  [HilosPages.I18N_LANGUAGE]: HilosI18nLanguage,
  [HilosPages.LOGS]: HilosLogsOverview,
  [HilosPages.LOGS_KEYS]: HilosLogsKeys,
  [HilosPages.LOGS_WORKERS]: HilosLogsWorkers,
  [HilosPages.LOGS_ROTATIONS]: HilosLogsRotations,
  [HilosPages.LOGS_SETTINGS]: HilosLogsSettings,
  [HilosPages.LOGS_VIEW]: HilosLogsView,
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, Component> = {
  [PAGE_MAIN]: MainSkeleton,
}

// The magic-link confirm route (HIL-283) and the OAuth callback route (HIL-281).
// Neither carries a page of its own — the router falls both back to the main
// subscription so their actions route — so App swaps the framework relay view in
// for the routed outlet while the path matches, and the relay navigates home once
// the session upgrades. The paths come from @hilos/core (HIL-409): a mail client
// and a provider enter them, so both halves have to agree on the strings.
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'App requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const currentPath = useSignal(router.currentPath)
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
    <template #brand>Hilos Chat</template>
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
