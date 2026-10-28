import type { Expect, Page } from '@playwright/test'
import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import {
  expectTrackedActionReply,
  trackedActionReplies,
} from './actionReplies.js'
import { clickSubmit, typeInto } from './input.js'
import { gotoPublicStatus } from './page.js'
import { expectDataFreeDeparture, shownByTestId } from './table.js'

export const STATUS_SUBSCRIBERS_PATH = '/hilos/status/subscribers'

/** Inspect the real table deliveries, including inner frames of a batch. */
export function statusSubscriberFrames(log: DeliveryFrameLog, type: string) {
  return log.packets.flatMap((packet) =>
    packet.frames.flatMap(({ wire }) => {
      const frame = deliveryObject(wire)
      const data = deliveryObject(frame.data)
      return frame.type === type &&
        data.tableKey === 'hilosStatusSubscribers' &&
        data.page === 'hilos_status_subscribers'
        ? [data]
        : []
    }),
  )
}

/** Match the latest subscriber viewport request to its answer on the same socket. */
export function statusSubscriberWindow(log: DeliveryFrameLog, since = 0) {
  const request = log.sent
    .slice(since)
    .filter(({ wire }) => {
      const frame = deliveryObject(wire)
      return (
        frame.type === 'table_viewport' &&
        frame.page === 'hilos_status_subscribers' &&
        frame.tableKey === 'hilosStatusSubscribers'
      )
    })
    .at(-1)
  const requestId = deliveryObject(request?.wire).requestId
  if (!request || typeof requestId !== 'string') return undefined
  return log.packets
    .filter(
      (packet) => packet.socket === request.socket && packet.at >= request.at,
    )
    .flatMap((packet) => packet.frames)
    .flatMap(({ wire }) => {
      const frame = deliveryObject(wire)
      const data = deliveryObject(frame.data)
      return frame.type === 'table_window' &&
        data.page === 'hilos_status_subscribers' &&
        data.tableKey === 'hilosStatusSubscribers' &&
        data.requestId === requestId
        ? [data]
        : []
    })
    .at(-1)
}

/** Page counts are authoritative even while the table holds an older window. */
export async function expectStatusSubscriberCounts(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  confirmedCount: number,
  pendingCount: number,
  overview = false,
): Promise<void> {
  const prefix = `hilos-status-subscribers-${overview ? 'tile-' : ''}`
  await expect(page.getByTestId(`${prefix}confirmed`)).toHaveText(
    String(confirmedCount),
  )
  await expect(page.getByTestId(`${prefix}pending`)).toHaveText(
    String(pendingCount),
  )
  await expect
    .poll(() => {
      const responses = log.packets
        .flatMap((packet) => packet.frames)
        .flatMap(({ wire }) => {
          const frame = deliveryObject(wire)
          const data = deliveryObject(frame.data)
          if (
            frame.type !== 'page_response' ||
            data.page !==
              (overview ? 'hilos_status' : 'hilos_status_subscribers')
          )
            return []
          const payload = deliveryObject(deliveryObject(data.payload).data)
          return [
            overview
              ? deliveryObject(payload.status).subscribers
              : payload.statusSubscribers,
          ]
        })
      return responses.at(-1)
    })
    .toEqual({ confirmedCount, pendingCount })
}

/** A remote departure waits, then retires private cells in both mounted projections. */
export async function expectStatusSubscriberDeparture(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  id: string,
  reason: 'deleted' | 'left_set',
): Promise<void> {
  await expect
    .poll(() => statusSubscriberFrames(log, 'table_viewport_delta'))
    .toContainEqual(
      expect.objectContaining({
        rowKey: Number(id),
        kind: 'row_removed',
        reason,
      }),
    )
  await expect(
    shownByTestId(page, `hilos-table-pending-remove-${id}`),
  ).toBeVisible()
  await applyStatusSubscribers(expect, page)
  await expectDataFreeDeparture(
    expect,
    page,
    id,
    reason === 'deleted' ? 'Removed' : 'No longer in this list',
    [
      `hilos-status-subscriber-email-${id}`,
      `hilos-status-subscriber-confirmed-${id}`,
      `hilos-status-subscriber-remove-${id}`,
    ],
  )
}

