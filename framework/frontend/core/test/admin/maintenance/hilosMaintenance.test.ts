import { describe, expect, it, vi } from 'vitest'

import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  MAINTENANCE_RESTORE_CLOSE_ACTION,
  MAINTENANCE_RESTORE_OPEN_ACTION,
  MAINTENANCE_RESTORE_PASS_ACTION,
  MAINTENANCE_STATE_SIGNAL,
  MAINTENANCE_STATE_SIGNAL_SCHEMAS,
  maintenancePassReplySchema,
  type HilosMaintenancePassReply,
  type HilosMaintenanceCircleTableView,
  type HilosMaintenanceContext,
  type HilosMaintenanceState,
  hilosMaintenanceCard,
  hilosMaintenanceElapsed,
  hilosMaintenanceOtherBrowserLine,
  hilosMaintenancePosition,
  hilosMaintenanceRestoreLadder,
  resolveHilosMaintenanceCircleRow,
} from '../../../src/admin/maintenance/hilosMaintenance.js'
import { HILOS_TABLE_ACTIONS_KEY } from '../../../src/table/hilosTableColumn.js'
import type { ActionSuccessSignal } from '../../../src/protocol/parseSignal.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type TableViewportDescriptor,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../../src/protocol/constants.js'

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

/** The row includes an entity slot so page-scope normalization is observable. */
function circleRowWithMember(
  rowKey: string,
  memberId: number,
  online: boolean,
): TableRow {
  return {
    rowKey,
    slots: {
      verifierCircle: {
        identityType: 'password',
        identifier: 'ann@example.test',
        online,
      },
      member: { id: memberId, name: 'Ann' },
    },
  }
}

/** A connection event source that records the factory's outgoing table frames. */
class MaintenanceCircleConnection {
  private readonly emitter = new Emitter<HilosConnectionEventMap>()
  private activeListeners = 0

  readonly registered = new Map<string, TableWindowDescriptorSource>()
  readonly viewports: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  readonly rendered: Array<{
    page: string
    tableKey: string
    fields: readonly string[]
  }> = []
  readonly focus: Array<{
    page: string
    tableKey: string
    rowKey: string
  }> = []
  readonly connection: HilosConnection

  constructor() {
    this.connection = {
      on: this.on.bind(this),
      registerTableWindow: (
        tableKey: string,
        source: TableWindowDescriptorSource,
      ) => {
        this.registered.set(tableKey, source)
      },
      unregisterTableWindow: (
        tableKey: string,
        source?: TableWindowDescriptorSource,
      ) => {
        if (source === undefined || this.registered.get(tableKey) === source) {
          this.registered.delete(tableKey)
        }
      },
      sendTableViewport: (
        page: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ) => {
        this.viewports.push({ page, tableKey, descriptor })
        return true
      },
      sendTableRendered: (
        page: string,
        tableKey: string,
        fields: readonly string[],
      ) => {
        this.rendered.push({ page, tableKey, fields })
        return true
      },
      sendTableRowFocus: (page: string, tableKey: string, rowKey: string) => {
        this.focus.push({ page, tableKey, rowKey })
        return true
      },
    } as unknown as HilosConnection
  }

  /** Publish one parsed connection event to the table's real binder. */
  emit<K extends keyof HilosConnectionEventMap>(
    event: K,
    payload: HilosConnectionEventMap[K],
  ): void {
    this.emitter.emit(event, payload)
  }

