/// <reference types="vite/client" />

// Ambient module shape for SFC imports from plain .ts files (main.ts). vue-tsc
// types .vue files natively and precisely; this wildcard only serves tooling
// that runs the stock TypeScript service over .ts sources.
declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent
  export default component
}

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
