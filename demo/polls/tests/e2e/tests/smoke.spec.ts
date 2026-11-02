import { test, expect } from '@playwright/test'
import {
  HILOS_STATUS_COMPONENTS_COPY,
  statusComponentSchema,
} from '../../../../../framework/frontend/core/src/admin/status/index.js'
import {
  deliveryObject,
  expectPageReady,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  shownByTestId,
  STATUS_COMPONENTS_PATH,
  STATUS_PATH,
  statusPagePayload,
  watchDeliveryFrames,
  watchPublicStatus,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { beatStatusHeartbeat } from '../../../../../framework/frontend/scripts/statusMoments.mjs'

test.use({ colorScheme: 'light', timezoneId: 'Atlantic/Canary' })

// Category-1 smoke (testing-strategy.md): the Angular app renders from the
// built artifact through the docker test stack. Contains, not equals: the root
// is the SDK application shell carrying the brand, the connection indicator, and
// the routed page.
test('blank page renders', async ({ page, browser, baseURL }) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('app-root')).toContainText('Hilos')

  await grantAdminToSelf(expect, page)
  const status = watchStatusFrames(page)
  const log = watchDeliveryFrames(page)
  try {
    await test.step('Status renders the polls instance counts', async () => {
      await gotoPage(expect, page, STATUS_PATH)
      await expect.poll(() => status.latest()).toBeDefined()
      const overview = status.latest()!
      expect(overview.healthKnown).toBe(true)
      expect(overview.components.totalCount).toBe(1)
      await expect(
        page.getByTestId('hilos-status-components-link'),
      ).toContainText(
        `${overview.components.operationalCount} / ${overview.components.totalCount} operational`,
      )
      await expect(page.getByTestId('hilos-status-works-count')).toHaveText(
        String(overview.scheduledWorks!.count),
      )
      await expect(page.getByTestId('hilos-status-history-count')).toHaveText(
        String(overview.historyCount),
      )
    })

    for (const reload of [false, true]) {
      await test.step(
        reload
          ? 'F5 binds Components to a new real page response'
          : 'Components renders the polls fallback from its first window',
        async () => {
          const since = log.packets.length
          if (reload) await page.reload()
          else
            await page
              .getByTestId('hilos-page-tab-hilos_status_components')
              .click()
          await expect(page).toHaveURL(STATUS_COMPONENTS_PATH)
          await expectPageReady(expect, page)
          await expect
            .poll(() =>
              statusPagePayload(log, 'hilos_status_components', since),
            )
            .toBeDefined()
          const payload = statusPagePayload(
            log,
            'hilos_status_components',
            since,
          )!
          const window = deliveryObject(
            deliveryObject(payload.windows).hilosStatusComponents,
          )
          expect(window).toMatchObject({ totalCount: 1, totalExact: true })
          const components = (window.rows as unknown[]).map((row) =>
            statusComponentSchema.parse(
              deliveryObject(deliveryObject(row).slots).component,
            ),
          )
          expect(components).toMatchObject([
            {
              componentKey: 'service',
              name: 'Service',
              description: null,
              agentType: 'hilos_index',
            },
          ])
          const component = components[0]
          const row = shownByTestId(page, 'hilos-table-row-service')
          await expect(row).toContainText(component.name)
          await expect(row).toContainText(component.agentType)
          await expect(
            shownByTestId(page, 'hilos-status-component-state-service'),
          ).toHaveText(HILOS_STATUS_COMPONENTS_COPY.states[component.state])
        },
      )
    }
  } finally {
    status.stop()
    log.stop()
  }

  await test.step('Public Status reads this nginx file pair in the reader zone', async () => {
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
      timezoneId: 'Atlantic/Canary',
    })
    try {
      const guest = await context.newPage()
      const publicLog = watchPublicStatus(guest)
      try {
        const { aliveAt } = await beatStatusHeartbeat()
        await gotoPublicStatus(expect, guest)
        const pair = await expectPublicStatusPair(
          expect,
          guest,
          publicLog,
          (pair) => pair.heartbeat.aliveAt >= aliveAt,
        )
        expect(pair.status.components).toMatchObject([
          { componentKey: 'service', name: 'Service', description: null },
        ])
        await expect(
          guest.getByTestId('public-status-components'),
        ).toContainText(pair.status.components[0].name)
        await expect(
          guest.getByTestId('public-status-component-state'),
        ).toHaveText(
          pair.status.components.map(
            (component) => HILOS_STATUS_COMPONENTS_COPY.states[component.state],
          ),
        )
        expectPublicStatusReadOnly(expect, publicLog)
      } finally {
        await publicLog.stop()
      }
    } finally {
      await context.close()
    }
  })
})
