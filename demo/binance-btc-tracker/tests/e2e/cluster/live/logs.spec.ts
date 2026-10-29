import { expect, test, type Locator, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
  watchLogFrames,
  expectLogLineClock,
  expectLogBatchViewer,
  expectLogEpochMoment,
  logBatchDirectory,
  gotoPage,
  PAGE_READY,
  expectPageReady,
  markDocument,
  expectDocumentUnchanged,
  grantAdminToSelf,
  tableRowKeys,
  typeInto,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  CLUSTER_MASTERS,
  CLUSTER_SLAVES,
  CLUSTER_FOLLOWER,
  newMasterPage,
  nodeHost,
} from '../../helpers/cluster.js'
import {
  appendLogLines,
  FOLLOWED_STREAM,
  logMarker,
  ROTATION_ARRIVAL_TIMEOUT_MS,
  TAIL_ARRIVAL_TIMEOUT_MS,
} from '../../helpers/logs.js'
// Red on the first attempt once in the 80 runs after HIL-1506 (0982, one lane) and
// green on its retry: hilos-logs-node-m1 did not show within 5 s. The journals of
// every node of that run are kept in
// runs/0982/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on the shared test box.
// Parked by the owner on 09.10.2026 (HOTFIX) without a diagnosis.
// Quarantine: F-009; original reason and parking date above.
test(
  'the logs overview names every member node',
  { tag: '@quarantine:F-009' },
  async ({ browser }) => {
    const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
    try {
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, '/hilos/logs', PAGE_READY)
      for (const node of [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]) {
        await expect(
          shownByTestId(page, `hilos-table-row-${node}`),
        ).toBeVisible()
      }
    } finally {
      await page.context().close()
    }
  },
)

// FIXME(F-051, 2026-10-29): log append command receives no reply · чужой · попытка 1 · HIL-1754
test(
  'the log nodes window updates slave Live bytes on a follower master and survives reload',
  { tag: '@quarantine:F-051' },
  async ({ browser }) => {
    const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const frames = watchLogFrames(page)
    const members = [...CLUSTER_MASTERS, ...CLUSTER_SLAVES].sort()
    const byteUnits = ['B', 'KB', 'MB', 'GB', 'TB']
    try {
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, page, '/hilos/logs', PAGE_READY)
      await expect
        .poll(() => tableRowKeys(page), { timeout: TAIL_ARRIVAL_TIMEOUT_MS })
        .toEqual(members)
      for (const node of members) {
        await expect(
          shownByTestId(page, `hilos-table-row-${node}`),
        ).toBeVisible()
      }

      const slave = CLUSTER_SLAVES[0]
      const live = shownByTestId(
        shownByTestId(page, `hilos-table-row-${slave}`),
        `hilos-logs-node-live-${slave}`,
      )
      await expect(live).toHaveText(/^\d+(?:\.\d+)? (?:B|KB|MB|GB|TB)$/)
      const [beforeValue, beforeUnit] = (await live.innerText())
        .trim()
        .split(/\s+/)
      const beforeBytes =
        Number(beforeValue) * 1024 ** byteUnits.indexOf(beforeUnit)
      await markDocument(page)

      // Make growth visible even when the byte label rounds to one decimal place.
      const marker = `${logMarker('cluster-node-live')} ${'x'.repeat(1024)}`
      const lines = 128
      expect(await appendLogLines(marker, lines, nodeHost(slave))).toBe(lines)
      await expect
        .poll(
          async () => {
            const [value, unit] = (await live.innerText()).trim().split(/\s+/)
            return Number(value) * 1024 ** byteUnits.indexOf(unit)
          },
          { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
        )
        .toBeGreaterThan(beforeBytes)
      await expectDocumentUnchanged(expect, page)

      await page.reload()
      await expectPageReady(expect, page)
      await expect
        .poll(() => tableRowKeys(page), { timeout: TAIL_ARRIVAL_TIMEOUT_MS })
        .toEqual(members)
      for (const node of members) {
        await expect(
          shownByTestId(page, `hilos-table-row-${node}`),
        ).toBeVisible()
      }
      await expect(live).toHaveText(/^\d+(?:\.\d+)? (?:B|KB|MB|GB|TB)$/)
    } finally {
      frames.stop()
      await test.info().attach('log-nodes-window-frames', {
        body: JSON.stringify({ packets: frames.packets, sent: frames.sent }),
        contentType: 'application/json',
      })
      await page.context().close()
    }
  },
)

