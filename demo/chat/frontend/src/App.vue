<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand slot and routes the content slot through HilosView, which renders the
component mapped to the navigator's current page. The brand and the shell's gear
move between the main page and the framework dashboard with no refresh. The live
connection state is the shell's own indicator (an extra status surface allowed
by docs/agents/frontend/core-and-connection.md). -->
<script setup lang="ts">
import {
  HilosLayout,
  HilosLink,
  HilosView,
  hilosAdminViews,
  useSignal,
} from '@hilos/vue'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import type { AuthGate } from '@hilos/core'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { connection } from './bootstrap/connection'
import { currentUserName } from './bootstrap/session'
import {
  PAGE_ADMIN_BOTS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_USERS,
  PAGE_BOT,
  PAGE_MAIN,
  PAGE_USER,
} from './pages/keys'
import About from './views/About/About.vue'
import AdminBots from './views/AdminBots/AdminBots.vue'
import AdminModerator from './views/AdminModerator/AdminModerator.vue'
import AdminUsers from './views/AdminUsers/AdminUsers.vue'
import Bot from './views/Bot/Bot.vue'
import Dashboard from './views/Dashboard/Dashboard.vue'
import License from './views/License/License.vue'
import Main from './views/Main/Main.vue'
import Privacy from './views/Privacy/Privacy.vue'
import Profile from './views/Profile/Profile.vue'
import Terms from './views/Terms/Terms.vue'
import User from './views/User/User.vue'
// The Hilos admin section. The framework ships a real default page for every
// admin key (hilosAdminViews), so the demo maps only the pages it implements
// itself — the rest render the framework default, never recopied per project
// (page-module-structure.md). Each page is still its own module file.
import HilosSettings from './views/Hilos/Settings/Settings.vue'
import HilosUsers from './views/Hilos/Users/Users.vue'
import HilosUser from './views/Hilos/Users/User.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from. The app's own pages, then the
// framework admin defaults (hilosAdminViews), then the demo's real admin pages
// overriding the default for their key (and users/user, which the framework's
// default map omits — they need a project context, so the demo mounts them
// directly). Pages without a mapped view (guardian, its own real page) render
// nothing.
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  [PAGE_USER]: User,
  [PAGE_BOT]: Bot,
  [PAGE_ADMIN_BOTS]: AdminBots,
  [PAGE_ADMIN_MODERATOR]: AdminModerator,
  [PAGE_ADMIN_USERS]: AdminUsers,
  ...hilosAdminViews(),
  [HilosPages.DASHBOARD]: Dashboard,
  [HilosPages.PROFILE]: Profile,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
  [HilosPages.SETTINGS]: HilosSettings,
  [HilosPages.USERS]: HilosUsers,
  [HilosPages.USER]: HilosUser,
}

// The navbar profile entry: the current user's name links to the framework
// profile page (its route owned by the page catalog), shown once the handshake
// names the user.
const userName = useSignal(currentUserName)
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]

// The shell logout control. Logout is page-independent, so it sends the
// agent-owned `logout` action (PHP `ChatSignalConstants::LOGOUT`) rather than a
// page action; the backend reverts the session to anonymous and the null
// handshake response clears the current user through the session scope.
const LOGOUT_ACTION = 'logout'
const logout = (): void => {
  connection.sendAction(LOGOUT_ACTION, {})
}
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
      <button
        v-if="userName"
        type="button"
        class="btn btn-link nav-link d-inline-flex align-items-center p-0 ms-3"
        data-id="nav-logout"
        aria-label="Log out"
        @click="logout"
      >
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
      </button>
    </template>
    <HilosView
      :pages="pages"
      :auth-surface="AuthSurface"
      :auth-gate="props.authGate"
    />
  </HilosLayout>
</template>
