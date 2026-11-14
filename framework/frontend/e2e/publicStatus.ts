import type { Expect, Page, Request, Response, Route } from '@playwright/test'
import {
  publicStatusFileSchema,
  publicStatusHeartbeatSchema,
  type PublicStatusPair,
} from '../core/src/status/index.js'
import { deliveryObject, watchDeliveryFrames } from './deliveryFrames.js'
import { readerMillisecondMoment } from './moments.js'

export const PUBLIC_STATUS_PATH = '/status'
export const PUBLIC_STATUS_FILES = [
  '/status-data/status.json',
  '/status-data/heartbeat.json',
] as const

/** Hold real file requests across a socket drop; release continues them unchanged. */
export async function holdPublicStatusFiles(page: Page) {
  const requested: string[] = []
  let release!: () => void
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })
  const active = new Set<Promise<void>>()
  const matches = (url: URL): boolean =>
    PUBLIC_STATUS_FILES.some((path) => url.pathname === path)
  const handler = async (route: Route): Promise<void> => {
    const operation = (async () => {
      requested.push(new URL(route.request().url()).pathname)
      await gate
      await route.continue()
    })()
    active.add(operation)
    try {
      await operation
    } finally {
      active.delete(operation)
    }
  }
  await page.route(matches, handler)
  return {
    requested,
    release,
    async dispose(): Promise<void> {
      release()
      await Promise.all(active)
      await page.unroute(matches, handler)
    },
  }
}

/** One real browser GET, including failed or incomplete reads. */
interface FileRead {
  path: string
  requestedAt: number
  /** Body captured; a JSON parse failure still counts as a completed read. */
  completedAt?: number
  status?: number
  headers?: Record<string, string>
  body?: unknown
  failure?: string
}

/** A coherent HTTP pair whose heartbeat the mounted DOM actually displays. */
export interface DisplayedStatusPair extends PublicStatusPair {
  displayedAt: number
  lastUpdate: string
}

/**
 * Observe from before cold navigation. Pair by read attempt, never by revision
 * alone: a heartbeat-only publication can repeat the same content revision.
 * No fetch, socket message or application state is replaced by this observer.
 */
export function watchPublicStatus(page: Page) {
  const wire = watchDeliveryFrames(page)
  const reads: [FileRead[], FileRead[]] = [[], []]
  const requests = new Map<Request, FileRead>()
  const pending = new Set<Promise<void>>()
  const displayed: DisplayedStatusPair[] = []
  const requested = (request: Request) => {
    const path = new URL(request.url()).pathname
    const kind = PUBLIC_STATUS_FILES.findIndex((file) => path === file)
    if (kind < 0 || request.method() !== 'GET') return
    const read = { path, requestedAt: performance.now() }
    reads[kind].push(read)
    requests.set(request, read)
  }
  const responded = (response: Response) => {
    const read = requests.get(response.request())
    if (!read) return
    const done = (async () => {
      read.status = response.status()
      read.headers = response.headers()
      try {
        const body = await response.text()
        // Navigation can discard Chromium's response resource before capture.
        // Keep that failure in the log without claiming the body was read.
        read.completedAt = performance.now()
        read.body = JSON.parse(body)
      } catch (error) {
        read.failure = String(error)
      }
    })()
    pending.add(done)
    void done.finally(() => pending.delete(done))
  }
  const failed = (request: Request) => {
    const read = requests.get(request)
    if (read) read.failure = request.failure()?.errorText ?? 'Request failed'
  }
  page.on('request', requested)
  page.on('response', responded)
  page.on('requestfailed', failed)
  return {
    wire,
    reads,
    displayed,
    pairs(): PublicStatusPair[] {
      return reads[0].flatMap((read, index) => {
        const other = reads[1][index]
        if (read.status !== 200 || other?.status !== 200) return []
        const status = publicStatusFileSchema.safeParse(read.body)
        const heartbeat = publicStatusHeartbeatSchema.safeParse(other.body)
        return status.success &&
          heartbeat.success &&
          status.data.revision === heartbeat.data.revision
          ? [{ status: status.data, heartbeat: heartbeat.data }]
          : []
      })
    },
    async stop(): Promise<void> {
      page.off('request', requested)
      page.off('response', responded)
      page.off('requestfailed', failed)
      wire.stop()
      await Promise.all(pending)
      requests.clear()
    },
  }
}

