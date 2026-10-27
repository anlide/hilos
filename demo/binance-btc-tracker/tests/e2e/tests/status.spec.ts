import { expect as baseExpect, test } from '@playwright/test'
import {
  RESTART_MARK_TIMEOUT_MS,
  STATUS_PATH,
  STATUS_COMPONENTS_PATH,
  STATUS_LEAD,
  PUBLIC_STATUS_FILES,
  controlSocketReconnect,
  deliveryObject,
  dismissToasts,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectStatusHead,
  shownByTestId,
  submitStatusIncident,
  watchPublicStatus,
  watchStatusFrames,
  expectDocumentUnchanged,
  expectPageReady,
  expectSelfReload,
  gotoMaintenance,
  gotoPage,
  gotoPublicStatus,
  markDocument,
  PAGE_READY,
  grantAdminToSelf,
  armViewerSubmit,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
  STATUS_FAULT_COMPONENT,
  withPrivateStatusFiles,
  withStatusAgentLoss,
} from '../helpers/status.js'
import {
  enterProtectedMode,
  inspectProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

const expect = baseExpect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })
const pausedExpect = expect.configure({
  timeout: Math.min(STATUS_EXPECT_TIMEOUT_MS, RESTART_MARK_TIMEOUT_MS),
})

// Real browser/daemon Status contract. The stand's test-only catalog observes
// the settings library; production still uses its ordinary Service fallback.
test.use({ colorScheme: 'light' })
test.afterEach(() => setAdminViewMode(false))

test('Status direct load, F5, Dashboard, shared head and Components use complete real page answers', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  const log = watchStatusFrames(page)
  try {
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
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
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page)
    expect(log.overviews.length).toBeGreaterThan(beforeReload)

    await gotoPage(expect, page, '/hilos', PAGE_READY)
    const card = page.getByTestId('dashboard-card-hilos_status')
    await expect(card).toContainText(STATUS_LEAD)
    await expect(card).toHaveAttribute('href', STATUS_PATH)
    await card.click()
    await expectPageReady(expect, page)
    await expectStatusHead(expect, page)
    await page.getByTestId('hilos-status-components-link').click()
    await expectPageReady(expect, page)
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
    await expectPageReady(expect, page)
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await page.getByTestId('hilos-status-settings-link').click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(/\/hilos\/settings$/)
  } finally {
    log.stop()
  }
})

test('real agent loss creates a candidate and cancellation echoes to a second administrator without reload', async ({
  page,
  browser,
  baseURL,
}) => {
  // Two independent administrator sessions, the real roster fanout and recovery.
  test.slow()
  await grantAdminToSelf(expect, page)
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
    await grantAdminToSelf(expect, other)
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
    await gotoPage(expect, other, STATUS_PATH, PAGE_READY)
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await markDocument(other)
    await withStatusAgentLoss(expect, workers, log, async () => {
      const expect = pausedExpect
      await expect.poll(() => log.latest()?.incidents?.length).toBe(1)
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
          admin.getByTestId(`hilos-status-publish-now-${incident.incidentId}`),
        ).toHaveCount(0)
        await expect(admin.getByTestId('hilos-status-operational')).toHaveCount(
          0,
        )
      }
      expect(echo.latest()?.incidents?.[0]).toMatchObject({
        incidentId: incident.incidentId,
        publishAt: incident.publishAt,
        publishedAt: null,
        suppressedAt: expect.any(Number),
      })
      await expectDocumentUnchanged(expect, other)
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
})

