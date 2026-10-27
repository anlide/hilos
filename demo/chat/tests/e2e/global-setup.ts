import https from 'node:https'

import { clearMail } from '../../../../framework/frontend/scripts/standMailbox.mjs'

import {
  READY_TIMEOUT_MS,
  probeStatic,
  probeWebSocketUpgrade,
  waitUntilReady,
} from '../../../../framework/frontend/scripts/standReady.mjs'

/**
 * Blocks until the test stack is fully ready — nginx serves the built
 * artifact AND the daemon answers the WebSocket upgrade through the /ws
 * proxy — so tests never race a stack that `test:e2e-up` brought up moments
 * ago. Plain node https instead of a Playwright request fixture: the probes
 * must tolerate the self-signed certificate before any browser context
 * exists.
 *
 * Then empties the mail directory: it is a bind mount that outlives the stack, so
 * the run starts against the letters it sends itself and nothing older.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL ?? 'https://localhost:8446'
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
