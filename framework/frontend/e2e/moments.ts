import type { Expect, Locator, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'

/** Independent Intl oracle: never import the product formatter into this check. */
export function readerMoment(
  moment: string,
  timeZone: string,
  kind: 'date' | 'dateTime' = 'dateTime',
): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    dateStyle: 'medium',
    ...(kind === 'dateTime' ? { timeStyle: 'medium' } : {}),
  }).format(new Date(moment))
}

/** An absolute server moment and its exact reader-local DOM representation. */
export async function expectReaderMoment(
  expect: Expect,
  target: Locator,
  moment: unknown,
  timeZone: string,
): Promise<string> {
  expect(moment).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/)
  const stamp = moment as string
  await expect(target).toHaveText(readerMoment(stamp, timeZone))
  return stamp
}

/** Numeric wire proof and an independent oracle in the actual browser's locale. */
export async function expectReaderMillisecondMoment(
  expect: Expect,
  target: Locator,
  moment: unknown,
  timeZone: string,
  prefix = '',
): Promise<string> {
  expect(moment).toEqual(expect.any(Number))
  expect(Number.isSafeInteger(moment)).toBe(true)
  const stamp = moment as number
  expect(Number.isFinite(new Date(stamp).getTime())).toBe(true)
  const expected = await target.page().evaluate(
    ({ stamp, timeZone }) =>
      new Intl.DateTimeFormat(undefined, {
        timeZone,
        dateStyle: 'medium',
        timeStyle: 'medium',
      }).format(new Date(stamp)),
    { stamp, timeZone },
  )
  await expect(target).toHaveText(`${prefix}${expected}`)
  return expected
}

/** Read the latest real initial/replacement window containing this archive. */
export function backupMomentRow(log: DeliveryFrameLog, id: string) {
  for (const packet of log.packets.slice().reverse()) {
    for (const frame of packet.frames.slice().reverse()) {
      const wire = deliveryObject(frame.wire)
      const data = deliveryObject(wire.data)
      const window =
        wire.type === 'page_response' && data.page === 'hilos_backup'
          ? deliveryObject(
              deliveryObject(deliveryObject(data.payload).windows).hilosBackups,
            )
          : wire.type === 'table_window' && data.tableKey === 'hilosBackups'
            ? data
            : {}
      if (!Array.isArray(window.rows)) continue
      const row = window.rows.find(
        (value) => deliveryObject(value).rowKey === id,
      )
      if (row) {
        return {
          frame,
          backup: deliveryObject(
            deliveryObject(deliveryObject(row).slots).backup,
          ),
        }
      }
    }
  }
  return undefined
}

/** Match the actual table payload to the row, including any real compact stamps. */
export async function expectBackupMoments(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  id: string,
  timeZone: string,
): Promise<string> {
  await expect.poll(() => backupMomentRow(log, id)).toBeDefined()
  const { frame, backup } = backupMomentRow(log, id)!
  expect(frame.failure).toBeUndefined()
  expect(frame.raw).toEqual(expect.any(String))
  const row = page.getByTestId(`hilos-table-row-${id}`)
  const createdAt = await expectReaderMoment(
    expect,
    row.getByTestId('hilos-backup-created-at'),
    backup.createdAt,
    timeZone,
  )
  for (const [field, state, expectedState, handle] of [
    ['verifiedAt', 'checksumState', 'verified', 'hilos-backup-checksum'],
    ['shippedAt', 'shipState', 'shipped', 'hilos-backup-shipping'],
  ] as const) {
    if (backup[state] === expectedState && typeof backup[field] === 'string') {
      expect(backup[field]).toMatch(/Z$/)
      await expect(row.getByTestId(handle)).toHaveText(
        `✓ ${readerMoment(backup[field], timeZone, 'date')}`,
      )
    }
  }
  return createdAt
}

