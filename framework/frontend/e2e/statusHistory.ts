import type { Expect, Page } from '@playwright/test'
import { z } from 'zod'
import { deliveryObject, type DeliveryFrameLog } from './deliveryFrames.js'
import { shownByTestId } from './table.js'
import type { SocketFrame } from './socket.js'

// Test-owned vocabulary: never derive expected History facts from its resolver.
export const STATUS_HISTORY_PATH = '/hilos/status/history'
export const STATUS_HISTORY_PAGE = 'hilos_status_history'
export const STATUS_HISTORY_TABLE = 'hilosStatusHistory'
export const STATUS_HISTORY_SLOT = 'history'

const moment = z.number().int().nonnegative()
export const statusHistoryFactSchema = z
  .strictObject({
    id: z.string(),
    kind: z.enum(['incident', 'maintenance']),
    sourceId: z.number().int().positive(),
    startedAt: moment,
    endedAt: moment.nullable(),
    completionObservedAt: moment.nullable(),
    publishedAt: moment.nullable(),
    outcome: z.enum([
      'resolved',
      'recovered_before_publication',
      'canceled_publication',
      'maintenance_completed',
    ]),
    title: z.string(),
    componentKeys: z.array(z.string().min(1)),
    severity: z.enum(['partial_outage', 'major_outage']).nullable(),
    announcementKnown: z.boolean().nullable(),
    workPublicId: z.string().min(1).nullable(),
  })
  .refine(
    (fact) =>
      fact.id === `${fact.kind}:${fact.sourceId}` &&
      (fact.kind === 'incident'
        ? fact.endedAt !== null &&
          fact.completionObservedAt === null &&
          fact.outcome !== 'maintenance_completed' &&
          fact.severity !== null &&
          fact.announcementKnown === null &&
          fact.workPublicId === null
        : fact.completionObservedAt !== null &&
          fact.publishedAt === null &&
          fact.outcome === 'maintenance_completed' &&
          fact.severity === null &&
          fact.announcementKnown !== null),
  )
export type StatusHistoryFact = z.infer<typeof statusHistoryFactSchema>

const catalogSchema = z
  .strictObject({
    components: z.array(
      z.strictObject({ componentKey: z.string().min(1), name: z.string() }),
    ),
  })
  .nullable()
const rowSchema = z
  .object({
    rowKey: z.string(),
    slots: z.strictObject({ [STATUS_HISTORY_SLOT]: statusHistoryFactSchema }),
  })
  .refine((row) => row.rowKey === row.slots.history.id)

interface StatusHistoryDelivery {
  frame: SocketFrame
  catalog: z.infer<typeof catalogSchema> | undefined
  rows: StatusHistoryFact[]
}

/**
 * Read this page's named table, preserving each original transport frame.
 * Validate catalog-only updates without presenting them as empty table windows.
 * Replay the prefix for opening knowledge on each socket; since limits output.
 */
export function statusHistoryDeliveries(
  log: DeliveryFrameLog,
  since = 0,
): StatusHistoryDelivery[] {
  const opened = new Set<number>()
  return log.packets.flatMap((packet, index) => {
    const deliveries = packet.frames.flatMap(
      (frame): StatusHistoryDelivery[] => {
        const wire = deliveryObject(frame.wire)
        const data = deliveryObject(wire.data)
        if (data.page !== STATUS_HISTORY_PAGE) return []
        if (wire.type === 'page_response') {
          const payload = deliveryObject(data.payload)
          const catalog = catalogSchema.parse(
            deliveryObject(payload.data).statusHistory,
          )
          const window = deliveryObject(payload.windows)[STATUS_HISTORY_TABLE]
          if (window === undefined) {
            if (payload.windows === undefined && opened.has(frame.socket))
              return []
            throw new Error('History first answer has no named window.')
          }
          const rows = parseRows(deliveryObject(window).rows)
          opened.add(frame.socket)
          return [{ frame, catalog, rows }]
        }
        if (data.tableKey !== STATUS_HISTORY_TABLE) return []
        if (
          wire.type === 'table_window' ||
          wire.type === 'table_viewport_append'
        )
          return [{ frame, catalog: undefined, rows: parseRows(data.rows) }]
        if (wire.type === 'table_viewport_delta' && data.row !== undefined) {
          const row = rowSchema.parse(data.row)
          if (data.rowKey !== row.rowKey)
            throw new Error('History delta targets another row.')
          return [{ frame, catalog: undefined, rows: [row.slots.history] }]
        }
        return []
      },
    )
    return index < since ? [] : deliveries
  })
}

function parseRows(value: unknown): StatusHistoryFact[] {
  return z
    .array(rowSchema)
    .parse(value)
    .map((row) => row.slots.history)
}

/** Match a filter/search answer to the latest request on the same connection. */
export function statusHistoryWindow(log: DeliveryFrameLog, since: number) {
  const request = log.sent
    .slice(since)
    .filter(({ wire }) => {
      const frame = deliveryObject(wire)
      return (
        frame.type === 'table_viewport' &&
        frame.page === STATUS_HISTORY_PAGE &&
        frame.tableKey === STATUS_HISTORY_TABLE
      )
    })
    .at(-1)
  const requestId = deliveryObject(request?.wire).requestId
  if (!request || typeof requestId !== 'string') return undefined
  const firstPacket = log.packets.findIndex((packet) => packet.at >= request.at)
  if (firstPacket === -1) return undefined
  const answer = statusHistoryDeliveries(log, firstPacket)
    .filter(({ frame }) => {
      const wire = deliveryObject(frame.wire)
      return (
        wire.type === 'table_window' &&
        frame.socket === request.socket &&
        deliveryObject(wire.data).requestId === requestId
      )
    })
    .at(-1)
  return answer === undefined ? undefined : { ...answer, request }
}

/** Check the saved fact's visible title and outcome in its own namespaced row. */
export async function expectStatusHistoryFact(
  expect: Expect,
  page: Page,
  fact: StatusHistoryFact,
): Promise<void> {
  const row = shownByTestId(page, `hilos-table-row-${fact.id}`)
  await expect(row).toBeVisible()
  await expect(row).toContainText(
    fact.kind === 'incident'
      ? fact.title
      : !fact.announcementKnown
        ? 'Maintenance: announcement unknown'
        : fact.workPublicId === null
          ? 'Unannounced maintenance'
          : fact.title,
  )
  await expect(row).toContainText(
    {
      resolved:
        fact.publishedAt === null
          ? 'Resolved automatically'
          : 'resolved automatically',
      recovered_before_publication:
        'Not published: recovered before publication',
      canceled_publication: 'Not published: publication canceled manually',
      maintenance_completed:
        fact.endedAt === null ? 'The exact end time is unknown.' : 'Completed',
    }[fact.outcome],
  )
}