// FIXME(F-033, 2026-10-25): Timeout ожидания воркера в withStatusAgentLoss до впрыска сбоя · чужой · попытка 2 · HIL-1508
test(
  'real publication and plain-text update are acknowledged, live, masked for viewers and reject forged writes',
  { tag: '@quarantine:F-033' },
  async ({ page, browser, baseURL }) => {
    // The real stop/collector path, mutation acks, viewer delivery and recovery.
    test.slow()
    await grantAdminToSelf(expect, page)
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
      await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
      await gotoPage(expect, viewer, STATUS_PATH, PAGE_READY)
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
      await withStatusAgentLoss(expect, workers, log, async () => {
        const expect = pausedExpect
        await expect.poll(() => log.latest()?.incidents?.length).toBe(1)
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
      await expectDocumentUnchanged(expect, viewer)
      await gotoPage(expect, viewer, STATUS_COMPONENTS_PATH, PAGE_READY)
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
  },
)

test('a guest sees a real Checking candidate without adding it to public days or history', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Independent guest, real worker pause/collector and recovery.
  await grantAdminToSelf(expect, page)
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const guest = await guestContext.newPage()
  const workers = await page.context().newPage()
  const admin = watchStatusFrames(page)
  const log = watchPublicStatus(guest)
  try {
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
    await gotoPublicStatus(expect, guest)
    const before = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    const history = await guest.getByTestId('public-status-history').innerText()
    const days = guest.getByTestId('public-status-days-90').getByRole('button')
    const labels = await days.evaluateAll((buttons) =>
      buttons.map((button) => button.getAttribute('aria-label')),
    )
    await withStatusAgentLoss(expect, workers, admin, async () => {
      const expect = pausedExpect
      await expect.poll(() => admin.latest()?.incidents?.length).toBe(1)
      const incident = admin.latest()!.incidents![0]
      const candidate = await expectPublicStatusPair(
        expect,
        guest,
        log,
        ({ status }) => status.candidates.length === 1,
      )
      expect(candidate.status.candidates).toEqual([
        {
          componentKey: STATUS_FAULT_COMPONENT,
          detectedAt: incident.detectedAt,
        },
      ])
      expect(candidate.status.outages).toEqual(before.status.outages)
      await expect(guest.getByTestId('public-status-candidates')).toContainText(
        'Checking a possible problem with Settings library',
      )
      await expect(
        guest.getByTestId('public-status-component-state'),
      ).toHaveText('Checking')
      await expect(guest.getByTestId('public-status-banner')).toHaveText(
        'Checking a possible problem',
      )
      await expect(guest.getByTestId('public-status-history')).toHaveText(
        history,
        { useInnerText: true },
      )
      expect(
        await days.evaluateAll((buttons) =>
          buttons.map((button) => button.getAttribute('aria-label')),
        ),
      ).toEqual(labels)
    })
    await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.candidates.length === 0 &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    await expect(guest.getByTestId('public-status-candidates')).toHaveCount(0)
    await expect(guest.getByTestId('public-status-history')).toHaveText(
      history,
      {
        useInnerText: true,
      },
    )
    expectPublicStatusReadOnly(expect, log)
  } finally {
    await log.stop()
    admin.stop()
    try {
      await test.info().attach('public-status-real-candidate', {
        body: JSON.stringify({ public: log, admin: admin.frames }),
        contentType: 'application/json',
      })
    } finally {
      await workers.close()
      await guestContext.close()
    }
  }
})

test('published public facts survive lost sockets, real file polling, recovery and a cold guest without handshake', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Real loss/publication, heartbeat polling and two independent guests.
  await grantAdminToSelf(expect, page)
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: 'Europe/Warsaw',
  })
  const coldContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const guest = await guestContext.newPage()
  const cold = await coldContext.newPage()
  const socket = await controlSocketReconnect(guest)
  const coldSocket = await controlSocketReconnect(cold)
  const log = watchPublicStatus(guest)
  const coldLog = watchPublicStatus(cold)
  const admin = watchStatusFrames(page)
  const workers = await page.context().newPage()
  const text = `<b>Public ${Date.now()}</b> & plain text`
  let detectedAt: number | undefined
  const recovery: Record<string, number> = {}
  try {
    await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.candidates.length === 0 &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    await expect(page.getByTestId('hilos-status-operational')).toBeVisible()
    await markDocument(guest)
    await withStatusAgentLoss(expect, workers, admin, async () => {
      const expect = pausedExpect
      await expect.poll(() => admin.latest()?.incidents?.length).toBe(1)
      const incident = admin.latest()!.incidents![0]
      detectedAt = incident.detectedAt
      await page
        .getByTestId(`hilos-status-publish-now-${incident.incidentId}`)
        .click()
      await submitStatusIncident(
        expect,
        page,
        admin,
        'hilos_status_publish_now',
        incident.incidentId,
      )
      await dismissToasts(page)
      await page
        .getByTestId(`hilos-status-add-update-${incident.incidentId}`)
        .click()
      const field = page.getByTestId('hilos-status-update-text')
      await field.fill('')
      await field.pressSequentially(text, { delay: 10 })
      await submitStatusIncident(
        expect,
        page,
        admin,
        'hilos_status_add_update',
        incident.incidentId,
      )
      const published = await expectPublicStatusPair(
        expect,
        guest,
        log,
        ({ status }) =>
          status.outages.some(
            (outage) =>
              outage.detectedAt === detectedAt &&
              outage.resolvedAt === null &&
              outage.updates.some(
                (update) => update.source === 'team' && update.text === text,
              ),
          ),
      )
      const outage = published.status.outages.find(
        (item) => item.detectedAt === detectedAt,
      )!
      expect(outage).toMatchObject({
        componentKey: STATUS_FAULT_COMPONENT,
        severity: 'major_outage',
        publishedAt: incident.publishedAt ?? expect.any(Number),
        resolvedAt: null,
      })
      await expect(guest.getByTestId('public-status-outages')).toContainText(
        text,
      )
      await expect(
        guest.getByTestId('public-status-update').locator('b'),
      ).toHaveCount(0)
      await expect(
        guest.getByTestId('public-status-component-state'),
      ).toHaveText('Major outage')
    })
    const resolved = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ status }) =>
        status.outages.some(
          (outage) =>
            outage.detectedAt === detectedAt && outage.resolvedAt !== null,
        ) &&
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    await expect(guest.getByTestId('public-status-outages')).toHaveCount(0)
    await expect(guest.getByTestId('public-status-history')).toContainText(text)
    await expect(
      guest.getByTestId('public-status-history').locator('b'),
    ).toHaveCount(0)
    const labels = await guest
      .getByTestId('public-status-days-90')
      .getByRole('button')
      .evaluateAll((buttons) =>
        buttons.map((button) => button.getAttribute('aria-label')),
      )
    expect(labels.at(-1)).toMatch(/ · Major outage$/)
    const archive = await guest.getByTestId('public-status-history').innerText()
    expect(
      log.wire.packets
        .flatMap((packet) => packet.frames)
        .some(
          ({ wire }) => deliveryObject(wire).type === 'status_files_changed',
        ),
    ).toBe(true)

    const beforeDrop = log.reads[0].length
    await socket.drop()
    await expect(guest.getByTestId('public-status-connection')).toHaveText(
      'Your live connection to the site is lost — the system is up',
    )
    await expect.poll(() => log.reads[0].length).toBeGreaterThan(beforeDrop + 1)
    const polled = await expectPublicStatusPair(
      expect,
      guest,
      log,
      ({ heartbeat }) => heartbeat.aliveAt > resolved.heartbeat.aliveAt,
    )
    expect(polled.status.outages).toEqual(resolved.status.outages)
    await expect(guest.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(guest.getByTestId('public-status-history')).toHaveText(
      archive,
      {
        useInnerText: true,
      },
    )
    await expectDocumentUnchanged(expect, guest)

    // Release just after a completed poll. A new GET before that poll's next
    // eligibility proves the usable-socket transition triggered the reread.
    const beforeRelease = log.reads[0].length
    recovery.lastPollAt = log.reads[0].at(-1)!.requestedAt
    recovery.releasedAt = performance.now()
    await socket.release()
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await expect.poll(() => log.reads[0].length).toBeGreaterThan(beforeRelease)
    recovery.rereadAt = log.reads[0][beforeRelease].requestedAt
    expect(recovery.rereadAt).toBeLessThan(
      recovery.lastPollAt + polled.status.config.pollIntervalSeconds * 1000,
    )
    await expect(guest.getByTestId('public-status-connection')).toHaveCount(0)

    await coldSocket.hold()
    await gotoPublicStatus(expect, cold)
    await expectPublicStatusPair(expect, cold, coldLog, ({ status }) =>
      status.outages.some(
        (outage) =>
          outage.detectedAt === detectedAt && outage.resolvedAt !== null,
      ),
    )
    await expect(cold.getByTestId('public-status-history')).toContainText(text)
    await expect(cold.getByTestId('public-status-banner')).toHaveText(
      'All systems operational',
    )
    await expect(cold.getByTestId('public-status-connection')).toHaveText(
      'Your live connection to the site is lost — the system is up',
    )
    expect(coldLog.wire.packets).toEqual([])
    expectPublicStatusReadOnly(expect, log)
    expectPublicStatusReadOnly(expect, coldLog)
  } finally {
    admin.stop()
    const cleanup = await Promise.allSettled([
      log.stop(),
      coldLog.stop(),
      workers.close(),
      guestContext.close(),
      coldContext.close(),
    ])
    await socket.dispose()
    await coldSocket.dispose()
    await test.info().attach('public-status-published-poll-recovery', {
      body: JSON.stringify({
        public: log,
        cold: coldLog,
        recovery,
        admin: admin.frames,
      }),
      contentType: 'application/json',
    })
    const failed = cleanup.filter((result) => result.status === 'rejected')
    expect(failed, 'Public Status cleanup failed').toEqual([])
  }
})

