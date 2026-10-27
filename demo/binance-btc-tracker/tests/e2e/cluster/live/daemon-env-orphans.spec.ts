import { expect, test, type Page } from '@playwright/test'

import {
  countSentFrames,
  deliveryObject,
  watchSocketFrames,
  type SocketFrame,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  newMasterPage,
  placementNode,
} from '../../helpers/cluster.js'

const ORPHANS_PAGE = 'hilos_daemon_env_orphans'
const ORPHANS_WINDOW = 'hilosDaemonEnvOrphans'

/** Page answers for the orphans tab, in arrival order. */
function orphanAnswers(frames: readonly SocketFrame[]): SocketFrame[] {
  return frames.filter((frame) => {
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    return (
      frame.failure === undefined &&
      wire.type === 'page_response' &&
      data.page === ORPHANS_PAGE
    )
  })
}

/**
 * Open one live node's orphans tab and read its first answer.
 *
 * An empty live window does not prove the cold window. The orphans page unit
 * owns the non-empty cold answer.
 */
async function readLiveOrphans(
  page: Page,
  nodeId: string,
  received: SocketFrame[],
  actions: () => number,
): Promise<void> {
  const before = received.length
  const sent = actions()
  await gotoPage(
    expect,
    page,
    `/hilos/daemon/${nodeId}/env/orphans`,
    PAGE_READY,
  )
  await expect(page).toHaveURL(
    new RegExp(`/hilos/daemon/${nodeId}/env/orphans$`),
  )
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(nodeId)
  await expect(
    page.getByTestId('hilos-page-tab-hilos_daemon_env_orphans'),
  ).toHaveAttribute('aria-current', 'page')
  const answers = orphanAnswers(received.slice(before))
  expect(answers.length).toBeGreaterThan(0)
  const payload = deliveryObject(
    deliveryObject(deliveryObject(answers[0]?.wire).data).payload,
  )
  expect(deliveryObject(payload.data).nodeSilent).toBe(false)
  expect(deliveryObject(payload.windows)).toHaveProperty(ORPHANS_WINDOW)
  expect(actions()).toBe(sent)
}

test('env orphans on known live nodes arrive in the first page response', async ({
  browser,
}) => {
  // One subscription per known member, each a real round trip.
  // The index agent is addressed as type:node, so placementNode's exact id
  // never matches it. The settings library is one started unindexed agent.
  test.slow()
  const started = await placementNode('hilos_settings_library')
  const nodes = [...new Set([...CLUSTER_MASTERS, ...CLUSTER_SLAVES])]
  expect(nodes).toContain(started)
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const received: SocketFrame[] = []
  const watch = watchSocketFrames(page, (frame) => {
    received.push(frame)
  })
  const actions = countSentFrames(page, '"type":"action"')
  try {
    await grantAdminToSelf(expect, page)
    for (const nodeId of nodes) {
      await readLiveOrphans(page, nodeId, received, actions)
    }
  } finally {
    watch.stop()
    await test.info().attach('env-orphans-page-responses', {
      body: JSON.stringify(orphanAnswers(received).map((frame) => frame.wire)),
      contentType: 'application/json',
    })
    await page.context().close()
  }
})