// FIXME(F-052, 2026-10-29): slave live tail badge does not appear · чужой · попытка 1 · HIL-1754
test(
  'a slave live file followed from a follower master receives a line appended on that slave',
  { tag: '@quarantine:F-052' },
  async ({ browser }) => {
    const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const frames = watchLogFrames(page)
    try {
      // The command round trip and remote artifact delivery outlive the base cap.
      test.slow()
      const slave = CLUSTER_SLAVES[0]
      await grantAdminToSelf(expect, page)
      await gotoPage(
        expect,
        page,
        `/hilos/logs/view/${slave}/live/${FOLLOWED_STREAM}`,
        PAGE_READY,
      )
      await expect(page.getByTestId('hilos-log-tail-badge')).toBeVisible()

      const marker = logMarker('cluster-follow')
      expect(await appendLogLines(marker, 1, nodeHost(slave))).toBe(1)
      await expect(
        page.getByTestId('hilos-log-entry').filter({ hasText: `${marker} #1` }),
      ).toBeVisible({
        timeout: TAIL_ARRIVAL_TIMEOUT_MS,
      })
      const timeZone = await page.evaluate(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
      )
      await expectLogLineClock(
        expect,
        page,
        frames,
        marker,
        'logs_lines_appended',
        timeZone,
      )
    } finally {
      frames.stop()
      await test.info().attach('slave-follow-time-frames', {
        body: JSON.stringify({ packets: frames.packets, sent: frames.sent }),
        contentType: 'application/json',
      })
      await page.context().close()
    }
  },
)

const ROTATION_MAX_AGE = 'logs.rotation.max_age_seconds'
const ROW_ID_PREFIX = 'hilos-table-row-'

let rotationControl: Page | null = null

test.afterEach(async () => {
  const tab = rotationControl
  rotationControl = null
  if (tab !== null && !tab.isClosed()) {
    try {
      await resetSetting(tab, ROTATION_MAX_AGE)
    } catch {
      // Best-effort cleanup when the tab or stand failed
    }
  }
})

/**
 * Narrow the server window to one settings key so assertions ignore pagination.
 *
 * @param tab The settings tab.
 * @param key Catalog key to isolate.
 */
async function isolate(tab: Page, key: string): Promise<void> {
  const search = shownByTestId(tab, 'hilos-table-search')
  await typeInto(search, key)
  await expect(shownByTestId(tab, `${ROW_ID_PREFIX}${key}`)).toBeVisible()
}

/**
 * Write a custom value for one catalog key, the way a person would.
 *
 * @param tab The settings tab.
 * @param key Catalog key to write.
 * @param value Value to write, as the field takes it.
 */