test('an active freeze outlives stale while real heartbeats and cold public readers keep showing Maintenance', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // The actual freeze must last longer than the file's six-second stale threshold.
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const cold = await otherContext.newPage()
  const coldSocket = await controlSocketReconnect(cold)
  const coldLog = watchPublicStatus(cold)
  const guarded = await page.context().newPage()
  const guardedSocket = await controlSocketReconnect(guarded)
  const guardedLog = watchPublicStatus(guarded)
  const log = watchPublicStatus(page)
  const evidence: Array<Record<string, unknown>> = []
  let enteredAt = 0
  try {
    await gotoPublicStatus(expect, page)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const initial = await expectPublicStatusPair(
      expect,
      page,
      log,
      ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
    )
    expect(initial.status.config).toEqual({
      pollIntervalSeconds: 2,
      staleAfterSeconds: 6,
    })
    expect(await enterProtectedMode('e2e-public-status-freeze')).toBe('active')
    enteredAt = performance.now()
    const active = await inspectProtectedMode()
    expect(active).toMatchObject({
      phase: 'active',
      agentStartGateClosed: true,
    })
    expect(active.stoppedAgents).toContain('hilos_settings_library')
    expect(active.stoppedAgents).not.toContain('hilos_status')
    evidence.push({ at: enteredAt, phase: active })

    await gotoMaintenance(expect, guarded, '/hilos')
    const link = guarded.getByTestId('maintenance-service-status')
    await expect(link).toHaveAttribute('href', '/status')
    await expect(link).toHaveText('Service status')
    await markDocument(guarded)
    await guardedSocket.drop()
    await link.click()
    await expectSelfReload(guarded, test.info().project.use.navigationTimeout)
    await expect(guarded).toHaveURL(/\/status$/)
    await expect(guarded.getByTestId('maintenance')).toHaveCount(0)
    await expectPublicStatusPair(expect, guarded, guardedLog, ({ status }) =>
      status.components.every((component) => component.state === 'maintenance'),
    )
    // This browser remembers the maintenance welcome, but its next document's
    // first socket is held. The native link still opens the actual file reader.
    expect(
      guardedLog.wire.packets.some((packet) =>
        packet.frames.some(
          ({ wire }) => deliveryObject(wire).type === 'handshake',
        ),
      ),
    ).toBe(true)
    await expect(guarded.getByTestId('public-status-banner')).toHaveText(
      'Maintenance in progress',
    )

    let aliveAt = initial.heartbeat.aliveAt
    let samples = 0
    do {
      const accepted = await expectPublicStatusPair(
        expect,
        page,
        log,
        ({ status, heartbeat }) =>
          heartbeat.aliveAt > aliveAt &&
          status.components.every(
            (component) => component.state === 'maintenance',
          ),
      )
      expect(accepted.heartbeat.aliveAt).toBeGreaterThan(aliveAt)
      aliveAt = accepted.heartbeat.aliveAt
      samples++
      await expect(page.getByTestId('public-status-banner')).toHaveText(
        'Maintenance in progress',
      )
      await expect(
        page.getByTestId('public-status-component-state'),
      ).toHaveText('Maintenance')
      evidence.push({ elapsedMs: performance.now() - enteredAt, accepted })
    } while (
      performance.now() - enteredAt <=
        initial.status.config.staleAfterSeconds * 1000 ||
      samples < 3
    )
    expect(performance.now() - enteredAt).toBeGreaterThan(
      initial.status.config.staleAfterSeconds * 1000,
    )
    expect(samples).toBeGreaterThanOrEqual(3)
    expect((await inspectProtectedMode()).phase).toBe('active')

    // Independent, never-welcomed visitor arrives after the stale boundary.
    await coldSocket.hold()
    await gotoPublicStatus(expect, cold)
    await expectPublicStatusPair(
      expect,
      cold,
      coldLog,
      ({ status, heartbeat }) =>
        status.components.every(
          (component) => component.state === 'maintenance',
        ) && heartbeat.aliveAt > initial.heartbeat.aliveAt,
    )
    await expect(cold.getByTestId('public-status-banner')).toHaveText(
      'Maintenance in progress',
    )
    await expect(cold.getByTestId('public-status-component-state')).toHaveText(
      'Maintenance',
    )
    await expect(cold.getByTestId('maintenance')).toHaveCount(0)
    expect(coldLog.wire.packets).toEqual([])
    expectPublicStatusReadOnly(expect, log)
    expectPublicStatusReadOnly(expect, coldLog)
    expectPublicStatusReadOnly(expect, guardedLog)
  } finally {
    // An inspect failure must not prevent the owner's unconditional open. The
    // warm browser must finish its own lift reload before readiness is checked.
    let frozen: boolean
    try {
      try {
        frozen = (await inspectProtectedMode()).phase !== 'inactive'
        if (frozen) await markDocument(page)
      } finally {
        await openProtectedModeIfAny()
      }
      if (frozen)
        await expectSelfReload(page, test.info().project.use.navigationTimeout)
      expect((await inspectProtectedMode()).phase).toBe('inactive')
      await expectPublicStatusPair(expect, page, log, ({ status }) =>
        status.components.every(
          (component) => component.state === 'operational',
        ),
      )
      await expect(page.getByTestId('public-status-banner')).toHaveText(
        'All systems operational',
      )
    } finally {
      const cleanup = await Promise.allSettled([
        log.stop(),
        guardedLog.stop(),
        coldLog.stop(),
        guarded.close(),
        otherContext.close(),
      ])
      await guardedSocket.dispose()
      await coldSocket.dispose()
      await test.info().attach('public-status-active-freeze-real-time', {
        body: JSON.stringify({
          enteredAt,
          evidence,
          warm: log,
          remembered: guardedLog,
          cold: coldLog,
        }),
        contentType: 'application/json',
      })
      const failed = cleanup.filter((result) => result.status === 'rejected')
      expect(failed, 'Freeze reader cleanup failed').toEqual([])
    }
  }
})

