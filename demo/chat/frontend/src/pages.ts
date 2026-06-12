// The chat page configuration: the page keys, the URL-to-page map for
// cold-load routing, and the per-signal schemas the core parse boundary
// validates. Config only — the page subscription behavior lives in
// pageScope.ts so configuration never mixes with signal handling.
import { pageResponseSchema } from '@hilos/core'

export const PAGE_MAIN = 'main'

/** Signal `type` of the backend page response (PHP `SignalTypeConstants::PAGE_RESPONSE`). */
export const SIGNAL_PAGE_RESPONSE = 'page_response'

/** Page key per cold-load route, mirroring the legacy route table. */
const PAGE_BY_PATH: Record<string, string> = {
  '/': PAGE_MAIN,
}

/** Concrete chat page signal schemas for the core parse boundary. */
export const pageSignalSchemas = {
  [SIGNAL_PAGE_RESPONSE]: pageResponseSchema,
}

/**
 * Resolve the page key the URL names on cold load; unknown paths fall back to
 * the main page like the legacy router's catch-all.
 *
 * @param pathname The location pathname at load time.
 */
export function pageForPath(pathname: string): string {
  return PAGE_BY_PATH[pathname] ?? PAGE_MAIN
}
