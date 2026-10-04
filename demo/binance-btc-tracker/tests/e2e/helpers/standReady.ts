import https from 'node:https'

export const READY_TIMEOUT_MS = 120_000
const POLL_INTERVAL_MS = 2_000

/**
 * @param baseURL Front origin.
 * @param agent Agent tolerating its test certificate.
 */
export function probeStatic(
  baseURL: string,
  agent: https.Agent,
): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(
      baseURL,
      { method: 'HEAD', agent },
      (response) => {
        response.resume()
        response.on('end', () => {
          const status = response.statusCode ?? 0
          resolve(status >= 200 && status < 400)
        })
      },
    )
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * @param baseURL Front origin.
 * @param agent Agent tolerating its test certificate.
 * @param master Optional master selected through the cluster stand entry.
 */
export function probeWebSocketUpgrade(
  baseURL: string,
  agent: https.Agent,
  master?: string,
): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(`${baseURL}/ws`, {
      agent,
      headers: {
        ...(master ? { Cookie: `hilos_stand_master=${master}` } : {}),
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13',
        'Sec-WebSocket-Key': Buffer.from('hilos-e2e-ready!').toString('base64'),
      },
    })
    request.on('upgrade', (response, socket) => {
      socket.destroy()
      resolve(response.statusCode === 101)
    })
    request.on('response', (response) => {
      response.resume()
      resolve(false)
    })
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * Poll one front until its static or WebSocket response arrives.
 *
 * @param what Failure-message label for the probed surface.
 * @param deadline Epoch milliseconds after which readiness fails.
 * @param probe One probe attempt.
 */
export async function waitUntilReady(
  what: string,
  deadline: number,
  probe: () => Promise<boolean>,
): Promise<void> {
  while (Date.now() < deadline) {
    if (await probe()) {
      return
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS))
  }
  throw new Error(`${what} did not become ready within ${READY_TIMEOUT_MS}ms`)
}
