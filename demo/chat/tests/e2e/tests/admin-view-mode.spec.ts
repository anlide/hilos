import { test, expect } from '@playwright/test'

import {
  armSocketDrop,
  leaveSecondAdmin,
  shownByTestId,
  deliveryObject,
  dropSocket,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  holdPageReply,
  markDocument,
  PAGE_READY,
  createTestUser,
  grantAdminToSelf,
  tableRowKeys,
  typeInto,
  watchDeliveryFrames,
  watchTableSearch,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'
import {
  clearTableRefusal,
  refuseTableWindow,
} from '../../../../../framework/frontend/scripts/tableRefusal.mjs'

// spec-owner: demo — it covers Chat's project setting alongside shared admin views.

// HIL-1253: the browser knows the node's admin view mode from the session
// response, so the shell draws the admin gear for a viewer who may look — a
// guest without an account included — and the admin routes do not refuse that
// viewer on the client. A grant turns the gear full and a revoke takes it back
// to the view, live, with no 403 drawn on the way: the server answers the page
// again, the view or the full one, and the tab only waits for it.
//
// HIL-1260: on every admin screen the viewer reads one strip saying the screen
// may be looked at and not changed, and what the server keeps from them reads
// as one mark, "Hidden" — a guest's look at the people who accepted the terms
// and at the people list names nobody.
//
// The lever is node-wide; every test here leaves it off, failed or not.

/** The words of the view-mode strip, as the shell draws them. */
const VIEW_MODE_STRIP_TEXT =
  'View mode · You can look around, but not change anything.'

test.afterEach(() => setAdminViewMode(false))

test('a guest without an account sees the admin gear and opens the admin section in the view mode', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  await setAdminViewMode(true)
  // The flip is not sent to the open tab; the next handshake carries it.
  await gotoPage(expect, page, '/')
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toBeVisible()
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expect(gear).toHaveAttribute(
    'aria-label',
    'Hilos dashboard — View mode',
  )
  await expect(gear.locator('.bi-eye')).toBeVisible()

  await gear.click()
  await expectPageReady(expect, page)
  await expect(page.getByTestId('dashboard-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos')
  await expect(page.getByTestId('page-error')).toHaveCount(0)
})

test('a guest reads the view-mode strip on the admin screens and the personal data as hidden', async ({
  browser,
  page,
}) => {
  // An account of this test's own: the acceptances and the users then hold at
  // least one person whose name is kept from the guest.
  const someone = await browser.newContext()
  await createTestUser(expect, await someone.newPage())
  await someone.close()

  await setAdminViewMode(true)
  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'view',
  )
  await expect(page.getByTestId('view-mode-banner')).toHaveCount(0)

  const screens = [
    { path: '/hilos', hidden: null },
    { path: '/hilos/legal/acceptances', hidden: 'legal-acceptances-table' },
    { path: '/hilos/users', hidden: 'hilos-viewport-table' },
  ]
  for (const screen of screens) {
    await gotoPage(expect, page, screen.path, PAGE_READY)
    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    if (screen.hidden !== null) {
      await expect(
        shownByTestId(page.getByTestId(screen.hidden), 'hilos-hidden').first(),
      ).toHaveText('Hidden')
    }
  }
})

