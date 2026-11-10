<script setup lang="ts">
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosOAuthCallbackPage,
  HilosSecondFactorCancelPage,
  hilosRouterKey,
  HilosNotificationBell,
  HilosView,
  useSignal,
} from '@hilos/vue/base'
import { hilosAdminViews } from '@hilos/vue/admin-views'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
} from '@hilos/core/base'
import type { AuthGate, PageView } from '@hilos/core/base'
import { computed, inject, type Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { hilosAuthContext } from './auth/hilosAuthContext.js'
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
import ProfileSecurity from './views/Profile/ProfileSecurity.vue'
import ProfileAgreements from './views/ProfileAgreements/ProfileAgreements.vue'
import ProfileData from './views/ProfileData/ProfileData.vue'
import ProfileDevices from './views/ProfileDevices/ProfileDevices.vue'
import ProfileNotifications from './views/ProfileNotifications/ProfileNotifications.vue'
import ProfileSessions from './views/ProfileSessions/ProfileSessions.vue'
import ProfileSignIn from './views/ProfileSignIn/ProfileSignIn.vue'
import Terms from './views/Terms/Terms.vue'
import TermsHistory from './views/TermsHistory/TermsHistory.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place, and the
// shell's Sign in button opens it over the page.
const props = defineProps<{ authGate: AuthGate }>()

// Framework defaults and project context bindings. Admin wrappers load only
// when selected; the public pages stay eager for the public prerender build.
const pages: Record<string, PageView<Component>> = {
  [PAGE_MAIN]: Main,
  ...hilosAdminViews(),
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
  [HilosPages.SECURITY]: lazyPage(() =>
    import('./views/Hilos/Security/Security.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_SIGN_IN_METHODS]: lazyPage(() =>
    import('./views/Hilos/Security/SecuritySignInMethods.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_OAUTH]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauth.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_OAUTH_PROVIDER]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauthProvider.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_STEP_UP]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityStepUp.vue').then((m) => m.default),
  ),
  [HilosPages.SECURITY_2FA]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityTwoFactor.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_IMPERSONATION]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityImpersonation.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.PROFILE]: Profile,
  [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
  [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
  [HilosPages.PROFILE_SESSIONS]: ProfileSessions,
  [HilosPages.PROFILE_DEVICES]: ProfileDevices,
  [HilosPages.PROFILE_AGREEMENTS]: ProfileAgreements,
  [HilosPages.PROFILE_DATA]: ProfileData,
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

const userName = useSignal(currentUserName)
const userPhoto = useSignal(currentUserPhoto)
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]
// The standing mark by the avatar (HIL-945): a takeover, or the session's own
// scheduled deletion, in the color of the strip that says it in words.
const avatarMark = useSignal(hilosSessionAvatarMark)
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>PMD</template>
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
