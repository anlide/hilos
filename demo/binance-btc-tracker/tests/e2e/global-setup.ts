import https from 'node:https'

import { clearMail } from './helpers/mail'
import {
  READY_TIMEOUT_MS,
  probeStatic,
  probeWebSocketUpgrade,
  waitUntilReady,
} from './helpers/standReady.js'

/**
 * Blocks until the test stack is fully ready — nginx serves the built
 * artifact AND the daemon answers the WebSocket upgrade through the /ws
 * proxy — so tests never race a stack that `test:e2e-up` brought up moments
 * ago. Plain node https instead of a Playwright request fixture: the probes
 * must tolerate the self-signed certificate before any browser context
 * exists.
 *
 * It also empties the mail interceptor, which outlives a single run: without
 * that, a run reads the letters the previous one sent. Once here and never
 * between tests — every spec coins an address no other one uses, so a
 * per-address read is already isolated from its neighbours, and a mid-run clear
 * would take the letter a parallel worker is still waiting for.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL ?? 'https://localhost:8118'
  const agent = new https.Agent({ rejectUnauthorized: false })
  const deadline = Date.now() + READY_TIMEOUT_MS

  await waitUntilReady(`App at ${baseURL}`, deadline, () =>
    probeStatic(baseURL, agent),
  )
  await waitUntilReady(`WebSocket at ${baseURL}/ws`, deadline, () =>
    probeWebSocketUpgrade(baseURL, agent),
  )
  await clearMail()
}

// Playwright loads this module by the `globalSetup` string path in
// playwright.config.ts and calls the default export — that field only accepts
// a path, so no static import of this symbol can exist and an IDE may report
// the export as unused.
export default globalSetup