async function setSetting(
  tab: Page,
  key: string,
  value: string,
): Promise<void> {
  await isolate(tab, key)
  await setCustomSetting(tab, key, value)

  await expect(tab.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await expect(shownByTestId(tab, `${ROW_ID_PREFIX}${key}`)).toContainText(
    'custom',
  )
}

/**
 * Return one key to the catalog default by taking its custom value away.
 *
 * @param tab The settings tab.
 * @param key Catalog key to reset.
 */
async function resetSetting(tab: Page, key: string): Promise<void> {
  await isolate(tab, key)
  const row = shownByTestId(tab, `${ROW_ID_PREFIX}${key}`)
  await clearCustomSetting(tab, key)
  await expect(row).toContainText('default')
}

/**
 * Wait for the complete sorted node list before trusting its indexed data ids.
 *
 * A partial cluster picture can give the same index to another node. Facet
 * counts may follow the node name without changing which option it represents.
 *
 * @param filter The visible, open node filter.
 * @param members Every expected cluster member.
 */
async function expectNodeOptions(
  filter: Locator,
  members: readonly string[],
): Promise<void> {
  await expect(shownByTestId(filter, 'hilos-dropdown-option--1')).toBeVisible({
    timeout: TAIL_ARRIVAL_TIMEOUT_MS,
  })
  await expect(
    shownByTestId(filter, /^hilos-dropdown-option-\d+$/),
  ).toHaveCount(members.length, { timeout: TAIL_ARRIVAL_TIMEOUT_MS })
  for (const [index, member] of [...members].sort().entries()) {
    await expect(
      shownByTestId(filter, `hilos-dropdown-option-${index}`),
    ).toHaveAccessibleName(new RegExp(`^${member}(\\s|$)`), {
      timeout: TAIL_ARRIVAL_TIMEOUT_MS,
    })
  }
}

/**
 * Select a node by its data id after checking the complete option mapping.
 *
 * @param page The follower master tab showing the table.
 * @param members Every expected cluster member.
 * @param member The cluster member to select.
 */
async function selectMember(
  page: Page,
  members: readonly string[],
  member: string,
): Promise<void> {
  const filter = shownByTestId(page, 'hilos-table-filter-node')
  await shownByTestId(filter, 'hilos-dropdown-toggle').click()
  await expectNodeOptions(filter, members)
  const index = [...members].sort().indexOf(member)
  expect(index).toBeGreaterThanOrEqual(0)
  await shownByTestId(filter, `hilos-dropdown-option-${index}`).click()
}

/**
 * Wait for a nonempty window containing only the selected member's rows.
 *
 * A positive length alone can accept the previous member's window while the
 * new filter request is still in flight, ending rotation preparation too soon.
 *
 * @param page The follower master tab showing the table.
 * @param member The cluster member whose rows must arrive.
 * @param timeout The existing arrival budget for this stage.
 * @param stage The stage named in a failed wait's diagnostic message.
 */
async function expectMemberRows(
  page: Page,
  member: string,
  timeout: number,
  stage: 'rotation preparation' | 'node filter',
): Promise<void> {
  await expect
    .poll(
      async () => {
        const keys = await tableRowKeys(page)
        return (
          keys.length > 0 && keys.every((key) => key.startsWith(`${member}:`))
        )
      },
      {
        timeout,
        message: `${stage}: waiting for rows exclusively from ${member}`,
      },
    )
    .toBe(true)
}

/**
 * Press Reset filters and settle on a window containing multiple nodes.
 *
 * @param page The follower master tab showing the table.
 */
async function resetNodeFilter(page: Page): Promise<void> {
  await shownByTestId(page, 'hilos-table-filter-reset').click()
  await expect
    .poll(
      async () => {
        const keys = await tableRowKeys(page)
        const prefixes = new Set(keys.map((key) => key.split(':')[0]))
        return keys.length >= 2 && prefixes.size >= 2
      },
      { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
    )
    .toBe(true)
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)
}

/**
 * Verifies common cluster table behavior: node column sort header, cluster-aware
 * search placeholder, node filter containing every cluster member, single-node filtering,
 * and resetting the filter to show rows from multiple nodes.
 *
 * @param page The follower master tab showing the table.
 * @param options Target path, expected search placeholder, cluster members, and optional setup.
 */
async function expectClusterTable(
  page: Page,
  options: {
    path: string
    searchPlaceholder: string
    members: readonly string[]
    prepare?: () => Promise<void>
  },
): Promise<void> {
  await gotoPage(expect, page, options.path, PAGE_READY)
  await options.prepare?.()

  const filter = shownByTestId(page, 'hilos-table-filter-node')
  const toggle = shownByTestId(filter, 'hilos-dropdown-toggle')
  await toggle.click()
  await expectNodeOptions(filter, options.members)
  await toggle.click()

  await expect(shownByTestId(page, 'hilos-table-sort-node')).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-search')).toHaveAttribute(
    'placeholder',
    options.searchPlaceholder,
  )

  for (const member of options.members) {
    await selectMember(page, options.members, member)
    await expectMemberRows(page, member, TAIL_ARRIVAL_TIMEOUT_MS, 'node filter')
  }

  await resetNodeFilter(page)
}

