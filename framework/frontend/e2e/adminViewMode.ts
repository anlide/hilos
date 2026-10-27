import type {
  Expect,
  Page,
  WebSocket as PlaywrightWebSocket,
} from '@playwright/test'
import { watchSocketFrames, type SocketFrame } from './socket.js'

// Bound how long the forged-action observer waits for its correlated reply.
const REPLY_TIMEOUT_MS = 5_000

/** Arm before the viewer loads; only the test hook can send on its private socket. */
export async function armViewerSubmit(page: Page) {
  const frames: SocketFrame[] = []
  let pending:
    | {
        socket: number
        requestId: string
        resolve: (frame: SocketFrame) => void
        reject: (error: Error) => void
        timer: ReturnType<typeof setTimeout>
      }
    | undefined
  let disposed = false
  const watcher = watchSocketFrames(page, (frame) => {
    frames.push(frame)
    const wire = frame.wire as
      | {
          requestId?: unknown
          data?: { requestId?: unknown }
        }
      | undefined
      | null
    if (
      pending &&
      frame.socket === pending.socket &&
      (wire?.requestId === pending.requestId ||
        wire?.data?.requestId === pending.requestId)
    ) {
      const waiter = pending
      pending = undefined
      clearTimeout(waiter.timer)
      waiter.resolve(frame)
    }
  })
  const closed = new Map<PlaywrightWebSocket, () => void>()
  const rejectPending = (error: Error) => {
    if (!pending) return
    const waiter = pending
    pending = undefined
    clearTimeout(waiter.timer)
    waiter.reject(error)
  }
  const opened = (socket: PlaywrightWebSocket) => {
    const id = watcher.sockets()
    const close = () => {
      if (pending?.socket === id) {
        rejectPending(
          new Error('The viewer socket closed before the forged reply'),
        )
      }
      closed.delete(socket)
    }
    closed.set(socket, close)
    socket.once('close', close)
  }
  const dispose = () => {
    disposed = true
    rejectPending(new Error('The forged submit observer was disposed'))
    watcher.stop()
    page.off('websocket', opened)
    page.off('close', dispose)
    for (const [socket, close] of closed) socket.off('close', close)
    closed.clear()
  }
  page.on('websocket', opened)
  page.once('close', dispose)
  try {
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
        value: (frame: string): void => {
          const socket = sockets.at(-1)
          if (socket?.readyState !== WebSocket.OPEN)
            throw new Error('The viewer socket is not open')
          socket.send(frame)
        },
      })
    })
  } catch (error) {
    dispose()
    throw error
  }
  return {
    frames,
    dispose,
    async submit(frame: string, requestId: string): Promise<SocketFrame> {
      if (disposed) throw new Error('The forged submit observer was disposed')
      if (pending) throw new Error('A forged submit is already pending')
      const answer = new Promise<SocketFrame>((resolve, reject) => {
        pending = {
          socket: watcher.sockets(),
          requestId,
          resolve,
          reject,
          timer: setTimeout(() => {
            rejectPending(
              new Error('The server did not answer the forged submit'),
            )
          }, REPLY_TIMEOUT_MS),
        }
      })
      // Arm correlation before sending; a reply may precede evaluate's completion.
      const sent = page
        .evaluate((frame) => {
          const hook = (
            window as unknown as Record<string, (frame: string) => void>
          ).__hilosE2eViewerSubmit
          hook(frame)
        }, frame)
        .catch((error: Error) => {
          rejectPending(error)
          throw error
        })
      const [, reply] = await Promise.all([sent, answer])
      return reply
    },
  }
}

/** Prove both the visible disabled control and the server's refusal of a forged restart. */
export async function expectViewerRestartRefused(
  expect: Expect,
  page: Page,
  agentId: string,
  viewer: Awaited<ReturnType<typeof armViewerSubmit>>,
): Promise<void> {
  const button = page
    .getByTestId(`hilos-daemon-agent-restart-${agentId}`)
    .filter({ visible: true })
  await expect(button).toBeDisabled()
  const requestId = 'e2e-forged-daemon-restart'
  const observation = await viewer.submit(
    JSON.stringify({
      type: 'action',
      action: 'hilos_daemon_agent_restart',
      data: { agentId, nodeId: 'standalone' },
      requestId,
    }),
    requestId,
  )
  expect(observation.failure).toBeUndefined()
  const wire = observation.wire as {
    type?: unknown
    data?: Record<string, unknown>
  }
  expect(wire.type).toBe('action_error')
  const reply = wire.data
  expect(reply).toBeDefined()
  if (!reply) throw new Error('The forged refusal has no payload')
  expect(reply.errorCode).toBe('view_mode')
  expect(reply.reason).toBe(
    'View mode: you can look around, but not change anything.',
  )
  expect(reply).not.toHaveProperty('errorType')
  expect(reply).not.toHaveProperty('errorDetail')
}
