import type { Expect, Page } from '@playwright/test'
import {
  statusWorkSchema,
  type HilosStatusWorkRow,
} from '../core/src/admin/status/index.js'
import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { trackedActionReplies } from './actionReplies.js'
import { clickSubmit, typeInto } from './input.js'
import { shownByTestId } from './table.js'
import { enterCalendarDateTime } from './moments.js'

export const STATUS_WORKS_PATH = '/hilos/status/works'
export const STATUS_WORK_SCHEDULE = 'hilos_status_work_schedule'
export const STATUS_WORK_CHANGE = 'hilos_status_work_change'
export const STATUS_WORK_CANCEL = 'hilos_status_work_cancel'

/** Values entered in the explicitly named reader zone, with epoch milliseconds on the wire. */
export interface StatusWorkDraft {
  title: string
  description: string
  startsAt: number
  endsAt: number
  componentKeys: readonly string[]
}

/** Read actual page/window/delta rows, including the row kept live by modal focus. */
export function statusWorkRows(log: DeliveryFrameLog): HilosStatusWorkRow[] {
  return log.packets.flatMap((packet) =>
    packet.frames.flatMap(({ wire }) => {
      const frame = deliveryObject(wire)
      const data = deliveryObject(frame.data)
      if (data.page !== 'hilos_status_works') return []
      const sections =
        frame.type === 'page_response'
          ? Object.values(deliveryObject(deliveryObject(data.payload).windows))
          : data.tableKey === 'hilosStatusWorks'
            ? [data]
            : []
      return sections.flatMap((value) => {
        const section = deliveryObject(value)
        const rows = Array.isArray(section.rows) ? section.rows : []
        return (
          section.row === undefined ? rows : [...rows, section.row]
        ).flatMap((row) => {
          const work = deliveryObject(deliveryObject(row).slots).work
          return work === undefined ? [] : [statusWorkSchema.parse(work)]
        })
      })
    }),
  )
}

/** The latest server row with this immutable public identity, never a DB read. */
export function latestStatusWork(log: DeliveryFrameLog, publicId: string) {
  return statusWorkRows(log)
    .filter((row) => row.publicId === publicId)
    .at(-1)
}

/** Open through the standard table action; no route or application state is replaced. */
export async function openStatusWorkSchedule(expect: Expect, page: Page) {
  const opener = shownByTestId(page, 'hilos-table-main-action')
  await opener.focus()
  await opener.press('Enter')
  await expect(page.getByTestId('hilos-status-work-title')).toBeFocused()
}

/** Fill the real modal in the browser's explicitly forwarded IANA time zone. */
export async function draftStatusWork(
  expect: Expect,
  page: Page,
  draft: StatusWorkDraft,
): Promise<void> {
  await typeInto(page.getByTestId('hilos-status-work-title'), draft.title)
  await typeInto(
    page.getByTestId('hilos-status-work-description'),
    draft.description,
  )
  await enterCalendarDateTime(
    expect,
    page,
    page.getByTestId('hilos-status-work-starts'),
    draft.startsAt,
  )
  await enterCalendarDateTime(
    expect,
    page,
    page.getByTestId('hilos-status-work-ends'),
    draft.endsAt,
  )
  for (const key of draft.componentKeys)
    await page.getByTestId(`hilos-status-work-component-${key}`).check()
}

/** Settle the UI before checking one request and its correlated server success. */
export async function submitStatusWork(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  action: string,
): Promise<void> {
  const since = log.sent.length
  const canceling = action === STATUS_WORK_CANCEL
  await clickSubmit(
    expect,
    page.getByTestId(
      canceling ? 'hilos-status-work-cancel-confirm' : 'hilos-status-work-save',
    ),
  )
  await expect(
    page.getByTestId(
      canceling ? 'hilos-status-work-cancel-form' : 'hilos-status-work-form',
    ),
  ).toBeHidden()
  await expect
    .poll(() => trackedActionReplies(log, action, since).length)
    .toBe(1)
  expect(trackedActionReplies(log, action, since)[0].frame.type).toBe(
    'action_success',
  )
  expect(
    log.sent
      .slice(since)
      .filter(({ wire }) => deliveryObject(wire).action === action),
  ).toHaveLength(1)
}