test('a viewer sees watchdog recipients hidden and thresholds read-only', async ({
  page,
}) => {
  const deliveries = watchDeliveryFrames(page)
  const isHiddenMark = (value: unknown): boolean => {
    const mark = deliveryObject(value)
    return Object.keys(mark).length === 1 && mark._hidden === true
  }

  try {
    await setAdminViewMode(true)
    await gotoPage(expect, page, '/hilos/maintenance', PAGE_READY)

    const maintenanceFrames = () =>
      deliveries.packets
        .flatMap((packet) => packet.frames)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).type === 'subscription_page_hilos_maintenance',
        )
    const publishedFrames = () =>
      maintenanceFrames().filter(({ wire }) => {
        const state = deliveryObject(deliveryObject(wire).data)
        return (
          typeof state.watchdogQuiesceTimeoutSeconds === 'number' &&
          Number.isInteger(state.watchdogQuiesceTimeoutSeconds) &&
          typeof state.watchdogSilenceTimeoutSeconds === 'number' &&
          Number.isInteger(state.watchdogSilenceTimeoutSeconds) &&
          typeof state.watchdogAlertIntervalSeconds === 'number' &&
          Number.isInteger(state.watchdogAlertIntervalSeconds) &&
          isHiddenMark(state.watchdogAlertEmails) &&
          typeof state.watchdogManualRestartIsNormal === 'boolean'
        )
      })

    await expect
      .poll(() => publishedFrames().length, {
        message:
          'the viewer receives published watchdog thresholds and the visible restart policy',
      })
      .toBeGreaterThan(0)

    const stateFrames = maintenanceFrames()
    expect(stateFrames.length).toBeGreaterThan(0)
    for (const frame of stateFrames) {
      expect(frame.failure).toBeUndefined()
      expect(frame.raw).toEqual(expect.any(String))
      expect(
        deliveryObject(deliveryObject(frame.wire).data).watchdogAlertEmails,
      ).toEqual({ _hidden: true })
    }

    const latestFrame = publishedFrames().at(-1)!
    const state = deliveryObject(deliveryObject(latestFrame.wire).data)
    const quiesceSeconds = Number(state.watchdogQuiesceTimeoutSeconds)
    const silentSeconds = Number(state.watchdogSilenceTimeoutSeconds)
    const repeatSeconds = Number(state.watchdogAlertIntervalSeconds)
    const restartIsNormal = state.watchdogManualRestartIsNormal === true
    const secondsText = (value: number): string =>
      `${value} ${value === 1 ? 'second' : 'seconds'}`

    const strip = page.getByTestId('view-mode-banner')
    await expect(strip).toBeVisible()
    await expect(strip).toHaveText(VIEW_MODE_STRIP_TEXT)
    const thresholds = page.getByTestId('hilos-maintenance-watchdog-thresholds')
    await expect(thresholds).toBeVisible()
    await expect(thresholds.getByRole('heading', { level: 2 })).toHaveText(
      'Watchdog thresholds',
    )
    await expect(thresholds.locator('dl dt')).toHaveText([
      'Wait for nodes to quiesce',
      'Consider silent after',
      'Repeat alert',
      'Send mail to',
      'Restart under manual maintenance',
    ])
    await expect(thresholds.locator('dl dd')).toHaveText([
      secondsText(quiesceSeconds),
      secondsText(silentSeconds),
      secondsText(repeatSeconds),
      'Hidden',
      restartIsNormal ? 'No mail — a log entry' : 'Send an alert',
    ])

    const recipients = thresholds.locator('dl dd').nth(3)
    await expect(thresholds.getByTestId('hilos-hidden')).toHaveCount(1)
    await expect(recipients.getByTestId('hilos-hidden')).toHaveText('Hidden')
    await expect(recipients).toHaveText('Hidden')
    await expect(
      thresholds.locator('input, select, textarea, button, a'),
    ).toHaveCount(0)
  } finally {
    deliveries.stop()
  }
})

test('a viewer sees open framework settings while a project key stays hidden', async ({
  page,
}) => {
  await setAdminViewMode(true)
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  const search = page.getByTestId('hilos-table-search')
  await typeInto(search, 'legal.consent_form')
  const openRow = shownByTestId(page, 'hilos-table-row-legal.consent_form')
  await expect(openRow).toBeVisible()
  await expect(openRow.getByTestId('setting-value')).toContainText('checkbox')
  await expect(openRow.getByTestId('hilos-hidden')).toHaveCount(0)

  await typeInto(search, 'default_bot_provider')
  const closedRow = shownByTestId(page, 'hilos-table-row-default_bot_provider')
  await expect(closedRow).toBeVisible()
  await expect(closedRow.getByTestId('hilos-hidden').first()).toHaveText(
    'Hidden',
  )
  await typeInto(search, 'local')
  await expect(closedRow).toHaveCount(0)

  await gotoPage(expect, page, '/hilos/legal/settings', PAGE_READY)
  await expect(
    shownByTestId(page, 'legal-setting-value-legal.consent_form'),
  ).toContainText('Checkbox')

  const { userId } = await createTestUser(expect, page)
  await setAdmin(userId, true)
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'data-access',
    'full',
  )
  await gotoPage(expect, page, '/hilos/settings', PAGE_READY)
  const adminSearch = page.getByTestId('hilos-table-search')
  await typeInto(adminSearch, 'default_bot_provider')
  const adminRow = shownByTestId(page, 'hilos-table-row-default_bot_provider')
  await expect(adminRow).toBeVisible()
  await expect(adminRow.getByTestId('setting-value')).toContainText('local')
  await expect(adminRow.getByTestId('hilos-hidden')).toHaveCount(0)
  await typeInto(adminSearch, 'legal.consent_form')
  await expect(
    shownByTestId(page, 'hilos-table-row-legal.consent_form').getByTestId(
      'setting-value',
    ),
  ).toContainText('checkbox')
})