/** Submit removal of the captured primary key and await exactly its own ack. */
export async function removeStatusSubscriber(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  id: string,
): Promise<void> {
  await shownByTestId(page, `hilos-status-subscriber-remove-${id}`).click()
  await expect(
    page.getByTestId('hilos-status-subscriber-remove-target'),
  ).toContainText(`#${id}`)
  const since = log.sent.length
  await clickSubmit(
    expect,
    page.getByTestId('hilos-status-subscriber-remove-confirm'),
  )
  await expect(
    page.getByTestId('hilos-status-subscriber-remove-form'),
  ).toBeHidden()
  await expect
    .poll(
      () =>
        trackedActionReplies(log, 'hilos_status_subscriber_remove', since)
          .length,
    )
    .toBe(1)
  const ack = trackedActionReplies(
    log,
    'hilos_status_subscriber_remove',
    since,
  )[0]
  expect(ack.frame.type).toBe('action_success')
  expect(ack.data.message).toBe('Subscriber removed.')
  const requests = log.sent
    .slice(since)
    .map(({ wire }) => deliveryObject(wire))
    .filter((frame) => frame.action === 'hilos_status_subscriber_remove')
  expect(requests).toHaveLength(1)
  expect(requests[0].data).toEqual({ subscriberId: Number(id) })
  await expect(
    page.getByTestId(`hilos-status-subscriber-email-${id}`),
  ).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-apply')).toBeHidden()
}

/** Public UI submission, settled through its real correlated check-inbox reply. */
export async function subscribeToStatus(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  email: string,
): Promise<void> {
  const since = log.sent.length
  await page.getByTestId('public-status-subscribe').click()
  await typeInto(page.getByTestId('status-subscription-email'), email)
  await clickSubmit(expect, page.getByTestId('status-subscription-submit'))
  await expect(page.getByTestId('status-subscription-form')).toBeHidden()
  await expect(
    page.getByTestId('public-status-subscription-notice'),
  ).toHaveText('Check your inbox')
  await expectTrackedActionReply(expect, log, 'status_subscribe', since, {
    status: 'check_inbox',
  })
}

/** Follow a delivered fragment and assert the address was scrubbed on this mount. */
export async function openStatusMailLink(
  expect: Expect,
  page: Page,
  link: string,
): Promise<void> {
  await gotoPublicStatus(expect, page, link)
  const target = new URL(link)
  await expect(page).toHaveURL(`${target.origin}${target.pathname}`)
}

/** Apply a shown subscriber's advertised removal or move. */
export async function applyStatusSubscribers(
  expect: Expect,
  page: Page,
): Promise<void> {
  const apply = page.getByTestId('hilos-table-apply')
  await expect(apply).toBeVisible()
  await apply.click()
  await expect(apply).toBeHidden()
}

/** Read a visible subscriber's stable primary key from the actual admin row. */
export async function statusSubscriberId(
  expect: Expect,
  page: Page,
  email: string,
): Promise<string> {
  const cell = shownByTestId(page, /^hilos-status-subscriber-email-/).filter({
    hasText: email,
  })
  await expect(cell).toHaveCount(1)
  return (await cell.getAttribute('data-id'))!.replace(
    'hilos-status-subscriber-email-',
    '',
  )
}

/** The delivered alternatives carry one common frame and recipient-zone labels. */
export function expectStatusMailAlternatives(
  expect: Expect,
  mail: { text: string; html?: string },
  links: { status: string; unsubscribe: string; confirm?: string },
  zone: string,
): void {
  expect(mail.text).not.toMatch(/<html|<body/i)
  expect(mail.text).toMatch(
    /\d{4}-\d\d-\d\d \d\d:\d\d:\d\d [+-]\d\d:\d\d \([^)]+\)/,
  )
  expect(mail.text).toContain(`(${zone})`)
  expect(mail.html).toEqual(expect.any(String))
  expect(mail.html!.match(/<!doctype html>/gi)).toHaveLength(1)
  expect(mail.html!.match(/<html\b/gi)).toHaveLength(1)
  expect(mail.html!.match(/<body\b/gi)).toHaveLength(1)
  expect(mail.html).toContain('<html lang="en" dir="ltr">')
  expect(mail.html!.match(/<table role="presentation"/g)).toHaveLength(2)
  expect(mail.html).not.toContain('[[:')
  for (const link of Object.values(links)) {
    expect(mail.text).toContain(link)
    expect(mail.html).toContain(`href="${link}"`)
  }
}