export type PublicStatusLog = ReturnType<typeof watchPublicStatus>

/** Independent test facts of a synthetic publication gap. */
export interface PublicStatusSilenceFact {
  startedAt: number
  endedAt: number | null
}

/** Keep archive facts strict and change only labels whose dates intersect test-owned silence. */
export async function expectPublicStatusArchive(
  expect: Expect,
  page: Page,
  archive: { history: string; days: string[]; accepted?: PublicStatusPair },
  silences: readonly PublicStatusSilenceFact[] = [],
): Promise<void> {
  await expect(page.getByTestId('public-status-history')).toHaveText(
    archive.history,
    { useInnerText: true },
  )
  let expected = archive.days
  if (silences.length > 0) {
    if (archive.accepted === undefined)
      throw new Error(
        'Silence assertions require the independently accepted archive.',
      )
    const days = await page.evaluate((aliveAt) => {
      const today = new Date(aliveAt)
      const formatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
      })
      return Array.from({ length: 90 }, (_, index) => {
        const start = new Date(
          today.getFullYear(),
          today.getMonth(),
          today.getDate() - 89 + index,
        )
        const end = new Date(
          start.getFullYear(),
          start.getMonth(),
          start.getDate() + 1,
        )
        return {
          start: start.getTime(),
          end: end.getTime(),
          label: formatter.format(start),
        }
      })
    }, archive.accepted.heartbeat.aliveAt)
    expected = expectedPublicStatusArchiveDays(
      archive.days,
      archive.accepted.status,
      days,
      silences,
      Date.now(),
    )
  }
  expect(
    await page
      .getByTestId('public-status-days-90')
      .getByRole('button')
      .evaluateAll((buttons) =>
        buttons.map((button) => button.getAttribute('aria-label')),
      ),
  ).toEqual(expected)
}

/**
 * Oracle from the accepted file and the page's own calendar: a 90-day strip day
 * that ended by its component's start of observation reads Not monitored yet,
 * and no other day does; a v1–v3 file carries no such moment.
 */
export async function expectPublicStatusNotMonitoredDays(
  expect: Expect,
  page: Page,
  accepted: PublicStatusPair,
): Promise<void> {
  const ends = await page.evaluate((aliveAt) => {
    const today = new Date(aliveAt)
    return Array.from({ length: 90 }, (_, index) =>
      new Date(
        today.getFullYear(),
        today.getMonth(),
        today.getDate() - 88 + index,
      ).getTime(),
    )
  }, accepted.heartbeat.aliveAt)
  const expected = accepted.status.components.flatMap((component) => {
    const observedSince =
      'observedSince' in component ? component.observedSince : null
    return ends.map((end) => observedSince !== null && end <= observedSince)
  })
  const buttons = page.getByTestId('public-status-days-90').getByRole('button')
  await expect(buttons).toHaveCount(expected.length)
  expect(
    await buttons.evaluateAll((days) =>
      days.map(
        (day) =>
          day.getAttribute('aria-label')?.endsWith(' · Not monitored yet') ??
          false,
      ),
    ),
  ).toEqual(expected)
}

