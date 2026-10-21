import { readFileSync } from 'node:fs'
import { vi } from 'vitest'
import { z } from 'zod'
import { tableViewportDeltaSignalDataSchema } from '../../../src/protocol/envelope.js'
import {
  pageResponseSchema,
  scopePayloadSchema,
} from '../../../src/protocol/scopePayload.js'
import { ingest, normalizeTableRow } from '../../../src/state/normalizer.js'
import { ScopeManager, type Scope } from '../../../src/state/ScopeManager.js'
import type { TableViewportDelta } from '../../../src/table/TableViewportController.js'

const frameSchema = z.discriminatedUnion('type', [
  z.object({ type: z.literal('page_response'), data: pageResponseSchema }),
  z.object({
    type: z.literal('table_viewport_delta'),
    data: tableViewportDeltaSignalDataSchema.extend({
      kind: z.enum(['row_updated', 'row_stale']),
      rowKey: z.union([z.string(), z.number()]),
    }),
  }),
])

const caseSchema = z.object({
  name: z.string(),
  carrier: z.enum(['entities', 'tables', 'lists', 'viewport', 'page', 'mixed']),
  original: z.array(frameSchema),
  coalesced: z.array(frameSchema),
  initial: scopePayloadSchema.optional(),
  entityTypes: z.record(z.string(), z.string()).optional(),
  expected: z.record(z.string(), z.unknown()),
})

export type DeliveryCase = z.infer<typeof caseSchema>
export type DeliveryCaseFrame = z.infer<typeof frameSchema>

export const deliveryCases = z
  .object({
    version: z.literal(1),
    cases: z.array(caseSchema),
  })
  .parse(
    JSON.parse(
      readFileSync(
        new URL('./delivery-coalescing.json', import.meta.url),
        'utf8',
      ),
    ),
  ).cases

/** Observe addresses written by the real normalizer; never reproduce its merge logic. */
export function probeDeliveryScope() {
  const scope = new ScopeManager().openPage('p')
  const entities = vi.spyOn(scope.entities, 'upsert')
  const data = vi.spyOn(scope.data, 'set')
  const tables = [
    vi.spyOn(scope.tables, 'upsert'),
    vi.spyOn(scope.tables, 'delete'),
    vi.spyOn(scope.tables, 'clear'),
  ]
  const lists = [
    vi.spyOn(scope.lists, 'upsert'),
    vi.spyOn(scope.lists, 'delete'),
    vi.spyOn(scope.lists, 'clear'),
  ]

  return {
    scope,
    snapshot: () => {
      const refs = new Map(
        entities.mock.calls.map(([ref]) => [
          JSON.stringify([ref.type, String(ref.id)]),
          ref,
        ]),
      )
      return {
        entities: [...refs.entries()]
          .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
          .map(([, ref]) => {
            const value = scope.entities.signal(ref).get()
            if (value === undefined)
              throw new Error('An upsert must leave an entity')
            return { type: value.type, id: value.id, fields: value.fields }
          }),
        data: Object.fromEntries(
          [...new Set(data.mock.calls.map(([key]) => key))].flatMap((key) => {
            const value = scope.data.signal(key).get()
            return value === undefined ? [] : [[key, value]]
          }),
        ),
        tables: Object.fromEntries(
          [
            ...new Set(
              tables.flatMap((spy) => spy.mock.calls.map(([key]) => key)),
            ),
          ].map((key) => [key, scope.tables.signal(key).get()]),
        ),
        lists: Object.fromEntries(
          [
            ...new Set(
              lists.flatMap((spy) => spy.mock.calls.map(([key]) => key)),
            ),
          ].map((key) => [key, scope.lists.signal(key).get()]),
        ),
      }
    },
    restore: () => {
      for (const spy of [entities, data, ...tables, ...lists]) spy.mockRestore()
    },
  }
}

/** Feed the actual ingest/row normalizer, optionally handing deltas to a real viewport. */
export function ingestDeliveryFrames(
  scope: Scope,
  testCase: DeliveryCase,
  frames: readonly DeliveryCaseFrame[],
  onDelta?: (delta: TableViewportDelta) => void,
): void {
  const options = { entityTypes: testCase.entityTypes }
  for (const frame of frames) {
    if (frame.type === 'page_response') {
      ingest(scope, frame.data.payload ?? {}, options)
    } else {
      const data = frame.data
      const rowKey = String(data.rowKey)
      if (data.kind === 'row_stale') {
        if (data.staleSources === undefined)
          throw new Error('Fixture stale delta needs sources')
        onDelta?.({
          kind: 'row_stale',
          rowKey,
          staleSources: data.staleSources,
        })
      } else {
        if (data.row === undefined)
          throw new Error('Fixture update needs a row')
        const row = normalizeTableRow(scope, data.row, options)
        onDelta?.({ kind: 'row_updated', rowKey, row, own: data.own })
      }
    }
  }
}

/** The fixture seeds each distinct updated row once, before either delivery is consumed. */
export function deliveryViewportSeed(testCase: DeliveryCase) {
  const keys = new Set(
    testCase.original.flatMap((frame) =>
      frame.type === 'table_viewport_delta' && frame.data.kind === 'row_updated'
        ? [String(frame.data.rowKey)]
        : [],
    ),
  )
  return [...keys].map((rowKey) => ({ rowKey, slots: {} }))
}
