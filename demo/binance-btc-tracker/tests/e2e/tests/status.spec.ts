import { expect, test } from '@playwright/test'
import {
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  STATUS_COMPONENTS_PATH,
  STATUS_LEAD,
  deliveryObject,
  dismissToasts,
  expectStatusHead,
  shownByTestId,
  submitStatusIncident,
  watchStatusFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant.js'
import { armViewerSubmit, setAdminViewMode } from '../helpers/adminViewMode.js'
import {
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
} from '../helpers/page.js'
import {
  STATUS_FAULT_COMPONENT,
  withStatusAgentLoss,
} from '../helpers/status.js'

// Real browser/daemon Status contract. The stand's test-only catalog observes
// the settings library; production still uses its ordinary Service fallback.
test.use({ colorScheme: 'light' })
test.afterEach(() => setAdminViewMode(false))

test('Status direct load, F5, Dashboard, shared head and Components use complete real page answers', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  const log = watchStatusFrames(page)
  try {
    await gotoPage(page, STATUS_PATH, PAGE_READY)
    await expectStatusHead(expect, page)
    expect(log.overviews.length).toBeGreaterThan(0)
    expect(log.overviews[0]).toMatchObject({
      components: { totalCount: 1 },
      publishDelaySeconds: 300,
      maintenance: null,
    })
    expect(deliveryObject(log.replies[0].data)).toMatchObject({
      pageHeadOwner: {
        page: 'hilos_status',
        label: 'Status',
        lead: STATUS_LEAD,
      },
      pageTabs: [
        { page: 'hilos_status', label: 'Overview' },
        { page: 'hilos_status_components', label: 'Components' },
      ],
    })
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    expect(log.latest()).toMatchObject({
      healthKnown: true,
      components: { operationalCount: 1, totalCount: 1 },
      incidents: [],
    })
    const beforeReload = log.overviews.length
    await page.reload()
    await expectPageReady(page)
    await expectStatusHead(expect, page)
    expect(log.overviews.length).toBeGreaterThan(beforeReload)

    await gotoPage(page, '/hilos', PAGE_READY)
    const card = page.getByTestId('dashboard-card-hilos_status')
    await expect(card).toContainText(STATUS_LEAD)
    await expect(card).toHaveAttribute('href', STATUS_PATH)
    await card.click()
    await expectPageReady(page)
    await expectStatusHead(expect, page)
    await page.getByTestId('hilos-status-components-link').click()
    await expectPageReady(page)
    await expect(page).toHaveURL(new RegExp(`${STATUS_COMPONENTS_PATH}$`))
    await expectStatusHead(expect, page, true)
    expect(log.components[0]).toHaveProperty('rows')
    const component = shownByTestId(
      page.getByTestId('hilos-status-components-table'),
      `hilos-status-component-${STATUS_FAULT_COMPONENT}`,
    )
    await expect(component).toContainText('Settings library')
    await expect(
      shownByTestId(
        page,
        `hilos-status-component-state-${STATUS_FAULT_COMPONENT}`,
      ),
    ).toHaveText('Operational')
    await expect(page.getByTestId(/^hilos-table-sort-/)).toHaveCount(0)
    const search = page.getByTestId('hilos-table-search')
    await search.fill('')
    await search.pressSequentially('no-status-component', { delay: 10 })
    await expect(component).toHaveCount(0)
    await search.fill('')
    await search.pressSequentially('hilos_settings_library', { delay: 10 })
    await expect(component).toBeVisible()
    await search.fill('')
    await expect(component).toBeVisible()
    const filter = page.getByTestId('hilos-table-filter-state')
    await filter.getByTestId('hilos-dropdown-toggle').click()
    // The select adapter addresses declared values by index: major_outage=3,
    // operational=0 (statusComponentStateSchema declaration order).
    await filter.getByTestId('hilos-dropdown-option-3').click()
    await expect(component).toHaveCount(0)
    await filter.getByTestId('hilos-dropdown-toggle').click()
    await filter.getByTestId('hilos-dropdown-option-0').click()
    await expect(component).toBeVisible()
    await page.getByTestId('hilos-page-tab-hilos_status').click()
    await expectPageReady(page)
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await page.getByTestId('hilos-status-settings-link').click()
    await expectPageReady(page)
    await expect(page).toHaveURL(/\/hilos\/settings$/)
  } finally {
    log.stop()
  }
})

