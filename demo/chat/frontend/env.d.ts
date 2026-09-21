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
   * WebSocket endpoint override for environments where the WebSocket lives on a
   * separate hostname, such as the preview stack (docker/docker-compose.preview.yml).
   * Unset in local dev (proxied by Vite), test, and production (proxied by nginx),
   * where the app uses the same-origin /ws default.
   */
  readonly VITE_WS_URL?: string
}
