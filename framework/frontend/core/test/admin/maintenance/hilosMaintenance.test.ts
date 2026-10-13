import { describe, expect, it, vi } from 'vitest'

import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  MAINTENANCE_STATE_SIGNAL,
  MAINTENANCE_STATE_SIGNAL_SCHEMAS,
  type HilosMaintenanceCircleTableView,
  type HilosMaintenanceContext,
  type HilosMaintenanceState,
  hilosMaintenanceCard,
  hilosMaintenanceElapsed,
  hilosMaintenancePosition,
  resolveHilosMaintenanceCircleRow,
} from '../../../src/admin/maintenance/hilosMaintenance.js'
import { HILOS_TABLE_ACTIONS_KEY } from '../../../src/table/hilosTableColumn.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** A view whose main action records that it was pressed. */
function circleView(pressed: string[] = []): HilosMaintenanceCircleTableView {
  return {
    openAdd: () => {
      pressed.push('openAdd')
    },
  }
}

/** Build a circle row whose inline `verifierCircle` slot carries the given fields. */
function circleRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { verifierCircle: slot } }
}

describe('resolveHilosMaintenanceCircleRow', () => {
  it('takes the membership id off the row key and the fields out of the slot', () => {
    const row = resolveHilosMaintenanceCircleRow(
      circleRow('7', {
        identityType: 'password',
        identifier: 'ann@example.test',
        online: true,
      }),
    )

    expect(row).toEqual({
      memberId: 7,
      identityType: 'password',
      identifier: 'ann@example.test',
      online: true,
    })
  })

  it('reads a row with no slot as nobody signed in', () => {
    const row = resolveHilosMaintenanceCircleRow(circleRow('12', undefined))

    expect(row.memberId).toBe(12)
    expect(row.identifier).toBe('')
    expect(row.online).toBe(false)
  })

  it('reads an address sent hidden as the one hidden value (HIL-1260)', () => {
    const row = resolveHilosMaintenanceCircleRow(
      circleRow('3', {
        identityType: 'password',
        identifier: { _hidden: true },
      }),
    )

    expect(row.identifier).toBe(HIDDEN_VALUE)
    expect(row.memberId).toBe(3)
  })
})

