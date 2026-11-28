import type { Expect, Locator, Page } from '@playwright/test'

import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'

// The tab of every stand writes in the formats of the built-in English locale
// without a country — DD.MM.YYYY, HH:mm:ss and 1,000.00 — with or without the
// I18N feature. The client never formats by the browser's locale: the formats
// come from the server, and with the feature its region ladder passes over the
// en-US or en-GB a stand's browser names, since the stand keeps the countries
// us and gb switched off. The moment oracles below spell those templates out by
// hand from the numeric parts Intl gives in a zone; readerNumber() has
// Intl.NumberFormat('en-US') write the figure, which equals the 1,000.00
// template on the values the specs pass and does not repeat its edges (-0, the
// infinities, compact). None imports the product formatter into the check.

/** The kinds and flags of the core moment formatter, mirrored by the oracle. */
export interface ReaderMomentOptions {
  /** What is written: the date, the time, both (the default), a calendar label, «November 2026» or «Monday». */
  readonly kind?:
    'date' | 'time' | 'dateTime' | 'calendar' | 'monthYear' | 'weekday'
  /** Whether a time carries its seconds; true by default. */
  readonly seconds?: boolean
  /** Whether a time with seconds carries «.SSS» after them; false by default. */
  readonly milliseconds?: boolean
  /** Whether the zone's short en-US name follows the text; false by default. */
  readonly zoneName?: boolean
  /** The reference of a calendar label in epoch milliseconds; the runner's clock by default. */
  readonly now?: number
}

/** The calendar words by the local-day difference from the reference. */
const CALENDAR_WORDS: ReadonlyMap<number, string> = new Map([
  [-1, 'yesterday'],
  [0, 'today'],
  [1, 'tomorrow'],
])
const DAY_MS = 86_400_000

/**
 * The numeric local parts of a moment in a zone, two digits each but the year,
 * on a 24-hour clock.
 *
 * @param moment The moment in epoch milliseconds.
 * @param timeZone The zone it is read in.
 * @param milliseconds Whether the fraction of a second is read too.
 */
function localParts(moment: number, timeZone: string, milliseconds: boolean) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
    ...(milliseconds ? { fractionalSecondDigits: 3 } : {}),
  }).formatToParts(new Date(moment))
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((item) => item.type === type)?.value ?? ''
  const [year, month, day] = [part('year'), part('month'), part('day')]
  return {
    date: `${day}.${month}.${year}`,
    time: `${part('hour')}:${part('minute')}`,
    second: part('second'),
    millisecond: part('fractionalSecond'),
    dayNumber: Date.UTC(Number(year), Number(month) - 1, Number(day)) / DAY_MS,
  }
}

/**
 * One English word or name of a moment in a zone by en-US Intl.
 *
 * @param moment The moment in epoch milliseconds.
 * @param timeZone The zone it is read in.
 * @param options The single Intl field asked for.
 */
function localName(
  moment: number,
  timeZone: string,
  options: Intl.DateTimeFormatOptions,
): string {
  return new Intl.DateTimeFormat('en-US', { timeZone, ...options }).format(
    new Date(moment),
  )
}

/**
 * Independent oracle of an epoch-millisecond moment as the stand's tab writes
 * it in the reader's zone: the en templates, the core's kinds and flags.
 *
 * @param moment The moment in epoch milliseconds.
 * @param timeZone The reader's zone.
 * @param options The kind and flags the screen asks the core formatter for.
 */
export function readerMillisecondMoment(
  moment: number,
  timeZone: string,
  options: ReaderMomentOptions = {},
): string {
  const kind = options.kind ?? 'dateTime'
  const seconds = options.seconds ?? true
  const milliseconds = seconds && options.milliseconds === true
  const local = localParts(moment, timeZone, milliseconds)
  const time = !seconds
    ? local.time
    : `${local.time}:${local.second}${milliseconds ? `.${local.millisecond}` : ''}`
  const now = options.now ?? Date.now()
  const text =
    kind === 'date'
      ? local.date
      : kind === 'time'
        ? time
        : kind === 'calendar'
          ? (CALENDAR_WORDS.get(
              local.dayNumber - localParts(now, timeZone, false).dayNumber,
            ) ?? local.date)
          : kind === 'monthYear'
            ? `${localName(moment, timeZone, { month: 'long' })} ${local.date.slice(-4)}`
            : kind === 'weekday'
              ? localName(moment, timeZone, { weekday: 'long' })
              : `${local.date} ${time}`
  if (options.zoneName !== true) return text
  const zone = new Intl.DateTimeFormat('en-US', {
    timeZone,
    timeZoneName: 'short',
  })
    .formatToParts(new Date(moment))
    .find((item) => item.type === 'timeZoneName')?.value
  return `${text} ${zone ?? timeZone}`
}

