import { expect, test, type Locator, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
} from '../../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../../helpers/adminGrant.js'
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
import { gotoPage, PAGE_READY } from '../../helpers/page.js'
import { tableRowKeys } from '../../helpers/table.js'

// Red on the first attempt once in the 80 runs after HIL-1506 (0982, one lane) and
// green on its retry: hilos-logs-node-m1 did not show within 5 s. The journals of
// every node of that run are kept in
// runs/0982/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on nova-de.
// Parked by the owner on 09.10.2026 (HOTFIX) without a diagnosis.
test.fixme('the logs overview names every member node', async ({ browser }) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(page)
    await gotoPage(page, '/hilos/logs', PAGE_READY)
    for (const node of [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]) {
      await expect(page.getByTestId(`hilos-logs-node-${node}`)).toBeVisible()
    }
  } finally {
    await page.context().close()
  }
})

test('a slave live file followed from a follower master receives a line appended on that slave', async ({
  browser,
}) => {
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    // The command round trip and remote artifact delivery outlive the base cap.
    test.slow()
    const slave = CLUSTER_SLAVES[0]
    await grantAdminToSelf(page)
    await gotoPage(
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
  } finally {
    await page.context().close()
  }
})

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
  await search.fill('')
  await search.pressSequentially(key, { delay: 10 })
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
  await gotoPage(page, options.path, PAGE_READY)
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
    await grantAdminToSelf(page)
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
    await grantAdminToSelf(page)
    await expectClusterTable(page, {
      path: '/hilos/logs/workers',
      searchPlaceholder: 'Search by key or node…',
      members: [...CLUSTER_MASTERS, ...CLUSTER_SLAVES],
    })
  } finally {
    await page.context().close()
  }
})

test('cluster logs rotations: shows node column, creates batches via max_age, and filters by every cluster member', async ({
  browser,
}) => {
  test.slow()
  const page = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const control = await page.context().newPage()
  rotationControl = control
  const members = [...CLUSTER_MASTERS, ...CLUSTER_SLAVES]
  try {
    await grantAdminToSelf(page)
    await gotoPage(control, '/hilos/settings', PAGE_READY)

    await expectClusterTable(page, {
      path: '/hilos/logs/rotations',
      searchPlaceholder: 'Search by batch date or node…',
      members,
      prepare: async () => {
        await setSetting(control, ROTATION_MAX_AGE, '1')

        for (const member of members) {
          await selectMember(page, members, member)
          await expectMemberRows(
            page,
            member,
            ROTATION_ARRIVAL_TIMEOUT_MS,
            'rotation preparation',
          )
        }

        await setSetting(control, ROTATION_MAX_AGE, '0')
        await resetNodeFilter(page)
      },
    })
  } finally {
    try {
      if (!control.isClosed()) {
        await resetSetting(control, ROTATION_MAX_AGE)
      }
    } finally {
      rotationControl = null
      await page.context().close()
    }
  }
})