/** Read the latest real initial/replacement window containing this legal acceptance. */
export function legalAcceptanceMomentRow(
  log: DeliveryFrameLog,
  userId: number | string,
  document?: string,
) {
  for (const packet of log.packets.slice().reverse()) {
    for (const frame of packet.frames.slice().reverse()) {
      const wire = deliveryObject(frame.wire)
      const data = deliveryObject(wire.data)
      const window =
        wire.type === 'page_response' && data.page === 'hilos_legal_acceptances'
          ? deliveryObject(
              deliveryObject(deliveryObject(data.payload).windows)
                .hilosLegalAcceptances,
            )
          : wire.type === 'table_window' &&
              data.tableKey === 'hilosLegalAcceptances'
            ? data
            : {}
      if (!Array.isArray(window.rows)) continue
      const row = window.rows.find((value) => {
        const item = deliveryObject(value)
        const acceptance = deliveryObject(deliveryObject(item.slots).acceptance)
        if (
          document !== undefined &&
          String(acceptance.document) !== String(document)
        ) {
          return false
        }
        return (
          String(acceptance.userId) === String(userId) ||
          String(item.rowKey) === String(userId)
        )
      })
      if (row) {
        const item = deliveryObject(row)
        return {
          frame,
          rowKey: String(item.rowKey),
          acceptance: deliveryObject(deliveryObject(item.slots).acceptance),
        }
      }
    }
  }
  return undefined
}

/** Match the actual legal acceptance payload to the row, checking numeric epoch ms against reader DOM. */
export async function expectLegalAcceptanceMoments(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  userId: number | string,
  timeZone: string,
): Promise<string> {
  await expect.poll(() => legalAcceptanceMomentRow(log, userId)).toBeDefined()
  const { frame, rowKey, acceptance } = legalAcceptanceMomentRow(log, userId)!
  expect(frame.failure).toBeUndefined()
  expect(frame.raw).toEqual(expect.any(String))
  expect(acceptance.acceptedAt).toEqual(expect.any(Number))
  expect(Number.isSafeInteger(acceptance.acceptedAt)).toBe(true)
  const stamp = acceptance.acceptedAt as number
  expect(Number.isFinite(new Date(stamp).getTime())).toBe(true)
  const acceptedIso = new Date(stamp).toISOString()
  const expected = readerMoment(acceptedIso, timeZone)
  const row = page.getByTestId(`hilos-table-row-${rowKey}`)
  await expect(row.getByTestId('legal-acceptance-accepted-at')).toHaveText(
    expected,
  )
  return expected
}

/** Read an admitted page's actual maintenance state, retaining physical evidence. */
export async function expectMaintenanceMoment(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  timeZone: string,
  manual: boolean,
): Promise<string> {
  const latest = () =>
    log.packets
      .flatMap((packet) => packet.frames)
      .filter(({ wire }) => {
        const value = deliveryObject(wire)
        const data = deliveryObject(value.data)
        return (
          value.type === 'subscription_page_hilos_maintenance' &&
          data.manual === manual &&
          data.startedAt !== null &&
          ['active', 'verifying'].includes(String(data.phase))
        )
      })
      .at(-1)
  await expect.poll(latest).toBeDefined()
  const frame = latest()!
  expect(frame.failure).toBeUndefined()
  expect(frame.raw).toEqual(expect.any(String))
  return expectReaderMoment(
    expect,
    page.getByTestId('hilos-maintenance-started-at'),
    deliveryObject(deliveryObject(frame.wire).data).startedAt,
    timeZone,
  )
}

/** Enter the stand's Chromium native date segments with real keyboard events. */
export async function enterCalendarDate(
  expect: Expect,
  field: Locator,
  date: string,
): Promise<void> {
  const [year, month, day] = date.split('-')
  await field.fill('')
  await field.focus()
  // The documents use lang=en: native segments are month/day/year even when
  // the context's Intl locale is en-GB. The retained trace proves that order.
  for (const [value, left] of [
    [year, 0],
    [month, 2],
    [day, 1],
  ] as const) {
    // Return to the last (year) segment after native automatic advancement.
    for (let step = 0; step < 3; step++) await field.press('ArrowRight')
    for (let step = 0; step < left; step++) await field.press('ArrowLeft')
    await field.pressSequentially(value, { delay: 10 })
  }
  await field.press('Tab')
  await expect(field).toHaveValue(date)
}
