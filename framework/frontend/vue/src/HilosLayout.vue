<!-- HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
app frame a project fills rather than re-implements. It renders the top
navigation bar carrying the project's brand, nav, and user slots, the framework admin
entry (the gear linking to the Hilos dashboard), the live connection indicator
the SDK owns (core-and-connection.md), a full-width #banner region below the nav a
project fills with an app-wide status strip (e.g. the impersonation banner) — empty
and zero-height otherwise, the routed page content in the default
slot, and a footer of the public framework pages (HILOS_FOOTER_LINKS). The shell
is a fixed-height viewport column (vh-100): the nav, banner, and footer never scroll
(flex-shrink-0) and the main region grows and scrolls its own overflow
(min-h-0 + overflow-auto), so a page either scrolls inside main or — like the
chat page — fills it and scrolls an inner region rather than the whole document.
The brand, the gear, and the footer links are HilosLinks — no-refresh navigation
that leaves the socket alive — so the shell alone moves between the project home,
the admin section, and the public pages. While the connection reports protected
mode the shell becomes the maintenance surface (HilosMaintenance) and keeps only
the connection indicator — every other region of the shell links to a page the
freeze has shut. Styling is Bootstrap classes only and the shell carries no CSS
of its own (styling-rules.md); the status and admin icons are Bootstrap Icons
(`bi-*`), shipped with the view layer (src/index.ts) like Bootstrap. -->
<script setup lang="ts">
import type { ConnectionState, HilosConnection } from '@hilos/core'
import { HILOS_FOOTER_LINKS, HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import { computed, inject, watch } from 'vue'

import HilosLink from './HilosLink.vue'
import HilosMaintenance from './HilosMaintenance.vue'
import HilosToastHost from './HilosToastHost.vue'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useConnectionState } from './useConnectionState.js'
import { useProtectedMode } from './useProtectedMode.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  connection: HilosConnection
  /**
   * Whether the signed-in user holds the admin privilege. The admin entry is
   * drawn for an admin and for nobody else, so a project that answers no admin
   * identity (the default) shows no way into a surface the gate would refuse.
   */
  isAdmin?: boolean
}>()

const connectionState = useConnectionState(props.connection)

// While the backend holds the node in protected mode the shell shows the
// maintenance surface instead of the routed page, and drops everything that
// leads anywhere: the brand, the nav, the user region, the admin gear, and the
// footer all point at pages the freeze has shut. The connection indicator is
// the one thing that stays — during planned work it is the only status worth
// telling the visitor. The state is read from the connection, not from a page
// store, so it outlives routing and subscription lifecycles.
const protectedMode = useProtectedMode(props.connection)
const underMaintenance = computed(() => protectedMode.value.active)

// Mirror the navigator's current page title: set it as the document title so the
// browser tab tracks the no-refresh navigation, and render it in the live region
// below so a screen reader announces the page change (WCAG 2.4.2). Without a
// router (tests, the hard-link fallback) there is no title to track.
const router = inject(hilosRouterKey, undefined)
const pageTitle = router ? useSignal(router.currentTitle) : undefined

// The maintenance surface shows the verifier's code field only on an
// administrative url, so the shell hands the route's surface type down to it.
// Without a router there is no route and therefore no administrative surface
// (tests, the hard-link fallback): the field then hides, which is the safe way
// round — a missing field is fixed by typing the admin url, a field shown where
// it should not be is the defect this closes.
const currentRoute = router ? useSignal(router.currentRoute) : undefined
watch(
  () => pageTitle?.value,
  (title) => {
    if (title) {
      document.title = title
    }
  },
  { immediate: true },
)

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
    <a
      href="#hilos-main-content"
      class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary btn-sm z-3"
      data-id="skip-to-content"
    >
      Skip to main content
    </a>
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      data-id="page-title"
    >
      {{ pageTitle }}
    </div>
    <nav
      class="navbar navbar-expand bg-body-tertiary border-bottom flex-shrink-0"
      aria-label="Main"
    >
      <div class="container">
        <HilosLink
          v-if="!underMaintenance"
          to="/"
          class="navbar-brand mb-0 h1"
          data-id="nav-brand"
        >
          <slot name="brand">Hilos</slot>
        </HilosLink>
        <!-- The auto margin lives on this region whether or not it holds links,
        so the connection indicator keeps its place on the right while the
        maintenance surface is up. -->
        <div class="navbar-nav me-auto">
          <template v-if="!underMaintenance">
            <slot name="nav" />
          </template>
        </div>
        <div class="d-flex align-items-center gap-3">
          <template v-if="!underMaintenance">
            <slot name="user" />
            <HilosLink
              v-if="props.isAdmin"
              class="nav-link d-inline-flex align-items-center p-0 fs-5"
              :to="adminHref"
              data-id="nav-admin"
              aria-label="Hilos dashboard"
            >
              <i class="bi bi-gear-fill" aria-hidden="true"></i>
              <span class="visually-hidden">Hilos dashboard</span>
            </HilosLink>
          </template>
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
    <div
      class="flex-shrink-0"
      role="status"
      aria-live="polite"
      data-id="app-banner"
    >
      <slot name="banner" />
    </div>
    <main
      id="hilos-main-content"
      tabindex="-1"
      class="container flex-grow-1 min-h-0 overflow-auto py-4"
      :class="{ 'd-flex flex-column': underMaintenance }"
    >
      <HilosMaintenance
        v-if="underMaintenance"
        :status="protectedMode"
        :connection="props.connection"
        :admin-surface="currentRoute?.admin ?? false"
      />
      <slot v-else />
    </main>
    <footer
      v-if="!underMaintenance"
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
    <!-- Transient notices float over the shell, so every page inside it can report
    an outcome without owning a notification surface of its own. -->
    <HilosToastHost />
  </div>
</template>