// FIXME(F-032, 2026-10-25): Workers observation times out before Status fault injection · свой · попытка 2 · HIL-1586
test(
  'real agent loss creates a candidate and cancellation echoes to a second administrator without reload',
  { tag: '@quarantine:F-032' },
  async ({ page, browser, baseURL }) => {
    // Two independent administrator sessions, the real roster fanout and recovery.
    test.slow()
    await grantAdminToSelf(page)
    const otherContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      colorScheme: 'light',
    })
    const other = await otherContext.newPage()
    const workers = await page.context().newPage()
    const log = watchStatusFrames(page)
    const echo = watchStatusFrames(other)
    try {
      await grantAdminToSelf(other)
      await gotoPage(page, STATUS_PATH, PAGE_READY)
      await gotoPage(other, STATUS_PATH, PAGE_READY)
      await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
      await markDocument(other)
      await withStatusAgentLoss(workers, log, async () => {
        await expect
          .poll(() => log.latest()?.incidents?.length, {
            timeout: RESTART_MARK_TIMEOUT_MS,
          })
          .toBe(1)
        const incident = log.latest()!.incidents![0]
        expect(incident).toMatchObject({
          componentKey: STATUS_FAULT_COMPONENT,
          agentType: 'hilos_settings_library',
          severity: 'major_outage',
          publishedAt: null,
          suppressedAt: null,
          expectedInstances: 1,
          liveInstances: 0,
          updates: [],
        })
        expect(incident.publishAt - incident.detectedAt).toBe(300_000)
        await expect(
          page.getByTestId(`hilos-status-countdown-${incident.incidentId}`),
        ).toBeVisible()
        await expect(
          other.getByTestId(`hilos-status-countdown-${incident.incidentId}`),
        ).toBeVisible()
        await page
          .getByTestId(`hilos-status-cancel-publication-${incident.incidentId}`)
          .click()
        await submitStatusIncident(
          expect,
          page,
          log,
          'hilos_status_cancel_publication',
          incident.incidentId,
        )
        for (const admin of [page, other]) {
          await expect(
            admin.getByTestId(`hilos-status-suppressed-${incident.incidentId}`),
          ).toHaveText('Publication canceled manually')
          await expect(
            admin.getByTestId(`hilos-status-incident-${incident.incidentId}`),
          ).toContainText('Checking')
          await expect(
            admin.getByTestId(`hilos-status-countdown-${incident.incidentId}`),
          ).toHaveCount(0)
          await expect(
            admin.getByTestId(
              `hilos-status-cancel-publication-${incident.incidentId}`,
            ),
          ).toHaveCount(0)
          await expect(
            admin.getByTestId(
              `hilos-status-publish-now-${incident.incidentId}`,
            ),
          ).toHaveCount(0)
          await expect(
            admin.getByTestId('hilos-status-operational'),
          ).toHaveCount(0)
        }
        expect(echo.latest()?.incidents?.[0]).toMatchObject({
          incidentId: incident.incidentId,
          publishAt: incident.publishAt,
          publishedAt: null,
          suppressedAt: expect.any(Number),
        })
        await expectDocumentUnchanged(other)
      })
      await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
      await expect(other.getByTestId('hilos-status-operational')).toBeVisible()
      expect(log.latest()?.incidents).toEqual([])
    } finally {
      await test.info().attach('status-cancel-real-wire', {
        body: JSON.stringify({
          admin: log.frames,
          adminSent: log.sent,
          secondAdmin: echo.frames,
        }),
        contentType: 'application/json',
      })
      log.stop()
      echo.stop()
      await workers.close()
      await otherContext.close()
    }
  },
)

