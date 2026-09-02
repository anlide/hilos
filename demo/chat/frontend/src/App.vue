<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand slot and routes the content slot through HilosView, which renders the
component mapped to the navigator's current page. The brand and the shell's gear
move between the main page and the framework dashboard with no refresh. The live
connection state is the shell's own indicator (an extra status surface allowed
by docs/agents/frontend/core-and-connection.md). -->
<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue'
import {
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosView,
  hilosAdminViews,
  hilosRouterKey,
  useSignal,
} from '@hilos/vue'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
} from '@hilos/core'
import type { AuthGate } from '@hilos/core'
import type { Component } from 'vue'

import AuthSurface from './auth/AuthSurface.vue'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import {
  currentUserIsAdmin,
  currentUserName,
  impersonating,
} from './bootstrap/session'
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
import HilosBackup from './views/Hilos/Backup/Backup.vue'
import HilosCommunications from './views/Hilos/Communications/Communications.vue'
import HilosCommunicationsChannel from './views/Hilos/Communications/Channel.vue'
import HilosCommunicationsDeliveries from './views/Hilos/Communications/Deliveries.vue'
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
  [HilosPages.BACKUP]: HilosBackup,
  [HilosPages.COMMUNICATIONS]: HilosCommunications,
  [HilosPages.COMMUNICATIONS_CHANNEL]: HilosCommunicationsChannel,
  [HilosPages.COMMUNICATIONS_DELIVERIES]: HilosCommunicationsDeliveries,
  [HilosPages.LOGS]: HilosLogsOverview,
  [HilosPages.LOGS_KEYS]: HilosLogsKeys,
  [HilosPages.LOGS_WORKERS]: HilosLogsWorkers,
  [HilosPages.LOGS_ROTATIONS]: HilosLogsRotations,
  [HilosPages.LOGS_SETTINGS]: HilosLogsSettings,
  [HilosPages.LOGS_VIEW]: HilosLogsView,
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

// The navbar profile entry: the current user's name links to the framework
// profile page (its route owned by the page catalog), shown once the handshake
// names the user.
const userName = useSignal(currentUserName)
const isAdmin = useSignal(currentUserIsAdmin)
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]

// The shell logout control. Logout is page-independent, so it sends the
// agent-owned `logout` action (PHP `ChatSignalConstants::LOGOUT`) rather than a
// page action; the backend reverts the session to anonymous and broadcasts the
// null handshake response, which clears the current user through the session
// scope for every tab.
//
// The clicker gets loading while it is in flight: the button enters `loggingOut`
// on send and leaves it when the broadcast lands — the session downgrade drops
// `userName`, which both un-loads and (through its own `v-if`) removes the
// control, the visible confirmation. A fallback timer releases loading if the
// signal never arrives, so the control can never wedge.
const LOGOUT_ACTION = 'hilos_logout'
const LOGOUT_FALLBACK_MS = 5000
const loggingOut = ref(false)
// The fallback timer's handle, kept so it is cleared the moment the broadcast
// ends loading. Without clearing it, a stale timer from one logout could fire
// during a later one and drop its loading early.
let fallbackTimer: ReturnType<typeof setTimeout> | undefined
const logout = (): void => {
  if (loggingOut.value) {
    return
  }
  loggingOut.value = true
  if (!connection.sendAction(LOGOUT_ACTION, {})) {
    // Not sent (the socket is down): the action never left, so do not show
    // loading for a broadcast that will never come.
    loggingOut.value = false

    return
  }
  fallbackTimer = setTimeout(() => {
    loggingOut.value = false
  }, LOGOUT_FALLBACK_MS)
}
// React to the broadcast: the downgrade clears the name, which ends loading and
// cancels the now-unnecessary fallback timer.
watch(userName, (name) => {
  if (!name) {
    loggingOut.value = false
    if (fallbackTimer !== undefined) {
      clearTimeout(fallbackTimer)
      fallbackTimer = undefined
    }
  }
})

// The impersonation banner and its Stop control. `impersonating` is the single
// source (the handshake response's impersonatedBy slot); while true the shell
// shows a full-width banner naming the impersonated user. Stop is page-independent
// — while impersonating the effective user is the non-admin target, so no admin
// page is guaranteed — so it sends the agent-owned `hilos_impersonate_stop` action
// (PHP `HilosSignalConstants::HILOS_IMPERSONATE_STOP`, framework-owned since
// HIL-729), a calque of logout: the backend reverts the session to the admin and
// broadcasts the cleared handshake response, which flips `impersonating` back to
// false for every tab.
const isImpersonating = useSignal(impersonating)
const IMPERSONATE_STOP_ACTION = 'hilos_impersonate_stop'
const IMPERSONATE_STOP_FALLBACK_MS = 5000
const stoppingImpersonation = ref(false)
// The fallback timer's handle, cleared the moment the broadcast ends loading (as
// with logout, so a stale timer from one stop cannot drop a later one early).
let impersonateFallbackTimer: ReturnType<typeof setTimeout> | undefined
const stopImpersonation = (): void => {
  if (stoppingImpersonation.value) {
    return
  }
  stoppingImpersonation.value = true
  if (!connection.sendAction(IMPERSONATE_STOP_ACTION, {})) {
    // Not sent (the socket is down): no broadcast will come, so do not show
    // loading for it.
    stoppingImpersonation.value = false

    return
  }
  impersonateFallbackTimer = setTimeout(() => {
    stoppingImpersonation.value = false
  }, IMPERSONATE_STOP_FALLBACK_MS)
}
// React to the broadcast: clearing the impersonation ends loading (and removes the
// banner through its own `v-if`) and cancels the now-unnecessary fallback timer.
watch(isImpersonating, (value) => {
  if (!value) {
    stoppingImpersonation.value = false
    if (impersonateFallbackTimer !== undefined) {
      clearTimeout(impersonateFallbackTimer)
      impersonateFallbackTimer = undefined
    }
  }
})
</script>

<template>
  <HilosLayout :connection="connection" :is-admin="isAdmin">
    <template #brand>Hilos Chat</template>
    <template #banner>
      <div
        v-if="isImpersonating"
        class="alert alert-warning border-0 rounded-0 mb-0 py-2"
        data-id="impersonation-banner"
      >
        <div
          class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
        >
          <span>
            <i class="bi bi-person-badge me-1" aria-hidden="true"></i>
            You are impersonating <strong>{{ userName }}</strong>
          </span>
          <button
            type="button"
            class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1"
            data-id="impersonation-stop"
            :disabled="stoppingImpersonation"
            @click="stopImpersonation"
          >
            <span
              v-if="stoppingImpersonation"
              class="spinner-border spinner-border-sm"
              role="status"
              aria-hidden="true"
            ></span>
            Stop
          </button>
        </div>
      </div>
    </template>
    <template #user>
      <HilosNotificationBell v-if="userName" :connection="connection" />
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
        :disabled="loggingOut"
        @click="logout"
      >
        <span
          v-if="loggingOut"
          class="spinner-border spinner-border-sm"
          role="status"
          aria-hidden="true"
        ></span>
        <i v-else class="bi bi-box-arrow-right" aria-hidden="true"></i>
      </button>
    </template>
    <HilosMagicLinkPage v-if="isMagicRoute" :context="hilosAuthContext" />
    <HilosOAuthCallbackPage
      v-else-if="isOAuthCallbackRoute"
      :context="hilosAuthContext"
    />
    <HilosView
      v-else
      :pages="pages"
      :auth-surface="AuthSurface"
      :auth-gate="props.authGate"
    />
  </HilosLayout>
</template>
