import https from 'node:https'

import {
  READY_TIMEOUT_MS,
  probeStatic,
  probeWebSocketUpgrade,
  waitUntilReady,
} from '../../../../../framework/frontend/scripts/standReady.mjs'
import { readClusterBrowserStand } from '../../../../../framework/frontend/e2e/index.js'
import { clearMail } from '../../../../../framework/frontend/scripts/standMailbox.mjs'

/** Wait for the Angular shell and every master's WebSocket before registration. */
export default async function globalSetup(): Promise<void> {
  const stand = readClusterBrowserStand()
  const agent = new https.Agent({ rejectUnauthorized: false })
  const deadline = Date.now() + READY_TIMEOUT_MS
  try {
    await waitUntilReady(`App at ${stand.baseURL}`, deadline, () =>
      probeStatic(stand.baseURL, agent),
    )
    for (const node of stand.masters) {
      await waitUntilReady(
        `WebSocket of ${node} through the entry`,
        deadline,
        () => probeWebSocketUpgrade(stand.baseURL, agent, node),
      )
    }
    await clearMail()
  } finally {
    agent.destroy()
  }
}
