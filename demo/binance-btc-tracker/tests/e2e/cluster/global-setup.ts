import https from 'node:https'

import { clearMail } from '../helpers/mail.js'
import {
  READY_TIMEOUT_MS,
  probeStatic,
  probeWebSocketUpgrade,
  waitUntilReady,
} from '../helpers/standReady.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  BASE_URL,
} from '../helpers/cluster.js'

/** The shared entry and every master behind it must answer before the browser starts. */
async function globalSetup(): Promise<void> {
  const agent = new https.Agent({ rejectUnauthorized: false })
  const deadline = Date.now() + READY_TIMEOUT_MS
  await waitUntilReady(`App at ${BASE_URL}`, deadline, () =>
    probeStatic(BASE_URL, agent),
  )
  for (const node of CLUSTER_MASTERS) {
    await waitUntilReady(
      `WebSocket of ${node} through the entry`,
      deadline,
      () => probeWebSocketUpgrade(BASE_URL, agent, node),
    )
  }
  await clearMail()

  const view = await clusterInspect(CLUSTER_FOLLOWER)
  if (view.leaderId !== CLUSTER_LEADER) {
    throw new Error(
      `leadership moved from ${CLUSTER_LEADER} to ${view.leaderId} before the suite started (P-459)`,
    )
  }
}

export default globalSetup