test('cluster logs keys: shows node column and filters by every cluster member', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(expect, page)
    await expectClusterTable(page, {
      path: '/hilos/logs/keys',
      searchPlaceholder: 'Search by key (agent-*) or node…',
      members: [...CLUSTER_MASTERS, ...CLUSTER_SLAVES],
    })
  } finally {
    await page.context().close()
  }
})

test('cluster logs workers: shows node column and filters by every cluster member', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(expect, page)
    await expectClusterTable(page, {
      path: '/hilos/logs/workers',
      searchPlaceholder: 'Search by key or node…',
      members: [...CLUSTER_MASTERS, ...CLUSTER_SLAVES],
    })
  } finally {
    await page.context().close()
  }
})

// FIXME(F-011, 2026-10-25): Timeout ожидания hilos-log-entry при ротации логов слейва · чужой · попытка 2 · HIL-1591
test(
  'cluster logs rotations: shows node column, creates batches via max_age, and filters by every cluster member',
  { tag: '@quarantine:F-011' },
  async ({ browser }) => {
    test.slow()
    const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const control = await page.context().newPage()
    const frames = watchLogFrames(page)
    const viewer = await page.context().newPage()
    const viewerFrames = watchLogFrames(viewer)
    rotationControl = control
    const members = [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]
    let slaveBatch: Record<string, unknown> | undefined
    try {
      await grantAdminToSelf(expect, page)
      await gotoPage(expect, control, '/hilos/settings', PAGE_READY)

      await expectClusterTable(page, {
        path: '/hilos/logs/rotations',
        searchPlaceholder: 'Search by batch date or node…',
        members,
        prepare: async () => {
          await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
          const before = frames.rotations()
          const newestBefore = Math.max(
            0,
            ...[...before.values()].map((row) =>
              expectLogEpochMoment(expect, row.batchAt),
            ),
          )
          await setSetting(control, ROTATION_MAX_AGE, '1')

          for (const member of members) {
            await selectMember(page, members, member)
            await expectMemberRows(
              page,
              member,
              ROTATION_ARRIVAL_TIMEOUT_MS,
              'rotation preparation',
            )
            await expect
              .poll(
                () =>
                  [...frames.rotations()].some(
                    ([key, row]) =>
                      row.node === member &&
                      !before.has(key) &&
                      typeof row.batchAt === 'number' &&
                      row.batchAt > newestBefore,
                  ),
                { timeout: ROTATION_ARRIVAL_TIMEOUT_MS },
              )
              .toBe(true)
            if (member === CLUSTER_SLAVES[0]) {
              slaveBatch = [...frames.rotations()].find(
                ([key, row]) =>
                  row.node === member &&
                  !before.has(key) &&
                  typeof row.batchAt === 'number' &&
                  row.batchAt > newestBefore,
              )?.[1]
            }
          }

          await setSetting(control, ROTATION_MAX_AGE, '0')
          await resetNodeFilter(page)
        },
      })
      expect(slaveBatch).toBeDefined()
      if (slaveBatch === undefined)
        throw new Error('No new slave rotation was observed')
      const batchAt = expectLogEpochMoment(expect, slaveBatch.batchAt)
      expect(slaveBatch.path).toEqual(
        expect.stringContaining(logBatchDirectory(batchAt)),
      )
      await gotoPage(
        expect,
        viewer,
        `/hilos/logs/view/${slaveBatch.node}/${batchAt}/daemon.log`,
        PAGE_READY,
      )
      await expect(viewer.getByTestId('hilos-log-entry').first()).toBeVisible({
        timeout: TAIL_ARRIVAL_TIMEOUT_MS,
      })
      const timeZone = await viewer.evaluate(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
      )
      await expectLogBatchViewer(
        expect,
        viewer,
        viewerFrames,
        slaveBatch,
        timeZone,
      )
    } finally {
      frames.stop()
      viewerFrames.stop()
      await test.info().attach('cluster-rotation-time-frames', {
        body: JSON.stringify({
          rotations: frames.packets,
          viewer: viewerFrames.packets,
          sent: viewerFrames.sent,
        }),
        contentType: 'application/json',
      })
      try {
        if (!control.isClosed()) {
          await resetSetting(control, ROTATION_MAX_AGE)
        }
      } finally {
        rotationControl = null
        await page.context().close()
      }
    }
  },
)
