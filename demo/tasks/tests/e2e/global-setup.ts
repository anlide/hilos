import https from 'node:https'

const READY_TIMEOUT_MS = 120_000
const POLL_INTERVAL_MS = 2_000

/**
 * One readiness probe attempt against the built artifact.
 *
 * @param baseURL Stack origin, e.g. https://tasks-nginx-test.
 * @param agent Shared agent that tolerates the self-signed test certificate.
 */
function probeStatic(baseURL: string, agent: https.Agent): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(baseURL, { method: 'HEAD', agent }, (response) => {
      response.resume()
      response.on('end', () => {
        const status = response.statusCode ?? 0
        resolve(status >= 200 && status < 400)
      })
    })
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * One readiness probe attempt against the daemon behind the nginx /ws
 * WebSocket upgrade proxy. nginx serves the static artifact before the daemon
 * finishes booting (startup migrations), so static readiness alone would let
 * connection-dependent tests race a half-started stack.
 *
 * @param baseURL Stack origin, e.g. https://tasks-nginx-test.
 * @param agent Shared agent that tolerates the self-signed test certificate.
 */
function probeWebSocketUpgrade(baseURL: string, agent: https.Agent): Promise<boolean> {
  return new Promise((resolve) => {
    const request = https.request(`${baseURL}/ws`, {
      agent,
      headers: {
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13',
        // Any 16 bytes in base64 satisfy RFC 6455 for a readiness probe.
        'Sec-WebSocket-Key': Buffer.from('hilos-e2e-ready!').toString('base64'),
      },
    })
    request.on('upgrade', (response, socket) => {
      socket.destroy()
      resolve(response.statusCode === 101)
    })
    request.on('response', (response) => {
      // E.g. a 502 from nginx while the daemon is still starting.
      response.resume()
      resolve(false)
    })
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * Polls one probe until it reports ready or the shared deadline passes.
 *
 * @param what Failure-message label for the probed surface.
 * @param deadline Epoch milliseconds after which readiness is a failure.
 * @param probe One probe attempt; resolves false while the stack is starting.
 */
async function waitUntilReady(
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

/**
 * Blocks until the test stack is fully ready — nginx serves the built
 * artifact AND the daemon answers the WebSocket upgrade through the /ws
 * proxy — so tests never race a stack that `test:e2e-up` brought up moments
 * ago. Plain node https instead of a Playwright request fixture: the probes
 * must tolerate the self-signed certificate before any browser context
 * exists.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL ?? 'https://localhost:8447'
  const agent = new https.Agent({ rejectUnauthorized: false })
  const deadline = Date.now() + READY_TIMEOUT_MS

  await waitUntilReady(`App at ${baseURL}`, deadline, () => probeStatic(baseURL, agent))
  await waitUntilReady(`WebSocket at ${baseURL}/ws`, deadline, () =>
    probeWebSocketUpgrade(baseURL, agent),
  )
}

// Playwright loads this module by the `globalSetup` string path in
// playwright.config.ts and calls the default export — that field only accepts
// a path, so no static import of this symbol can exist and an IDE may report
// the export as unused.
export default globalSetup
