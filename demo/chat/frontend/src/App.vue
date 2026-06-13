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
import About from './views/About/About.vue'
import Dashboard from './views/Dashboard/Dashboard.vue'
import Licence from './views/Licence/Licence.vue'
import Main from './views/Main/Main.vue'
import Privacy from './views/Privacy/Privacy.vue'
import Terms from './views/Terms/Terms.vue'

// The page-key → view map HilosView renders from. Pages without a mapped view
// (other routes land later) render nothing.
const pages: Record<string, Component> = {
  [PAGE_MAIN]: Main,
  [HilosPages.DASHBOARD]: Dashboard,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENCE]: Licence,
}
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>Hilos Chat</template>
    <HilosView :pages="pages" />
  </HilosLayout>
</template>
