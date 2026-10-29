import { expect as baseExpect, test as base } from '@playwright/test'
import {
  STATUS_PATH,
  STATUS_WORKS_PATH,
  STATUS_WORK_SCHEDULE,
  STATUS_WORK_CHANGE,
  STATUS_WORK_CANCEL,
  STATUS_HISTORY_PATH,
  statusHistoryDeliveries,
  expectStatusHistoryFact,
  armViewerSubmit,
  clickSubmit,
  controlSocketReconnect,
  deliveryObject,
  dismissToasts,
  draftStatusWork,
  expectPublicStatusPair,
  expectPublicStatusReadOnly,
  expectPageReady,
  expectSelfReload,
  expectStatusHead,
  expectStatusMailAlternatives,
  expectTrackedActionReply,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  latestStatusWork,
  markDocument,
  openMountedStatusMailLink,
  openStatusWorkSchedule,
  showAnnouncedRows,
  shownByTestId,
  statusWorkFixtures,
  statusWorkRows,
  submitStatusWork,
  subscribeToStatus,
  tableRowKeys,
  trackedActionReplies,
  typeInto,
  uniqueEmail,
  watchDeliveryFrames,
  watchPublicStatus,
  type StatusWorkDraft,
  type StatusWorkFixtureScope,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  mailsTo,
  waitForMailTo,
} from '../../../../../framework/frontend/scripts/standMailbox.mjs'
import {
  STATUS_CONFIRM_SUBJECT,
  statusMailLinks,
} from '../../../../../framework/frontend/scripts/statusMail.mjs'
import {
  enterProtectedMode,
  inspectProtectedMode,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'
import { STATUS_EXPECT_TIMEOUT_MS } from '../status.config.js'

const expect = baseExpect.configure({ timeout: STATUS_EXPECT_TIMEOUT_MS })
const test = base.extend<{ statusWorks: StatusWorkFixtureScope }>({
  statusWorks: statusWorkFixtures(expect),
})
const READER_ZONE = 'Pacific/Honolulu'
const OVERLAP =
  'This maintenance window overlaps another announcement. Choose a different time.'
const VERSION_RACE =
  'This maintenance announcement changed in another window. Review its current values before saving.'

test.use({ colorScheme: 'light', timezoneId: READER_ZONE })

test('Status Works schedule, change and cancel reach live windows, public files and real mail while another admin conflicts', async ({
  page,
  browser,
  baseURL,
  statusWorks,
}) => {
  // Confirmation plus five event letters, two admin connections and coherent HTTP pairs.
  test.slow()
  const otherContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  const publicContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  const other = await otherContext.newPage()
  const guest = await publicContext.newPage()
  const adminLog = watchDeliveryFrames(page)
  const otherLog = watchDeliveryFrames(other)
  const publicLog = watchPublicStatus(guest)
  const crafted = await armViewerSubmit(other)
  const email = uniqueEmail()
  const startsAt = Math.floor(Date.now() / 1000) * 1000 + 600_000
  const draft: StatusWorkDraft = {
    title: 'Upgrade <primary> & replicas',
    description: 'Read <b>this announcement</b> & keep the window.',
    startsAt,
    endsAt: startsAt + 3_600_000,
    componentKeys: ['settings'],
  }
  statusWorks.reserve(draft)
  try {
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, publicLog.wire, email)
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    const confirming = publicLog.wire.sent.length
    await openMountedStatusMailLink(expect, guest, links.confirm!)
    await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      publicLog.wire,
      'status_subscription_confirm',
      confirming,
      { status: 'subscribed' },
    )
    await grantAdminToSelf(expect, page)
    await grantAdminToSelf(expect, other)
    await gotoPage(expect, page, STATUS_WORKS_PATH)
    await gotoPage(expect, other, STATUS_WORKS_PATH)
    await expectStatusHead(expect, page, 'works')
    await openStatusWorkSchedule(expect, page)
    await openStatusWorkSchedule(expect, other)
    await draftStatusWork(expect, page, draft)
    await draftStatusWork(expect, other, {
      ...draft,
      title: 'Conflicting announcement',
    })
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_SCHEDULE)
    await expect
      .poll(() =>
        statusWorkRows(adminLog).find((work) => work.title === draft.title),
      )
      .toBeDefined()
    const work = statusWorkRows(adminLog).find(
      (work) => work.title === draft.title,
    )!
    expect(work).toMatchObject({
      version: 1,
      startsAt: draft.startsAt,
      endsAt: draft.endsAt,
      componentKeys: draft.componentKeys,
      canceledAt: null,
      consumedAt: null,
      expiredAt: null,
    })
    await expect(
      shownByTestId(page, `hilos-status-work-title-${work.id}`),
    ).toHaveText(draft.title)
    const scheduled = await waitForMailTo(
      email,
      `Scheduled maintenance: ${draft.title}`,
    )
    expectStatusMailAlternatives(
      expect,
      scheduled,
      statusMailLinks(scheduled),
      READER_ZONE,
    )
    expect(scheduled.text).toContain(draft.description)
    expect(scheduled.html).toContain('Upgrade &lt;primary&gt; &amp; replicas')
    expect(scheduled.html).toContain(
      'Read &lt;b&gt;this announcement&lt;/b&gt; &amp; keep the window.',
    )
    await expect
      .poll(() => latestStatusWork(adminLog, work.publicId)?.lastMailQueuedAt)
      .toEqual(expect.any(Number))
    const firstPair = await expectPublicStatusPair(
      expect,
      guest,
      publicLog,
      ({ status }) =>
        status.scheduledWorks.some((entry) => entry.id === work.publicId),
    )
    expect(firstPair.status.scheduledWorks).toEqual([
      {
        id: work.publicId,
        title: draft.title,
        description: draft.description,
        componentKeys: draft.componentKeys,
        startsAt: draft.startsAt,
        endsAt: draft.endsAt,
      },
    ])
    await expect(guest.getByTestId('public-status-works')).toContainText(
      draft.title,
    )
    await expect(guest.getByTestId('public-status-works')).toContainText(
      draft.description,
    )
    // The second form was already valid when the first one committed. The server,
    // rather than a local disabled control, must refuse the real second request.
    const rejecting = otherLog.sent.length
    await clickSubmit(expect, other.getByTestId('hilos-status-work-save'))
    await expect(other.getByTestId('hilos-status-work-error')).toContainText(
      OVERLAP,
    )
    await expect(other.getByTestId('hilos-status-work-save')).toBeEnabled()
    await expect
      .poll(
        () =>
          trackedActionReplies(otherLog, STATUS_WORK_SCHEDULE, rejecting)
            .length,
      )
      .toBe(1)
    expect(
      trackedActionReplies(otherLog, STATUS_WORK_SCHEDULE, rejecting)[0].frame
        .type,
    ).toBe('action_error')
    await other.keyboard.press('Escape')
    await other.getByTestId('modal-confirm-discard').press('Enter')
    await expect(other.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect(
      shownByTestId(other, `hilos-status-work-title-${work.id}`),
    ).toHaveText(draft.title)
    await expect(page.getByTestId('hilos-table-apply')).toBeHidden()
    await dismissToasts(page)
    await dismissToasts(other)
    // Two nonoverlapping rows make the later edit an actual order change.
    const anchorDraft = {
      ...draft,
      title: 'The neighboring window',
      startsAt: draft.startsAt - 120_000,
      endsAt: draft.startsAt,
    }
    statusWorks.reserve(anchorDraft)
    await openStatusWorkSchedule(expect, page)
    await draftStatusWork(expect, page, anchorDraft)
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_SCHEDULE)
    await expect
      .poll(() =>
        statusWorkRows(adminLog).find((row) => row.title === anchorDraft.title),
      )
      .toBeDefined()
    const anchor = statusWorkRows(adminLog).find(
      (row) => row.title === anchorDraft.title,
    )!
    await waitForMailTo(email, `Scheduled maintenance: ${anchorDraft.title}`)
    await showAnnouncedRows(expect, other)
    await expect
      .poll(() => tableRowKeys(other, 'hilos-status-works-table'))
      .toEqual([String(anchor.id), String(work.id)])
    await shownByTestId(other, `hilos-status-work-edit-${work.id}`).click()
    await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
    await typeInto(
      other.getByTestId('hilos-status-work-title'),
      'My focused draft',
    )
    await shownByTestId(page, `hilos-status-work-edit-${work.id}`).click()
    const changed = {
      ...draft,
      title: 'Upgrade <secondary> & replicas',
      startsAt: anchorDraft.startsAt - 120_000,
      endsAt: anchorDraft.startsAt,
    }
    await draftStatusWork(expect, page, changed)
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_CHANGE)
    await expect(
      other.getByTestId('hilos-status-work-notice-slot'),
    ).toHaveAttribute('role', 'status')
    await expect(other.getByTestId('hilos-status-work-notice')).toContainText(
      'Changed elsewhere',
    )
    await expect(other.getByTestId('hilos-status-work-title')).toHaveValue(
      'My focused draft',
    )
    await expect(other.getByTestId('hilos-status-work-save')).toBeDisabled()
    await expect(
      other.getByTestId('conflict-accept-mine'),
    ).toHaveAccessibleName('Keep mine')
    await expect(
      other.getByTestId('conflict-accept-theirs'),
    ).toHaveAccessibleName('Take theirs')
    await expect
      .poll(() => latestStatusWork(otherLog, work.publicId)?.version)
      .toBe(2)
    expect(
      otherLog.sent.some(({ wire }) => {
        const frame = deliveryObject(wire)
        return (
          frame.type === 'table_row_focus' &&
          frame.tableKey === 'hilosStatusWorks' &&
          String(frame.rowKey) === String(work.id)
        )
      }),
    ).toBe(true)
    const changedMail = await waitForMailTo(
      email,
      `Maintenance changed: ${changed.title}`,
    )
    expectStatusMailAlternatives(
      expect,
      changedMail,
      statusMailLinks(changedMail),
      READER_ZONE,
    )
    expect(changedMail.text).toContain(
      'What changed:\nTitle, Scheduled start, Scheduled end.',
    )
    const changedPair = await expectPublicStatusPair(
      expect,
      guest,
      publicLog,
      ({ status }) =>
        status.scheduledWorks.some(
          (entry) =>
            entry.id === work.publicId && entry.startsAt === changed.startsAt,
        ),
    )
    expect(changedPair.status.scheduledWorks).toEqual([
      { id: work.publicId, ...changed },
      { id: anchor.publicId, ...anchorDraft },
    ])
    await expect(guest.getByTestId('public-status-works')).toContainText(
      changed.title,
    )
    // A captured old version is submitted only once on that same real connection.
    // Its correlated refusal proves the owner guard even though the UI is live.
    for (const [requestId, publicId, expectedVersion, message] of [
      ['works-stale-version', work.publicId, 1, VERSION_RACE],
      [
        'works-stale-identity',
        `${work.publicId[0] === '0' ? '1' : '0'}${work.publicId.slice(1)}`,
        2,
        'This maintenance announcement no longer exists. Refresh the list.',
      ],
    ] as const) {
      const reply = await crafted.submit(
        JSON.stringify({
          type: 'action',
          action: STATUS_WORK_CHANGE,
          requestId,
          data: { ...changed, workId: work.id, publicId, expectedVersion },
        }),
        requestId,
      )
      expect(reply.failure).toBeUndefined()
      const frame = deliveryObject(reply.wire)
      expect(frame.type).toBe('action_error')
      expect(deliveryObject(frame.data)).toMatchObject({
        action: STATUS_WORK_CHANGE,
        reason: message,
      })
    }
    await other.getByTestId('conflict-accept-theirs').focus()
    await other.getByTestId('conflict-accept-theirs').press('Enter')
    await expect(other.getByTestId('hilos-status-work-title')).toHaveValue(
      changed.title,
    )
    await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
    await other.keyboard.press('Escape')
    await expect(other.getByTestId('hilos-status-work-form')).toBeHidden()
    await expect(
      shownByTestId(other, `hilos-table-pending-move-${work.id}`),
    ).toBeVisible()
    await expect
      .poll(() => tableRowKeys(other, 'hilos-status-works-table'))
      .toEqual([String(anchor.id), String(work.id)])
    await other.getByTestId('hilos-table-apply').click()
    await expect(other.getByTestId('hilos-table-apply')).toBeHidden()
    await expect(
      shownByTestId(other, `hilos-status-work-title-${work.id}`),
    ).toHaveText(changed.title)
    await expect
      .poll(() => tableRowKeys(other, 'hilos-status-works-table'))
      .toEqual([String(work.id), String(anchor.id)])
    await expect
      .poll(() => tableRowKeys(page, 'hilos-status-works-table'))
      .toEqual([String(work.id), String(anchor.id)])
    await expect(page.getByTestId('hilos-table-apply')).toBeHidden()
    await gotoPage(expect, other, STATUS_PATH)
    await expect(other.getByTestId('hilos-status-works-count')).toHaveText('2')
    await expect(other.getByTestId('hilos-status-works-next')).toContainText(
      changed.title,
    )
    await dismissToasts(page)
    await shownByTestId(page, `hilos-status-work-cancel-${work.id}`).click()
    await expect(page.getByTestId('modal')).toHaveAccessibleName(
      'Cancel scheduled maintenance',
    )
    await expect(page.getByTestId('modal')).toBeFocused()
    await expect(
      page.getByTestId('hilos-status-work-cancel-target'),
    ).toHaveText(changed.title)
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_CANCEL)
    const canceled = await waitForMailTo(
      email,
      `Maintenance canceled: ${changed.title}`,
    )
    expectStatusMailAlternatives(
      expect,
      canceled,
      statusMailLinks(canceled),
      READER_ZONE,
    )
    await expect(other.getByTestId('hilos-status-works-count')).toHaveText('1')
    await expectPublicStatusPair(
      expect,
      guest,
      publicLog,
      ({ status }) =>
        status.scheduledWorks.length === 1 &&
        status.scheduledWorks[0].id === anchor.publicId,
    )
    await expect(guest.getByTestId('public-status-works')).not.toContainText(
      changed.title,
    )
    await dismissToasts(page)
    await shownByTestId(page, `hilos-status-work-cancel-${anchor.id}`).click()
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_CANCEL)
    await waitForMailTo(email, `Maintenance canceled: ${anchorDraft.title}`)
    await expectPublicStatusPair(
      expect,
      guest,
      publicLog,
      ({ status }) =>
        status.scheduledWorks.length === 0 &&
        status.revision !== changedPair.status.revision,
    )
    await expect(guest.getByTestId('public-status-works')).toHaveCount(0)
    await expect(other.getByTestId('hilos-status-works-count')).toHaveText('0')
    expect((await mailsTo(email)).map((mail) => mail.subject).sort()).toEqual(
      [
        STATUS_CONFIRM_SUBJECT,
        `Scheduled maintenance: ${draft.title}`,
        `Scheduled maintenance: ${anchorDraft.title}`,
        `Maintenance changed: ${changed.title}`,
        `Maintenance canceled: ${changed.title}`,
        `Maintenance canceled: ${anchorDraft.title}`,
      ].sort(),
    )
  } finally {
    await test.info().attach('status-works-real-deliveries', {
      body: JSON.stringify({
        admin: adminLog,
        other: otherLog,
        files: publicLog.reads,
        pairs: publicLog.displayed,
        crafted: crafted.frames,
      }),
      contentType: 'application/json',
    })
    crafted.dispose()
    adminLog.stop()
    otherLog.stop()
    await publicLog.stop()
    await otherContext.close()
    await publicContext.close()
  }
})

