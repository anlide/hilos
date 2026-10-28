import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosStatusSubscriberController,
  createHilosStatusSubscribersState,
  createHilosStatusSubscribersTable,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  resolveHilosStatusSubscriberRow,
  statusSubscriberCountsSchema,
  statusSubscribersDataSchema,
  statusSubscriberSchema,
} from '../../../src/admin/status/index.js'
import { HILOS_VIEW_MODE_COPY } from '../../../src/admin/viewMode.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { bindAdminAccess } from '../../../src/session/adminAccess.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import {
  normalizeTableRow,
  type TableRowFragment,
} from '../../../src/state/normalizer.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'
import { Socket } from '../../status/fixtures.js'
import { harness } from './fixtures.js'

const subscriber = {
  id: 7,
  email: 'reader@example.net',
  subscribedAt: 1_800_000_000_000,
  confirmed: false,
}
const row = (value = subscriber) => ({
  rowKey: String(value.id),
  slots: { subscriber: value },
})
const page = 'hilos_status_subscribers'
const tableKey = 'hilosStatusSubscribers'
const openingSort = [{ field: 'subscribedAt', direction: 'desc' as const }]
function setup(viewer = false) {
  const h = harness(HilosPages.STATUS_SUBSCRIBERS)
  if (viewer) h.viewer()
  const table = createHilosStatusSubscribersTable(h.context)
  const state = createHilosStatusSubscribersState(h.context)
  const controller = createHilosStatusSubscriberController(h.context, table)
  table.start()
  state.start()
  controller.start()
  table.start()
  state.start()
  controller.start()
  const setCounts = (data: unknown) =>
    h.scopes.page()?.data.set('statusSubscribers', data)
  return {
    ...h,
    table,
    state,
    controller,
    setCounts,
    window(rows = [row()]) {
      h.emit('projectSignal', {
        type: 'page_response',
        data: {
          page,
          payload: {
            windows: {
              [tableKey]: {
                rows,
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                limit: 25,
                sort: openingSort,
              },
            },
          },
        },
      })
    },
    delta(data: Record<string, unknown>) {
      h.emit('tableViewportDelta', { data: { page, tableKey, ...data } })
    },
    answer(
      success: boolean,
      data: Record<string, unknown> = {},
      index = h.sent.length - 1,
    ) {
      const sent = h.sent[index]!
      h.emit(success ? 'actionSuccess' : 'actionError', {
        action: sent.action,
        requestId: sent.requestId,
        ...data,
      })
    },
    dispose() {
      controller.dispose()
      table.dispose()
      state.dispose()
      h.releaseAccess()
    },
  }
}
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('Status subscriber wire', () => {
  it('distinguishes unavailable counts from a proven empty set', () => {
    expect(statusSubscribersDataSchema.parse(null)).toBeNull()
    const counts = { confirmedCount: 0, pendingCount: 0 }
    const parsed = statusSubscriberCountsSchema.parse(counts)
    expect(parsed).toEqual(counts)
    expect(Object.isFrozen(parsed)).toBe(true)
    for (const wire of [
      undefined,
      {},
      { confirmedCount: 0 },
      { pendingCount: 0 },
      { confirmedCount: -1, pendingCount: 0 },
      { confirmedCount: 0, pendingCount: 0.5 },
      { confirmedCount: '0', pendingCount: 0 },
      { ...counts, total: 0 },
      { ...counts, maxSubscriberId: 1 },
    ]) {
      expect(statusSubscribersDataSchema.safeParse(wire).success).toBe(false)
    }
  })

  it('accepts only the four public row fields, including a hidden address', () => {
    const row = {
      id: 7,
      email: 'reader@example.net',
      subscribedAt: 1_800_000_000_000,
      confirmed: false,
    }
    expect(statusSubscriberSchema.parse(row)).toEqual(row)
    expect(
      statusSubscriberSchema.parse({ ...row, email: HIDDEN_VALUE }).email,
    ).toBe(HIDDEN_VALUE)
    expect(Object.isFrozen(statusSubscriberSchema.parse(row))).toBe(true)
    for (const key of Object.keys(row)) {
      const wire: Record<string, unknown> = { ...row }
      delete wire[key]
      expect(statusSubscriberSchema.safeParse(wire).success, key).toBe(false)
    }
    for (const wire of [
      { ...row, id: 0 },
      { ...row, email: null },
      { ...row, subscribedAt: 1.5 },
      { ...row, confirmed: 'true' },
      { ...row, confirmationToken: 'secret' },
      { ...row, timeZone: 'UTC' },
      { ...row, lastDeliveredAt: 1 },
    ]) {
      expect(statusSubscriberSchema.safeParse(wire).success).toBe(false)
    }
  })

  it('resolves only its own slot and requires the persisted id as row key', () => {
    const h = harness(HilosPages.STATUS_SUBSCRIBERS)
    const resolve = (wire: TableRowFragment) =>
      resolveHilosStatusSubscriberRow(
        normalizeTableRow(h.scope, wire),
        h.scopes,
      )
    expect(resolve(row())).toEqual(subscriber)
    expect(
      resolve({
        rowKey: '7',
        slots: { subscriber: { ...subscriber, email: HIDDEN_VALUE } },
      }).email,
    ).toBe(HIDDEN_VALUE)
    for (const wire of [
      { ...row(), rowKey: '8' },
      { ...row(), rowKey: '07' },
      { rowKey: '7', slots: { user: subscriber } },
      {
        rowKey: '7',
        slots: { subscriber: { ...subscriber, token: 'private' } },
      },
    ])
      expect(() =>
        resolveHilosStatusSubscriberRow(
          normalizeTableRow(h.scope, wire),
          h.scopes,
        ),
      ).toThrow()
    h.releaseAccess()
  })
})