/** The Works table's naming and real keyboard sort request keep the shared heading. */
export async function expectStatusWorksAccessibility(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
): Promise<void> {
  const table = page.getByTestId('hilos-status-works-table').getByRole('table')
  await expect(table).toHaveAccessibleName('Status')
  await expect(table).toHaveAttribute(
    'aria-labelledby',
    (await page.getByTestId('hilos-page-head-title').getAttribute('id'))!,
  )
  await expect(page.getByTestId('hilos-table-search')).toHaveAccessibleName(
    'Search by title or description…',
  )
  const sort = page.getByTestId('hilos-table-sort-startsAt')
  const header = table.getByRole('columnheader').filter({ has: sort })
  await expect(header).toHaveAttribute('aria-sort', 'ascending')
  const since = log.sent.length
  await sort.focus()
  await sort.press('Enter')
  await expect(header).toHaveAttribute('aria-sort', 'descending')
  await expect(page.getByTestId('hilos-table-loading')).toBeHidden()
  await expect(sort).toBeFocused()
  await expect
    .poll(
      () =>
        log.sent.slice(since).filter(({ wire }) => {
          const frame = deliveryObject(wire)
          return (
            frame.type === 'table_viewport' &&
            frame.tableKey === 'hilosStatusWorks'
          )
        }).length,
    )
    .toBe(1)
}

/** Field names, focus trap, Escape, dirty-close confirmation and focus return. */
export async function expectStatusWorkScheduleDialog(
  expect: Expect,
  page: Page,
) {
  const opener = shownByTestId(page, 'hilos-table-main-action')
  await openStatusWorkSchedule(expect, page)
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveRole('dialog')
  await expect(modal).toHaveAttribute('aria-modal', 'true')
  await expect(modal).toHaveAccessibleName('Schedule maintenance')
  await expect(page.getByTestId('hilos-status-work-form')).toHaveAccessibleName(
    'Schedule maintenance',
  )
  await expect(
    page.getByTestId('hilos-status-work-title'),
  ).toHaveAccessibleName('Title')
  await expect(
    page.getByTestId('hilos-status-work-starts'),
  ).toHaveAccessibleName(/^Starts, /)
  await expect(page.getByTestId('hilos-status-work-ends')).toHaveAccessibleName(
    /^Ends, /,
  )
  await expect(
    page.getByTestId('hilos-status-work-description'),
  ).toHaveAccessibleName('What visitors read')
  await expect(modal.getByRole('group', { name: 'Affects' })).toBeVisible()
  const save = page.getByTestId('hilos-status-work-save')
  await expect(save).toBeDisabled()
  const cancel = page.getByTestId('hilos-status-work-close')
  await cancel.focus()
  await cancel.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(cancel).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(modal).toBeHidden()
  await expect(opener).toBeFocused()
  await openStatusWorkSchedule(expect, page)
  await typeInto(
    page.getByTestId('hilos-status-work-title'),
    'Retain this draft',
  )
  await page.keyboard.press('Escape')
  const confirm = page.getByTestId('modal-confirm')
  await expect(confirm).toHaveRole('alertdialog')
  await expect(confirm).toBeFocused()
  await page.getByTestId('modal-confirm-cancel').press('Enter')
  await expect(confirm).toBeHidden()
  await expect(page.getByTestId('hilos-status-work-title')).toHaveValue(
    'Retain this draft',
  )
  await page.keyboard.press('Escape')
  await page.getByTestId('modal-confirm-discard').press('Enter')
  await expect(modal).toBeHidden()
  await expect(opener).toBeFocused()
}