/** Check one gap's reader-local date and keep its selected detail through recovery. */
export async function expectPublicStatusSilenceDay(
  expect: Expect,
  page: Page,
  status: PublicStatusPair['status'],
  silence: PublicStatusSilenceFact,
  select = false,
): Promise<void> {
  const day = await page.evaluate((at) => {
    const date = new Date(at)
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate())
    const end = new Date(
      date.getFullYear(),
      date.getMonth(),
      date.getDate() + 1,
    )
    return {
      start: start.getTime(),
      end: end.getTime(),
      label: new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
      }).format(start),
    }
  }, silence.startedAt)
  const labels = expectedPublicStatusArchiveDays(
    [],
    status,
    [day],
    [silence],
    Date.now(),
  )
  for (const [index, label] of labels.entries()) {
    expect(label).toContain(' · No data')
    const button = page
      .getByTestId('public-status-days-90')
      .nth(index)
      .getByRole('button', { name: label, exact: true })
    await expect(button).toBeVisible()
    if (select) await button.click()
    await expect(
      page.getByTestId('public-status-day-detail').nth(index),
    ).toHaveText(label)
  }
}

/**
 * Test oracle from saved transport facts and explicit gap boundaries; no production calendar resolver.
 * Unaffected dates keep their complete saved assertion. Affected dates recompute known events
 * by splitting each recorded interval at gap boundaries.
 */
export function expectedPublicStatusArchiveDays(
  saved: readonly string[],
  status: PublicStatusPair['status'],
  days: readonly { start: number; end: number; label: string }[],
  silences: readonly PublicStatusSilenceFact[],
  now: number,
): string[] {
  const newGaps = silences.map((gap) => ({
    start: gap.startedAt,
    end: gap.endedAt ?? now,
  }))
  const gaps = [
    ...newGaps,
    ...('silencePeriods' in status
      ? status.silencePeriods.map((period) => ({
          start: period.startedAt,
          end: period.endedAt,
        }))
      : []),
  ]
  return status.components.flatMap((component, componentIndex) =>
    days.map((day, index) => {
      const end = Math.min(day.end, now)
      if (!newGaps.some((gap) => gap.start < end && gap.end > day.start))
        return saved[componentIndex * days.length + index]!
      const events = [
        ...status.maintenancePeriods.map((period) => ({
          start: period.startedAt,
          end: period.endedAt ?? period.lastObservedAt,
          label: 'Maintenance',
          priority: 1,
        })),
        ...status.outages
          .filter((outage) => outage.componentKey === component.componentKey)
          .map((outage) => ({
            start: outage.detectedAt,
            end: outage.resolvedAt ?? now,
            label:
              outage.severity === 'major_outage'
                ? 'Major outage'
                : 'Partial outage',
            priority: outage.severity === 'major_outage' ? 3 : 2,
          })),
      ].sort((left, right) => right.priority - left.priority)
      const known = events.find((event) => {
        const start = Math.max(day.start, event.start)
        const stop = Math.min(end, event.end)
        if (start >= stop) return false
        const boundaries = [
          start,
          stop,
          ...gaps.flatMap((gap) => [gap.start, gap.end]),
        ]
          .filter((point) => point >= start && point <= stop)
          .sort((left, right) => left - right)
        return boundaries.some((point, i) => {
          const next = boundaries[i + 1]
          return (
            next !== undefined &&
            point < next &&
            !gaps.some((gap) => gap.start <= point && next <= gap.end)
          )
        })
      })
      return `${day.label} · No data${known === undefined ? '' : ` · ${known.label} recorded`}`
    }),
  )
}

/** A stopped publisher's timestamp explains Unknown without erasing history. */
export async function expectPublicStatusStale(
  expect: Expect,
  page: Page,
  pair: DisplayedStatusPair,
): Promise<void> {
  const zone = await page.evaluate(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone,
  )
  const label = await readerMillisecondMoment(
    page,
    pair.heartbeat.aliveAt,
    zone,
  )
  await expect(page.getByTestId('public-status-banner')).toHaveText(
    `The system is not responding since ${label}`,
  )
  await expect(page.getByTestId('public-status-component-state')).toHaveText(
    pair.status.components.map(() => 'Unknown'),
  )
  await expect(page.getByTestId('public-status-last-update')).toHaveText(
    `Last update from the system: ${label}`,
  )
}