test('nginx serves only the two no-store JSON names through GET and HEAD', async ({
  request,
}) => {
  const evidence: Array<Record<string, unknown>> = []
  try {
    await withPrivateStatusFiles(async (names, sentinel) => {
      for (const path of PUBLIC_STATUS_FILES) {
        for (const method of ['GET', 'HEAD']) {
          const response = await request.fetch(path, { method })
          evidence.push({
            path,
            method,
            status: response.status(),
            headers: response.headers(),
          })
          expect(response.status()).toBe(200)
          expect(response.headers()['content-type']).toMatch(
            /^application\/json\b/,
          )
          expect(response.headers()['cache-control']).toBe('no-store')
          if (method === 'GET')
            expect(await response.json()).toHaveProperty('schemaVersion', 1)
          else expect(await response.body()).toHaveLength(0)
          await response.dispose()
        }
        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']) {
          const response = await request.fetch(path, { method })
          evidence.push({
            path,
            method,
            status: response.status(),
            headers: response.headers(),
          })
          expect(response.status()).toBe(403)
          expect(response.headers()['cache-control']).toBe('no-store')
          await response.dispose()
        }
      }
      for (const suffix of [
        '',
        '/',
        '/missing.json',
        '/status.json/',
        ...names.map((name) => `/${name}`),
      ]) {
        const path = `/status-data${suffix}`
        for (const method of ['GET', 'HEAD']) {
          const response = await request.fetch(path, {
            method,
            maxRedirects: 0,
          })
          evidence.push({
            path,
            method,
            status: response.status(),
            headers: response.headers(),
          })
          expect(response.status()).toBe(404)
          expect(response.headers()['cache-control']).toBe('no-store')
          expect(await response.text()).not.toContain(sentinel)
          await response.dispose()
        }
      }
    })
  } finally {
    await test.info().attach('public-status-nginx-boundary', {
      body: JSON.stringify(evidence),
      contentType: 'application/json',
    })
  }
})
