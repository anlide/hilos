import { expect, test, type Locator, type Page } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
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
  const search = tab.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially(key, { delay: 10 })
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toBeVisible()
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
  await expect(tab.getByTestId(`${ROW_ID_PREFIX}${key}`)).toContainText(
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
  const row = tab.getByTestId(`${ROW_ID_PREFIX}${key}`)
  await clearCustomSetting(tab, key)
  await expect(row).toContainText('default')
}

/**
 * Locates the node dropdown option for a specific cluster member.
 *
 * Matches both before and after facet counts arrive: the accessible option name
 * starts with the member identifier followed by either space and the facet count
 * or the end of string.
 *
 * @param filter The node filter locator.
 * @param member The cluster member node identifier.
 */
function nodeOption(filter: Locator, member: string): Locator {
  return filter
    .getByRole('option', { name: new RegExp(`^${member}(\\s|$)`) })
    .or(filter.getByTestId(`hilos-dropdown-option-${member}`))
}

/**
 * Locates the "All nodes" dropdown option.
 *
 * @param filter The node filter locator.
 */
function allNodesOption(filter: Locator): Locator {
  return filter
    .getByRole('option', { name: /^All nodes(\s|$)/ })
    .or(filter.getByTestId('hilos-dropdown-option-all'))
    .or(filter.getByTestId('hilos-dropdown-option--1'))
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

  const filter = page.getByTestId('hilos-table-filter-node')
  const toggle = filter.getByTestId('hilos-dropdown-toggle')
  await toggle.click()
  const allOption = allNodesOption(filter)
  await expect(allOption).toBeVisible({ timeout: TAIL_ARRIVAL_TIMEOUT_MS })
  for (const member of options.members) {
    const memberOption = nodeOption(filter, member)
    await expect(memberOption).toBeVisible({ timeout: TAIL_ARRIVAL_TIMEOUT_MS })
  }
  await toggle.click()

  await expect(page.getByTestId('hilos-table-sort-node')).toBeVisible()
  await expect(page.getByTestId('hilos-table-search')).toHaveAttribute(
    'placeholder',
    options.searchPlaceholder,
  )

  for (const member of options.members) {
    await toggle.click()
    const memberOption = nodeOption(filter, member)
    await memberOption.click()
    await expect
      .poll(
        async () => {
          const keys = await tableRowKeys(page)
          return (
            keys.length >= 1 && keys.every((k) => k.startsWith(`${member}:`))
          )
        },
        { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
      )
      .toBe(true)
  }

  await toggle.click()
  await allOption.click()
  await expect
    .poll(
      async () => {
        const keys = await tableRowKeys(page)
        const prefixes = new Set(keys.map((k) => k.split(':')[0]))
        return keys.length >= 2 && prefixes.size >= 2
      },
      { timeout: TAIL_ARRIVAL_TIMEOUT_MS },
    )
    .toBe(true)
  await expect(page.getByTestId('hilos-table-filter-reset')).toHaveCount(0)
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

// Red in 2 of the 6 full runs since HIL-1347 added it, both on the HIL-1350 leaf: 1056
// failed all three attempts and 1060 passed on the third. Every failed attempt is the
// same - after picking a member in the node filter, the rows did not all turn to that
// member's within 15 s (expectClusterTable, the poll after memberOption.click()). The
// journals of every node of both runs are kept in
// runs/<run>/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on nova-de.
// Parked by the owner on 10.10.2026 (HOTFIX) without a diagnosis.
test.fixme('cluster logs rotations: shows node column, creates batches via max_age, and filters by every cluster member', async ({
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

        const filter = page.getByTestId('hilos-table-filter-node')
        const toggle = filter.getByTestId('hilos-dropdown-toggle')
        await toggle.click()
        const allOption = allNodesOption(filter)
        await expect(allOption).toBeVisible({
          timeout: TAIL_ARRIVAL_TIMEOUT_MS,
        })
        for (const member of members) {
          const memberOption = nodeOption(filter, member)
          await expect(memberOption).toBeVisible({
            timeout: TAIL_ARRIVAL_TIMEOUT_MS,
          })
        }
        await toggle.click()

        for (const member of members) {
          await toggle.click()
          const memberOption = nodeOption(filter, member)
          await memberOption.click()
          await expect
            .poll(async () => (await tableRowKeys(page)).length, {
              timeout: ROTATION_ARRIVAL_TIMEOUT_MS,
            })
            .toBeGreaterThanOrEqual(1)
        }

        await setSetting(control, ROTATION_MAX_AGE, '0')
        await toggle.click()
        await allOption.click()
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
