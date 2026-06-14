<!-- HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
app frame a project fills rather than re-implements. It renders the top
navigation bar carrying the project's brand, nav, and user slots, the framework admin
entry (the gear linking to the Hilos dashboard), the live connection indicator
the SDK owns (core-and-connection.md), the routed page content in the default
slot, and a footer of the public framework pages (HILOS_FOOTER_LINKS). The shell
is a fixed-height viewport column (vh-100): the nav and footer never scroll
(flex-shrink-0) and the main region grows and scrolls its own overflow
(min-h-0 + overflow-auto), so a page either scrolls inside main or — like the
chat page — fills it and scrolls an inner region rather than the whole document.
The brand, the gear, and the footer links are HilosLinks — no-refresh navigation
that leaves the socket alive — so the shell alone moves between the project home,
the admin section, and the public pages. Styling is Bootstrap classes only and
the shell carries no CSS of its own (styling-rules.md); the status and admin
icons are Bootstrap Icons (`bi-*`), shipped with the view layer (src/index.ts)
like Bootstrap. -->
<script setup lang="ts">
import type { ConnectionState, HilosConnection } from '@hilos/core'
import { HILOS_FOOTER_LINKS, HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import { computed } from 'vue'

import HilosLink from './HilosLink.vue'
import { useConnectionState } from './useConnectionState.js'

const props = defineProps<{ connection: HilosConnection }>()

const connectionState = useConnectionState(props.connection)

// Each transport state maps to a Bootstrap Icon and a Bootstrap text color:
// green while the socket is live, amber while it is (re)connecting, red when it
// is down. `connecting` and `reconnecting` share the in-progress icon — the only
// thing that distinguishes them is the visually-hidden label.
type ConnVisual = { icon: string; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { icon: 'bi-check-circle-fill', color: 'text-success' },
  connecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  reconnecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  disconnected: { icon: 'bi-exclamation-triangle-fill', color: 'text-danger' },
}
const connVisual = computed(() => CONN_VISUAL[connectionState.value])

// The gear targets the framework's own dashboard page; its URL is owned by the
// framework page catalog, not restated here as a literal (routing/hilosPages).
const adminHref = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]

// The footer's public framework pages and their hrefs are owned by the
// framework (routing/hilosPages), so every project's footer offers the same
// links and a project supplies only each page's content component.
const footerLinks = HILOS_FOOTER_LINKS
const footerHref = (page: string): string => HILOS_PAGE_ROUTES[page] ?? '/'
</script>

<template>
  <div class="d-flex flex-column vh-100 overflow-hidden" data-id="app-root">
    <nav
      class="navbar navbar-expand bg-body-tertiary border-bottom flex-shrink-0"
      aria-label="Main"
    >
      <div class="container">
        <HilosLink to="/" class="navbar-brand mb-0 h1" data-id="nav-brand">
          <slot name="brand">Hilos</slot>
        </HilosLink>
        <div class="navbar-nav me-auto">
          <slot name="nav" />
        </div>
        <div class="d-flex align-items-center gap-3">
          <slot name="user" />
          <HilosLink
            class="nav-link d-inline-flex align-items-center p-0 fs-5"
            :to="adminHref"
            data-id="nav-admin"
            aria-label="Hilos dashboard"
          >
            <i class="bi bi-gear-fill" aria-hidden="true"></i>
            <span class="visually-hidden">Hilos dashboard</span>
          </HilosLink>
          <span
            class="navbar-text d-inline-flex align-items-center fs-5"
            :class="connVisual.color"
            data-id="conn-state"
            role="status"
            aria-live="polite"
            :title="connectionState"
          >
            <i class="bi" :class="connVisual.icon" aria-hidden="true"></i>
            <span class="visually-hidden">{{ connectionState }}</span>
          </span>
        </div>
      </div>
    </nav>
    <main class="container flex-grow-1 min-h-0 overflow-auto py-4">
      <slot />
    </main>
    <footer
      class="footer flex-shrink-0 border-top bg-body-tertiary py-2"
      data-id="app-footer"
    >
      <div
        class="container d-flex flex-wrap justify-content-center gap-3 small"
      >
        <HilosLink
          v-for="link in footerLinks"
          :key="link.page"
          class="link-secondary text-decoration-none"
          :to="footerHref(link.page)"
          :data-id="`footer-link-${link.page}`"
        >
          {{ link.label }}
        </HilosLink>
      </div>
    </footer>
  </div>
</template>