describe('createHilosMaintenanceCircleTable', () => {
  it('holds its window on the maintenance page under the circle table key', () => {
    const registered: string[] = []
    const sent: Array<{ page: string; tableKey: string }> = []
    const connection = {
      on: () => () => {},
      registerTableWindow: (tableKey: string) => registered.push(tableKey),
      unregisterTableWindow: () => {},
      sendTableViewport: (page: string, tableKey: string) =>
        sent.push({ page, tableKey }) > 0,
    } as unknown as HilosConnection
    const circle = createHilosMaintenanceCircleTable(
      {
        connection,
        scopes: new ScopeManager(),
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(),
    )

    circle.start()
    // The first window rides the page's own answer; a request for it again goes to the
    // same address.
    circle.controller.refresh()

    expect(registered).toEqual(['hilosVerifierCircle'])
    expect(sent.at(-1)).toEqual({
      page: 'hilos_maintenance',
      tableKey: 'hilosVerifierCircle',
    })
    circle.dispose()
  })

  it('hands a row into focus to the dialog over it, and lets it go with an empty key', () => {
    const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
    const connection = {
      sendTableViewport: () => true,
      sendTableRendered: () => true,
      sendTableRowFocus: (page: string, tableKey: string, rowKey: string) =>
        focus.push({ page, tableKey, rowKey }) > 0,
    } as unknown as HilosConnection
    const circle = createHilosMaintenanceCircleTable(
      {
        connection,
        scopes: new ScopeManager(),
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(),
    )
    circle.controller.ingestSubscriptionWindow(
      [
        circleRow('7', {
          identityType: 'password',
          identifier: 'ann@example.test',
          online: false,
        }),
      ],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(circle.controller.focusRow('7')?.identifier).toBe('ann@example.test')
    circle.controller.releaseFocus()

    expect(focus).toEqual([
      {
        page: 'hilos_maintenance',
        tableKey: 'hilosVerifierCircle',
        rowKey: '7',
      },
      {
        page: 'hilos_maintenance',
        tableKey: 'hilosVerifierCircle',
        rowKey: '',
      },
    ])
  })

  it('declares the bar, the sortable address, and the empty circle', () => {
    const pressed: string[] = []
    const connection = {
      sendTableViewport: () => true,
      sendTableRendered: () => true,
      sendTableRowFocus: () => true,
    } as unknown as HilosConnection
    const circle = createHilosMaintenanceCircleTable(
      {
        connection,
        scopes: new ScopeManager(),
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(pressed),
    )
    const declaration = circle.controller.frame.declaration
    const columns = circle.controller.frame.columns.get()

    expect(declaration?.title).toBe(HILOS_MAINTENANCE_CIRCLE_COPY.title)
    expect(declaration?.subtitle).toBe(
      `${HILOS_MAINTENANCE_CIRCLE_COPY.rule} ${HILOS_MAINTENANCE_CIRCLE_COPY.volatile}`,
    )
    expect(declaration?.search).toBeUndefined()
    expect(declaration?.filters).toBeUndefined()
    expect(declaration?.bulkActions).toBeUndefined()
    expect(declaration?.mainAction?.label).toBe(
      HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
    )
    expect(declaration?.empty).toEqual(HILOS_MAINTENANCE_CIRCLE_COPY.empty)
    declaration?.mainAction?.press()
    expect(pressed).toEqual(['openAdd'])
    expect(columns.map((column) => column.key)).toEqual([
      MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
      MAINTENANCE_CIRCLE_ONLINE_FIELD,
      HILOS_TABLE_ACTIONS_KEY,
    ])
    expect(columns[0]?.sortable).toBe(true)
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.card).toBe('badge')
    expect(columns[2]?.reads).toEqual([MAINTENANCE_CIRCLE_IDENTIFIER_FIELD])
  })

  it('tells the server which fields the columns draw once the cold window lands', () => {
    const rendered: Array<{
      page: string
      tableKey: string
      rendered: readonly string[]
    }> = []
    const connection = {
      sendTableViewport: () => true,
      sendTableRendered: (
        page: string,
        tableKey: string,
        fields: readonly string[],
      ) => rendered.push({ page, tableKey, rendered: fields }) > 0,
      sendTableRowFocus: () => true,
    } as unknown as HilosConnection
    const circle = createHilosMaintenanceCircleTable(
      {
        connection,
        scopes: new ScopeManager(),
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(),
    )

    expect(rendered).toEqual([])
    circle.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(rendered).toEqual([
      {
        page: 'hilos_maintenance',
        tableKey: 'hilosVerifierCircle',
        rendered: [
          MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
          MAINTENANCE_CIRCLE_ONLINE_FIELD,
        ],
      },
    ])
    expect(circle.controller.descriptor()?.rendered).toEqual(
      rendered[0]?.rendered,
    )
  })
})

describe('createHilosMaintenanceActions', () => {
  it('dispatches one tracked empty enable and never retries an unanswered command', async () => {
    vi.useFakeTimers()
    try {
      const sendAction = vi.fn(() => true)
      const actions = new ActionLifecycle({
        sendAction,
        on: () => () => {},
      })
      const handle = createHilosMaintenanceActions({
        actions,
      } as HilosMaintenanceContext).sendMaintenanceEnable()
      const outcome = expect(handle.done).rejects.toMatchObject({
        outcome: 'timeout',
      })

      expect(sendAction.mock.calls).toEqual([
        ['maintenance_enable', {}, handle.requestId],
      ])
      await vi.runAllTimersAsync()
      await outcome
      expect(sendAction).toHaveBeenCalledTimes(1)
    } finally {
      vi.useRealTimers()
    }
  })

  /** A context whose action lifecycle records what was dispatched over it. */
  function dispatchContext(
    sent: Array<{ action: string; payload: unknown }>,
  ): HilosMaintenanceContext {
    const actions = {
      dispatch(action: string, payload: unknown) {
        sent.push({ action, payload })

        return { done: Promise.resolve(), loading: null }
      },
    } as unknown as ActionLifecycle

    return { actions } as unknown as HilosMaintenanceContext
  }

  it('names a verifier by the address exactly as the operator typed it', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosMaintenanceActions(
      dispatchContext(sent),
    ).sendMaintenanceCircleAdd('+7 900 000-00-00')

    // The stored form is the server's answer: the client neither trims nor normalizes.
    expect(sent).toEqual([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
  })

  it('takes a verifier out by the membership key, not by the address', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosMaintenanceActions(
      dispatchContext(sent),
    ).sendMaintenanceCircleRemove(7)

    expect(sent).toEqual([
      { action: 'maintenance_circle_remove', payload: { memberId: 7 } },
    ])
  })
})
const STARTED_AT = '2026-10-10T10:00:00+00:00'
const NOW = Date.parse('2026-10-10T10:23:00+00:00')

function state(
  overrides: Partial<HilosMaintenanceState> = {},
): HilosMaintenanceState {
  return {
    phase: 'inactive',
    operation: null,
    manual: false,
    startedAt: null,
    enteredFromBrowser: false,
    passCount: 0,
    circleNamed: 0,
    circleAdmitted: 0,
    ...overrides,
  }
}

describe('maintenance state frame', () => {
  it('matches the backend DTO and rejects an incomplete or malformed frame', () => {
    expect(Object.keys(MAINTENANCE_STATE_SIGNAL_SCHEMAS)).toEqual([
      MAINTENANCE_STATE_SIGNAL,
    ])
    expect(
      MAINTENANCE_STATE_SIGNAL_SCHEMAS[MAINTENANCE_STATE_SIGNAL].parse(
        state({ phase: 'verifying', operation: 'restore', passCount: 2 }),
      ).passCount,
    ).toBe(2)
    const incomplete: Partial<HilosMaintenanceState> = state()
    delete incomplete.circleAdmitted
    expect(
      MAINTENANCE_STATE_SIGNAL_SCHEMAS[MAINTENANCE_STATE_SIGNAL].safeParse(
        incomplete,
      ).success,
    ).toBe(false)
    expect(
      MAINTENANCE_STATE_SIGNAL_SCHEMAS[MAINTENANCE_STATE_SIGNAL].safeParse(
        state({ passCount: 1.5 }),
      ).success,
    ).toBe(false)
  })

  it('stays empty before its frame, then follows each server push and disposes', () => {
    const listeners = new Set<
      (signal: { type: string; data: unknown }) => void
    >()
    const context = {
      connection: {
        on(
          event: string,
          listener: (signal: { type: string; data: unknown }) => void,
        ) {
          if (event === 'projectSignal') {
            listeners.add(listener)
          }

          return () => listeners.delete(listener)
        },
      },
    } as unknown as HilosMaintenanceContext
    const handle = createHilosMaintenanceState(context)
    expect(handle.state.get()).toBeNull()
    handle.start()
    const first = state()
    for (const listener of listeners) {
      listener({ type: MAINTENANCE_STATE_SIGNAL, data: first })
    }
    expect(handle.state.get()).toEqual(first)
    const second = state({ phase: 'verifying', passCount: 2 })
    for (const listener of listeners) {
      listener({ type: MAINTENANCE_STATE_SIGNAL, data: second })
    }
    expect(handle.state.get()?.passCount).toBe(2)
    handle.dispose()
    expect(listeners.size).toBe(0)
  })
})

describe('maintenance state selector', () => {
  it('keeps the block absent until the row arrives, then distinguishes all positions', () => {
    expect(hilosMaintenancePosition(null)).toBeNull()
    expect(hilosMaintenancePosition(state())).toBe('open')
    expect(
      hilosMaintenancePosition(
        state({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
        }),
      ),
    ).toBe('manual')
    expect(
      hilosMaintenancePosition(
        state({ phase: 'verifying', operation: 'restore' }),
      ),
    ).toBe('operation')
  })

  it('selects the manual source, circle counts and elapsed words', () => {
    const manual = state({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
      startedAt: STARTED_AT,
      enteredFromBrowser: true,
      passCount: 1,
      circleNamed: 3,
      circleAdmitted: 2,
    })
    expect(hilosMaintenanceCard(manual, NOW)).toMatchObject({
      position: 'manual',
      title: HILOS_MAINTENANCE_STATE_COPY.manualTitle,
      turnedOn: HILOS_MAINTENANCE_STATE_COPY.turnedOnFromBrowser,
      passCount: 1,
      circle: '3 named · 2 let in',
      elapsed: '23 minutes ago',
    })
    expect(
      hilosMaintenanceCard({ ...manual, enteredFromBrowser: false }, NOW)
        ?.turnedOn,
    ).toBe(HILOS_MAINTENANCE_STATE_COPY.turnedOnFromConsole)
    expect(
      hilosMaintenanceElapsed(STARTED_AT, Date.parse(STARTED_AT) - 1),
    ).toBe(HILOS_MAINTENANCE_STATE_COPY.justNow)
    expect(
      hilosMaintenanceElapsed(STARTED_AT, Date.parse(STARTED_AT) + 60_000),
    ).toBe('1 minute ago')
  })

  it('selects operation lines and the verification note only in that phase', () => {
    const restore = state({
      phase: 'verifying',
      operation: 'restore',
      startedAt: STARTED_AT,
      passCount: 2,
    })
    expect(hilosMaintenanceCard(restore, NOW)).toMatchObject({
      position: 'operation',
      phaseNote: HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
      operationCaption: 'operation restore',
      startedAt: STARTED_AT,
      passCount: 2,
      elapsed: null,
      turnedOn: null,
    })
    expect(
      hilosMaintenanceCard({ ...restore, phase: 'active' }, NOW)?.phaseNote,
    ).toBeNull()
    expect(hilosMaintenanceCard(state(), NOW)).toBeNull()
  })
})
