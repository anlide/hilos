// Ambient declarations for the small Node build-time surface this package uses.
// The prerender package is Node tooling — it reads the client build's index.html
// and writes <route>.html — but the SDK workspace ships no @types/node on
// purpose: hoisting it into the workspace would add Node's global WebSocket,
// Response, Blob, and fetch declarations to the browser-runtime packages
// (@hilos/core constructs a real `WebSocket`), where they clash with the DOM lib.
// So this package declares only the handful of node: module members it calls,
// as explicit module imports, keeping Node types out of every other package.

declare module 'node:fs' {
  export function readFileSync(path: string, encoding: 'utf8'): string
  export function writeFileSync(path: string, data: string): void
  export function renameSync(oldPath: string, newPath: string): void
  export function readdirSync(path: string): string[]
  export function mkdtempSync(prefix: string): string
  export function rmSync(
    path: string,
    options?: { recursive?: boolean; force?: boolean },
  ): void
}

declare module 'node:path' {
  export function join(...paths: string[]): string
}

declare module 'node:os' {
  export function tmpdir(): string
}

declare module 'node:process' {
  const process: {
    readonly env: Record<string, string | undefined>
    readonly pid: number
  }
  export default process
}
