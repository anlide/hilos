<!-- Root view. The application shell is the SDK's HilosLayout; the site fills
its brand and user slots and routes the content slot through HilosView, which
renders the component mapped to the navigator's current page. The live connection
state, the theme menu and the MCP mark are the shell's own. The site has no
navigation of its own and no header icons of its own, so the #actions slot stays
empty; the signed-in region is the avatar — a link to the profile root — and a
visitor gets nothing there: the public site asks nobody to sign in, and the owner
signs in where /hilos answers that it wants an account (the approved layout mockup). -->
<script setup lang="ts">
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
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
import { currentUserName } from './bootstrap/session.js'
import { PAGE_HISTORY, PAGE_MAIN } from './pages/keys.js'
import About from './views/About/About.vue'
import History from './views/History/History.vue'
import License from './views/License/License.vue'
import LicenseDependencies from './views/LicenseDependencies/LicenseDependencies.vue'
import Main from './views/Main/Main.vue'
import MainSkeleton from './views/Main/MainSkeleton.vue'
import Privacy from './views/Privacy/Privacy.vue'
import PrivacyHistory from './views/PrivacyHistory/PrivacyHistory.vue'
import Profile from './views/Profile/Profile.vue'
import ProfileData from './views/Profile/ProfileData.vue'
import ProfileSignIn from './views/Profile/ProfileSignIn.vue'
import Terms from './views/Terms/Terms.vue'
import TermsHistory from './views/TermsHistory/TermsHistory.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from: the home, the site's own
// history page, the framework admin defaults (hilosAdminViews, of which only the
// dashboard is served by this backend), and then exactly the other pages the
// backend serves (Hilos.php PAGES): the Analytics and Settings sections and the
// delivery journal — mapped by the site itself, because those pages need the
// project's context and hilosAdminViews leaves them out — the profile pages, and
// the footer pages whose text is this site's.
// Each descriptor is created once for this App instance; selecting a page
// loads only its project wrapper and SDK section. The prose pages stay eager.
const pages: Record<string, PageView<Component>> = {
  [PAGE_MAIN]: Main,
  [PAGE_HISTORY]: History,
  ...hilosAdminViews(),
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.vue').then((m) => m.default),
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
  // The notification delivery journal of a channel: the one Communications page
  // the backend serves, because the notification delivery feature refuses to
  // start without it. The Communications hub, its channel page, configuration
  // and frame are not served here, so they are not mounted either.
  [HilosPages.COMMUNICATIONS_DELIVERIES]: lazyPage(() =>
    import('./views/Hilos/Communications/Deliveries.vue').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.PROFILE]: Profile,
  [HilosPages.PROFILE_DATA]: ProfileData,
  [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
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
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]
// The standing mark by the avatar (HIL-945): a takeover, or the session's own
// scheduled deletion, in the color of the strip that says it in words.
const avatarMark = useSignal(hilosSessionAvatarMark)
</script>

<template>
  <HilosLayout :connection="connection">
    <!-- The logo beside the name; on a narrow screen the name is visually hidden so the mark stands alone while keeping the accessible link label. -->
    <template #brand>
      <span class="d-inline-flex align-items-center gap-2">
        <img src="/hilos-medium.svg" width="32" height="32" alt="" />
        <span class="d-none d-md-inline">Hilos</span>
        <span class="visually-hidden d-md-none">Hilos</span>
      </span>
    </template>
    <template #user>
      <HilosLink
        v-if="userName"
        :to="profileHref"
        class="nav-link d-inline-flex align-items-center p-0"
        data-id="nav-profile-name"
        :title="userName"
      >
        <HilosAvatar :name="userName" :mark="avatarMark" />
        <span class="visually-hidden">{{ userName }}</span>
      </HilosLink>
    </template>
    <HilosView
      :pages="pages"
      :page-skeletons="pageSkeletons"
      :auth-surface="AuthSurface"
      :auth-gate="props.authGate"
    />
  </HilosLayout>
</template>
