import { expect, test as base } from '../test.js'
import {
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  draftStatusWork,
  enterCalendarDate,
  enterPickerDateTime,
  expectPageReady,
  expectPickerDateTime,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectSelfReload,
  expectStatusHistoryFact,
  gotoMaintenance,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  latestStatusWork,
  markDocument,
  openStatusWorkSchedule,
  readerLocalDateTime,
  readerMillisecondMoment,
  showAnnouncedRows,
  shownByTestId,
  STATUS_HISTORY_PATH,
  STATUS_PATH,
  STATUS_WORK_CANCEL,
  STATUS_WORK_CHANGE,
  STATUS_WORK_SCHEDULE,
  STATUS_WORKS_PATH,
  statusHistoryDeliveries,
  statusHistoryWindow,
  statusWorkFixtures,
  statusWorkRows,
  submitStatusWork,
  tableRowKeys,
  trackedActionReplies,
  typeInto,
  watchDeliveryFrames,
  watchPublicStatus,
  watchStatusFrames,
  type StatusWorkDraft,
  type StatusWorkFixtureScope,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  enterProtectedMode,
  inspectProtectedMode,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'
import { beatStatusHeartbeat } from '../../../../../framework/frontend/scripts/statusMoments.mjs'

const test = base.extend<{
  statusWorks: StatusWorkFixtureScope
  cleanup: TestCleanup
}>({
  statusWorks: statusWorkFixtures(expect),
  cleanup: cleanupFixtures,
})
const READER_ZONE = 'Pacific/Johnston'
const OVERLAP =
  'This maintenance window overlaps another announcement. Choose a different time.'
const MOMENT_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  timeZoneName: 'short',
}

test.use({ colorScheme: 'light', timezoneId: READER_ZONE })

