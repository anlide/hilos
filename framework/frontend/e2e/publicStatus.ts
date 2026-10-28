import type { Expect, Page, Request, Response } from '@playwright/test'
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

/** Last trusted archive and day labels remain readable through stale/recovery. */
export async function expectPublicStatusArchive(
  expect: Expect,
  page: Page,
  archive: { history: string; days: string[] },
): Promise<void> {
  await expect(page.getByTestId('public-status-history')).toHaveText(
    archive.history,
    { useInnerText: true },
  )
  expect(
    await page
      .getByTestId('public-status-days-90')
      .getByRole('button')
      .evaluateAll((buttons) =>
        buttons.map((button) => button.getAttribute('aria-label')),
      ),
  ).toEqual(archive.days)
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
      /\S+ · (Operational|Maintenance|Partial outage|Major outage)$/.test(
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
