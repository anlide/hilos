import type { Expect, Locator, Page } from '@playwright/test'

import {
  deliveryObject as object,
  watchDeliveryFrames,
} from './deliveryFrames.js'

/** Observe real Logs traffic; the delivery toolbox retains raw frames and failures. */
export function watchLogFrames(page: Page) {
  const log = watchDeliveryFrames(page)
  const frames = () => log.packets.flatMap((packet) => packet.frames)
  const data = (type: string) =>
    frames()
      .filter((frame) => object(frame.wire).type === type)
      .map((frame) => object(object(frame.wire).data))

  return {
    ...log,
    frames,
    data,
    request(action: string) {
      return log.sent
        .map(({ wire }) => object(wire))
        .reverse()
        .find((wire) => wire.type === 'action' && wire.action === action)
    },
    ack(action: string) {
      return frames()
        .map(({ wire }) => object(wire))
        .reverse()
        .find(
          (wire) =>
            wire.type === 'action_success' &&
            object(wire.data).action === action,
        )
    },
    line(marker: string, carrier: string) {
      const payloads =
        carrier === 'logs_lines_appended'
          ? data(carrier)
          : data('action_success')
              .filter((reply) => reply.action === carrier)
              .map((reply) => object(reply.reply))
      return payloads
        .flatMap((payload) =>
          Array.isArray(payload.lines) ? payload.lines : [],
        )
        .map(object)
        .find((line) => line.text === `${marker} #1`)
    },
    rotations(): Map<string, Record<string, unknown>> {
      const rows = new Map<string, Record<string, unknown>>()
      for (const { wire } of frames()) {
        const envelope = object(wire)
        const payload = object(envelope.data)
        const window =
          envelope.type === 'page_response'
            ? object(object(object(payload.payload).windows).hilosLogRotations)
            : envelope.type === 'table_window' &&
                payload.tableKey === 'hilosLogRotations'
              ? payload
              : {}
        if (!Array.isArray(window.rows)) continue
        for (const row of window.rows) {
          const fragment = object(row)
          rows.set(
            String(fragment.rowKey),
            object(object(fragment.slots).batch),
          )
        }
      }
      return rows
    },
  }
}

/** One observation, including the original physical transport and outgoing requests. */
export type LogFrameLog = ReturnType<typeof watchLogFrames>

/** An independent browser-locale oracle with an explicit reader zone. */
export async function logMomentLabel(
  page: Page,
  moment: string | number,
  timeZone: string,
  kind: 'clock' | 'dateTime' | 'overview' = 'dateTime',
): Promise<string> {
  return page.evaluate(
    ({ moment, timeZone, kind }) => {
      const format: Intl.DateTimeFormatOptions =
        kind === 'clock'
          ? {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
              fractionalSecondDigits: 3,
              hourCycle: 'h23',
            }
          : kind === 'overview'
            ? {
                year: 'numeric',
                month: 'numeric',
                day: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
                second: 'numeric',
              }
            : { dateStyle: 'medium', timeStyle: 'medium' }
      return new Intl.DateTimeFormat(undefined, { ...format, timeZone }).format(
        new Date(moment),
      )
    },
    { moment, timeZone, kind },
  )
}

/** Assert canonical UTC, retaining the exact milliseconds sent by the real owner. */
export function expectLogUtcMoment(expect: Expect, moment: unknown): string {
  expect(moment).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
  const value = moment as string
  expect(new Date(value).toISOString()).toBe(value)
  return value
}

/** Distinguish milliseconds from seconds and a second conversion, without the device clock. */
export function expectLogEpochMoment(expect: Expect, moment: unknown): number {
  expect(Number.isSafeInteger(moment)).toBe(true)
  const value = moment as number
  expect(value).toBeGreaterThan(Date.UTC(2020, 0, 1))
  expect(value).toBeLessThan(Date.UTC(2100, 0, 1))
  expect(value % 1000).toBe(0)
  return value
}

/** The physical directory keeps UTC even when the reader wears another zone. */
export function logBatchDirectory(batchAt: number): string {
  return new Date(batchAt)
    .toISOString()
    .replace('T', '-')
    .replace(/:/g, '-')
    .replace('.000Z', 'Z')
}

/** The same physical batch is addressed in the catalog, URL and correlated read. */
export async function expectLogBatchViewer(
  expect: Expect,
  page: Page,
  log: LogFrameLog,
  batch: Record<string, unknown>,
  timeZone: string,
): Promise<void> {
  const batchAt = expectLogEpochMoment(expect, batch.batchAt)
  const directory = logBatchDirectory(batchAt)
  expect([`staging/${directory}/`, `archive/${directory}/`]).toContain(
    batch.path,
  )
  expect(batch.absolutePath).toEqual(expect.stringContaining('/' + directory))
  const source = page.getByTestId('hilos-log-source')
  await expect(source).toHaveValue(String(batchAt))
  expect(
    await source.evaluate((select: HTMLSelectElement) =>
      select.selectedOptions[0]?.textContent?.trim(),
    ),
  ).toBe(`Batch — ${await logMomentLabel(page, batchAt, timeZone)}`)
  const stream = await page.getByTestId('hilos-log-stream').inputValue()
  await expect(page).toHaveURL(
    `/hilos/logs/view/${batch.node}/${batchAt}/${stream}`,
  )
  const catalog = log.data('subscription_page_hilos_logs_view').at(-1)
  const node = (Array.isArray(catalog?.nodes) ? catalog.nodes : [])
    .map(object)
    .find((node) => node.nodeId === batch.node)
  expect(node?.batches).toContain(batchAt)
  const entry = (Array.isArray(node?.streams) ? node.streams : [])
    .map(object)
    .find((entry) => entry.key === stream)
  expect(entry?.batchTimestamps).toContain(batchAt)
  const request = log.request('logs_read_lines')
  expect(object(request?.data)).toMatchObject({
    nodeId: batch.node,
    source: 'batch',
    batchTimestamp: batchAt,
    stream,
  })
  const ack = log.ack('logs_read_lines')
  expect(ack?.requestId).toBe(request?.requestId)
  expect(object(object(ack?.data).reply).readable).toBe(true)
}

/** Read one unique real line and compare its DOM clock to the same wire moment. */
export async function expectLogLineClock(
  expect: Expect,
  page: Page,
  log: LogFrameLog,
  marker: string,
  carrier: string,
  timeZone: string,
): Promise<string> {
  const line = log.line(marker, carrier)
  expect(line, `${carrier} carried ${marker}`).toBeDefined()
  const moment = expectLogUtcMoment(expect, line?.time)
  const entry: Locator = page
    .getByTestId('hilos-log-entry')
    .filter({ hasText: `${marker} #1` })
  const expected = await logMomentLabel(page, moment, timeZone, 'clock')
  await expect(entry.getByTestId('hilos-log-entry-time')).toHaveText(expected)
  if (timeZone !== 'UTC') {
    expect(expected).not.toBe(
      await logMomentLabel(page, moment, 'UTC', 'clock'),
    )
  }
  expect(expected).not.toBe(moment)
  return moment
}
