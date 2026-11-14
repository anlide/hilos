import type { Page } from '@playwright/test'
import { test, expect } from '../test.js'
import {
  PAGE_READY,
  STATUS_COMPONENTS_PATH,
  STATUS_PATH,
  deliveryObject,
  expectPageReady,
  expectPublicStatusNotMonitoredDays,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectTableTotal,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  shownByTestId,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { beatStatusHeartbeat } from '../../../../../framework/frontend/scripts/statusMoments.mjs'

// Category-1 smoke (testing-strategy.md): the React app renders from the built
// artifact through the docker test stack. Contains, not equals: the root is the
// SDK application shell carrying the brand, the connection indicator, and the
// routed page. The same path then reads this project's Status: the public reader
// shows the files this daemon has just published, and two lazy admin views show
// their own first answers at a cold address and after F5. The Status journeys
// themselves are proved by the ecommerce-shop specs and are not repeated here.

// Picked at random from the IANA list when these steps were written: the stand
// runs this project in Europe/Warsaw, so a reader-zone slip cannot hide behind it.
test.use({ timezoneId: 'America/Cuiaba' })

// This project declares no Status components: its catalog is the framework's
// single fallback, the project's index agent published as Service.
const SERVICE = {
  componentKey: 'service',
  name: 'Service',
  description: null,
  agentType: 'hilos_index',
} as const

/**
 * Open the address cold, then press F5 on it; after each, wait for the page's
 * next answer and check what the screen shows against it.
 */
async function coldAndReloaded<T>(
  page: Page,
  path: string,
  answers: readonly T[],
  check: (answer: T) => Promise<void>,
): Promise<void> {
  for (const open of [
    () => gotoPage(expect, page, path, PAGE_READY),
    async () => {
      await page.reload()
      await expectPageReady(expect, page)
    },
  ]) {
    const since = answers.length
    await open()
    await expect(page).toHaveURL(new RegExp(`${path}$`))
    await expect.poll(() => answers.length).toBeGreaterThan(since)
    await check(answers[since])
  }
}

test('the app renders, and Status shows this project from its first answers and its published files', async ({
  page,
}) => {
  await test.step('the blank page renders', async () => {
    await gotoPage(expect, page, '/')
    await expect(page.getByTestId('app-root')).toContainText('Hilos')
  })

  await test.step('public /status reads the files this project has just published', async () => {
    const log = watchPublicStatus(page)
    try {
      const { aliveAt } = await beatStatusHeartbeat()
      await gotoPublicStatus(expect, page)
      const accepted = await expectPublicStatusPair(
        expect,
        page,
        log,
        (pair) => pair.heartbeat.aliveAt >= aliveAt,
      )
      const { status, heartbeat } = accepted
      expect(status.components).toEqual([
        {
          componentKey: SERVICE.componentKey,
          name: SERVICE.name,
          description: SERVICE.description,
          state: expect.any(String),
          observedSince: expect.any(Number),
        },
      ])
      // The owner came under observation on this stand, before the pair it published.
      const [component] = status.components
      expect(
        'observedSince' in component ? component.observedSince : null,
      ).toBeLessThanOrEqual(heartbeat.aliveAt)
      await expectPublicStatusNotMonitoredDays(expect, page, accepted)
      // This stand came under observation inside the 90-day window, so the oracle cannot pass on zero empty days.
      await expect(
        page
          .getByTestId('public-status-days-90')
          .getByRole('button', { name: / · Not monitored yet$/ }),
      ).not.toHaveCount(0)
      await expect(page.getByTestId('public-status-components')).toContainText(
        SERVICE.name,
      )
      expectPublicStatusReadOnly(expect, log)
    } finally {
      await log.stop()
    }
  })

  await grantAdminToSelf(expect, page)
  const status = watchStatusFrames(page)
  try {
    await test.step('the Overview shows its first answer at its address and after F5', async () => {
      await coldAndReloaded(
        page,
        STATUS_PATH,
        status.overviews,
        async ({ components, historyCount }) => {
          expect(components.totalCount).toBe(1)
          await expect(
            page.getByTestId('hilos-status-components-link'),
          ).toContainText(`${components.operationalCount} / 1 operational`)
          expect(historyCount).not.toBeNull()
          await expect(
            page.getByTestId('hilos-status-history-count'),
          ).toHaveText(String(historyCount))
        },
      )
    })

    await test.step('Components shows its first window at its address and after F5', async () => {
      await coldAndReloaded(
        page,
        STATUS_COMPONENTS_PATH,
        status.components,
        async ({ rows, totalCount }) => {
          expect(totalCount).toBe(1)
          const [row] = (Array.isArray(rows) ? rows : []).map(deliveryObject)
          expect(deliveryObject(row.slots).component).toEqual({
            ...SERVICE,
            state: expect.any(String),
          })
          await expectTableTotal(expect, page, 1)
          const table = page.getByTestId('hilos-status-components-table')
          await expect(
            shownByTestId(
              table,
              `hilos-status-component-${SERVICE.componentKey}`,
            ),
          ).toContainText(SERVICE.name)
          await expect(
            shownByTestId(table, `hilos-table-row-${SERVICE.componentKey}`),
          ).toContainText(SERVICE.agentType)
        },
      )
    })
  } finally {
    status.stop()
  }
})