/**
 * Independent oracle of an ISO UTC wire moment as the stand's tab writes it in
 * the reader's zone; never import the product formatter into this check.
 *
 * @param moment The moment as ISO UTC ending in Z.
 * @param timeZone The reader's zone.
 * @param options The kind and flags the screen asks the core formatter for.
 */
export function readerMoment(
  moment: string,
  timeZone: string,
  options: ReaderMomentOptions = {},
): string {
  return readerMillisecondMoment(Date.parse(moment), timeZone, options)
}

/**
 * Independent oracle of a number as the stand's tab writes it in the en
 * template 1,000.00. Intl.NumberFormat('en-US') writes the figure — thousands
 * grouped by a comma, a point before the fraction, trailing fraction zeros
 * dropped down to the minimum — which equals the template on the values the
 * specs pass; the template's edges (-0, the infinities, compact) are not
 * repeated.
 *
 * @param value The number the screen writes.
 * @param options The fraction digits the screen asks the core for (0…3 by default).
 */
export function readerNumber(
  value: number,
  options: {
    readonly minimumFractionDigits?: number
    readonly maximumFractionDigits?: number
  } = {},
): string {
  return new Intl.NumberFormat('en-US', {
    useGrouping: true,
    minimumFractionDigits: options.minimumFractionDigits ?? 0,
    maximumFractionDigits: Math.max(
      options.minimumFractionDigits ?? 0,
      options.maximumFractionDigits ?? 3,
    ),
  }).format(value)
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

/** Numeric wire proof and the independent oracle of the stand tab's formats. */
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
  const expected = readerMillisecondMoment(stamp, timeZone)
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
        `✓ ${readerMoment(backup[field], timeZone, { kind: 'date' })}`,
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
  const expected = readerMillisecondMoment(stamp, timeZone)
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

/**
 * The moment as `YYYY-MM-DDTHH:mm:ss` on a 24-hour clock in the named zone,
 * by the browser's own Intl — never the product's formatter.
 */
export function readerLocalDateTime(
  page: Page,
  at: number,
  timeZone: string,
): Promise<string> {
  return page.evaluate(
    ({ at, timeZone }) => {
      const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
      }).formatToParts(new Date(at))
      const part = (type: string) =>
        parts.find((part) => part.type === type)!.value
      return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}:${part('second')}`
    },
    { at, timeZone },
  )
}

/** The named parts of a DateTimePicker edge with their text for one moment, in the named zone. */
async function pickerDateTimeParts(
  page: Page,
  at: number,
  timeZone: string,
): Promise<ReadonlyArray<readonly [string, string]>> {
  const [date, time] = (await readerLocalDateTime(page, at, timeZone)).split(
    'T',
  )
  const [year, month, day] = date.split('-')
  const [hours, minutes, seconds] = time.split(':')
  return [
    ['Day', day],
    ['Month', month],
    ['Year', year],
    ['Hours', hours],
    ['Minutes', minutes],
    ['Seconds', seconds],
  ]
}

/** Type one moment into a DateTimePicker edge by its named parts, in the named zone. */
export async function enterPickerDateTime(
  expect: Expect,
  field: Locator,
  edge: string,
  at: number,
  timeZone: string,
): Promise<void> {
  for (const [name, text] of await pickerDateTimeParts(
    field.page(),
    at,
    timeZone,
  )) {
    const part = field.getByRole('spinbutton', {
      name: `${edge} ${name}`,
      exact: true,
    })
    await part.focus()
    // One Backspace empties the whole part, so a prefilled edge is replaced.
    await part.press('Backspace')
    await part.pressSequentially(text, { delay: 10 })
    await expect(part).toHaveAttribute('aria-valuenow', String(Number(text)))
  }
}

/** Check that a DateTimePicker edge shows one moment by its named parts, in the named zone. */
export async function expectPickerDateTime(
  expect: Expect,
  field: Locator,
  edge: string,
  at: number,
  timeZone: string,
): Promise<void> {
  for (const [name, text] of await pickerDateTimeParts(
    field.page(),
    at,
    timeZone,
  ))
    await expect(
      field.getByRole('spinbutton', { name: `${edge} ${name}`, exact: true }),
    ).toHaveAttribute('aria-valuenow', String(Number(text)))
}