/** Wait for real HTTP evidence and the same heartbeat in reader-local DOM. */
export async function expectPublicStatusPair(
  expect: Expect,
  page: Page,
  log: PublicStatusLog,
  matches: (pair: PublicStatusPair) => boolean = () => true,
): Promise<DisplayedStatusPair> {
  const zone = await page.evaluate(
    () => Intl.DateTimeFormat().resolvedOptions().timeZone,
  )
  let displayed: DisplayedStatusPair | undefined
  await expect
    .poll(async () => {
      const pair = log.pairs().filter(matches).at(-1)
      if (!pair) return false
      const label = await readerMillisecondMoment(
        page,
        pair.heartbeat.aliveAt,
        zone,
      )
      const lastUpdate = `Last update from the system: ${label}`
      if (
        (await page.getByTestId('public-status-last-update').innerText()) !==
        lastUpdate
      )
        return false
      displayed = { ...pair, displayedAt: performance.now(), lastUpdate }
      return true
    })
    .toBe(true)
  log.displayed.push(displayed!)
  return displayed!
}

/** Guest file reading sends no page/table subscription or mutation. */
export function expectPublicStatusReadOnly(
  expect: Expect,
  log: PublicStatusLog,
): void {
  expect(
    log.wire.sent.filter(({ wire }) => {
      const type = String(deliveryObject(wire).type)
      return (
        type === 'page_subscribe' ||
        type === 'action' ||
        type.startsWith('table_')
      )
    }),
  ).toEqual([])
  for (const packet of log.wire.packets) {
    for (const frame of packet.frames) {
      const wire = deliveryObject(frame.wire)
      if (wire.type !== 'status_files_changed') continue
      expect(frame.failure).toBeUndefined()
      expect(wire.data).toEqual({})
    }
  }
}

/** Public semantics and keyboard day details over the real built project. */
export async function expectPublicStatusAccessibility(
  expect: Expect,
  page: Page,
  componentName: string,
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'System status',
  )
  const live = page.getByTestId('public-status-live')
  await expect(live).toHaveAttribute('role', 'status')
  await expect(live).toHaveAttribute('aria-live', 'polite')
  await expect(live).toHaveAttribute('aria-atomic', 'true')
  await expect(page.getByTestId('public-status-component-state')).toHaveText(
    'Operational',
  )
  const days = page.getByTestId('public-status-days-90')
  await expect(days).toBeVisible()
  await expect(days.getByRole('group')).toHaveAccessibleName(
    `${componentName}: last 90 days`,
  )
  await expect(days.getByRole('button')).toHaveCount(90)
  await expect(page.getByTestId('public-status-days-30')).toBeHidden()
  const labels = await days
    .getByRole('button')
    .evaluateAll((buttons) =>
      buttons.map((button) => button.getAttribute('aria-label')),
    )
  expect(
    labels.every((label) =>
      /\S+ · (Operational|Maintenance|Partial outage|Major outage|No data(?: · (Maintenance|Partial outage|Major outage) recorded)?|Not monitored yet)$/.test(
        label ?? '',
      ),
    ),
  ).toBe(true)
  const skip = page.getByTestId('skip-to-content')
  await expect(skip).toHaveAttribute('href', '#hilos-main-content')
  await skip.focus()
  await skip.press('Enter')
  await expect(page.getByRole('main')).toBeFocused()
  await page.keyboard.press('Tab')
  const subscribe = page.getByTestId('public-status-subscribe')
  await expect(subscribe).toBeFocused()
  await expect(subscribe).toHaveAccessibleName('Subscribe to updates')
  await page.keyboard.press('Tab')
  const focusedDay = days.locator('button:focus')
  await expect(focusedDay).toHaveCount(1)
  const label = await focusedDay.getAttribute('aria-label')
  const detail = page.getByTestId('public-status-day-detail')
  await expect(focusedDay).toHaveAttribute(
    'aria-describedby',
    (await detail.getAttribute('id'))!,
  )
  await page.keyboard.press('Enter')
  await expect(detail).toHaveText(label!)
  await expect(detail).toBeVisible()
  expect(
    await page.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    ),
  ).toBe(true)
}
