import type { Page } from '@playwright/test'

// Some specs need one thing the browser will not give them: a socket that dies
// while the page stays put. Playwright's offline emulation was the obvious way
// and does not work — Chromium blocks new requests but leaves an established
// WebSocket running, so the client never notices and `conn-state` stays
// `connected` (measured: three runs, 15 s each, never a transition). The
// connection object is deliberately not exposed on `window`, so the remaining
// seam is the one the page itself goes through: wrap the constructor before the
// app loads, keep the sockets it makes, and close them on demand. The product is
// untouched — only the drop is simulated, and everything after it is the real
// client's own reconnect.
//
// The proof that a drop happened is the NEXT socket, counted with
// `page.on('websocket')`, rather than a glimpse of the disconnected label, which
// a fast reconnect can pass through unseen.

/** Name of the page-side hook {@link armSocketDrop} installs for {@link dropSocket}. */
const DROP_SOCKET_HOOK = '__hilosE2eDropSocket'

/**
 * Wraps the page's WebSocket constructor so {@link dropSocket} can close what it
 * opened. Call it before the page loads: a socket opened before the wrap is out
 * of reach.
 *
 * @param page the page whose sockets are to be dropped later.
 */
export async function armSocketDrop(page: Page): Promise<void> {
  await page.addInitScript((hook: string) => {
    const sockets: WebSocket[] = []
    const NativeWebSocket = window.WebSocket
    class TrackedWebSocket extends NativeWebSocket {
      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols)
        sockets.push(this)
      }
    }
    window.WebSocket = TrackedWebSocket
    Object.defineProperty(window, hook, {
      value: () => {
        for (const socket of sockets.splice(0)) {
          socket.close()
        }
      },
    })
  }, DROP_SOCKET_HOOK)
}

/**
 * Closes every socket the page has opened, the way a dropped network would.
 *
 * @param page a page armed with {@link armSocketDrop} before it loaded.
 */
export function dropSocket(page: Page): Promise<void> {
  return page.evaluate((hook) => {
    ;(window as unknown as Record<string, () => void>)[hook]()
  }, DROP_SOCKET_HOOK)
}
