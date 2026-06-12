<!-- HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
app frame a project fills rather than re-implements. It renders the top
navigation bar carrying the project's brand and nav slots, the live connection
indicator the SDK owns (core-and-connection.md), and the routed page content in
the default slot. Styling is Bootstrap classes only and the shell carries no CSS
of its own (styling-rules.md). -->
<script setup lang="ts">
import type { ConnectionState, HilosConnection } from '@hilos/core'

import { useConnectionState } from './useConnectionState.js'

const props = defineProps<{ connection: HilosConnection }>()

const connectionState = useConnectionState(props.connection)

// Bootstrap contextual color per transport state: green only while the socket
// is live, amber while it is (re)connecting, red when it is down.
const STATE_VARIANT: Record<ConnectionState, string> = {
  connected: 'text-bg-success',
  connecting: 'text-bg-warning',
  reconnecting: 'text-bg-warning',
  disconnected: 'text-bg-danger',
}
</script>

<template>
  <div class="d-flex flex-column min-vh-100" data-id="app-root">
    <nav
      class="navbar navbar-expand bg-body-tertiary border-bottom"
      aria-label="Main"
    >
      <div class="container">
        <span class="navbar-brand mb-0 h1">
          <slot name="brand">Hilos</slot>
        </span>
        <div class="navbar-nav me-auto">
          <slot name="nav" />
        </div>
        <span
          class="badge rounded-pill"
          :class="STATE_VARIANT[connectionState]"
          data-id="conn-state"
          role="status"
          aria-live="polite"
          >{{ connectionState }}</span
        >
      </div>
    </nav>
    <main class="container flex-grow-1 py-4">
      <slot />
    </main>
  </div>
</template>