describe('Status subscribers viewport and page state', () => {
  it.each([false, true])(
    'leaves replayed observed rows before the next subscription with pending removal = %s',
    async (removing) => {
      const socket = new Socket()
      const connection = new HilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket,
        projectSchemas: PAGE_SIGNAL_SCHEMAS,
      })
      const scopes = new ScopeManager()
      scopes.session.data.set('currentUser', { type: 'users', id: '1' })
      scopes.session.entities.upsert(
        { type: 'users', id: '1' },
        { admin: true },
      )
      const releaseAccess = bindAdminAccess(scopes)
      const actions = new ActionLifecycle(connection)
      const context = { connection, scopes, actions }
      const pages = bindPageScope(connection, scopes)
      connection.connect()
      socket.emit('open')
      pages.releaseOnSession()
      pages.subscribe(page)
      socket.push('page_response', {
        page,
        payload: {
          data: { statusSubscribers: { confirmedCount: 0, pendingCount: 1 } },
          windows: {
            [tableKey]: {
              rows: [row()],
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: openingSort,
            },
          },
        },
      })
      // HilosView mounts only after the answer. The child mirrors rows before
      // the parent's onMounted binds the table and synchronously replays it.
      const table = createHilosStatusSubscribersTable(context)
      const state = createHilosStatusSubscribersState(context)
      const controller = createHilosStatusSubscriberController(context, table)
      const observed = vi.fn()
      const off = subscribeSignal(table.controller.rows, observed)
      try {
        table.start()
        state.start()
        controller.start()
        expect(table.controller.rows.get()[0]?.row).toEqual(subscriber)
        expect(state.counts.get()).toEqual({
          confirmedCount: 0,
          pendingCount: 1,
        })
        expect(connection.tableWindowDescriptors()).toHaveProperty(tableKey)
        if (removing) controller.open('7')
        const pending = removing ? controller.submit() : Promise.resolve(false)
        const requestId = controller.requestId.get()
        const previous = scopes.page()
        const sent = socket.send.mock.calls.length
        pages.subscribe(HilosPages.STATUS)
        expect(scopes.page()).not.toBe(previous)
        expect(scopes.page()?.key).toBe(HilosPages.STATUS)
        expect(connection.tableWindowDescriptors()).toEqual({})
        expect(
          socket.send.mock.calls
            .slice(sent)
            .map(([raw]) => JSON.parse(raw))
            .filter((frame) => frame.type === 'page_subscribe'),
        ).toEqual([
          { type: 'page_subscribe', page: HilosPages.STATUS, params: {} },
        ])
        expect(pages.pageLoading.get()).toBe(true)
        socket.push('page_response', {
          page: HilosPages.STATUS,
          payload: { data: { heading: 'Overview' } },
        })
        expect(pages.pageLoading.get()).toBe(false)
        expect(table.controller.rows.get()).toEqual([])
        expect(state.counts.get()).toBeNull()
        expect(controller.dialog.get()).toBeNull()
        if (removing)
          socket.emit('message', {
            data: JSON.stringify({
              type: 'action_success',
              data: { action: 'hilos_status_subscriber_remove' },
              requestId,
              outcome: 'success',
            }),
          })
        expect(await pending).toBe(false)
        expect(controller.success.get()).toBeNull()
        socket.push('page_response', {
          page,
          payload: {
            data: {
              statusSubscribers: { confirmedCount: 100, pendingCount: 100 },
            },
          },
        })
        table.start()
        state.start()
        controller.start()
        expect(table.controller.rows.get()).toEqual([])
        expect(state.counts.get()).toBeNull()
        expect(connection.tableWindowDescriptors()).toEqual({})
      } finally {
        off()
        controller.dispose()
        state.dispose()
        table.dispose()
        releaseAccess()
        connection.close()
      }
    },
  )

  it('reads complete and counts-only page answers through the shared scope binder without resetting pending Apply', () => {
    const h = harness(HilosPages.STATUS_SUBSCRIBERS)
    h.context.connection.forgetPageFrames = vi.fn()
    const pages = bindPageScope(h.context.connection, h.scopes)
    pages.subscribe(page)
    const table = createHilosStatusSubscribersTable(h.context)
    const state = createHilosStatusSubscribersState(h.context)
    table.start()
    state.start()
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page,
        payload: {
          data: { statusSubscribers: { confirmedCount: 0, pendingCount: 1 } },
          windows: {
            [tableKey]: {
              rows: [row()],
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: openingSort,
            },
          },
        },
      },
    })
    expect(state.counts.get()).toEqual({ confirmedCount: 0, pendingCount: 1 })
    expect(table.controller.rows.get()[0]?.row).toEqual(subscriber)
    h.emit('tableViewportDelta', {
      data: {
        page,
        tableKey,
        kind: 'row_removed',
        rowKey: '7',
        reason: 'deleted',
      },
    })
    const requests = [...h.viewports]
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page,
        payload: {
          data: { statusSubscribers: { confirmedCount: 0, pendingCount: 0 } },
        },
      },
    })
    expect(state.counts.get()).toEqual({ confirmedCount: 0, pendingCount: 0 })
    expect(table.controller.pendingCount.get()).toBe(1)
    expect(table.controller.rows.get()[0]?.pending).toBe('remove')
    expect(h.viewports).toEqual(requests)
    state.dispose()
    table.dispose()
    h.releaseAccess()
  })

  it('uses the server opening order, boolean filter, search, and all six indexed field orders', () => {
    const h = setup()
    expect(h.viewports).toEqual([])
    expect(h.sent).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window()
    expect(h.table.controller.descriptor()?.sort).toEqual(openingSort)
    expect(h.table.controller.pageSize.get()).toBe(25)
    const filter = h.table.controller.frame.filters.get()[0]?.filter
    expect(
      filter?.kind === 'select'
        ? filter.options().map((option) => option.value)
        : null,
    ).toEqual([true, false])
    h.table.controller.setFilter('confirmed', false)
    expect(h.viewports.at(-1)).toEqual([
      page,
      tableKey,
      expect.objectContaining({ filter: { confirmed: false } }),
    ])
    h.table.controller.setFilter('confirmed', true)
    expect(h.table.controller.descriptor()?.filter).toEqual({ confirmed: true })
    h.table.controller.setFilter('confirmed', undefined)
    expect(h.table.controller.descriptor()?.filter).not.toHaveProperty(
      'confirmed',
    )
    h.table.controller.setSearch('reader@example.net')
    expect(h.table.controller.descriptor()?.filter).toEqual({
      search: 'reader@example.net',
    })
    for (const field of ['email', 'subscribedAt', 'confirmed'])
      for (const direction of ['asc', 'desc'] as const) {
        h.table.controller.setOrder([{ field, direction }])
        expect(h.viewports.at(-1)).toEqual([
          page,
          tableKey,
          expect.objectContaining({ sort: [{ field, direction }] }),
        ])
      }
    expect(
      h.table.controller.frame.columns
        .get()
        .filter((column) => column.sortable)
        .map((column) => column.key),
    ).toEqual(['email', 'subscribedAt', 'confirmed'])
    expect(h.facets).toContainEqual([
      page,
      tableKey,
      { confirmed: [true, false] },
    ])
    h.dispose()
  })

  it('keeps unknown/null counts unavailable and page count fanout independent of pending Apply', () => {
    const h = setup()
    h.window([row(), row({ ...subscriber, id: 8 })])
    expect(h.state.counts.get()).toBeNull()
    for (const value of [
      null,
      {},
      { confirmedCount: 0 },
      { confirmedCount: -1, pendingCount: 0 },
    ]) {
      h.setCounts(value)
      expect(h.state.counts.get()).toBeNull()
    }
    h.setCounts({ confirmedCount: 0, pendingCount: 0 })
    expect(h.state.counts.get()).toEqual({ confirmedCount: 0, pendingCount: 0 })
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    const descriptor = h.table.controller.descriptor()
    const requests = [...h.viewports]
    h.setCounts({ confirmedCount: 1, pendingCount: 0 })
    h.delta({
      kind: 'row_updated',
      rowKey: '7',
      row: row({ ...subscriber, confirmed: true }),
    })
    expect(h.table.controller.rows.get()[0]?.row?.confirmed).toBe(true)
    expect(h.table.controller.rows.get()[1]?.pending).toBe('remove')
    expect(h.table.controller.pendingCount.get()).toBe(1)
    expect(h.table.controller.descriptor()).toEqual(descriptor)
    expect(h.viewports).toEqual(requests)
    expect(h.state.counts.get()).toEqual({ confirmedCount: 1, pendingCount: 0 })
    h.table.controller.apply()
    expect(h.table.controller.rows.get()[1]?.placeholder).toBe(true)
    h.dispose()
  })

  it('offers a viewer only nonpersonal sorts, fields, counts and boolean facets with the shared reason', async () => {
    const h = setup(true)
    h.table.controller.ingestSubscriptionWindow(
      [
        normalizeTableRow(h.scope, {
          rowKey: '7',
          slots: { subscriber: { ...subscriber, email: HIDDEN_VALUE } },
        }),
      ],
      1,
      true,
      null,
      null,
      25,
      openingSort,
      [],
    )
    h.setCounts({ confirmedCount: 0, pendingCount: 1 })
    expect(h.table.controller.rows.get()[0]?.row?.email).toBe(HIDDEN_VALUE)
    expect(h.table.controller.frame.declaration.search).toBeUndefined()
    expect(
      h.table.controller.frame.columns
        .get()
        .filter((column) => column.sortable)
        .map((column) => column.key),
    ).toEqual(['subscribedAt', 'confirmed'])
    expect(h.table.personalControlsReason.get()).toBe(
      HILOS_VIEW_MODE_COPY.explanation,
    )
    expect(h.state.counts.get()).toEqual({ confirmedCount: 0, pendingCount: 1 })
    expect(h.facets).toEqual([[page, tableKey, { confirmed: [true, false] }]])
    h.controller.open('7')
    expect(h.controller.dialog.get()?.email).toBe(HIDDEN_VALUE)
    expect(h.controller.blockedReason.get()).toBe(
      HILOS_VIEW_MODE_COPY.explanation,
    )
    expect(await h.controller.submit()).toBe(false)
    expect(h.sent).toEqual([])
    const requests = h.viewports.length
    h.table.controller.setSearch('crafted@example.net')
    h.table.controller.setOrder([{ field: 'email', direction: 'asc' }])
    expect(h.viewports).toHaveLength(requests)
    h.dispose()
  })

  it('holds remote movement and deletion behind Apply, applying only a tagged own echo immediately', () => {
    const h = setup()
    h.window([row(), row({ ...subscriber, id: 8 })])
    h.delta({
      kind: 'row_moved',
      rowKey: '7',
      row: row({ ...subscriber, confirmed: true }),
      position: 1,
    })
    expect(h.table.controller.rows.get()[0]?.rowKey).toBe('7')
    expect(h.table.controller.pendingCount.get()).toBe(1)
    h.table.controller.apply()
    expect(h.table.controller.rows.get().map((item) => item.rowKey)).toEqual([
      '8',
      '7',
    ])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    expect(h.table.controller.rows.get()[1]?.placeholder).toBe(false)
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted', own: true })
    expect(h.table.controller.rows.get()[0]?.placeholder).toBe(true)
    expect(h.table.controller.rows.get()[1]?.pending).toBe('remove')
    expect(h.table.controller.pendingCount.get()).toBe(1)
    h.dispose()
  })
})