test('Status Works uses the published announcement during an actual freeze and completes once after lift before its planned end', async ({
  page,
  browser,
  baseURL,
  statusWorks,
}) => {
  // A short future start, confirmation/event mail, actual freeze and owner recovery.
  test.slow()
  const guestContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  const coldContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
    timezoneId: READER_ZONE,
  })
  const guest = await guestContext.newPage()
  const cold = await coldContext.newPage()
  const coldSocket = await controlSocketReconnect(cold)
  const adminLog = watchDeliveryFrames(page)
  const publicLog = watchPublicStatus(guest)
  const coldLog = watchPublicStatus(cold)
  const email = uniqueEmail()
  try {
    await gotoPublicStatus(expect, guest)
    await expect(guest.getByTestId('conn-state')).toHaveText('connected')
    await subscribeToStatus(expect, guest, publicLog.wire, email)
    const links = statusMailLinks(
      await waitForMailTo(email, STATUS_CONFIRM_SUBJECT),
    )
    const confirming = publicLog.wire.sent.length
    await openMountedStatusMailLink(expect, guest, links.confirm!)
    await expect(guest.getByTestId('status-subscription-form')).toBeHidden()
    await expectTrackedActionReply(
      expect,
      publicLog.wire,
      'status_subscription_confirm',
      confirming,
      { status: 'subscribed' },
    )
    await grantAdminToSelf(expect, page)
    await gotoPage(expect, page, STATUS_WORKS_PATH)
    await openStatusWorkSchedule(expect, page)
    const startsAt = Math.floor(Date.now() / 1000) * 1000 + 20_000
    const draft = {
      title: `The announced real freeze ${startsAt}`,
      description: 'Service closes only for the actual freeze.',
      startsAt,
      endsAt: startsAt + 3_600_000,
      componentKeys: ['settings'],
    }
    statusWorks.reserve(draft)
    await draftStatusWork(expect, page, draft)
    await submitStatusWork(expect, page, adminLog, STATUS_WORK_SCHEDULE)
    await expect
      .poll(() =>
        statusWorkRows(adminLog).find((work) => work.title === draft.title),
      )
      .toBeDefined()
    const work = statusWorkRows(adminLog).find(
      (work) => work.title === draft.title,
    )!
    const scheduled = await waitForMailTo(
      email,
      `Scheduled maintenance: ${draft.title}`,
    )
    expectStatusMailAlternatives(
      expect,
      scheduled,
      statusMailLinks(scheduled),
      READER_ZONE,
    )
    await expectPublicStatusPair(expect, guest, publicLog, ({ status }) =>
      status.scheduledWorks.some((entry) => entry.id === work.publicId),
    )
    // The public heartbeat is the observable clock; no past date or fake clock
    // bypasses the owner's strictly-future schedule guard.
    if (publicLog.pairs().at(-1)!.heartbeat.aliveAt < startsAt) {
      await guest.waitForResponse(
        async (response) =>
          new URL(response.url()).pathname === '/status-data/heartbeat.json' &&
          response.ok() &&
          (deliveryObject(await response.json()).aliveAt as number) >= startsAt,
      )
    }
    expect(Date.now()).toBeLessThan(draft.endsAt)
    expect(await enterProtectedMode('e2e-status-announced-work')).toBe('active')
    const phase = await inspectProtectedMode()
    expect(phase).toMatchObject({ phase: 'active', agentStartGateClosed: true })
    expect(phase.stoppedAgents).toContain('hilos_settings_library')
    expect(phase.stoppedAgents).not.toContain('hilos_status')
    // A never-welcomed document reads nginx files while its first socket is held.
    await coldSocket.hold()
    await gotoPublicStatus(expect, cold)
    const active = await expectPublicStatusPair(
      expect,
      cold,
      coldLog,
      ({ status }) =>
        status.maintenancePeriods.some(
          (period) =>
            period.workId === work.publicId &&
            period.completionObservedAt === null,
        ),
    )
    expect(
      active.status.components.every(
        (component) => component.state === 'maintenance',
      ),
    ).toBe(true)
    const period = active.status.maintenancePeriods.find(
      (period) => period.workId === work.publicId,
    )!
    expect(period).toMatchObject({
      workId: work.publicId,
      announcementKnown: true,
      title: draft.title,
      endedAt: null,
      completionObservedAt: null,
    })
    expect(period.startedAt).toBeGreaterThanOrEqual(startsAt)
    expect(period.startedAt).toBeLessThan(draft.endsAt)
    await expect(cold.getByTestId('public-status-maintenance')).toContainText(
      draft.title,
    )
    await expect(cold.getByTestId('public-status-maintenance')).toContainText(
      'Announced in advance',
    )
    await expect(cold.getByTestId('public-status-component-state')).toHaveText(
      active.status.components.map(() => 'Maintenance'),
    )
    expect(coldLog.wire.packets).toEqual([])
    expectPublicStatusReadOnly(expect, coldLog)
    expect((await mailsTo(email)).map((mail) => mail.subject).sort()).toEqual(
      [STATUS_CONFIRM_SUBJECT, `Scheduled maintenance: ${draft.title}`].sort(),
    )
    await markDocument(page)
    await openProtectedMode()
    await expectSelfReload(page, test.info().project.use.navigationTimeout)
    await expectPageReady(expect, page)
    const completedPair = await expectPublicStatusPair(
      expect,
      cold,
      coldLog,
      ({ status }) =>
        status.maintenancePeriods.some(
          (entry) =>
            entry.id === period.id && entry.completionObservedAt !== null,
        ) && status.scheduledWorks.every((entry) => entry.id !== work.publicId),
    )
    const completed = completedPair.status.maintenancePeriods.filter(
      (entry) => entry.id === period.id,
    )
    expect(completed).toHaveLength(1)
    expect(completed[0].completionObservedAt).toBeLessThan(draft.endsAt)
    await expect(cold.getByTestId('public-status-maintenance')).toHaveCount(0)
    const history = cold.getByTestId('public-status-history')
    await expect(history).toContainText(draft.title)
    // Other scenarios can leave genuine completed maintenance in this history.
    // This announcement owns exactly one completed period and heading.
    await expect(
      history.getByRole('heading', {
        name: `${draft.title} Maintenance Completed`,
        exact: true,
      }),
    ).toHaveCount(1)
    await expect(cold.getByTestId('public-status-works')).toHaveCount(0)
    const completedMail = await waitForMailTo(
      email,
      `Completed: ${draft.title}`,
    )
    expectStatusMailAlternatives(
      expect,
      completedMail,
      statusMailLinks(completedMail),
      READER_ZONE,
    )
    expect(completedMail.text).toContain('Service is open again.')
    await gotoPage(expect, page, STATUS_WORKS_PATH)
    await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
    await gotoPage(expect, page, STATUS_PATH)
    await expect(page.getByTestId('hilos-status-works-count')).toHaveText('0')
    await test.step('History retains exactly this completed period and announcement', async () => {
      const since = adminLog.packets.length
      await page.getByTestId('hilos-page-tab-hilos_status_history').click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${STATUS_HISTORY_PATH}$`))
      const answer = statusHistoryDeliveries(adminLog, since)[0]
      const facts = answer.rows.filter(
        (row) => row.workPublicId === work.publicId,
      )
      expect(facts).toHaveLength(1)
      const fact = facts[0]
      // Public period ids are opaque; History names the numeric source row.
      // The public work and exact saved interval identify this same observation.
      expect(fact).toMatchObject({
        kind: 'maintenance',
        startedAt: period.startedAt,
        endedAt: completed[0].endedAt,
        completionObservedAt: completed[0].completionObservedAt,
        publishedAt: null,
        outcome: 'maintenance_completed',
        title: draft.title,
        componentKeys: draft.componentKeys,
        severity: null,
        announcementKnown: true,
        workPublicId: work.publicId,
      })
      await expectStatusHistoryFact(expect, page, fact)
      await expect(
        shownByTestId(page, `hilos-table-row-${fact.id}`),
      ).toHaveCount(1)
    })
    expect((await mailsTo(email)).map((mail) => mail.subject).sort()).toEqual(
      [
        STATUS_CONFIRM_SUBJECT,
        `Scheduled maintenance: ${draft.title}`,
        `Completed: ${draft.title}`,
      ].sort(),
    )
  } finally {
    try {
      await openProtectedModeIfAny()
      expect((await inspectProtectedMode()).phase).toBe('inactive')
    } finally {
      await test.info().attach('status-works-actual-freeze', {
        body: JSON.stringify({
          admin: adminLog,
          warm: publicLog.reads,
          cold: coldLog.reads,
          displayed: coldLog.displayed,
        }),
        contentType: 'application/json',
      })
      adminLog.stop()
      await publicLog.stop()
      await coldLog.stop()
      await coldSocket.dispose()
      await guestContext.close()
      await coldContext.close()
    }
  }
})
