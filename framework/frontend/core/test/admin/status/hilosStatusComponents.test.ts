import { describe, expect, it } from 'vitest'
import {
  createHilosStatusComponentsTable,
  resolveHilosStatusComponentRow,
  statusComponentSchema,
  statusComponentStateSchema,
} from '../../../src/admin/status/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { harness } from './fixtures.js'

const component = {
  componentKey: 'service',
  name: 'Service',
  description: null,
  agentType: 'service',
  state: 'unknown',
}
const row = (slot: unknown = component) => ({
  rowKey: 'service',
  slots: { component: slot },
})

describe('Status Components', () => {
  it('strictly parses every state and requires nullable description', () => {
    for (const state of [
      'operational',
      'checking',
      'partial_outage',
      'major_outage',
      'maintenance',
      'unknown',
    ]) {
      expect(
        resolveHilosStatusComponentRow(row({ ...component, state })),
      ).toEqual({ ...component, state })
    }
    expect(statusComponentStateSchema.options).toEqual([
      'operational',
      'checking',
      'partial_outage',
      'major_outage',
      'maintenance',
      'unknown',
    ])
    for (const slot of [
      null,
      {},
      { ...component, state: 'healthy' },
      { ...component, position: 0 },
      { ...component, description: undefined },
      { ...component, agentType: null },
    ]) {
      expect(statusComponentSchema.safeParse(slot).success).toBe(false)
      expect(() => resolveHilosStatusComponentRow(row(slot))).toThrow()
    }
    expect(() =>
      resolveHilosStatusComponentRow({ ...row(), rowKey: 'different' }),
    ).toThrow()
  })

  it.each(statusComponentStateSchema.options)(
    'projects stale %s as unknown without changing the authoritative slot',
    (state) => {
      const slot = Object.freeze({ ...component, state })
      const raw = Object.freeze({
        rowKey: 'service',
        slots: Object.freeze({ component: slot }),
        staleSources: Object.freeze(['component']),
      })
      expect(resolveHilosStatusComponentRow(raw)).toEqual({
        ...slot,
        state: 'unknown',
      })
      expect(slot.state).toBe(state)
      for (const staleSources of [[], ['unrelated']])
        expect(
          resolveHilosStatusComponentRow({ ...raw, staleSources }),
        ).toEqual(slot)
    },
  )

  it('projects metadata-only staleness under a state filter while retaining order, raw slots and pending Apply', () => {
    const h = harness(HilosPages.STATUS_COMPONENTS)
    const table = createHilosStatusComponentsTable(h.context)
    const controller = table.controller
    table.start()
    controller.setFilter('state', 'operational')
    const slot = Object.freeze({ ...component, state: 'operational' })
    const raw = Object.freeze({
      rowKey: 'service',
      slots: Object.freeze({ component: slot }),
    })
    controller.ingestSubscriptionWindow(
      [
        raw,
        {
          rowKey: 'mail',
          slots: { component: { ...slot, componentKey: 'mail', name: 'Mail' } },
        },
      ],
      2,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    expect(controller.rows.get()[0]?.row?.state).toBe('operational')
    const descriptor = controller.descriptor()
    expect(descriptor?.filter).toEqual({ state: 'operational' })
    const requests = [...h.viewports]
    h.emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_removed',
        rowKey: 'service',
        reason: 'left_set',
      },
    })
    const retained = controller.rows.get()
    for (const [staleSources, state] of [
      [['component'], 'unknown'],
      [[], 'operational'],
      [['unrelated'], 'operational'],
      [['component'], 'unknown'],
    ] as const) {
      h.emit('tableViewportDelta', {
        data: {
          page: 'hilos_status_components',
          tableKey: 'hilosStatusComponents',
          kind: 'row_stale',
          rowKey: 'service',
          staleSources,
        },
      })
      expect(controller.rows.get()).toEqual([
        {
          ...retained[0],
          row: { ...slot, state },
          staleSources,
        },
        retained[1],
      ])
      expect(controller.pendingCount.get()).toBe(1)
      expect(controller.frame.footer.get().totalCount).toBe(2)
      expect(controller.descriptor()).toEqual(descriptor)
      expect(h.viewports).toEqual(requests)
      expect(raw.slots.component).toBe(slot)
      expect(slot.state).toBe('operational')
    }
    controller.apply()
    expect(controller.rows.get()[0]).toMatchObject({
      rowKey: 'service',
      row: null,
      placeholder: true,
      pending: null,
      removal: 'left_set',
    })
    expect(controller.pendingCount.get()).toBe(0)
    table.dispose()
    h.releaseAccess()
  })

  it.each(['operational', 'partial_outage'])(
    'projects a stale first window as unknown and restores authoritative %s on freshness',
    (state) => {
      const h = harness(HilosPages.STATUS_COMPONENTS)
      const table = createHilosStatusComponentsTable(h.context)
      table.start()
      table.controller.ingestSubscriptionWindow(
        [
          {
            ...row({ ...component, state }),
            staleSources: ['component'],
          },
        ],
        1,
        true,
        null,
        null,
        25,
        undefined,
        [],
      )
      expect(table.controller.rows.get()[0]?.row?.state).toBe('unknown')
      h.emit('tableViewportDelta', {
        data: {
          page: 'hilos_status_components',
          tableKey: 'hilosStatusComponents',
          kind: 'row_stale',
          rowKey: 'service',
          staleSources: [],
        },
      })
      expect(table.controller.rows.get()[0]?.row?.state).toBe(state)
      table.dispose()
      h.releaseAccess()
    },
  )

  it('binds the cold page_response without a mount fetch, retaining declaration order and server size', () => {
    const h = harness(HilosPages.STATUS_COMPONENTS)
    const table = createHilosStatusComponentsTable(h.context)
    table.start()
    table.start()
    expect(h.viewports).toEqual([])
    expect(h.sent).toEqual([])
    expect(h.registered.size).toBe(1)
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: 'hilos_status_components',
        payload: {
          windows: {
            hilosStatusComponents: {
              rows: [
                row(),
                {
                  rowKey: 'mail',
                  slots: {
                    component: {
                      ...component,
                      componentKey: 'mail',
                      name: 'Mail',
                    },
                  },
                },
              ],
              totalCount: 2,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: null,
            },
          },
        },
      },
    })
    expect(table.controller.pageSize.get()).toBe(25)
    expect(
      table.controller.rows.get().map((entry) => entry.row?.componentKey),
    ).toEqual(['service', 'mail'])
    expect(
      table.controller.frame.columns
        .get()
        .every((column) => column.sortable !== true),
    ).toBe(true)
    table.controller.setFilter('state', 'unknown')
    expect(h.viewports).toEqual([
      [
        'hilos_status_components',
        'hilosStatusComponents',
        expect.objectContaining({ filter: { state: 'unknown' }, limit: 25 }),
      ],
    ])
    table.dispose()
    table.dispose()
    expect(h.registered.size).toBe(0)
    h.releaseAccess()
  })

  it('uses the standard live values and pending/Apply boundary and disposes on scope switch', () => {
    const h = harness(HilosPages.STATUS_COMPONENTS)
    const table = createHilosStatusComponentsTable(h.context)
    table.start()
    table.controller.ingestSubscriptionWindow(
      [row()],
      1,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    h.emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_updated',
        rowKey: 'service',
        row: row({ ...component, state: 'maintenance' }),
      },
    })
    expect(table.controller.rows.get()[0]?.row?.state).toBe('maintenance')
    h.emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_removed',
        rowKey: 'service',
      },
    })
    expect(table.controller.rows.get()).toHaveLength(1)
    h.scopes.openPage(HilosPages.STATUS)
    expect(table.controller.rows.get()).toEqual([])
    expect(h.registered.size).toBe(0)
    for (const [event, listeners] of h.listeners)
      if (
        event !== 'actionError' &&
        event !== 'actionSuccess' &&
        event !== 'state'
      )
        expect(listeners.size).toBe(0)
    h.emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_updated',
        rowKey: 'service',
        row: row(),
      },
    })
    expect(table.controller.rows.get()).toEqual([])
    table.dispose()
    h.releaseAccess()
  })
})