test('real publication and plain-text update are acknowledged, live, masked for viewers and reject forged writes', async ({
  page,
  browser,
  baseURL,
}) => {
  // The real stop/collector path, mutation acks, viewer delivery and recovery.
  test.slow()
  await grantAdminToSelf(page)
  await setAdminViewMode(true)
  const viewerContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const viewer = await viewerContext.newPage()
  const forged = await armViewerSubmit(viewer)
  const log = watchStatusFrames(page)
  const viewerLog = watchStatusFrames(viewer)
  const workers = await page.context().newPage()
  const text = `<b>Status ${Date.now()}</b> & plain text`
  let incidentId: number | undefined
  try {
    await gotoPage(page, STATUS_PATH, PAGE_READY)
    await gotoPage(viewer, STATUS_PATH, PAGE_READY)
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await expect(viewer.getByTestId('view-mode-banner')).toBeVisible()
    await markDocument(viewer)
    await expect
      .poll(() => viewerLog.latest())
      .toMatchObject({
        healthKnown: true,
        components: { operationalCount: 1, totalCount: 1 },
        maintenance: null,
        incidents: [],
      })
    await withStatusAgentLoss(workers, log, async () => {
      await expect
        .poll(() => log.latest()?.incidents?.length, {
          timeout: RESTART_MARK_TIMEOUT_MS,
        })
        .toBe(1)
      incidentId = log.latest()!.incidents![0].incidentId
      expect(log.latest()!.incidents![0]).toMatchObject({
        componentKey: STATUS_FAULT_COMPONENT,
        expectedInstances: 1,
        liveInstances: 0,
        observedAt: expect.any(Number),
        publishedAt: null,
        suppressedAt: null,
      })
      for (const operation of ['cancel-publication', 'publish-now']) {
        await expect(
          viewer.getByTestId(`hilos-status-${operation}-${incidentId}`),
        ).toBeDisabled()
      }
      await page.getByTestId(`hilos-status-publish-now-${incidentId}`).click()
      await submitStatusIncident(
        expect,
        page,
        log,
        'hilos_status_publish_now',
        incidentId,
      )
      await expect(
        page.getByTestId(`hilos-status-timeline-${incidentId}`),
      ).toContainText('Published')
      await dismissToasts(page)
      await page.getByTestId(`hilos-status-add-update-${incidentId}`).click()
      const field = page.getByTestId('hilos-status-update-text')
      await field.fill('')
      await field.pressSequentially(text, { delay: 10 })
      await submitStatusIncident(
        expect,
        page,
        log,
        'hilos_status_add_update',
        incidentId,
      )
      const timeline = page.getByTestId(`hilos-status-timeline-${incidentId}`)
      await expect(timeline).toContainText(text)
      await expect(timeline.locator('b')).toHaveCount(0)
      await expect(
        viewer.getByTestId(`hilos-status-add-update-${incidentId}`),
      ).toBeDisabled()
      await expect
        .poll(() => viewerLog.latest()?.incidents?.[0]?.updates.length)
        .toBe(2)
      expect(log.latest()?.incidents?.[0]).toMatchObject({
        incidentId,
        publishedAt: expect.any(Number),
        updates: [{ kind: 'team', text }, { kind: 'published' }],
      })
      expect(viewerLog.latest()?.incidents?.[0]?.updates[0]).toMatchObject({
        kind: 'team',
        text: { _hidden: true },
      })
      await expect(
        viewer.getByTestId(`hilos-status-timeline-${incidentId}`),
      ).not.toContainText(text)
    })
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await expect(viewer.getByTestId('hilos-status-operational')).toBeVisible()
    expect(incidentId).toEqual(expect.any(Number))
    const before = log.latest()
    // Gatekeeper refusal precedes forwarding to StatusAgent. The exact view_mode
    // code and sentence must still win over this now-ended real incident target.
    for (const [action, data] of [
      ['hilos_status_cancel_publication', { incidentId }],
      ['hilos_status_publish_now', { incidentId }],
      ['hilos_status_add_update', { incidentId, text: 'forged' }],
    ] as const) {
      const requestId = `forged-${action}`
      const frame = await forged.submit(
        JSON.stringify({ type: 'action', action, data, requestId }),
        requestId,
      )
      expect(frame.failure).toBeUndefined()
      const wire = deliveryObject(frame.wire)
      expect(wire.type).toBe('action_error')
      expect(deliveryObject(wire.data)).toMatchObject({
        action,
        errorCode: 'view_mode',
        reason: 'View mode: you can look around, but not change anything.',
      })
      expect(deliveryObject(wire.data)).not.toHaveProperty('errorDetail')
      expect(deliveryObject(wire.data)).not.toHaveProperty('errorType')
    }
    expect(log.latest()).toEqual(before)
    await expectDocumentUnchanged(viewer)
    await gotoPage(viewer, STATUS_COMPONENTS_PATH, PAGE_READY)
    await expectStatusHead(expect, viewer, true)
    await expect(
      shownByTestId(
        viewer,
        `hilos-status-component-state-${STATUS_FAULT_COMPONENT}`,
      ),
    ).toHaveText('Operational')
  } finally {
    await test.info().attach('status-publish-real-wire', {
      body: JSON.stringify({
        admin: log.frames,
        adminSent: log.sent,
        viewer: viewerLog.frames,
        forged: forged.frames,
      }),
      contentType: 'application/json',
    })
    forged.dispose()
    log.stop()
    viewerLog.stop()
    await workers.close()
    await viewerContext.close()
  }
})