test('a signed-in non-admin views the people, is granted the full section and taken back to the view live', async ({
  browser,
  page,
}) => {
  await setAdminViewMode(true)
  // Revocation requires another administrator, even when this test runs alone.
  // Made before the people open, that one's row is in the table from its first
  // answer rather than arriving live in the middle of the scenario.
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  await leaveSecondAdmin(browser, (page) => grantAdminToSelf(expect, page), {
    baseURL,
    ignoreHTTPSErrors,
  })
  const { userId } = await createTestUser(expect, page)
  const gear = page.getByTestId('nav-admin')
  await expect(gear).toHaveAttribute('data-access', 'view')

  await gotoPage(expect, page, '/hilos/users', PAGE_READY)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  const strip = page.getByTestId('view-mode-banner')
  await expect(strip).toBeVisible()

  // The grant re-sends the session response to this tab: the gear turns full
  // and the page is answered again, now the full one; the strip leaves.
  await setAdmin(userId, true)
  await expect(gear).toHaveAttribute('data-access', 'full')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(strip).toHaveCount(0)

  // The revoke with the mode on answers the view rather than a 403, and the
  // tab waits for it instead of drawing the refusal ahead of the server.
  await setAdmin(userId, false)
  await expect(gear).toHaveAttribute('data-access', 'view')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('page-error')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(strip).toBeVisible()
})

