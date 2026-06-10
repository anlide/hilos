// @hilos/vue — the Vue view layer over @hilos/core (the Hilos frontend SDK).
//
// Thin Vue adapters and the slot-extensible SDK components (tier 1 universal
// components and tier 2 page chunks) live here; all protocol, store, and table
// logic stays in @hilos/core. Fills in across rewrite step 7; shipped so far:
// the connection-state composable.

export { useConnectionState } from './useConnectionState.js'
