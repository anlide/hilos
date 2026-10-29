import type { Expect, Page, TestInfo } from '@playwright/test'
import { trackedActionReplies } from './actionReplies.js'
import {
  deliveryObject,
  watchDeliveryFrames,
  type DeliveryFrameLog,
} from './deliveryFrames.js'
import { gotoPage, PAGE_READY } from './page.js'
import {
  STATUS_WORKS_PATH,
  STATUS_WORK_SCHEDULE,
  STATUS_WORK_CANCEL,
  statusWorkRows,
  submitStatusWork,
  type StatusWorkDraft,
} from './statusWorks.js'
import { advanceTableToRow, goToLastPage, shownByTestId } from './table.js'

/**
 * Reserve UI-created announcements before submitting and cancel only their
 * remaining upcoming rows in finally. A fresh page in the same admin context
 * leaves a failed or dirty subject dialog alone. All reads and cancellations
 * use the real page, viewport and owner-confirmed action; no database lever.
 */
function ownStatusWorks(expect: Expect, admin: Page, log: DeliveryFrameLog) {
  const since = log.sent.length
  const drafts: StatusWorkDraft[] = []
  const deliveries: DeliveryFrameLog[] = []
  return {
    deliveries,
    reserve(draft: StatusWorkDraft): void {
      drafts.push({ ...draft, componentKeys: [...draft.componentKeys] })
    },
    async cleanup(): Promise<void> {
      const sent = log.sent.slice(since).map(({ wire }) => deliveryObject(wire))
      const submitted = drafts.splice(0).filter((draft) =>
        sent.some((frame) => {
          const data = deliveryObject(frame.data)
          return (
            frame.type === 'action' &&
            frame.action === STATUS_WORK_SCHEDULE &&
            data.title === draft.title &&
            data.startsAt === draft.startsAt &&
            data.endsAt === draft.endsAt
          )
        }),
      )
      if (submitted.length === 0) return
      // A failed test can leave Schedule in flight. Read the committed set only
      // after every sent Schedule has answered, including a server refusal.
      await expect
        .poll(() => trackedActionReplies(log, STATUS_WORK_SCHEDULE, since))
        .toHaveLength(
          sent.filter(
            (frame) =>
              frame.type === 'action' && frame.action === STATUS_WORK_SCHEDULE,
          ).length,
        )
      const matches = (row: ReturnType<typeof statusWorkRows>[number]) =>
        submitted.some(
          (draft) =>
            row.title === draft.title &&
            row.startsAt === draft.startsAt &&
            row.endsAt === draft.endsAt,
        )
      const owned = new Set(
        statusWorkRows(log)
          .filter(matches)
          .map((row) => row.publicId),
      )
      const page = await admin.context().newPage()
      const cleanup = watchDeliveryFrames(page)
      deliveries.push(cleanup)
      try {
        await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
        await expect(page.getByTestId('hilos-status-works-table')).toBeVisible()
        if (statusWorkRows(cleanup).length > 0)
          await goToLastPage(expect, page, 'hilos-status-works-table')
        const upcoming = new Map(
          statusWorkRows(cleanup).map((row) => [row.publicId, row]),
        )
        const errors: unknown[] = []
        for (const row of [...upcoming.values()].reverse()) {
          if (!owned.has(row.publicId) && !matches(row)) continue
          try {
            await gotoPage(expect, page, STATUS_WORKS_PATH, PAGE_READY)
            await advanceTableToRow(
              expect,
              page,
              String(row.id),
              'hilos-status-works-table',
            )
            await shownByTestId(
              page,
              `hilos-status-work-cancel-${row.id}`,
            ).click()
            await submitStatusWork(expect, page, cleanup, STATUS_WORK_CANCEL)
            await expect(
              page.getByTestId(`hilos-status-work-cancel-${row.id}`),
            ).toHaveCount(0)
          } catch (error) {
            errors.push(error)
          }
        }
        if (errors.length > 0)
          throw new AggregateError(errors, 'Status work cleanup failed')
      } finally {
        cleanup.stop()
        await page.close()
      }
    },
  }
}

export type StatusWorkFixtureScope = ReturnType<typeof ownStatusWorks>

/**
 * Playwright owns teardown independently of the scenario's outcome, preserving
 * the original assertion failure beside any cleanup failure. No row is created
 * by reservation; the scenario remains responsible for its real UI actions.
 */
export function statusWorkFixtures(expect: Expect) {
  return async (
    { page }: { page: Page },
    use: (scope: StatusWorkFixtureScope) => Promise<void>,
    info: TestInfo,
  ): Promise<void> => {
    const log = watchDeliveryFrames(page)
    const scope = ownStatusWorks(expect, page, log)
    try {
      await use(scope)
    } finally {
      try {
        await scope.cleanup()
      } finally {
        log.stop()
        await info.attach('status-work-owned-cleanup', {
          body: JSON.stringify({ subject: log, cleanup: scope.deliveries }),
          contentType: 'application/json',
        })
      }
    }
  }
}
