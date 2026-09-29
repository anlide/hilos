<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand and user slots and routes the content slot through HilosView, which
renders the component mapped to the navigator's current page. The live connection
state, the language/theme switch and the MCP mark are the shell's own. The demo
has no navigation of its own yet and no profile page, so the signed-in region is
the avatar alone — not a link — and a visitor gets one button that opens the
sign-in surface over the page they are standing on (mockups/framework/layout). -->
<script setup lang="ts">
import {
  HilosAvatar,
  HilosLayout,
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
import Terms from './views/Terms/Terms.vue'
import HilosMaintenance from './views/Hilos/Maintenance/Maintenance.vue'

// The auth gate is created in bootstrap (it needs the navigator, the current
// user, and the connection) and passed in as a root prop; App wires it and the
// project's AuthSurface to the outlet so a 401 shows sign-in in place, and the
// shell's Sign in button opens it over the page.
const props = defineProps<{ authGate: AuthGate }>()

// The page-key → view map HilosView renders from: the home, the framework admin
// defaults (hilosAdminViews, of which only the dashboard is registered on the
// backend today), the Maintenance section — mapped by the demo itself, because
// that page needs the project's context and hilosAdminViews leaves it out — and
// the four footer pages whose text is this demo's.
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  ...hilosAdminViews(),
  [HilosPages.MAINTENANCE]: HilosMaintenance,
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