  /** Publish a page response with one first table window. */
  emitPageResponse(page: string, row: TableRow): void {
    this.emit('projectSignal', {
      kind: 'project',
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page,
        payload: {
          windows: {
            hilosVerifierCircle: {
              rows: [row],
              sort: [],
              limit: 10,
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
            },
          },
        },
      },
      envelope: {},
    } as unknown as HilosConnectionEventMap['projectSignal'])
  }

  /** Publish a live value update addressed to the given page. */
  emitRowUpdate(page: string, row: TableRow): void {
    this.emit('tableViewportDelta', {
      kind: 'tableViewportDelta',
      data: {
        page,
        tableKey: 'hilosVerifierCircle',
        kind: 'row_updated',
        rowKey: row.rowKey,
        row,
      },
      envelope: {},
    } as unknown as HilosConnectionEventMap['tableViewportDelta'])
  }

  /** Count active listeners held by the table binder. */
  listenerCount(): number {
    return this.activeListeners
  }

  private on<K extends keyof HilosConnectionEventMap>(
    event: K,
    listener: (payload: HilosConnectionEventMap[K]) => void,
  ): () => void {
    const unsubscribe = this.emitter.on(event, listener)
    this.activeListeners += 1
    let subscribed = true

    return () => {
      if (!subscribed) {
        return
      }
      subscribed = false
      this.activeListeners -= 1
      unsubscribe()
    }
  }
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
  it('defaults every circle table address to maintenance', () => {
    const connection = new MaintenanceCircleConnection()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.MAINTENANCE)
    const circle = createHilosMaintenanceCircleTable(
      {
        connection: connection.connection,
        scopes,
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(),
    )

    circle.start()
    circle.controller.refresh()
    connection.emitPageResponse(
      HilosPages.MAINTENANCE,
      circleRow('7', {
        identityType: 'password',
        identifier: 'ann@example.test',
        online: true,
      }),
    )

    expect(circle.controller.focusRow('7')?.identifier).toBe('ann@example.test')
    circle.controller.releaseFocus()
    expect(connection.viewports.at(-1)).toMatchObject({
      page: HilosPages.MAINTENANCE,
      tableKey: 'hilosVerifierCircle',
    })
    expect(connection.rendered.at(-1)).toMatchObject({
      page: HilosPages.MAINTENANCE,
      tableKey: 'hilosVerifierCircle',
    })
    expect(
      connection.focus.map(({ page, rowKey }) => ({ page, rowKey })),
    ).toEqual([
      { page: HilosPages.MAINTENANCE, rowKey: '7' },
      { page: HilosPages.MAINTENANCE, rowKey: '' },
    ])

    circle.dispose()
    expect(connection.listenerCount()).toBe(0)
    expect(connection.registered.size).toBe(0)
  })

  it('uses a selected page for every address and keeps windows and deltas in that scope', () => {
    const connection = new MaintenanceCircleConnection()
    const scopes = new ScopeManager()
    const maintenanceScope = scopes.openPage(HilosPages.MAINTENANCE)
    const circle = createHilosMaintenanceCircleTable(
      {
        connection: connection.connection,
        scopes,
        actions: {} as unknown as ActionLifecycle,
      },
      circleView(),
      HilosPages.MAINTENANCE_CIRCLE,
    )

    circle.start()
    circle.controller.refresh()
    connection.emitPageResponse(
      HilosPages.MAINTENANCE_CIRCLE,
      circleRowWithMember('7', 91, true),
    )

    expect(circle.controller.rows.get()).toEqual([])
    expect(
      maintenanceScope.entities.signal({ type: 'member', id: 91 }).get(),
    ).toBeUndefined()

    const circleScope = scopes.openPage(HilosPages.MAINTENANCE_CIRCLE)
    connection.emitPageResponse(
      HilosPages.MAINTENANCE,
      circleRowWithMember('7', 92, true),
    )
    expect(
      circleScope.entities.signal({ type: 'member', id: 92 }).get(),
    ).toBeUndefined()
    expect(circle.controller.rows.get()).toEqual([])

    connection.emitPageResponse(
      HilosPages.MAINTENANCE_CIRCLE,
      circleRowWithMember('7', 93, true),
    )
    expect(circle.controller.rows.get()[0]?.row?.online).toBe(true)
    expect(
      circleScope.entities.signal({ type: 'member', id: 93 }).get()?.fields
        .name,
    ).toBe('Ann')
    expect(connection.viewports.at(-1)).toMatchObject({
      page: HilosPages.MAINTENANCE_CIRCLE,
      tableKey: 'hilosVerifierCircle',
    })
    expect(connection.rendered.at(-1)).toMatchObject({
      page: HilosPages.MAINTENANCE_CIRCLE,
      tableKey: 'hilosVerifierCircle',
    })

    expect(circle.controller.focusRow('7')?.identifier).toBe('ann@example.test')
    circle.controller.releaseFocus()
    expect(
      connection.focus.map(({ page, rowKey }) => ({ page, rowKey })),
    ).toEqual([
      { page: HilosPages.MAINTENANCE_CIRCLE, rowKey: '7' },
      { page: HilosPages.MAINTENANCE_CIRCLE, rowKey: '' },
    ])

    connection.emitRowUpdate(
      HilosPages.MAINTENANCE,
      circleRowWithMember('7', 94, false),
    )
    expect(circle.controller.rows.get()[0]?.row?.online).toBe(true)
    expect(
      circleScope.entities.signal({ type: 'member', id: 94 }).get(),
    ).toBeUndefined()

    connection.emitRowUpdate(
      HilosPages.MAINTENANCE_CIRCLE,
      circleRowWithMember('7', 95, false),
    )
    expect(circle.controller.rows.get()[0]?.row?.online).toBe(false)
    expect(
      circleScope.entities.signal({ type: 'member', id: 95 }).get()?.fields
        .name,
    ).toBe('Ann')

    const resetRequests = vi.spyOn(circle.controller, 'resetWindowRequests')
    circle.dispose()
    const resetCountOnDispose = resetRequests.mock.calls.length
    scopes.openPage(HilosPages.MAINTENANCE)

    expect(resetRequests).toHaveBeenCalledTimes(resetCountOnDispose)
    expect(connection.listenerCount()).toBe(0)
    expect(connection.registered.size).toBe(0)
  })

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
  it('dispatches restore pass with the code schema and open or close with an empty payload', () => {
    const sendAction = vi.fn(() => true)
    const actions = new ActionLifecycle({
      sendAction,
      on: () => () => {},
    })
    const made = createHilosMaintenanceActions({
      actions,
    } as HilosMaintenanceContext)
    const pass = made.sendMaintenanceRestorePass()
    const open = made.sendMaintenanceRestoreOpen()
    const close = made.sendMaintenanceRestoreClose()
    expect(sendAction.mock.calls).toEqual([
      [MAINTENANCE_RESTORE_PASS_ACTION, {}, pass.requestId],
      [MAINTENANCE_RESTORE_OPEN_ACTION, {}, open.requestId],
      [MAINTENANCE_RESTORE_CLOSE_ACTION, {}, close.requestId],
    ])
  })

  it.each([
    undefined,
    {},
    { pass: '' },
    { pass: null },
    { pass: 7 },
    { pass: 'raw+code/unchanged' },
  ])(
    'validates the correlated code reply without storing it in page state: %j',
    async (reply) => {
      const sendAction = vi.fn(() => true)
      let success: (signal: ActionSuccessSignal) => void = () => {}
      const actions = new ActionLifecycle({
        sendAction,
        on(event, listener) {
          if (event === 'actionSuccess')
            success = listener as (signal: ActionSuccessSignal) => void
          return () => {}
        },
      })
      let pushState: (signal: {
        type: string
        data: unknown
      }) => void = () => {}
      const context = {
        actions,
        connection: {
          on(
            event: string,
            listener: (signal: { type: string; data: unknown }) => void,
          ) {
            if (event === 'projectSignal') pushState = listener
            return () => {}
          },
        },
      } as unknown as HilosMaintenanceContext
      const pageState = createHilosMaintenanceState(context)
      pageState.start()
      pushState({
        type: MAINTENANCE_STATE_SIGNAL,
        data: state({ passCount: 1 }),
      })
      const handle =
        createHilosMaintenanceActions(context).sendMaintenancePass()
      expect(sendAction.mock.calls).toEqual([
        ['maintenance_pass', {}, handle.requestId],
      ])
      const valid = reply?.pass === 'raw+code/unchanged'
      expect(maintenancePassReplySchema.safeParse(reply).success).toBe(valid)
      const outcome = valid
        ? handle.done
        : expect(handle.done).rejects.toMatchObject({
            outcome: 'invalid-reply',
          })
      success({
        kind: 'actionSuccess',
        envelope: { type: 'action_success', data: {} },
        action: 'maintenance_pass',
        requestId: handle.requestId,
        message: 'Code issued',
        reply,
      })
      await outcome
      if (valid) {
        const result: HilosMaintenancePassReply | undefined = (
          await handle.done
        ).reply
        expect(result).toEqual(reply)
        expect(pageState.state.get()).toEqual(state({ passCount: 1 }))
        expect(JSON.stringify(pageState.state.get())).not.toContain(
          result?.pass,
        )
      }
      pageState.dispose()
      expect(sendAction).toHaveBeenCalledTimes(1)
    },
  )

  it('times out code issuance without retrying and ignores the late secret', async () => {
    vi.useFakeTimers()
    try {
      const sendAction = vi.fn(() => true)
      let success: (signal: ActionSuccessSignal) => void = () => {}
      const late = vi.fn()
      const actions = new ActionLifecycle(
        {
          sendAction,
          on(event, listener) {
            if (event === 'actionSuccess')
              success = listener as (signal: ActionSuccessSignal) => void
            return () => {}
          },
        },
        { onLateReply: late },
      )
      const handle = createHilosMaintenanceActions({
        actions,
      } as HilosMaintenanceContext).sendMaintenancePass()
      const outcome = expect(handle.done).rejects.toMatchObject({
        outcome: 'timeout',
      })
      await vi.runAllTimersAsync()
      await outcome
      success({
        kind: 'actionSuccess',
        envelope: { type: 'action_success', data: {} },
        action: 'maintenance_pass',
        requestId: handle.requestId,
        reply: { pass: 'late-secret' },
        message: undefined,
      })
      expect(sendAction).toHaveBeenCalledTimes(1)
      expect(JSON.stringify(late.mock.calls)).not.toContain('late-secret')
      await expect(handle.done).rejects.toMatchObject({ outcome: 'timeout' })
    } finally {
      vi.useRealTimers()
    }
  })

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

  it('dispatches one tracked empty disable and never retries an unanswered command', async () => {
    vi.useFakeTimers()
    try {
      const sendAction = vi.fn(() => true)
      const actions = new ActionLifecycle({
        sendAction,
        on: () => () => {},
      })
      const handle = createHilosMaintenanceActions({
        actions,
      } as HilosMaintenanceContext).sendMaintenanceDisable()
      const outcome = expect(handle.done).rejects.toMatchObject({
        outcome: 'timeout',
      })

      expect(sendAction.mock.calls).toEqual([
        ['maintenance_disable', {}, handle.requestId],
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
    initiatorAgentType: null,
    initiatorNodeId: null,
    activatedAt: null,
    progressAt: null,
    isInitiator: false,
    diagnosticsNodeId: null,
    stoppedAgentCount: null,
    preservedAgentTypes: null,
    agentStartGateClosed: null,
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
    expect(
      MAINTENANCE_STATE_SIGNAL_SCHEMAS[MAINTENANCE_STATE_SIGNAL].parse({
        phase: 'inactive',
        operation: null,
        manual: false,
        startedAt: null,
        enteredFromBrowser: false,
        passCount: 0,
        circleNamed: 0,
        circleAdmitted: 0,
      }),
    ).toMatchObject({
      initiatorAgentType: null,
      isInitiator: false,
      stoppedAgentCount: null,
      preservedAgentTypes: null,
      agentStartGateClosed: null,
    })
    expect(
      MAINTENANCE_STATE_SIGNAL_SCHEMAS[MAINTENANCE_STATE_SIGNAL].parse({
        phase: 'inactive',
        operation: null,
        manual: false,
        startedAt: null,
        enteredFromBrowser: false,
        passCount: 0,
        circleNamed: 0,
        circleAdmitted: 0,
        preservedAgentTypes: [],
      }).preservedAgentTypes,
    ).toEqual([])
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

  it('keeps unpublished diagnostics null and does not treat a count as zero', () => {
    const restore = state({
      phase: 'verifying',
      operation: 'restore',
      initiatorAgentType: 'hilos_backup',
      initiatorNodeId: 'node-1',
      activatedAt: STARTED_AT,
      progressAt: STARTED_AT,
      isInitiator: true,
    })
    expect(hilosMaintenanceCard(restore, NOW)).toMatchObject({
      initiatorAgentType: 'hilos_backup',
      initiatorNodeId: 'node-1',
      initiatorCaption: 'hilos_backup · node node-1',
      activatedAt: STARTED_AT,
      progressAt: STARTED_AT,
      stoppedAgentCount: null,
      preservedAgentTypes: null,
      stoppedAgentsCaption: '—',
      diagnosticsNodeId: null,
      agentStartGateClosed: null,
      agentStartCaption: '—',
      isInitiator: true,
    })
    expect(
      hilosMaintenanceCard(
        {
          ...restore,
          phase: 'verifying',
          stoppedAgentCount: null,
          agentStartGateClosed: null,
        },
        NOW,
      )?.agentStartCaption,
    ).toBe('—')
  })

  it('renders a stopped count with the preserved types and the published gate', () => {
    const card = hilosMaintenanceCard(
      state({
        phase: 'active',
        operation: 'restore',
        stoppedAgentCount: 6,
        preservedAgentTypes: ['hilos_mail'],
        agentStartGateClosed: true,
        diagnosticsNodeId: 'node-1',
      }),
      NOW,
    )
    expect(card).toMatchObject({
      stoppedAgentCount: 6,
      preservedAgentTypes: ['hilos_mail'],
      stoppedAgentsCaption: '6 · hilos_mail left running',
      diagnosticsNodeId: 'node-1',
      agentStartGateClosed: true,
      agentStartCaption: 'closed',
    })
    expect(card?.stoppedAgentsCaption).not.toContain('cluster')
    expect(
      hilosMaintenanceCard(
        state({
          phase: 'verifying',
          operation: 'restore',
          stoppedAgentCount: 0,
          preservedAgentTypes: [],
          agentStartGateClosed: false,
        }),
        NOW,
      ),
    ).toMatchObject({
      stoppedAgentCount: 0,
      preservedAgentTypes: [],
      stoppedAgentsCaption: '0',
      agentStartGateClosed: false,
      agentStartCaption: 'open',
    })
    expect(
      hilosMaintenanceCard(
        state({
          phase: 'active',
          operation: 'restore',
          initiatorAgentType: null,
          initiatorNodeId: null,
        }),
        NOW,
      )?.initiatorCaption,
    ).toBe('— · node —')
  })
})

describe('maintenance restore ladder', () => {
  const restore = state({
    phase: 'verifying',
    operation: 'restore',
    initiatorAgentType: 'hilos_backup',
    initiatorNodeId: 'node-1',
  })

  it('names the initiating browser and anyone else only while restore is verifying', () => {
    expect(hilosMaintenanceRestoreLadder(null)).toBeNull()
    expect(
      hilosMaintenanceRestoreLadder({ ...restore, isInitiator: true }),
    ).toBe('initiator')
    expect(hilosMaintenanceRestoreLadder(restore)).toBe('other')
    expect(
      hilosMaintenanceRestoreLadder({ ...restore, manual: true }),
    ).toBeNull()
    expect(
      hilosMaintenanceRestoreLadder({ ...restore, phase: 'active' }),
    ).toBeNull()
    expect(
      hilosMaintenanceRestoreLadder({
        ...restore,
        operation: 'manual_maintenance',
      }),
    ).toBeNull()
    expect(
      hilosMaintenanceOtherBrowserLine({
        ...restore,
        initiatorAgentType: null,
        initiatorNodeId: null,
      }),
    ).toBe(
      'The operation is run by — on node —. Only the browser that started it can open the system.',
    )
    expect(hilosMaintenanceOtherBrowserLine(restore)).toBe(
      'The operation is run by hilos_backup on node node-1. Only the browser that started it can open the system.',
    )
  })
})
