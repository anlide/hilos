import { expect, type Page } from '@playwright/test'
import { createCommandChannel } from '../../../../../framework/frontend/scripts/commandChannel.mjs'

// The daemon command channel — the same socket the CLI test:admin-view-mode command
// speaks. The Playwright runner has no PHP, so the e2e pulls the lever over the wire
// directly; the master answers it itself and writes the node's admin view mode row,
// which every worker reads when it stamps a session response or judges a page
// (HIL-1249).
const COMMAND_HOST =
  process.env.COMMAND_HOST ?? 'binance-btc-tracker-daemon-test'
const COMMAND_PORT = Number(process.env.COMMAND_PORT ?? 8094)

// The master answers out of its own memory, without parking the request for an
// agent, so the reply is as quick as the socket.
const REPLY_TIMEOUT_MS = 5_000

const ADMIN_VIEW_MODE_COMMAND = 'test:admin-view-mode'

const sendCommand = createCommandChannel({
  host: COMMAND_HOST,
  port: COMMAND_PORT,
  timeoutMs: REPLY_TIMEOUT_MS,
})

/**
 * Turns the node's admin view mode on or off: on, a non-admin — a guest too —
 * may open the admin section to look (HIL-1253).
 *
 * The mode is the whole node's, which is safe only because the binance suite runs
 * in a single worker (playwright.config.ts: CI → workers: 1); a spec that turns it
 * on turns it off in its `afterEach`, failed test or not. The flip is not sent to
 * open tabs: a tab learns the mode on its next handshake, so a spec opens its
 * page again (`gotoPage`) after pulling the lever. A master that refuses the
 * command rejects the returned promise.
 *
 * @param enabled Whether the node is in the admin view mode.
 */
export async function setAdminViewMode(enabled: boolean): Promise<void> {
  await sendCommand(ADMIN_VIEW_MODE_COMMAND, { enabled })
}

/** Arm a forged submit before the viewer loads; the product connection stays private. */
export async function armViewerSubmit(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const NativeWebSocket = window.WebSocket
    const sockets: WebSocket[] = []
    window.WebSocket = class extends NativeWebSocket {
      constructor(url: string | URL, protocols?: string | string[]) {
        super(url, protocols)
        sockets.push(this)
      }
    }
    Object.defineProperty(window, '__hilosE2eViewerSubmit', {
      value: (
        frame: string,
        requestId: string,
      ): Promise<Record<string, unknown>> => {
        const socket = sockets.at(-1)
        if (socket?.readyState !== WebSocket.OPEN)
          throw new Error('The viewer socket is not open')
        const current = socket
        return new Promise((resolve, reject) => {
          const timer = setTimeout(() => {
            current.removeEventListener('message', receive)
            reject(new Error('The server did not answer the forged submit'))
          }, 5000)
          function receive(event: MessageEvent): void {
            if (typeof event.data !== 'string') return
            let reply: {
              type?: string
              requestId?: string
              data?: Record<string, unknown>
            }
            try {
              reply = JSON.parse(event.data)
            } catch {
              return
            }
            if (
              reply.requestId !== requestId &&
              reply.data?.requestId !== requestId
            )
              return
            clearTimeout(timer)
            current.removeEventListener('message', receive)
            resolve({ type: reply.type, ...reply.data })
          }
          current.addEventListener('message', receive)
          current.send(frame)
        })
      },
    })
  })
}

/** Prove both the visible disabled control and the server's refusal of a forged restart. */
export async function expectViewerRestartRefused(
  page: Page,
  agentId: string,
): Promise<void> {
  const button = page
    .getByTestId(`hilos-daemon-agent-restart-${agentId}`)
    .filter({ visible: true })
  await expect(button).toBeDisabled()
  const requestId = 'e2e-forged-daemon-restart'
  const reply = await page.evaluate(
    ({ agentId, requestId }) => {
      const hook = (
        window as unknown as Record<
          string,
          (frame: string, requestId: string) => Promise<Record<string, unknown>>
        >
      ).__hilosE2eViewerSubmit
      return hook(
        JSON.stringify({
          type: 'action',
          action: 'hilos_daemon_agent_restart',
          data: { agentId, nodeId: 'standalone' },
          requestId,
        }),
        requestId,
      )
    },
    { agentId, requestId },
  )
  expect(reply.type).toBe('action_error')
  expect(reply.errorCode).toBe('view_mode')
  expect(reply.reason).toBe(
    'View mode: you can look around, but not change anything.',
  )
  expect(reply).not.toHaveProperty('errorType')
  expect(reply).not.toHaveProperty('errorDetail')
}
