/// <reference types="vite/client" />

// App-level typing of the Vite env (vite/client declares ImportMetaEnv as an
// augmentable interface).
interface ImportMetaEnv {
  /**
   * WebSocket endpoint override for a dev stack where the page is served by
   * Vite and the same-origin /ws default cannot reach a daemon. Unset for
   * now: the demo ships no backend yet, so neither environment sets it.
   */
  readonly VITE_WS_URL?: string
}