describe('Status remove subscriber controller', () => {
  it('captures id/caption, locks busy close/double-submit and closes on its page ack without optimistic deletion', async () => {
    const h = setup()
    h.window()
    h.controller.open('7')
    expect(h.focuses.at(-1)).toEqual([page, tableKey, '7'])
    const submit = h.controller.submit()
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: 7 },
        requestId: '1',
      },
    ])
    expect(h.controller.busy.get()).toBe(true)
    expect(h.controller.loading.get()).toBe(false)
    h.controller.close()
    h.controller.open('8')
    expect(await h.controller.submit()).toBe(false)
    expect(h.controller.dialog.get()?.subscriberId).toBe(7)
    vi.advanceTimersByTime(30)
    expect(h.controller.loading.get()).toBe(true)
    h.emit('actionSuccess', {
      action: 'hilos_status_subscriber_remove',
      requestId: 'wrong',
    })
    expect(h.controller.busy.get()).toBe(true)
    expect(h.table.controller.rows.get()[0]?.placeholder).toBe(false)
    h.answer(true)
    expect(await submit).toBe(true)
    expect(h.controller.dialog.get()).toBeNull()
    expect(h.controller.success.get()).toBe('Subscriber removed.')
    expect(h.focuses.at(-1)).toEqual([page, tableKey, ''])
    expect(h.table.controller.rows.get()[0]?.placeholder).toBe(false)
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    expect(h.table.controller.rows.get()[0]?.placeholder).toBe(true)
    h.dispose()
  })

  it('keeps the captured identity through reorder, replacement and delete/recreate of the same email', async () => {
    const h = setup()
    h.window([row(), row({ ...subscriber, id: 8, email: 'other@example.net' })])
    h.controller.open('7')
    h.window([
      row({ ...subscriber, id: 8 }),
      row({ ...subscriber, email: 'changed@example.net' }),
    ])
    expect(h.controller.dialog.get()).toEqual({
      subscriberId: 7,
      email: 'reader@example.net',
    })
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    expect(h.controller.target.get()).toBeNull()
    expect(h.controller.blockedReason.get()).toBe(
      HILOS_STATUS_SUBSCRIBERS_COPY.deleted,
    )
    expect(await h.controller.submit()).toBe(false)
    h.window([row({ ...subscriber, id: 9 })])
    expect(h.controller.dialog.get()?.subscriberId).toBe(7)
    expect(await h.controller.submit()).toBe(false)
    expect(h.sent).toEqual([])
    h.dispose()
  })

  it.each(['disconnect', 'freeze', 'viewer', 'stale', 'frozen-window'])(
    'blocks new removals under %s and retains the captured dialog',
    async (mode) => {
      const h = setup()
      h.window()
      h.controller.open('7')
      if (mode === 'disconnect') h.connect('disconnected')
      if (mode === 'freeze') h.freeze(true)
      if (mode === 'viewer') h.viewer()
      if (mode === 'stale')
        h.delta({
          kind: 'row_stale',
          rowKey: '7',
          staleSources: ['subscriber'],
        })
      if (mode === 'frozen-window')
        h.emit('tableViewportFrozen', { data: { page, tableKey, since: 1 } })
      expect(h.controller.canSubmit.get()).toBe(false)
      expect(h.controller.blockedReason.get()).not.toBeNull()
      expect(await h.controller.submit()).toBe(false)
      expect(h.controller.dialog.get()?.subscriberId).toBe(7)
      expect(h.sent).toEqual([])
      h.dispose()
    },
  )

  it.each(['refusal', 'timeout', 'disconnect'])(
    'keeps a %s inside its dialog and ignores late success',
    async (mode) => {
      const h = setup()
      h.window()
      h.controller.open('7')
      const submit = h.controller.submit()
      if (mode === 'refusal')
        h.answer(false, {
          reason: 'This subscriber cannot be removed.',
          errorType: 'SubscriberFailure',
          errorDetail: 'Admin diagnostic',
          errorCode: 'forbidden',
        })
      else if (mode === 'timeout') vi.advanceTimersByTime(1000)
      else h.connect('disconnected')
      expect(await submit).toBe(false)
      expect(h.controller.dialog.get()?.subscriberId).toBe(7)
      expect(h.controller.error.get()).toBe(
        mode === 'refusal'
          ? 'This subscriber cannot be removed.'
          : HILOS_STATUS_SUBSCRIBERS_COPY.unconfirmed,
      )
      expect(h.controller.unconfirmed.get()).toBe(mode !== 'refusal')
      if (mode === 'refusal')
        expect(h.controller.failure.get()?.errorDetail).toBe('Admin diagnostic')
      h.answer(true)
      await Promise.resolve()
      expect(h.controller.dialog.get()?.subscriberId).toBe(7)
      expect(h.controller.success.get()).toBeNull()
      expect(h.table.controller.rows.get()[0]?.placeholder).toBe(false)
      h.dispose()
    },
  )

  it('disposes every mounted owner on scope change and cannot be revived by an old completion', async () => {
    const h = setup()
    h.window()
    h.controller.open('7')
    const submit = h.controller.submit()
    h.scopes.openPage(HilosPages.STATUS_SUBSCRIBERS)
    h.scope.data.set('statusSubscribers', {
      confirmedCount: 100,
      pendingCount: 100,
    })
    const changed = vi.fn()
    const off = subscribeSignal(h.controller.success, changed)
    h.answer(true)
    expect(await submit).toBe(false)
    expect(h.state.counts.get()).toBeNull()
    expect(h.controller.dialog.get()).toBeNull()
    expect(h.table.controller.rows.get()).toEqual([])
    expect(h.registered.size).toBe(0)
    h.controller.start()
    h.table.start()
    h.state.start()
    h.controller.open('7')
    expect(h.controller.canSubmit.get()).toBe(false)
    expect(h.registered.size).toBe(0)
    expect(changed).not.toHaveBeenCalled()
    for (const [event, listeners] of h.listeners)
      expect(listeners.size, event).toBe(
        ['state', 'actionError', 'actionSuccess'].includes(event) ? 1 : 0,
      )
    off()
    h.dispose()
    h.dispose()
  })
})
