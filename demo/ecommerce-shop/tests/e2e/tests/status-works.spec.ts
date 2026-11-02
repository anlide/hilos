import { expect, test as base } from '@playwright/test'

import type { PublicStatusFile } from '../../../../../framework/frontend/core/src/status/index.js'
import {
  PAGE_READY,
  PUBLIC_STATUS_PATH,
  STATUS_HISTORY_PATH,
  STATUS_PATH,
  STATUS_WORKS_PATH,
  STATUS_WORK_CANCEL,
  STATUS_WORK_CHANGE,
  STATUS_WORK_SCHEDULE,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  draftStatusWork,
  enterCalendarDate,
  enterCalendarDateTime,
  expectPageReady,
  expectPublicStatusPair,
  expectSelfReload,
  expectStatusHistoryFact,
  gotoPage,
  gotoPublicStatus,
  grantAdminToSelf,
  latestStatusWork,
  markDocument,
  openStatusWorkSchedule,
  showAnnouncedRows,
  shownByTestId,
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
  type DeliveryFrameLog,
  type StatusHistoryFact,
  type StatusWorkDraft,
  type StatusWorkFixtureScope,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  enterProtectedMode,
  inspectProtectedMode,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../framework/frontend/scripts/protectedMode.mjs'
import {
  beatStatusHeartbeat,
  startStatusWork,
} from '../../../../../framework/frontend/scripts/statusMoments.mjs'

// Scheduled maintenance as this project builds it in React: the Works page with
// its creation and row-edit windows, the Overview, public /status and History
// over the real daemon, nginx files and an actual freeze. The SDK's unit tests
// drive each window from a fixture; only the built artifact can lose the focus
// of a row edit, merge a held draft wrongly, let the shell close the public
// reader under a freeze, or bind History to anything but the server's window.
// The announcement mail, forged stale versions and the cold reader behind a held
// socket are the server's and are proved once, by the Vue spec of this feature.

const test = base.extend<{ statusWorks: StatusWorkFixtureScope }>({
  statusWorks: statusWorkFixtures(expect),
})

// Picked at random from the IANA list when this spec was written: the stand runs
// this project on UTC, so a reader-zone slip cannot hide behind the project's.
// Contexts opened by the test inherit it with the rest of the configured options.
const READER_ZONE = 'America/Santiago'
test.use({ timezoneId: READER_ZONE })

// This project declares no Status components: its catalog is the framework's
// single fallback, Service.
const SERVICE = 'service'
const WORKS_TABLE = 'hilos-status-works-table'
const OVERLAP =
  'This maintenance window overlaps another announcement. Choose a different time.'
const FREEZE = 'e2e-status-announced-work'

/**
 * A strictly future start, 17 seconds past a minute, so every native value of
 * this spec carries its seconds and the oracle below needs no normalizing.
 */
function futureStart(): number {
  return Math.floor(Date.now() / 60_000) * 60_000 + 600_000 + 17_000
}

/** The datetime-local value of a moment in the reader zone: the test's own Intl oracle. */
function readerFieldValue(at: number): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: READER_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(at))
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((entry) => entry.type === type)!.value
  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}:${part('second')}`
}

/** The calendar day after this one: day arithmetic, with no zone or clock in it. */
function dayAfter(day: string): string {
  const [year, month, date] = day.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, date + 1))
    .toISOString()
    .slice(0, 10)
}

/** The public entry an announcement takes, by its immutable public id. */
function publicEntry(id: string, draft: StatusWorkDraft) {
  return {
    id,
    title: draft.title,
    description: draft.description,
    componentKeys: draft.componentKeys,
    startsAt: draft.startsAt,
    endsAt: draft.endsAt,
  }
}

/** This test's announcements in the public file, in the file's own order. */
function publishedWorks(status: PublicStatusFile, ids: readonly string[]) {
  return status.scheduledWorks.filter((work) => ids.includes(work.id))
}

/** The public maintenance period an announcement turned into, if any. */
function periodOf(status: PublicStatusFile, publicId: string) {
  return status.maintenancePeriods.find((period) => period.workId === publicId)
}

/** The row a schedule committed, read from the server's own frames. */
async function scheduledRow(log: DeliveryFrameLog, draft: StatusWorkDraft) {
  const find = () =>
    statusWorkRows(log).find(
      (row) => row.title === draft.title && row.startsAt === draft.startsAt,
    )
  await expect.poll(find).toBeDefined()
  return find()!
}

/** The rows of the History window that answered the latest request with this filter. */
async function historyRows(
  log: DeliveryFrameLog,
  since: number,
  filter: Record<string, unknown>,
): Promise<StatusHistoryFact[]> {
  await expect
    .poll(() => {
      const window = statusHistoryWindow(log, since)
      return window === undefined
        ? undefined
        : deliveryObject(deliveryObject(window.request.wire).filter)
    })
    .toMatchObject(filter)
  return statusHistoryWindow(log, since)!.rows
}

test('scheduled maintenance is planned, refused on overlap, changed against a held draft and canceled across two admin windows and the public files', async ({
  page,
  browser,
  statusWorks,
}) => {
  const otherContext = await browser.newContext()
  const guestContext = await browser.newContext()
  const other = await otherContext.newPage()
  const guest = await guestContext.newPage()
  const admin = watchDeliveryFrames(page)
  const second = watchDeliveryFrames(other)
  const overview = watchStatusFrames(other)
  const files = watchPublicStatus(guest)
  const stamp = Date.now()
  const startsAt = futureStart()
  const draft: StatusWorkDraft = {
    title: `A ${stamp}`,
    description: 'A.',
    startsAt,
    endsAt: startsAt + 3_600_000,
    componentKeys: [SERVICE],
  }
  const overlapping: StatusWorkDraft = {
    title: `B ${stamp}`,
    description: 'B.',
    startsAt: startsAt - 120_000,
    endsAt: draft.endsAt + 1_800_000,
    componentKeys: [SERVICE],
  }
  // Adjacent windows are allowed: this one ends exactly where the upgrade starts.
  const neighbor: StatusWorkDraft = {
    ...overlapping,
    startsAt: startsAt - 120_000,
    endsAt: startsAt,
  }
  // The change moves the upgrade before its neighbor, an actual order change.
  const changed: StatusWorkDraft = {
    ...draft,
    title: `A2 ${stamp}`,
    startsAt: neighbor.startsAt - 120_000,
    endsAt: neighbor.startsAt,
  }
  const held = 'My focused draft'
  for (const reserved of [draft, overlapping, neighbor])
    statusWorks.reserve(reserved)
  try {
    await grantAdminToSelf(expect, page)
    await grantAdminToSelf(expect, other)

    const counted =
      await test.step('the Overview counts the upcoming announcements before any is made', async () => {
        await gotoPage(expect, other, STATUS_PATH, PAGE_READY)
        await expect.poll(() => overview.latest()).toBeDefined()
        expect(overview.latest()!.scheduledWorks).not.toBeNull()
        const count = overview.latest()!.scheduledWorks!.count
        await expect(other.getByTestId('hilos-status-works-count')).toHaveText(
          String(count),
        )
        return count
      })

    const work =
      await test.step('a scheduled announcement reaches both admin windows and the public files', async () => {
        await gotoPublicStatus(expect, guest)
        await expect(guest.getByTestId('conn-state')).toHaveText('connected')
        await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
        await gotoPage(expect, other, STATUS_WORKS_PATH, PAGE_READY)
        await openStatusWorkSchedule(expect, page)
        await expect(
          page.getByTestId('hilos-status-work-starts'),
        ).toHaveAccessibleName(`Starts, ${READER_ZONE}`)
        await draftStatusWork(expect, page, draft)
        await submitStatusWork(expect, page, admin, STATUS_WORK_SCHEDULE)
        const row = await scheduledRow(admin, draft)
        expect(row).toMatchObject({
          version: 1,
          title: draft.title,
          description: draft.description,
          startsAt: draft.startsAt,
          endsAt: draft.endsAt,
          componentKeys: draft.componentKeys,
          canceledAt: null,
          consumedAt: null,
          expiredAt: null,
        })
        for (const surface of [page, other])
          await expect(
            shownByTestId(surface, `hilos-status-work-title-${row.id}`),
          ).toHaveText(draft.title)
        const pair = await expectPublicStatusPair(
          expect,
          guest,
          files,
          ({ status }) => publishedWorks(status, [row.publicId]).length === 1,
        )
        expect(publishedWorks(pair.status, [row.publicId])).toEqual([
          publicEntry(row.publicId, draft),
        ])
        await expect(guest.getByTestId('public-status-works')).toContainText(
          draft.title,
        )
        return row
      })

    const anchor =
      await test.step('an overlapping window is refused in the form with its draft kept, and the adjacent one is scheduled', async () => {
        await dismissToasts(page)
        await openStatusWorkSchedule(expect, page)
        await draftStatusWork(expect, page, overlapping)
        const since = admin.sent.length
        await clickSubmit(expect, page.getByTestId('hilos-status-work-save'))
        await expect(page.getByTestId('hilos-status-work-error')).toContainText(
          OVERLAP,
        )
        await expect
          .poll(
            () =>
              trackedActionReplies(admin, STATUS_WORK_SCHEDULE, since).length,
          )
          .toBe(1)
        expect(
          trackedActionReplies(admin, STATUS_WORK_SCHEDULE, since)[0].frame
            .type,
        ).toBe('action_error')
        await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
          overlapping.title,
        )
        await expect(page.getByTestId('hilos-status-work-starts')).toHaveValue(
          readerFieldValue(overlapping.startsAt),
        )
        await expect(page.getByTestId('hilos-status-work-save')).toBeEnabled()
        // The same window, moved to end where the upgrade starts.
        await enterCalendarDateTime(
          expect,
          page,
          page.getByTestId('hilos-status-work-ends'),
          neighbor.endsAt,
        )
        await submitStatusWork(expect, page, admin, STATUS_WORK_SCHEDULE)
        const row = await scheduledRow(admin, neighbor)
        // The reader's own creation takes its place at once; the other window
        // is told of a row above its first one and shows it on request.
        await expect
          .poll(() => tableRowKeys(page, WORKS_TABLE))
          .toEqual([String(row.id), String(work.id)])
        await showAnnouncedRows(expect, other)
        await expect
          .poll(() => tableRowKeys(other, WORKS_TABLE))
          .toEqual([String(row.id), String(work.id)])
        const pair = await expectPublicStatusPair(
          expect,
          guest,
          files,
          ({ status }) =>
            publishedWorks(status, [work.publicId, row.publicId]).length === 2,
        )
        expect(
          publishedWorks(pair.status, [work.publicId, row.publicId]),
        ).toEqual([
          publicEntry(row.publicId, neighbor),
          publicEntry(work.publicId, draft),
        ])
        return row
      })

    await test.step('a change from the second window reaches the draft held in the first as a conflict that keeps its focus', async () => {
      await dismissToasts(page)
      await shownByTestId(page, `hilos-status-work-edit-${work.id}`).click()
      const title = page.getByTestId('hilos-status-work-title')
      await expect(title).toBeFocused()
      await expect(title).toHaveValue(draft.title)
      await typeInto(title, held)
      expect(
        admin.sent.some(({ wire }) => {
          const frame = deliveryObject(wire)
          return (
            frame.type === 'table_row_focus' &&
            frame.tableKey === 'hilosStatusWorks' &&
            String(frame.rowKey) === String(work.id)
          )
        }),
      ).toBe(true)

      await shownByTestId(other, `hilos-status-work-edit-${work.id}`).click()
      await expect(other.getByTestId('hilos-status-work-title')).toBeFocused()
      await typeInto(
        other.getByTestId('hilos-status-work-title'),
        changed.title,
      )
      await enterCalendarDateTime(
        expect,
        other,
        other.getByTestId('hilos-status-work-starts'),
        changed.startsAt,
      )
      await enterCalendarDateTime(
        expect,
        other,
        other.getByTestId('hilos-status-work-ends'),
        changed.endsAt,
      )
      await submitStatusWork(expect, other, second, STATUS_WORK_CHANGE)

      const notice = page.getByTestId('hilos-status-work-notice')
      await expect(
        page.getByTestId('hilos-status-work-notice-slot'),
      ).toHaveAttribute('role', 'status')
      await expect(notice).toContainText('Changed elsewhere')
      await expect(notice).toContainText(
        `Title: Keep mine: ${held}; Take theirs: ${changed.title}`,
      )
      await expect
        .poll(() => latestStatusWork(admin, work.publicId)?.version)
        .toBe(2)
      // The typed title stays, and so does the focus; the fields nobody
      // touched here took the other window's moments in the reader zone.
      await expect(title).toHaveValue(held)
      await expect(title).toBeFocused()
      await expect(page.getByTestId('hilos-status-work-starts')).toHaveValue(
        readerFieldValue(changed.startsAt),
      )
      await expect(page.getByTestId('hilos-status-work-ends')).toHaveValue(
        readerFieldValue(changed.endsAt),
      )
      await expect(page.getByTestId('hilos-status-work-save')).toBeDisabled()
      await expect(
        page.getByTestId('conflict-accept-mine'),
      ).toHaveAccessibleName('Keep mine')
      const theirs = page.getByTestId('conflict-accept-theirs')
      await expect(theirs).toHaveAccessibleName('Take theirs')
      await theirs.focus()
      await theirs.press('Enter')
      await expect(title).toHaveValue(changed.title)
      // Resolving removes the focused choice; focus stays in the dialog, and
      // Escape still reaches it.
      await expect(title).toBeFocused()
      await page.keyboard.press('Escape')
      await expect(page.getByTestId('hilos-status-work-form')).toBeHidden()
    })

    await test.step('the moved row waits for Apply in the first window, takes its place at once in the second, and the public file follows', async () => {
      await expect(
        shownByTestId(page, `hilos-table-pending-move-${work.id}`),
      ).toBeVisible()
      await expect
        .poll(() => tableRowKeys(page, WORKS_TABLE))
        .toEqual([String(anchor.id), String(work.id)])
      await page.getByTestId('hilos-table-apply').click()
      await expect(page.getByTestId('hilos-table-apply')).toBeHidden()
      await expect
        .poll(() => tableRowKeys(page, WORKS_TABLE))
        .toEqual([String(work.id), String(anchor.id)])
      await expect(
        shownByTestId(page, `hilos-status-work-title-${work.id}`),
      ).toHaveText(changed.title)
      await expect
        .poll(() => tableRowKeys(other, WORKS_TABLE))
        .toEqual([String(work.id), String(anchor.id)])
      await expect(other.getByTestId('hilos-table-apply')).toBeHidden()
      const pair = await expectPublicStatusPair(
        expect,
        guest,
        files,
        ({ status }) =>
          publishedWorks(status, [work.publicId])[0]?.startsAt ===
          changed.startsAt,
      )
      expect(
        publishedWorks(pair.status, [work.publicId, anchor.publicId]),
      ).toEqual([
        publicEntry(work.publicId, changed),
        publicEntry(anchor.publicId, neighbor),
      ])
      await expect(guest.getByTestId('public-status-works')).toContainText(
        changed.title,
      )
    })

    await test.step('cancels through their dialog take both out of the windows, the live Overview and the public files', async () => {
      await gotoPage(expect, other, STATUS_PATH, PAGE_READY)
      const count = other.getByTestId('hilos-status-works-count')
      await expect(count).toHaveText(String(counted + 2))
      await expect(other.getByTestId('hilos-status-works-next')).toContainText(
        changed.title,
      )
      for (const [row, entry, left] of [
        [work, changed, 1],
        [anchor, neighbor, 0],
      ] as const) {
        await dismissToasts(page)
        await shownByTestId(page, `hilos-status-work-cancel-${row.id}`).click()
        const modal = page.getByTestId('modal')
        await expect(modal).toHaveAccessibleName('Cancel scheduled maintenance')
        await expect(modal).toBeFocused()
        await expect(
          page.getByTestId('hilos-status-work-cancel-target'),
        ).toHaveText(entry.title)
        await submitStatusWork(expect, page, admin, STATUS_WORK_CANCEL)
        await expect(
          shownByTestId(page, `hilos-status-work-title-${row.id}`),
        ).toHaveCount(0)
        await expect(count).toHaveText(String(counted + left))
        await expectPublicStatusPair(
          expect,
          guest,
          files,
          ({ status }) => publishedWorks(status, [row.publicId]).length === 0,
        )
        await expect(
          guest
            .getByTestId('public-status-works')
            .filter({ hasText: entry.title }),
        ).toHaveCount(0)
      }
    })
  } finally {
    await test.info().attach('status-works-windows-wire', {
      body: JSON.stringify({
        admin,
        second,
        files: files.reads,
        pairs: files.displayed,
      }),
      contentType: 'application/json',
    })
    admin.stop()
    second.stop()
    overview.stop()
    await files.stop()
    await Promise.all([otherContext.close(), guestContext.close()])
  }
})

test('an announced window closes nothing; the actual freeze stubs the shop but not /status, and its completed period reaches History', async ({
  page,
  statusWorks,
}) => {
  const admin = watchDeliveryFrames(page)
  const overview = watchStatusFrames(page)
  const files = watchPublicStatus(page)
  try {
    await grantAdminToSelf(expect, page)

    const counted =
      await test.step('the Overview counts announcements and completed events before the work', async () => {
        await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
        await expect.poll(() => overview.latest()).toBeDefined()
        const latest = overview.latest()!
        expect(latest.scheduledWorks).not.toBeNull()
        expect(latest.historyCount).not.toBeNull()
        return {
          works: latest.scheduledWorks!.count,
          history: latest.historyCount!,
        }
      })

    const { draft, work, started } =
      await test.step('the announcement is published with its start brought to now, and the window alone closes nothing', async () => {
        await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
        await openStatusWorkSchedule(expect, page)
        // The actual freeze finds the announcement only if it starts before the
        // window ends, and nothing brings that end to now: an hour keeps a slow
        // path to the freeze inside the window, and the check before the freeze
        // names a miss. A consumed announcement holds its window against overlap
        // until the window ends, so a second attempt of this test in the same
        // run is refused; every full e2e cycle starts from a reset stand
        // database, and a retried test already makes its step red. The 20
        // seconds only carry the form's strictly-future guard to the submit.
        const stamp = Date.now()
        const startsAt = Math.floor(stamp / 1000) * 1000 + 20_000
        const announced: StatusWorkDraft = {
          title: `Announced freeze ${stamp}`,
          description: 'The shop closes only for the actual freeze.',
          startsAt,
          endsAt: startsAt + 3_600_000,
          componentKeys: [SERVICE],
        }
        statusWorks.reserve(announced)
        await draftStatusWork(expect, page, announced)
        await submitStatusWork(expect, page, admin, STATUS_WORK_SCHEDULE)
        const row = await scheduledRow(admin, announced)
        // The schedule keeps its strictly-future guard; a command, not a wait,
        // brings the announced start to now.
        const first = await startStatusWork(row.publicId)
        expect(first.startsAt).toBeLessThanOrEqual(Date.now())
        // The command floors the start to its second, and the import refuses a
        // freeze that starts inside the announcement's creation second: the
        // server's answer of a start one second later keeps the freeze out of it.
        let moved = first
        await expect
          .poll(async () => {
            moved = await startStatusWork(row.publicId)
            return moved.startsAt
          })
          .toBeGreaterThan(first.startsAt)
        // The owner has read the moved start once its pair carries it: only
        // then can an actual freeze find the announcement covering its start.
        await gotoPublicStatus(expect, page)
        const pair = await expectPublicStatusPair(
          expect,
          page,
          files,
          ({ status }) =>
            publishedWorks(status, [row.publicId])[0]?.startsAt ===
            moved.startsAt,
        )
        expect(publishedWorks(pair.status, [row.publicId])).toEqual([
          publicEntry(row.publicId, { ...announced, startsAt: moved.startsAt }),
        ])
        await expect(page.getByTestId('public-status-works')).toContainText(
          announced.title,
        )
        // Inside its window nothing closes.
        await expect(page.getByTestId('public-status-maintenance')).toHaveCount(
          0,
        )
        expect((await inspectProtectedMode()).phase).toBe('inactive')
        await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
        await expect(
          shownByTestId(page, `hilos-status-work-title-${row.id}`),
        ).toHaveText(announced.title)
        return { draft: announced, work: row, started: moved }
      })

    const period =
      await test.step('the actual freeze puts the stub over Works, and its Service status opens /status with the announced Maintenance', async () => {
        expect(Date.now()).toBeLessThan(draft.endsAt)
        expect(await enterProtectedMode(FREEZE)).toBe('active')
        await expect(page.getByTestId('maintenance')).toBeVisible()
        await expect(page.getByTestId(WORKS_TABLE)).toHaveCount(0)
        // A reader held by the freeze gets no hint and polls: a command
        // publishes the freeze's period before the reader opens.
        const { aliveAt } = await beatStatusHeartbeat()
        await page.getByTestId('maintenance-service-status').click()
        await expect(page).toHaveURL(new RegExp(`${PUBLIC_STATUS_PATH}$`))
        await expect(page.getByTestId('public-status-components')).toBeVisible()
        const active = await expectPublicStatusPair(
          expect,
          page,
          files,
          ({ status, heartbeat }) =>
            heartbeat.aliveAt >= aliveAt &&
            periodOf(status, work.publicId)?.completionObservedAt === null,
        )
        const opened = periodOf(active.status, work.publicId)!
        expect(opened).toMatchObject({
          announcementKnown: true,
          title: draft.title,
          endedAt: null,
          completionObservedAt: null,
        })
        expect(opened.startedAt).toBeGreaterThanOrEqual(started.startsAt)
        expect(opened.startedAt).toBeLessThan(draft.endsAt)
        expect(
          active.status.components.map((component) => component.state),
        ).toEqual(active.status.components.map(() => 'maintenance'))
        // The shell's exception: the stub does not cover the file reader, and
        // the reader offers nothing that needs the held connection.
        await expect(page.getByTestId('maintenance')).toHaveCount(0)
        const maintenance = page.getByTestId('public-status-maintenance')
        await expect(maintenance).toContainText(draft.title)
        await expect(maintenance).toContainText('Announced in advance')
        await expect(
          page.getByTestId('public-status-component-state'),
        ).toHaveText(active.status.components.map(() => 'Maintenance'))
        await expect(page.getByTestId('public-status-subscribe')).toHaveCount(0)
        return opened
      })

    const completed =
      await test.step('the lift reloads /status, which shows the period completed once', async () => {
        await markDocument(page)
        expect(await openProtectedMode()).toBe('inactive')
        await expectSelfReload(page, test.info().project.use.navigationTimeout)
        await expect(page.getByTestId('public-status-components')).toBeVisible()
        const pair = await expectPublicStatusPair(
          expect,
          page,
          files,
          ({ status }) =>
            status.maintenancePeriods.some(
              (entry) =>
                entry.id === period.id && entry.completionObservedAt !== null,
            ) && publishedWorks(status, [work.publicId]).length === 0,
        )
        const periods = pair.status.maintenancePeriods.filter(
          (entry) => entry.id === period.id,
        )
        expect(periods).toHaveLength(1)
        await expect(
          page
            .getByTestId('public-status-maintenance')
            .filter({ hasText: draft.title }),
        ).toHaveCount(0)
        // Other scenarios leave genuine completed maintenance in this history;
        // this announcement owns exactly one completed period.
        await expect(
          page.getByTestId('public-status-history').getByRole('heading', {
            name: `${draft.title} Maintenance Completed`,
            exact: true,
          }),
        ).toHaveCount(1)
        return periods[0]
      })

    await test.step('Works and the Overview no longer count the announcement, and History holds its one completed period', async () => {
      await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
      await expect(
        shownByTestId(page, `hilos-status-work-title-${work.id}`),
      ).toHaveCount(0)
      await gotoPage(expect, page, STATUS_PATH, PAGE_READY)
      await expect(page.getByTestId('hilos-status-works-count')).toHaveText(
        String(counted.works),
      )
      await expect(page.getByTestId('hilos-status-history-count')).toHaveText(
        String(counted.history + 1),
      )

      const since = admin.packets.length
      await page.getByTestId('hilos-page-tab-hilos_status_history').click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(new RegExp(`${STATUS_HISTORY_PATH}$`))
      await expect
        .poll(() => statusHistoryDeliveries(admin, since).length)
        .toBeGreaterThan(0)
      const facts = statusHistoryDeliveries(admin, since)[0].rows.filter(
        (row) => row.workPublicId === work.publicId,
      )
      expect(facts).toHaveLength(1)
      const fact = facts[0]
      // Public period ids are opaque; History names the numeric source row.
      // The public work and the exact saved interval identify the same period.
      expect(fact).toMatchObject({
        kind: 'maintenance',
        startedAt: period.startedAt,
        endedAt: completed.endedAt,
        completionObservedAt: completed.completionObservedAt,
        publishedAt: null,
        outcome: 'maintenance_completed',
        title: draft.title,
        componentKeys: draft.componentKeys,
        severity: null,
        announcementKnown: true,
        workPublicId: work.publicId,
      })
      await expectStatusHistoryFact(expect, page, fact)
      const row = shownByTestId(page, `hilos-table-row-${fact.id}`)

      const search = page.getByTestId('hilos-table-search')
      let sent = admin.sent.length
      await typeInto(search, 'no-matching-history-event')
      await expect
        .poll(() => statusHistoryWindow(admin, sent)?.rows)
        .toEqual([])
      await expect(row).toHaveCount(0)
      sent = admin.sent.length
      await typeInto(search, draft.title)
      await expect
        .poll(() => statusHistoryWindow(admin, sent)?.rows)
        .toContainEqual(fact)
      await expectStatusHistoryFact(expect, page, fact)

      for (const [key, option, value] of [
        ['componentKey', 0, SERVICE],
        ['outcome', 3, 'maintenance_completed'],
      ] as const) {
        sent = admin.sent.length
        const filter = page.getByTestId(`hilos-table-filter-${key}`)
        await filter.getByTestId('hilos-dropdown-toggle').click()
        await filter.getByTestId(`hilos-dropdown-option-${option}`).click()
        expect(await historyRows(admin, sent, { [key]: value })).toContainEqual(
          fact,
        )
        await expectStatusHistoryFact(expect, page, fact)
      }

      // The Period's days are the reader's: the zone travels with the bounds.
      const day = readerFieldValue(fact.startedAt).slice(0, 10)
      const next = dayAfter(day)
      const from = page.getByTestId('hilos-table-filter-from-from')
      const to = page.getByTestId('hilos-table-filter-from-to')
      await page.getByTestId('hilos-table-filter-from').click()
      sent = admin.sent.length
      await enterCalendarDate(expect, from, day)
      await enterCalendarDate(expect, to, day)
      expect(
        await historyRows(admin, sent, {
          from: day,
          to: day,
          timeZone: READER_ZONE,
        }),
      ).toContainEqual(fact)
      await expectStatusHistoryFact(expect, page, fact)
      sent = admin.sent.length
      await enterCalendarDate(expect, to, next)
      await enterCalendarDate(expect, from, next)
      expect(
        await historyRows(admin, sent, {
          from: next,
          to: next,
          timeZone: READER_ZONE,
        }),
      ).not.toContainEqual(fact)
      await expect(row).toHaveCount(0)
      sent = admin.sent.length
      await page.getByTestId('hilos-table-filter-from-clear').click()
      await expect
        .poll(() => statusHistoryWindow(admin, sent)?.rows)
        .toContainEqual(fact)
      await expectStatusHistoryFact(expect, page, fact)

      const reload = admin.packets.length
      await page.reload()
      await expectPageReady(expect, page)
      await expect
        .poll(() => statusHistoryDeliveries(admin, reload).length)
        .toBeGreaterThan(0)
      expect(statusHistoryDeliveries(admin, reload)[0].rows).toContainEqual(
        fact,
      )
      await expectStatusHistoryFact(expect, page, fact)
    })
  } finally {
    try {
      await openProtectedModeIfAny()
      expect((await inspectProtectedMode()).phase).toBe('inactive')
    } finally {
      await test.info().attach('status-works-actual-freeze', {
        body: JSON.stringify({
          admin,
          files: files.reads,
          pairs: files.displayed,
        }),
        contentType: 'application/json',
      })
      admin.stop()
      overview.stop()
      await files.stop()
    }
  }
})