test('Angular Works preserves a refused draft, merges another admin edit and cancels the announcement live', async ({
  page,
  browser,
  baseURL,
  statusWorks,
  cleanup,
}) => {
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  cleanup.defer(() => otherContext.close())
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  cleanup.defer(() => guestContext.close())
  const other = await otherContext.newPage()
  const guest = await guestContext.newPage()
  const summary = await page.context().newPage()
  cleanup.defer(() => summary.close())
  const log = watchDeliveryFrames(page)
  const otherLog = watchDeliveryFrames(other)
  const overview = watchStatusFrames(summary)
  const publicLog = watchPublicStatus(guest)
  cleanup.defer(() => log.stop())
  cleanup.defer(() => otherLog.stop())
  cleanup.defer(() => overview.stop())
  cleanup.defer(() => publicLog.stop())
  cleanup.defer(() =>
    test.info().attach('angular-status-work-deliveries', {
      body: JSON.stringify({ log, otherLog, public: publicLog.reads }),
      contentType: 'application/json',
    }),
  )
  await grantAdminToSelf(expect, page)
  await grantAdminToSelf(expect, other)
  await gotoPage(expect, page, STATUS_WORKS_PATH)
  await gotoPage(expect, other, STATUS_WORKS_PATH)
  await gotoPage(expect, summary, STATUS_PATH)
  await gotoPublicStatus(expect, guest)
  await expect(guest.getByTestId('conn-state')).toHaveText('connected')

  const startsAt = Math.floor(Date.now() / 1000) * 1000 + 600_000
  const draft: StatusWorkDraft = {
    title: 'Angular service upgrade',
    description: 'A planned service window.',
    startsAt,
    endsAt: startsAt + 3_600_000,
    componentKeys: ['service'],
  }
  statusWorks.reserve(draft)
  await test.step('Schedule enters the window in the reader zone and waits for the owner acknowledgement', async () => {
    await openStatusWorkSchedule(expect, page)
    await draftStatusWork(expect, page, draft, READER_ZONE)
    await submitStatusWork(expect, page, log, STATUS_WORK_SCHEDULE)
  })
  await expect
    .poll(() => statusWorkRows(log).find((row) => row.title === draft.title))
    .toBeDefined()
  const work = statusWorkRows(log).find((row) => row.title === draft.title)!
  expect(work).toMatchObject({
    ...draft,
    version: 1,
    canceledAt: null,
    consumedAt: null,
    expiredAt: null,
  })
  await expect(
    shownByTestId(page, `hilos-status-work-title-${work.id}`),
  ).toHaveText(work.title)
  await expect(shownByTestId(page, `hilos-table-row-${work.id}`)).toContainText(
    await readerMillisecondMoment(
      page,
      work.startsAt,
      READER_ZONE,
      MOMENT_FORMAT,
    ),
  )
  const scheduled = await expectPublicStatusPair(
    expect,
    guest,
    publicLog,
    ({ status }) =>
      status.scheduledWorks.some((row) => row.id === work.publicId),
  )
  expect(scheduled.status.scheduledWorks).toContainEqual({
    id: work.publicId,
    ...draft,
  })
  await expect(guest.getByTestId('public-status-works')).toContainText(
    work.title,
  )
  await expect
    .poll(() => overview.latest()?.scheduledWorks)
    .toMatchObject({
      count: 1,
      nextWork: { id: work.publicId, ...draft },
    })
  await expect(summary.getByTestId('hilos-status-works-count')).toHaveText('1')
  await expect(summary.getByTestId('hilos-status-works-next')).toContainText(
    work.title,
  )

  // This second window exists only to make Change encounter a real overlap.
  const neighbor = {
    ...draft,
    title: 'Neighboring window',
    startsAt: startsAt - 120_000,
    endsAt: startsAt,
  }
  statusWorks.reserve(neighbor)
  await openStatusWorkSchedule(expect, page)
  await draftStatusWork(expect, page, neighbor, READER_ZONE)
  await submitStatusWork(expect, page, log, STATUS_WORK_SCHEDULE)
  await expect
    .poll(() => statusWorkRows(log).find((row) => row.title === neighbor.title))
    .toBeDefined()
  const anchor = statusWorkRows(log).find(
    (row) => row.title === neighbor.title,
  )!
  await showAnnouncedRows(expect, other)
  await expect(
    shownByTestId(other, `hilos-status-work-edit-${work.id}`),
  ).toBeVisible()

  await test.step('A server overlap refusal retains every entered value for correction', async () => {
    await dismissToasts(page)
    await shownByTestId(page, `hilos-status-work-edit-${work.id}`).click()
    const refused = {
      ...draft,
      title: 'Correct this window',
      startsAt: neighbor.startsAt,
    }
    await typeInto(page.getByTestId('hilos-status-work-title'), refused.title)
    const window = page.getByTestId('hilos-status-work-window')
    await enterPickerDateTime(
      expect,
      window,
      'Starts',
      refused.startsAt,
      READER_ZONE,
    )
    const since = log.sent.length
    await clickSubmit(expect, page.getByTestId('hilos-status-work-save'))
    await expect(page.getByTestId('hilos-status-work-error')).toContainText(
      OVERLAP,
    )
    await expect(page.getByTestId('hilos-status-work-save')).toBeEnabled()
    await expect
      .poll(() => trackedActionReplies(log, STATUS_WORK_CHANGE, since).length)
      .toBe(1)
    expect(
      trackedActionReplies(log, STATUS_WORK_CHANGE, since)[0].frame.type,
    ).toBe('action_error')
    expect(
      deliveryObject(
        trackedActionReplies(log, STATUS_WORK_CHANGE, since)[0].request.wire,
      ).data,
    ).toEqual({
      ...refused,
      workId: work.id,
      publicId: work.publicId,
      expectedVersion: work.version,
    })
    await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
      refused.title,
    )
    await expect(page.getByTestId('hilos-status-work-description')).toHaveValue(
      refused.description,
    )
    await expectPickerDateTime(
      expect,
      window,
      'Starts',
      refused.startsAt,
      READER_ZONE,
    )
    await expectPickerDateTime(
      expect,
      window,
      'Ends',
      refused.endsAt,
      READER_ZONE,
    )
    await expect(
      page.getByTestId('hilos-status-work-component-service'),
    ).toBeChecked()
  })

  const changed = {
    ...draft,
    title: 'Updated service upgrade',
    startsAt: neighbor.startsAt - 120_000,
    endsAt: neighbor.startsAt,
  }
  const mine = 'My service upgrade'
  await test.step('Two admins resolve the same field through the ordinary row edit session', async () => {
    await shownByTestId(other, `hilos-status-work-edit-${work.id}`).click()
    await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
    await typeInto(other.getByTestId('hilos-status-work-title'), mine)
    await typeInto(page.getByTestId('hilos-status-work-title'), changed.title)
    const window = page.getByTestId('hilos-status-work-window')
    await enterPickerDateTime(
      expect,
      window,
      'Starts',
      changed.startsAt,
      READER_ZONE,
    )
    await enterPickerDateTime(
      expect,
      window,
      'Ends',
      changed.endsAt,
      READER_ZONE,
    )
    await submitStatusWork(expect, page, log, STATUS_WORK_CHANGE)
    await expect(other.getByTestId('hilos-status-work-notice')).toContainText(
      'Changed elsewhere',
    )
    await expect(other.getByTestId('hilos-status-work-title')).toHaveValue(mine)
    await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
    await expect(other.getByTestId('hilos-status-work-save')).toBeDisabled()
    await expect
      .poll(() => latestStatusWork(otherLog, work.publicId)?.version)
      .toBe(2)
    expect(await tableRowKeys(other, 'hilos-status-works-table')).toEqual([
      String(anchor.id),
      String(work.id),
    ])
    await expect(
      shownByTestId(other, `hilos-table-pending-move-${work.id}`),
    ).toBeVisible()
    await other.getByTestId('conflict-accept-mine').focus()
    await other.getByTestId('conflict-accept-mine').press('Enter')
    await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
    await expect(other.getByTestId('hilos-status-work-title')).toHaveValue(mine)
    await submitStatusWork(expect, other, otherLog, STATUS_WORK_CHANGE)
    await expect
      .poll(() => latestStatusWork(otherLog, work.publicId))
      .toMatchObject({
        ...changed,
        title: mine,
        version: 3,
      })
    await expect
      .poll(() => tableRowKeys(other, 'hilos-status-works-table'))
      .toEqual([String(work.id), String(anchor.id)])
  })
  await expect(
    shownByTestId(page, `hilos-status-work-title-${work.id}`),
  ).toHaveText(mine)
  const changedPair = await expectPublicStatusPair(
    expect,
    guest,
    publicLog,
    ({ status }) =>
      status.scheduledWorks.some(
        (row) => row.id === work.publicId && row.title === mine,
      ),
  )
  expect(changedPair.status.scheduledWorks).toContainEqual({
    id: work.publicId,
    ...changed,
    title: mine,
  })
  await expect(guest.getByTestId('public-status-works')).toContainText(mine)
  await expect
    .poll(() => overview.latest()?.scheduledWorks)
    .toMatchObject({ count: 2, nextWork: { title: mine } })
  await expect(summary.getByTestId('hilos-status-works-count')).toHaveText('2')
  await expect(summary.getByTestId('hilos-status-works-next')).toContainText(
    mine,
  )

  await test.step('Cancel settles on its ack before Works, Overview and public upcoming change', async () => {
    await dismissToasts(page)
    await shownByTestId(page, `hilos-status-work-cancel-${work.id}`).click()
    await expect(
      page.getByTestId('hilos-status-work-cancel-target'),
    ).toHaveText(mine)
    await submitStatusWork(expect, page, log, STATUS_WORK_CANCEL)
    await expect(
      page.getByTestId(`hilos-status-work-cancel-${work.id}`),
    ).toHaveCount(0)
    await expect(
      shownByTestId(other, `hilos-table-pending-remove-${work.id}`),
    ).toBeVisible()
    await other.getByTestId('hilos-table-apply').click()
    await expect(other.getByTestId('hilos-table-apply')).toBeHidden()
    await expect(
      other.getByTestId(`hilos-status-work-cancel-${work.id}`),
    ).toHaveCount(0)
    await expect(summary.getByTestId('hilos-status-works-count')).toHaveText(
      '1',
    )
    await expect.poll(() => overview.latest()?.scheduledWorks?.count).toBe(1)
    await expectPublicStatusPair(
      expect,
      guest,
      publicLog,
      ({ status }) =>
        status.scheduledWorks.length === 1 &&
        status.scheduledWorks[0].title === neighbor.title,
    )
    await expect(guest.getByTestId('public-status-works')).not.toContainText(
      mine,
    )
  })
})

