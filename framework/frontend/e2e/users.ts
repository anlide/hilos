import type { Expect, Page } from '@playwright/test'

import { deliveryObject } from './deliveryFrames.js'
import { expectReaderMillisecondMoment } from './moments.js'
import type { SocketFrame } from './socket.js'

/** A real people row, retaining the physical daemon message it arrived in. */
export interface UserTableRow {
  readonly frame: SocketFrame
  readonly tableKey: string
  readonly rowKey: string
  readonly user: Record<string, unknown>
}

/** Read snapshots and live rows through the existing decoder's observations. */
export function userTableRows(frames: readonly SocketFrame[]): UserTableRow[] {
  const result: UserTableRow[] = []
  for (const frame of frames) {
    const wire = deliveryObject(frame.wire)
    const data = deliveryObject(wire.data)
    const sections: Array<[string, unknown]> = []
    if (
      wire.type === 'page_response' &&
      (data.page === 'hilos_users' || data.page === 'hilos_user')
    ) {
      const payload = deliveryObject(data.payload)
      sections.push(
        ...Object.entries(deliveryObject(payload.tables)),
        ...Object.entries(deliveryObject(payload.windows)),
      )
    } else if (
      [
        'table_window',
        'table_viewport_delta',
        'table_viewport_append',
      ].includes(String(wire.type))
    ) {
      sections.push([String(data.tableKey), data])
    }
    for (const [tableKey, section] of sections) {
      if (
        tableKey !== 'hilosUsers' &&
        tableKey !== 'userDetail' &&
        tableKey !== 'mergeCandidates'
      )
        continue
      const body = deliveryObject(section)
      const rows = Array.isArray(body.rows) ? body.rows : []
      for (const value of body.row === undefined ? rows : [...rows, body.row]) {
        const row = deliveryObject(value)
        const slots = deliveryObject(row.slots)
        if (!('users' in slots)) continue
        result.push({
          frame,
          tableKey,
          rowKey: String(row.rowKey),
          user: deliveryObject(slots.users),
        })
      }
    }
  }
  return result
}

/** Pin every observed activity's wire type, including nullable rows. */
export function expectUserActivityWire(
  expect: Expect,
  frames: readonly SocketFrame[],
): void {
  const rows = userTableRows(frames)
  expect(rows.length).toBeGreaterThan(0)
  for (const { frame, user } of rows) {
    expect(frame.failure).toBeUndefined()
    expect(frame.raw).toEqual(expect.any(String))
    if (user.lastActivity !== null) {
      expect(user.lastActivity).toEqual(expect.any(Number))
      expect(Number.isSafeInteger(user.lastActivity)).toBe(true)
      // These are contemporary accounts on a fresh stand. Epoch seconds would
      // fall in January 1970 if mistaken for milliseconds.
      expect(user.lastActivity).toBeGreaterThan(1_000_000_000_000)
      expect(
        Number.isFinite(new Date(user.lastActivity as number).getTime()),
      ).toBe(true)
    }
  }
}

/** The latest delivered activity of a real list/card/candidate, without coercion. */
export function expectUserActivityMoment(
  expect: Expect,
  frames: readonly SocketFrame[],
  tableKey: 'hilosUsers' | 'userDetail' | 'mergeCandidates',
  userId: number,
): number {
  expectUserActivityWire(expect, frames)
  const rows = userTableRows(frames).filter(
    (row) => row.tableKey === tableKey && row.rowKey === String(userId),
  )
  expect(rows.length).toBeGreaterThan(0)
  const user = rows.at(-1)!.user
  expect(user.id).toBe(userId)
  expect(user.lastActivity).toEqual(expect.any(Number))
  return user.lastActivity as number
}

/** A viewer's real cards hide both chosen and null values on every delivery. */
export function expectHiddenUserTimeZones(
  expect: Expect,
  frames: readonly SocketFrame[],
  userIds: readonly number[],
  privateTimeZone: string,
): void {
  const rows = userTableRows(frames)
  for (const userId of userIds) {
    const matching = rows.filter((row) => row.rowKey === String(userId))
    const details = matching.filter((row) => row.tableKey === 'userDetail')
    expect(details.length).toBeGreaterThan(0)
    for (const { user } of details) {
      expect(user.id).toBe(userId)
      expect(user.name).toEqual({ _hidden: true })
      expect(user.timeZone).toEqual({ _hidden: true })
    }
    for (const { frame } of matching) {
      expect(frame.failure).toBeUndefined()
      expect(frame.raw).toEqual(expect.any(String))
      expect(frame.raw).not.toContain(privateTimeZone)
    }
  }
  expectUserActivityWire(expect, frames)
}

/** The exact read-only Profile row, including a hidden null for a viewer. */
export async function expectUserTimeZone(
  expect: Expect,
  page: Page,
  value: string | null | { readonly _hidden: true },
): Promise<void> {
  const row = page.getByTestId('hilos-user-time-zone')
  await expect(row).toBeVisible()
  await expect(row.locator('p.fw-medium')).toHaveText('Time zone')
  if (typeof value === 'object' && value !== null) {
    await expect(row.getByTestId('hilos-hidden')).toHaveText('Hidden')
    await expect(row.locator('p')).toHaveText(['Time zone'])
  } else {
    await expect(row.locator('p')).toHaveText([
      'Time zone',
      value ?? 'Automatic — follows the browser',
    ])
    await expect(row.getByTestId('hilos-hidden')).toHaveCount(0)
  }
  await expect(
    row.locator('button, a, input, select, textarea, [tabindex]'),
  ).toHaveCount(0)
}

/** Compare a real list/candidate cell or shared head to the numeric wire moment. */
export async function expectUserActivityDisplay(
  expect: Expect,
  page: Page,
  userId: number,
  moment: unknown,
  timeZone: string,
  surface: 'list' | 'card' | 'candidate',
): Promise<string> {
  if (surface === 'card') {
    const head = page.getByTestId('hilos-page-head-block')
    // The shared head's description is the paragraph containing the public id.
    const description = head
      .locator('p')
      .filter({ hasText: `#${userId} · Last seen ` })
    await expect(description).toBeVisible()
    return expectReaderMillisecondMoment(
      expect,
      description,
      moment,
      timeZone,
      `#${userId} · Last seen `,
    )
  }
  const scope = surface === 'candidate' ? page.getByTestId('modal') : page
  const row = scope.getByTestId(`hilos-table-row-${userId}`)
  await expect(row).toBeVisible()
  // The header declares Last activity; select its data cell by that real column.
  const header =
    surface === 'candidate'
      ? scope.getByRole('columnheader', { name: 'Last activity', exact: true })
      : scope.getByTestId('hilos-table-sort-lastActivity')
  const column = await header.evaluate(
    (header) => (header.closest('th') as HTMLTableCellElement).cellIndex,
  )
  return expectReaderMillisecondMoment(
    expect,
    row.locator('td, th').nth(column),
    moment,
    timeZone,
  )
}
