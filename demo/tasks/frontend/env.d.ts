/// <reference types="vite/client" />

// App-level typing of the Vite env (vite/client declares ImportMetaEnv as an
// augmentable interface).
interface ImportMetaEnv {
  /**
   * WebSocket endpoint override for the local dev stack, where the page is
   * served by Vite and the same-origin /ws default cannot reach the daemon
   * (set in docker/docker-compose.local.yml). Unset in test and production:
   * there the app uses same-origin /ws through nginx.
   */
  readonly VITE_WS_URL?: string
}
