<!-- HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
app frame a project fills rather than re-implements. It renders the top
navigation bar carrying the project's brand and nav slots, the framework admin
entry (the gear linking to the Hilos dashboard), the live connection indicator
the SDK owns (core-and-connection.md), and the routed page content in the
default slot. The brand is a home link and the gear a dashboard link, so the
shell alone can move between the project home and the admin section. Styling is
Bootstrap classes only and the shell carries no CSS of its own
(styling-rules.md); the status and admin icons are inline Bootstrap-Icons SVGs,
so the shell needs no icon-font dependency. -->
<script setup lang="ts">
import type { ConnectionState, HilosConnection } from '@hilos/core'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import { computed } from 'vue'

import { useConnectionState } from './useConnectionState.js'

const props = defineProps<{ connection: HilosConnection }>()

const connectionState = useConnectionState(props.connection)

// Each transport state maps to an icon shape and a Bootstrap text color: green
// while the socket is live, amber while it is (re)connecting, red when it is
// down. `connecting` and `reconnecting` share the in-progress shape — the only
// thing that distinguishes them is the visually-hidden label.
type ConnVisual = { shape: 'live' | 'progress' | 'down'; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { shape: 'live', color: 'text-success' },
  connecting: { shape: 'progress', color: 'text-warning' },
  reconnecting: { shape: 'progress', color: 'text-warning' },
  disconnected: { shape: 'down', color: 'text-danger' },
}
const connVisual = computed(() => CONN_VISUAL[connectionState.value])

// The gear targets the framework's own dashboard page; its URL is owned by the
// framework page catalog, not restated here as a literal (routing/hilosPages).
const adminHref = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
</script>

<template>
  <div class="d-flex flex-column min-vh-100" data-id="app-root">
    <nav
      class="navbar navbar-expand bg-body-tertiary border-bottom"
      aria-label="Main"
    >
      <div class="container">
        <a class="navbar-brand mb-0 h1" href="/" data-id="nav-brand">
          <slot name="brand">Hilos</slot>
        </a>
        <div class="navbar-nav me-auto">
          <slot name="nav" />
        </div>
        <div class="d-flex align-items-center gap-3">
          <a
            class="nav-link d-inline-flex align-items-center p-0 fs-5"
            :href="adminHref"
            data-id="nav-admin"
            aria-label="Hilos dashboard"
          >
            <svg
              width="20"
              height="20"
              viewBox="0 0 16 16"
              fill="currentColor"
              aria-hidden="true"
            >
              <path
                d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 1 1 0-5.858 2.929 2.929 0 0 1 0 5.858z"
              />
            </svg>
            <span class="visually-hidden">Hilos dashboard</span>
          </a>
          <span
            class="navbar-text d-inline-flex align-items-center"
            :class="connVisual.color"
            data-id="conn-state"
            role="status"
            aria-live="polite"
            :title="connectionState"
          >
            <svg
              width="20"
              height="20"
              viewBox="0 0 16 16"
              fill="currentColor"
              aria-hidden="true"
            >
              <path
                v-if="connVisual.shape === 'live'"
                d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"
              />
              <template v-else-if="connVisual.shape === 'progress'">
                <path
                  d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41m-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9"
                />
                <path
                  d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3M3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9z"
                />
              </template>
              <path
                v-else
                d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"
              />
            </svg>
            <span class="visually-hidden">{{ connectionState }}</span>
          </span>
        </div>
      </div>
    </nav>
    <main class="container flex-grow-1 py-4">
      <slot />
    </main>
  </div>
</template>