/** The subscriber table inherits the shared heading and exposes keyboard sorting. */
export async function expectStatusSubscribersAccessibility(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
): Promise<void> {
  const table = page
    .getByTestId('hilos-status-subscribers-table')
    .getByRole('table')
  await expect(table).toHaveAccessibleName('Status')
  await expect(table).toHaveAttribute(
    'aria-labelledby',
    (await page.getByTestId('hilos-page-head-title').getAttribute('id'))!,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveCount(0)
  const search = page.getByTestId('hilos-table-search')
  await expect(search).toHaveAccessibleName('Search by email…')
  await search.focus()
  await expect(search).toBeFocused()
  const sort = page.getByTestId('hilos-table-sort-subscribedAt')
  const header = table.getByRole('columnheader').filter({ has: sort })
  await expect(header).toHaveAttribute('aria-sort', 'descending')
  const since = log.sent.length
  await sort.focus()
  await sort.press('Enter')
  const requests = () =>
    log.sent
      .slice(since)
      .filter(({ wire }) => deliveryObject(wire).type === 'table_viewport')
  await expect.poll(() => requests().length).toBe(1)
  await expect.poll(() => statusSubscriberWindow(log, since)).toBeDefined()
  await expect(page.getByTestId('hilos-table-loading')).toBeHidden()
  await expect(header).toHaveAttribute('aria-sort', 'ascending')
  await expect(sort).toBeFocused()
  await expect(
    page
      .getByTestId('hilos-table-filter-confirmed')
      .getByTestId('hilos-dropdown-toggle'),
  ).toHaveAccessibleName('Confirmed: Any')
}

/** The captured-id destructive dialog names itself, traps focus and returns it on cancel. */
export async function expectStatusSubscriberRemoveDialog(
  expect: Expect,
  page: Page,
  id: string,
): Promise<void> {
  const opener = shownByTestId(page, `hilos-status-subscriber-remove-${id}`)
  await expect(opener).toHaveAccessibleName('Remove a subscriber')
  for (const close of ['Escape', 'Cancel']) {
    await opener.focus()
    await opener.press('Enter')
    const modal = page.getByTestId('modal')
    await expect(modal).toHaveRole('dialog')
    await expect(modal).toHaveAttribute('aria-modal', 'true')
    await expect(modal).toHaveAccessibleName('Remove a subscriber')
    await expect(modal).toBeFocused()
    await expect(
      page.getByTestId('hilos-status-subscriber-remove-form'),
    ).toHaveAccessibleName('Remove a subscriber')
    const cancel = page.getByTestId('hilos-status-subscriber-remove-cancel')
    const confirm = page.getByTestId('hilos-status-subscriber-remove-confirm')
    await expect(cancel).toHaveAccessibleName('Cancel')
    await expect(confirm).toHaveAccessibleName('Remove')
    await confirm.focus()
    await page.keyboard.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await expect(confirm).toBeFocused()
    if (close === 'Escape') await page.keyboard.press('Escape')
    else {
      await cancel.focus()
      await cancel.press('Enter')
    }
    await expect(modal).toBeHidden()
    await expect(opener).toBeFocused()
  }
}

/** Public subscription opens from the keyboard, with a labelled field and reversible focus. */
export async function expectStatusSubscribeDialog(
  expect: Expect,
  page: Page,
): Promise<void> {
  const opener = page.getByTestId('public-status-subscribe')
  await opener.focus()
  await opener.press('Enter')
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveRole('dialog')
  await expect(modal).toHaveAttribute('aria-modal', 'true')
  await expect(modal).toHaveAccessibleName('Subscribe to updates')
  const email = page.getByTestId('status-subscription-email')
  await expect(email).toHaveAccessibleName('Email address')
  await expect(email).toBeFocused()
  await expect(page.getByTestId('status-subscription-live')).toHaveAttribute(
    'aria-live',
    'polite',
  )
  await expect(
    page.getByTestId('status-subscription-error-slot'),
  ).toHaveAttribute('role', 'alert')
  const cancel = page.getByTestId('status-subscription-cancel')
  await cancel.focus()
  await cancel.press('Tab') // The empty form cannot submit; the trap wraps to Close.
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(cancel).toBeFocused()
  await modal.press('Escape')
  await expect(modal).toBeHidden()
  await expect(opener).toBeFocused()
  await opener.press('Enter')
  await expect(email).toBeFocused()
}

/** A mailed unsubscribe capability opens a named, masked and focus-trapped confirmation. */
export async function expectStatusUnsubscribeDialog(
  expect: Expect,
  page: Page,
  email: string,
): Promise<void> {
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveRole('dialog')
  await expect(modal).toHaveAttribute('aria-modal', 'true')
  await expect(modal).toHaveAccessibleName('Unsubscribe')
  await expect(page.getByTestId('status-subscription-address')).toContainText(
    '****',
  )
  await expect(
    page.getByTestId('status-subscription-address'),
  ).not.toContainText(email)
  await expect(modal).toBeFocused()
  const keep = page.getByTestId('status-subscription-cancel')
  const submit = page.getByTestId('status-subscription-submit')
  await expect(keep).toHaveAccessibleName('Keep')
  await expect(submit).toHaveAccessibleName('Unsubscribe')
  await submit.focus()
  await submit.press('Tab')
  await expect(page.getByTestId('modal-close')).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(submit).toBeFocused()
  await keep.focus()
  await keep.press('Enter')
  await expect(modal).toBeHidden()
}
