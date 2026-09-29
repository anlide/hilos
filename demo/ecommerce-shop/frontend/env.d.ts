/// <reference types="vite/client" />

// App-level typing of the Vite env (vite/client declares ImportMetaEnv as an
// augmentable interface).
interface ImportMetaEnv {
  /**
   * WebSocket endpoint override for environments where the WebSocket lives on a
   * separate hostname. Unset in local dev (proxied by Vite), test, and production
   * (proxied by nginx), where the app uses the same-origin /ws default.
   */
  readonly VITE_WS_URL?: string
}
