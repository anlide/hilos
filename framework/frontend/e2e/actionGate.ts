import type { Page, WebSocketRoute } from '@playwright/test'
import { deliveryObject } from './deliveryFrames.js'
import { isApplicationSocket } from './socket.js'

/**
 * Hold one outgoing action unchanged, then send that same frame once on release.
 * Install before navigation. This exposes the real tracked busy state without
 * forging a server reply or retrying a mutation. Release forwards a live hold.
 */
export async function holdSocketAction(page: Page) {
  let action: string | null = null
  let held: { server: WebSocketRoute; message: string } | null = null
  const captured: string[] = []
  const release = (): void => {
    action = null
    const waiting = held
    held = null
    waiting?.server.send(waiting.message)
  }
  await page.routeWebSocket(isApplicationSocket, (client) => {
    const server = client.connectToServer()
    client.onMessage((message) => {
      let frame: Record<string, unknown> = {}
      if (typeof message === 'string') {
        try {
          frame = deliveryObject(JSON.parse(message))
        } catch {
          // A transport ping is forwarded unchanged too.
        }
      }
      if (frame.type === 'action' && frame.action === action) {
        if (held !== null)
          throw new Error('A second action was sent during the held request.')
        held = { server, message: message as string }
        captured.push(message as string)
        return
      }
      server.send(message)
    })
    client.onClose((code, reason) => {
      if (held?.server === server) held = null
      server.close({ code, reason })
    })
  })
  return {
    captured,
    hold(next: string): void {
      if (held !== null || action !== null)
        throw new Error('An action is already held.')
      action = next
    },
    release,
  }
}
