import { expect, test } from '@playwright/test'

import {
  armSocketDrop,
  dropSocket,
  expectClusterEnvironmentMismatch,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  PAGE_READY,
  watchDaemonMismatchFrames,
} from '../../../../../../framework/frontend/e2e/index.js'
import { CLUSTER_FOLLOWER, newMasterPage } from '../../helpers/cluster.js'

test('cluster env mismatch: one diverged table survives reload and reconnect', async ({
  browser,
}) => {
  // Read at execution time: native collection runs before the host fixture starts.
  const remoteNodeId = process.env.CLUSTER_ENV_MISMATCH_NODE
  const key = process.env.CLUSTER_ENV_MISMATCH_KEY
  const value = process.env.CLUSTER_ENV_MISMATCH_VALUE
  if (!remoteNodeId || !key || !value) {
    throw new Error(
      'Run through test:cluster:e2e with its environment mismatch fixture',
    )
  }
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const log = watchDaemonMismatchFrames(page)
  try {
    await armSocketDrop(page)
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, '/hilos/daemon/env-mismatch', PAGE_READY)
    await expectClusterEnvironmentMismatch(expect, page, {
      key,
      remoteNodeId,
      frames: log.frames,
    })

    const reload = log.frames.length
    await page.reload()
    await expectPageReady(expect, page)
    await expectClusterEnvironmentMismatch(expect, page, {
      key,
      remoteNodeId,
      frames: log.frames,
      after: reload,
    })

    const reconnect = log.frames.length
    await dropSocket(page)
    await expectClusterEnvironmentMismatch(expect, page, {
      key,
      remoteNodeId,
      frames: log.frames,
      after: reconnect,
    })
  } finally {
    await test.info().attach('daemon-env-mismatch-frames', {
      body: JSON.stringify({ remoteNodeId, key, frames: log.frames }),
      contentType: 'application/json',
    })
    log.stop()
    await page.context().close()
  }
})
