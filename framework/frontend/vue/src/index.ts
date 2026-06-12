// @hilos/vue — the Vue view layer over @hilos/core (the Hilos frontend SDK).
//
// Thin Vue adapters and the slot-extensible SDK components (tier 1 universal
// components and tier 2 page chunks) live here; all protocol, store, and table
// logic stays in @hilos/core. Fills in across rewrite step 7; shipped so far:
// the connection-state composable, the core-signal bridge, the entity resolver,
// and the application shell.

// The SDK ships Bootstrap so every consumer is styled transitively and never
// imports it itself (styling-rules.md). The library build inlines this
// stylesheet into the bundle, so the import covers both the dev (source) and the
// built (dist) resolution paths.
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'

export { useConnectionState } from './useConnectionState.js'
export { useSignal } from './useSignal.js'
export { useEntity } from './useEntity.js'
export { hilosRouterKey } from './hilosRouterKey.js'
export { default as HilosLayout } from './HilosLayout.vue'
export { default as HilosLink } from './HilosLink.vue'
export { default as HilosView } from './HilosView.vue'