test('Legal recovers unknown windows and keeps searched ordered windows across separately delivered grant and revoke answers', async ({
  browser,
  page,
}) => {
  const pageKey = 'hilos_legal_document_set'
  const tableKey = 'hilosLegalDocumentSet'
  const path = '/hilos/legal/document/terms/set'
  const log = watchDeliveryFrames(page)
  await armSocketDrop(page)
  const browserLog = await watchTableSearch(page, { page: pageKey, tableKey })
  const gate = await holdPageReply(page, pageKey, {
    initiallyArmed: false,
    passSession: true,
  })
  const errors: unknown[] = []
  let userId: number | undefined
  try {
    await setAdminViewMode(true)
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    await leaveSecondAdmin(
      browser,
      (other) => grantAdminToSelf(expect, other),
      {
        baseURL,
        ignoreHTTPSErrors,
      },
    )
    userId = (await createTestUser(expect, page)).userId
    const gear = page.getByTestId('nav-admin')
    const search = page.getByTestId('hilos-table-search')
    const sort = page.getByTestId('hilos-table-sort-clauseKey')
    const header = page.getByRole('columnheader').filter({ has: sort })
    const strip = page.getByTestId('view-mode-banner')
    await expect(gear).toHaveAttribute('data-access', 'view')

    const requests = (since: number) =>
      log.sent
        .slice(since)
        .map(({ wire }) => deliveryObject(wire))
        .filter((wire) =>
          [
            'page_subscribe',
            'page_update_subscription',
            'action',
            'table_viewport',
            'table_facets',
          ].includes(String(wire.type)),
        )
    const pageReplies = (since: number) =>
      log.packets
        .slice(since)
        .flatMap(({ frames }) => frames)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).type === 'page_response' &&
            deliveryObject(deliveryObject(wire).data).page === pageKey,
        )
    const forwardedReplies = (since: number) =>
      gate.forwarded
        .slice(since)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).type === 'page_response' &&
            deliveryObject(deliveryObject(wire).data).page === pageKey,
        )
    const assertWindow = (
      window: Record<string, unknown>,
      keys: string[],
      direction: string,
    ) => {
      expect(window).toMatchObject({
        limit: 25,
        rowsBefore: 0,
        totalCount: 2,
        totalExact: true,
      })
      if ('sort' in window)
        expect(window.sort).toEqual([{ field: 'clauseKey', direction }])
      expect(
        (window.rows as unknown[]).map((row) => deliveryObject(row).rowKey),
      ).toEqual(keys)
      expect(deliveryObject(window.firstAnchor).rowKey).toBe(keys[0])
      expect(deliveryObject(window.lastAnchor).rowKey).toBe(keys.at(-1))
    }
    const expectControls = async (
      term: string,
      keys: string[],
      direction: string,
    ) => {
      await expect(search).toHaveValue(term)
      await expect(header).toHaveAttribute(
        'aria-sort',
        direction === 'asc' ? 'ascending' : 'descending',
      )
      await expect.poll(() => tableRowKeys(page)).toEqual(keys)
      await expect(page.getByTestId('hilos-table-count')).toHaveText(
        '1 – 2 of 2',
      )
      // The real catalog has six clauses, below its declared window of 25.
      // Its held rowsBefore=0 therefore owes no next/previous page controls.
      await expect(
        page.getByRole('group', { name: 'Pagination', exact: true }),
      ).toHaveCount(0)
      await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
      await expect(page.getByTestId('hilos-table-unavailable')).toHaveCount(0)
    }
    const changeWindow = async (
      term: string,
      direction: string,
      keys: string[],
    ) => {
      const since = log.sent.length
      await typeInto(search, term)
      // From descending the documented three-state cycle visits the opening
      // catalog order before ascending on this column again.
      if (
        direction === 'asc' &&
        (await header.getAttribute('aria-sort')) === 'descending'
      ) {
        await sort.click()
        await expect(header).toHaveAttribute('aria-sort', 'none')
      }
      if (
        (await header.getAttribute('aria-sort')) !==
        (direction === 'asc' ? 'ascending' : 'descending')
      )
        await sort.click()
      await expectControls(term, keys, direction)
      const asked = requests(since)
        .filter(({ type }) => type === 'table_viewport')
        .at(-1)!
      expect(asked).toMatchObject({
        page: pageKey,
        tableKey,
        filter: { document: 'terms', search: term },
        sort: [{ field: 'clauseKey', direction }],
        limit: 25,
      })
      const answer = () =>
        log.packets
          .flatMap(({ frames }) => frames)
          .find(({ wire }) => {
            const value = deliveryObject(wire)
            const data = deliveryObject(value.data)
            return (
              value.type === 'table_window' &&
              data.tableKey === tableKey &&
              data.requestId === asked.requestId
            )
          })
      await expect.poll(answer).toBeDefined()
      expect(answer()!.failure).toBeUndefined()
      assertWindow(
        deliveryObject(deliveryObject(answer()!.wire).data),
        keys,
        direction,
      )
    }

    for (const admin of [true, false]) {
      await test.step(`${admin ? 'Grant' : 'Revoke'} recovers a cold refused window through the existing subscription`, async () => {
        await refuseTableWindow(tableKey)
        const openingSent = log.sent.length
        const openingPackets = log.packets.length
        await gotoPage(expect, page, path, PAGE_READY)
        await markDocument(page)
        await expect(gear).toHaveAttribute(
          'data-access',
          admin ? 'view' : 'full',
        )
        await expect(strip).toHaveCount(admin ? 1 : 0)
        await expect(
          shownByTestId(page, 'hilos-table-unavailable'),
        ).toBeVisible()
        expect(pageReplies(openingPackets)).toHaveLength(1)
        const refusal = deliveryObject(
          deliveryObject(
            deliveryObject(pageReplies(openingPackets)[0]!.wire).data,
          ).payload,
        )
        expect(deliveryObject(refusal.windows)).not.toHaveProperty(tableKey)
        expect(deliveryObject(refusal.refusedWindows)).toEqual({
          [tableKey]: { errorCode: 'internal_error' },
        })
        await expect(search).toBeDisabled()
        await expect(search).toHaveValue('')
        const sorts = page.locator('[data-id^="hilos-table-sort-"]:visible')
        expect(await sorts.count()).toBeGreaterThan(0)
        for (const control of await sorts.all())
          await expect(control).toBeDisabled()
        await search.pressSequentially('Content', { delay: 10 })
        await expect(search).toHaveValue('')
        await expect.poll(() => tableRowKeys(page)).toEqual([])
        const before = await browserLog.read()
        expect(before.application).toHaveLength(1)
        expect(before.application[0]).toMatchObject({
          search: '',
          domSearch: '',
          filter: { document: 'terms' },
          rows: [],
          domRows: [],
          descriptor: null,
        })
        const opening = requests(openingSent)
        expect(opening.map(({ type }) => type)).toEqual(['page_subscribe'])
        expect(deliveryObject(opening[0]!.tableWindows)).not.toHaveProperty(
          tableKey,
        )
        expect(
          log.sent
            .slice(openingSent)
            .filter(
              ({ wire }) => deliveryObject(wire).type === 'table_rendered',
            ),
        ).toEqual([])

        const oldTable = await page
          .getByTestId('hilos-viewport-table')
          .elementHandle()
        expect(oldTable).not.toBeNull()
        const sent = log.sent.length
        const forwarded = gate.forwarded.length
        const serverFrames = gate.frames.length
        const replies = gate.replies.length
        const connections = log.connections.length
        const socket = log.connections.at(-1)!.socket
        await clearTableRefusal()
        void gate.arm()
        try {
          await setAdmin(userId!, admin)
          await expect.poll(() => gate.replies.length).toBeGreaterThan(replies)
          await expect(gear).toHaveAttribute(
            'data-access',
            admin ? 'full' : 'view',
          )
          await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
            'data-state',
            'loading',
          )
          await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
          await expect(page.getByTestId('legal-document-key')).toHaveCount(0)
          await expect.poll(() => tableRowKeys(page)).toEqual([])
          await expect(page.getByTestId('page-error')).toHaveCount(0)
          expect(await oldTable!.evaluate((node) => node.isConnected)).toBe(
            false,
          )
          expect((await browserLog.read()).application).toEqual([])
          expect(forwardedReplies(forwarded)).toEqual([])
          const session = gate.forwarded
            .slice(forwarded)
            .find(
              ({ wire }) => deliveryObject(wire).type === 'handshake_response',
            )!
          expect(
            deliveryObject(
              deliveryObject(deliveryObject(session.wire).data).entities,
            ).currentUser,
          ).toMatchObject({ id: userId, admin })
          const held = gate.frames
            .slice(serverFrames)
            .find(
              ({ wire }) =>
                deliveryObject(wire).type === 'page_response' &&
                deliveryObject(deliveryObject(wire).data).page === pageKey,
            )!
          expect(held.failure).toBeUndefined()
          const payload = deliveryObject(
            deliveryObject(deliveryObject(held.wire).data).payload,
          )
          expect(deliveryObject(payload.data).legalDocument).toMatchObject({
            document: 'terms',
          })
          expect(deliveryObject(payload.refusedWindows)).toEqual({})
          const window = deliveryObject(
            deliveryObject(payload.windows)[tableKey],
          )
          const keys = [
            'standard.file_access',
            'standard.retention',
            'standard.moderation',
            'standard.availability',
            'standard.account_rules',
            'standard.ownership',
          ]
          expect(window).toMatchObject({
            limit: 25,
            rowsBefore: 0,
            totalCount: 6,
            totalExact: true,
            sort: [{ field: 'position', direction: 'asc' }],
          })
          expect(
            (window.rows as unknown[]).map((row) => deliveryObject(row).rowKey),
          ).toEqual(keys)
          expect(requests(sent)).toEqual([])
          gate.release()
          await expectPageReady(expect, page)
          await expect(page).toHaveURL(path)
          await expect(search).toBeEnabled()
          await expect(sort).toBeEnabled()
          await expect(search).toHaveValue('')
          await expect.poll(() => tableRowKeys(page)).toEqual(keys)
          await expect(page.getByTestId('hilos-table-count')).toHaveText(
            '1 – 6 of 6',
          )
          await expect(page.getByRole('table')).toContainText(
            'Content is kept for 12 months.',
          )
          await expect(page.getByTestId('legal-document-key')).toHaveText(
            'terms',
          )
          await expect(page.getByTestId('hilos-table-unavailable')).toHaveCount(
            0,
          )
          await expect(strip).toHaveCount(admin ? 0 : 1)
          expect(forwardedReplies(forwarded)).toHaveLength(1)
          expect(forwardedReplies(forwarded)[0]!.wire).toEqual(held.wire)
          const resumed = await browserLog.read()
          expect(resumed.application).toHaveLength(1)
          expect(resumed.application[0]!.controller).not.toBe(
            before.application[0]!.controller,
          )
          expect(resumed.application[0]).toMatchObject({
            search: '',
            domSearch: '',
            filter: { document: 'terms' },
            rows: keys,
            domRows: keys,
            descriptor: {
              filter: { document: 'terms' },
              sort: [{ field: 'position', direction: 'asc' }],
              limit: 25,
            },
          })
          expect(resumed.socket).toBe(before.socket)
          expect(resumed.documentStarted).toBe(before.documentStarted)
          expect(requests(sent)).toEqual([])
          expect(log.connections).toHaveLength(connections)
          expect(log.connections.at(-1)).toMatchObject({ socket })
          expect(log.connections.at(-1)!.closed).toBeUndefined()
          await expectDocumentUnchanged(expect, page)
        } finally {
          gate.release()
          await oldTable?.dispose()
        }
        await changeWindow('Content', 'asc', [
          'standard.moderation',
          'standard.retention',
        ])
      })
    }

    let term = 'Content'
    let direction = 'asc'
    let keys = ['standard.moderation', 'standard.retention']
    await changeWindow(term, direction, keys)

    for (const admin of [true, false]) {
      await test.step(`${admin ? 'Grant' : 'Revoke'} removes the old live view until the guarded answer is released`, async () => {
        const oldTable = await page
          .getByTestId('hilos-viewport-table')
          .elementHandle()
        expect(oldTable).not.toBeNull()
        const sent = log.sent.length
        const before = await browserLog.read()
        expect(before.application).toHaveLength(1)
        const forwarded = gate.forwarded.length
        const serverFrames = gate.frames.length
        const replies = gate.replies.length
        const connections = log.connections.length
        void gate.arm()
        try {
          await setAdmin(userId!, admin)
          await expect.poll(() => gate.replies.length).toBeGreaterThan(replies)
          await expect(gear).toHaveAttribute(
            'data-access',
            admin ? 'full' : 'view',
          )
          await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
            'data-state',
            'loading',
          )
          await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
          await expect(page.getByTestId('legal-document-key')).toHaveCount(0)
          await expect.poll(() => tableRowKeys(page)).toEqual([])
          await expect(page.getByTestId('page-error')).toHaveCount(0)
          expect(await oldTable!.evaluate((node) => node.isConnected)).toBe(
            false,
          )
          // Native observers can see the proxy's upstream message. Its output
          // journal, the loading outlet and the absent live controller prove
          // the page has not processed the guarded answer yet.
          const waiting = await browserLog.read()
          expect(waiting.application).toEqual([])
          expect(forwardedReplies(forwarded)).toEqual([])
          expect(
            gate.forwarded
              .slice(forwarded)
              .filter(
                ({ wire }) =>
                  deliveryObject(wire).type === 'handshake_response',
              ),
          ).not.toEqual([])
          expect(requests(sent)).toEqual([])
          const held = gate.frames
            .slice(serverFrames)
            .find(
              ({ wire }) =>
                deliveryObject(wire).type === 'page_response' &&
                deliveryObject(deliveryObject(wire).data).page === pageKey,
            )!
          expect(held.failure).toBeUndefined()
          const payload = deliveryObject(
            deliveryObject(deliveryObject(held.wire).data).payload,
          )
          expect(deliveryObject(payload.data).legalDocument).toMatchObject({
            document: 'terms',
          })
          expect(deliveryObject(payload.refusedWindows)).toEqual({})
          const heldWindow = deliveryObject(
            deliveryObject(payload.windows)[tableKey],
          )
          expect(heldWindow.sort).toEqual([{ field: 'clauseKey', direction }])
          assertWindow(heldWindow, keys, direction)
          const session = gate.frames
            .slice(serverFrames)
            .find(
              ({ wire }) => deliveryObject(wire).type === 'handshake_response',
            )!
          expect(
            deliveryObject(
              deliveryObject(deliveryObject(session.wire).data).entities,
            ).currentUser,
          ).toMatchObject({ id: userId, admin })
          gate.release()
          await expectPageReady(expect, page)
          await expectControls(term, keys, direction)
          await expect(strip).toHaveCount(admin ? 0 : 1)
          expect(forwardedReplies(forwarded)).toHaveLength(1)
          const resumed = await browserLog.read()
          expect(forwardedReplies(forwarded)[0]!.wire).toEqual(held.wire)
          expect(resumed.application).toHaveLength(1)
          expect(resumed.application[0]!.controller).not.toBe(
            before.application[0]!.controller,
          )
          expect(resumed.application[0]).toMatchObject({
            search: term,
            domSearch: term,
            filter: { document: 'terms', search: term },
            rows: keys,
            domRows: keys,
            descriptor: {
              filter: { document: 'terms', search: term },
              sort: [{ field: 'clauseKey', direction }],
              limit: 25,
            },
          })
          expect(requests(sent)).toEqual([])
          expect(log.connections).toHaveLength(connections)
          await expectDocumentUnchanged(expect, page)
        } finally {
          gate.release()
          await oldTable?.dispose()
        }
      })
      // A new search and order must still use the server after each remount.
      term = admin ? 'What' : 'Content'
      direction = admin ? 'desc' : 'asc'
      keys = admin
        ? ['standard.ownership', 'standard.account_rules']
        : ['standard.moderation', 'standard.retention']
      await changeWindow(term, direction, keys)
    }

    await test.step('Reconnect retains the held window and explicit active navigation opens the preset', async () => {
      const connections = log.connections.length
      let sent = log.sent.length
      const packets = log.packets.length
      await dropSocket(page)
      await expect
        .poll(() => log.connections.length)
        .toBeGreaterThan(connections)
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await expectPageReady(expect, page)
      await expectControls(term, keys, direction)
      await expect.poll(() => pageReplies(packets)).toHaveLength(1)
      const asks = requests(sent)
      expect(asks.map(({ type }) => type)).toEqual(['page_subscribe'])
      expect(deliveryObject(asks[0]!.tableWindows)[tableKey]).toMatchObject({
        filter: { document: 'terms', search: term },
        sort: [{ field: 'clauseKey', direction }],
        limit: 25,
      })
      const payload = deliveryObject(
        deliveryObject(deliveryObject(pageReplies(packets)[0]!.wire).data)
          .payload,
      )
      assertWindow(
        deliveryObject(deliveryObject(payload.windows)[tableKey]),
        keys,
        direction,
      )
      sent = log.sent.length
      const openingPackets = log.packets.length
      await page.getByTestId(`hilos-page-tab-${pageKey}`).click()
      await expectPageReady(expect, page)
      await expect(search).toHaveValue('')
      await expect
        .poll(() => tableRowKeys(page))
        .toEqual([
          'standard.file_access',
          'standard.retention',
          'standard.moderation',
          'standard.availability',
          'standard.account_rules',
          'standard.ownership',
        ])
      await expect(page.getByTestId('hilos-table-count')).toHaveText(
        '1 – 6 of 6',
      )
      expect(requests(sent).map(({ type }) => type)).toEqual(['page_subscribe'])
      const opening = deliveryObject(
        deliveryObject(
          deliveryObject(pageReplies(openingPackets)[0]!.wire).data,
        ).payload,
      )
      expect(
        deliveryObject(deliveryObject(opening.windows)[tableKey]),
      ).toMatchObject({
        sort: [{ field: 'position', direction: 'asc' }],
        limit: 25,
        rowsBefore: 0,
        totalCount: 6,
      })
      await expectDocumentUnchanged(expect, page)
    })
  } catch (error) {
    errors.push(error)
  } finally {
    gate.dispose()
    for (const cleanup of [
      async () => clearTableRefusal(),
      async () => {
        if (userId !== undefined) await setAdmin(userId, false)
      },
      async () => setAdminViewMode(false),
      async () =>
        test.info().attach('legal-access-deliveries', {
          body: JSON.stringify({
            sent: log.sent,
            packets: log.packets,
            server: gate.frames,
            forwarded: gate.forwarded,
            browser: await browserLog.read(),
          }),
          contentType: 'application/json',
        }),
    ]) {
      try {
        await cleanup()
      } catch (error) {
        errors.push(error)
      }
    }
    log.stop()
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Legal access scenario or cleanup failed',
      { cause: errors[0] },
    )
})
