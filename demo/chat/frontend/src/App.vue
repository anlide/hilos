<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand slot and routes the content slot through HilosView, which renders the
component mapped to the navigator's current page. The brand and the shell's gear
move between the main page and the framework dashboard with no refresh. The live
connection state is the shell's own indicator (an extra status surface allowed
by docs/agents/frontend/core-and-connection.md). -->
<script setup lang="ts">
import { HilosLayout, HilosView } from '@hilos/vue'
import { HilosPages } from '@hilos/core'
import type { Component } from 'vue'

import { connection } from './bootstrap/connection'
import { PAGE_MAIN } from './pages/keys'
import { ADMIN_STUB_KEYS } from './views/Hilos/adminMap'
import HilosAdminStub from './views/Hilos/HilosAdminStub.vue'
import About from './views/About/About.vue'
import Dashboard from './views/Dashboard/Dashboard.vue'
import Licence from './views/Licence/Licence.vue'
import Main from './views/Main/Main.vue'
import Privacy from './views/Privacy/Privacy.vue'
import Terms from './views/Terms/Terms.vue'

// The page-key → view map HilosView renders from. The main page, the dashboard,
// and the static footer pages have their own components; the rest of the Hilos
// admin section is stubbed, so every admin stub key renders through the single
// HilosAdminStub. Pages without a mapped view (settings/users/guardian, which
// get their own real pages) render nothing.
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  [HilosPages.DASHBOARD]: Dashboard,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENCE]: Licence,
  ...Object.fromEntries(ADMIN_STUB_KEYS.map((key) => [key, HilosAdminStub])),
}
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>Hilos Chat</template>
    <HilosView :pages="pages" />
  </HilosLayout>
</template>