test('Angular Service status reads Maintenance during a real freeze and History retains its completed period', async ({
  page,
  browser,
  baseURL,
  cleanup,
}) => {
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()
  const log = watchDeliveryFrames(page)
  const overview = watchStatusFrames(page)
  const publicLog = watchPublicStatus(guest)
  cleanup.defer(() => log.stop())
  cleanup.defer(() => overview.stop())
  cleanup.defer(() =>
    test.info().attach('angular-status-freeze-history', {
      body: JSON.stringify({
        log,
        overview: overview.overviews,
        public: publicLog.reads,
      }),
      contentType: 'application/json',
    }),
  )
  cleanup.defer(() => publicLog.stop())
  // Close before draining captures that may still belong to an old document.
  // The early registration also covers failures before observer setup.
  cleanup.defer(() => guestContext.close())
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, STATUS_PATH)
  await expect.poll(() => overview.latest()).toBeDefined()
  const baseline = overview.latest()!.historyCount
  expect(baseline).toEqual(expect.any(Number))
  if (baseline === null) throw new Error('Status history count is unavailable')
  await expect(page.getByTestId('hilos-status-history-count')).toHaveText(
    String(baseline),
  )
  await beatStatusHeartbeat()
  await gotoPublicStatus(expect, guest)
  const initial = await expectPublicStatusPair(expect, guest, publicLog)
  expect(initial.status.scheduledWorks).toEqual([])
  expect(
    initial.status.maintenancePeriods.every(
      (period) => period.completionObservedAt !== null,
    ),
  ).toBe(true)
  const previousPeriods = new Set(
    initial.status.maintenancePeriods.map((period) => period.id),
  )
  expect((await inspectProtectedMode()).phase).toBe('inactive')
  let ownFreeze = false
  let liftMarked = false
  let reloadExpected = false
  // Registered in reverse: arm the documents, lift unconditionally, then settle
  // both reloads and the completed import before observers and contexts close.
  // Separate callbacks let fixture teardown attempt each stage and keep errors.
  cleanup.defer(async () => {
    if (!ownFreeze || !reloadExpected) return
    await expectPageReady(expect, page)
    await beatStatusHeartbeat()
    await expect
      .poll(() => overview.latest())
      .toMatchObject({
        historyCount: baseline + 1,
        healthKnown: true,
      })
  })
  for (const document of [page, guest])
    cleanup.defer(async () => {
      if (ownFreeze && reloadExpected)
        await expectSelfReload(
          document,
          test.info().project.use.navigationTimeout,
        )
    })
  cleanup.defer(async () => {
    if (ownFreeze) await openProtectedModeIfAny()
  })
  cleanup.defer(async () => {
    if (!ownFreeze || liftMarked) return
    // Even a refused enter is followed by the unconditional open: it may have
    // landed after the caller gave up. Arm before that cleanup drive too.
    reloadExpected = true
    await markDocument(page)
    await markDocument(guest)
    liftMarked = true
    reloadExpected =
      (await inspectProtectedMode()).phase !== 'inactive' ||
      (await page.getByTestId('maintenance').isVisible())
  })
  ownFreeze = true
  expect(await enterProtectedMode('e2e-angular-status-work')).toBe('active')
  await expect(page.getByTestId('maintenance')).toBeVisible()
  await beatStatusHeartbeat()
  await gotoMaintenance(expect, guest, '/')
  const link = guest.getByTestId('maintenance-service-status')
  await expect(link).toHaveAccessibleName('Service status')
  await expect(link).toHaveAttribute('href', '/status')
  await link.click()
  await expect(guest).toHaveURL(/\/status$/)
  const active = await expectPublicStatusPair(
    expect,
    guest,
    publicLog,
    ({ status }) =>
      status.maintenancePeriods.some(
        (period) =>
          !previousPeriods.has(period.id) &&
          period.completionObservedAt === null,
      ),
  )
  const period = active.status.maintenancePeriods.find(
    (entry) =>
      !previousPeriods.has(entry.id) && entry.completionObservedAt === null,
  )!
  expect(period).toMatchObject({
    workId: null,
    announcementKnown: true,
    title: 'Maintenance',
    componentKeys: [],
    endedAt: null,
    completionObservedAt: null,
  })
  await expect(guest.getByTestId('public-status-banner')).toContainText(
    'Maintenance',
  )
  await expect(guest.getByTestId('public-status-maintenance')).toContainText(
    period.title,
  )
  await expect(guest.getByTestId('public-status-components')).toBeVisible()
  expectPublicStatusReadOnly(expect, publicLog)
  await markDocument(page)
  await markDocument(guest)
  liftMarked = true
  reloadExpected = true
  await openProtectedMode()
  await expectSelfReload(page, test.info().project.use.navigationTimeout)
  await expectPageReady(expect, page)
  await expectSelfReload(guest, test.info().project.use.navigationTimeout)
  await beatStatusHeartbeat()
  const completedPair = await expectPublicStatusPair(
    expect,
    guest,
    publicLog,
    ({ status }) =>
      status.maintenancePeriods.some(
        (entry) =>
          entry.id === period.id && entry.completionObservedAt !== null,
      ),
  )
  const completed = completedPair.status.maintenancePeriods.find(
    (entry) => entry.id === period.id,
  )!
  await expect(guest.getByTestId('public-status-maintenance')).toHaveCount(0)
  await expect(guest.getByTestId('public-status-history')).toContainText(
    period.title,
  )
  expect(completed.completionObservedAt).toBeGreaterThanOrEqual(
    period.startedAt,
  )
  expectPublicStatusReadOnly(expect, publicLog)
  // The file observation can complete before its DB import. Let Overview's
  // completed count and known health prove import and recovery after the lift.
  await expect
    .poll(() => overview.latest())
    .toMatchObject({
      historyCount: baseline + 1,
      healthKnown: true,
    })
  await expect(page.getByTestId('hilos-status-history-count')).toHaveText(
    String(baseline + 1),
  )
  ownFreeze = false
  await expect(page.getByTestId('hilos-status-works-count')).toHaveText('0')

  await test.step('History matches the real completed HTTP period, filters it and survives F5', async () => {
    const since = log.packets.length
    await page.getByTestId('hilos-page-tab-hilos_status_history').click()
    await expect(page).toHaveURL(new RegExp(`${STATUS_HISTORY_PATH}$`))
    await expectPageReady(expect, page)
    await expect
      .poll(() =>
        statusHistoryDeliveries(log, since)
          .flatMap((entry) => entry.rows)
          .find(
            (row) =>
              row.kind === 'maintenance' &&
              row.startedAt === period.startedAt &&
              row.completionObservedAt === completed.completionObservedAt,
          ),
      )
      .toBeDefined()
    const facts = statusHistoryDeliveries(log, since)[0].rows.filter(
      (row) =>
        row.kind === 'maintenance' &&
        row.startedAt === period.startedAt &&
        row.completionObservedAt === completed.completionObservedAt,
    )
    expect(facts).toHaveLength(1)
    const fact = facts[0]
    expect(fact).toMatchObject({
      kind: 'maintenance',
      startedAt: period.startedAt,
      endedAt: completed.endedAt,
      completionObservedAt: completed.completionObservedAt,
      outcome: 'maintenance_completed',
      title: period.title,
      componentKeys: period.componentKeys,
      announcementKnown: period.announcementKnown,
      workPublicId: period.workId,
    })
    await expectStatusHistoryFact(expect, page, fact)
    const row = shownByTestId(page, `hilos-table-row-${fact.id}`)
    await expect(row).toContainText(
      await readerMillisecondMoment(
        page,
        fact.startedAt,
        READER_ZONE,
        MOMENT_FORMAT,
      ),
    )
    if (fact.endedAt === null) {
      await expect(
        row.getByRole('cell', { name: 'Unknown', exact: true }),
      ).toBeVisible()
      await expect(row).toContainText(
        `Completion observed at ${await readerMillisecondMoment(page, fact.completionObservedAt!, READER_ZONE, MOMENT_FORMAT)}`,
      )
    }
    let sent = log.sent.length
    await typeInto(
      page.getByTestId('hilos-table-search'),
      'no-angular-history-match',
    )
    await expect.poll(() => statusHistoryWindow(log, sent)?.rows).toEqual([])
    await expect(page.getByTestId(`hilos-table-row-${fact.id}`)).toHaveCount(0)
    sent = log.sent.length
    await typeInto(page.getByTestId('hilos-table-search'), fact.title)
    await expect
      .poll(() => statusHistoryWindow(log, sent)?.rows)
      .toContainEqual(fact)
    await expectStatusHistoryFact(expect, page, fact)
    sent = log.sent.length
    const outcome = page.getByTestId('hilos-table-filter-outcome')
    await outcome.getByTestId('hilos-dropdown-toggle').click()
    await outcome.getByTestId('hilos-dropdown-option-3').click()
    await expect
      .poll(() => statusHistoryWindow(log, sent)?.rows)
      .toContainEqual(fact)
    expect(
      deliveryObject(
        deliveryObject(statusHistoryWindow(log, sent)!.request.wire).filter,
      ),
    ).toMatchObject({ outcome: 'maintenance_completed' })
    await page.getByTestId('hilos-table-filter-from').click()
    const date = (
      await readerLocalDateTime(page, fact.startedAt, READER_ZONE)
    ).slice(0, 10)
    sent = log.sent.length
    await enterCalendarDate(
      expect,
      page.getByTestId('hilos-table-filter-from-from'),
      date,
    )
    await enterCalendarDate(
      expect,
      page.getByTestId('hilos-table-filter-from-to'),
      date,
    )
    // The filter carries the zone as the browser names it: Chromium reports
    // the configured link Pacific/Johnston as its canonical Pacific/Honolulu.
    const zone = await page.evaluate(
      () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    )
    await expect
      .poll(() =>
        deliveryObject(
          deliveryObject(statusHistoryWindow(log, sent)?.request.wire).filter,
        ),
      )
      .toMatchObject({ from: date, to: date, timeZone: zone })
    await expect
      .poll(() => statusHistoryWindow(log, sent)?.rows)
      .toContainEqual(fact)
    await expectStatusHistoryFact(expect, page, fact)
    const reloading = log.packets.length
    await page.reload()
    await expectPageReady(expect, page)
    expect(statusHistoryDeliveries(log, reloading)[0].rows).toContainEqual(fact)
    await expectStatusHistoryFact(expect, page, fact)
  })
})
