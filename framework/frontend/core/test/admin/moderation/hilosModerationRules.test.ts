import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'
import {
  createHilosModerationKind,
  createHilosModerationRules,
  moderationKindRulesPath,
  resolveHilosModerationRuleRow,
} from '../../../src/admin/moderation/index.js'

const PAGE = HilosPages.MODERATION_KIND_RULES
const TABLE = 'hilosModerationRules'
const cleanups: Array<() => void> = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
})
function wireRule(id = 1, kind = 'first', rule = `Rule ${id}`) {
  return { rowKey: String(id), slots: { rule: { id, kind, rule } } }
}

/** Real parsing, page scopes, replay and both viewport bindings, with only the socket scripted. */
function setup() {
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (name, listener) => listeners.set(name, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const scopes = new ScopeManager()
  const pages = bindPageScope(connection, scopes)
  const kind = createSignal('first')
  connection.connect()
  listeners.get('open')?.({})
  pages.releaseOnSession()
  pages.subscribe(PAGE, { kind: kind.get() })
  const registered = vi.spyOn(connection, 'registerTableWindow')
  const rules = createHilosModerationRules({ connection, scopes }, kind)
  const detail = createHilosModerationKind({ connection, scopes }, kind, PAGE)
  cleanups.push(() => {
    detail.dispose()
    rules.dispose()
    connection.close()
  })
  const receive = (type: string, data: unknown) =>
    listeners.get('message')?.({ data: JSON.stringify({ type, data }) })
  function window(rows = [wireRule()], totalCount = rows.length) {
    return {
      rows,
      sort: [{ field: 'id', direction: 'asc' }],
      totalCount,
      totalExact: true,
      firstAnchor: rows.length ? { id: 1 } : null,
      lastAnchor: rows.length ? { id: rows.length } : null,
      limit: 25,
      rowsBefore: 0,
    }
  }
  function answer(rows = [wireRule()], totalCount = rows.length) {
    receive('page_response', {
      page: PAGE,
      payload: {
        windows: {
          hilosModerationKinds: {
            ...window([]),
            rows: [
              {
                rowKey: kind.get(),
                slots: {
                  kind: {
                    kind: kind.get(),
                    title: `Title ${kind.get()}`,
                    ownRuleCount: totalCount,
                    defaultRules: ['Built-in rule'],
                  },
                },
              },
            ],
            limit: 1,
            totalCount: 1,
          },
          [TABLE]: window(rows, totalCount),
        },
      },
    })
  }
  const viewports = () =>
    sent.filter((frame) => frame.type === 'table_viewport')
  function reply(
    rows = [wireRule()],
    requestId = String(viewports().at(-1)?.requestId),
    totalCount = rows.length,
  ) {
    receive('table_window', {
      page: PAGE,
      tableKey: TABLE,
      ...window(rows, totalCount),
      requestId,
    })
  }
  return {
    connection,
    scopes,
    pages,
    kind,
    rules,
    detail,
    sent,
    receive,
    answer,
    reply,
    viewports,
    registered,
    start() {
      detail.start()
      rules.start()
    },
    reopen() {
      connection.close()
      connection.connect()
      listeners.get('open')?.({})
      pages.releaseOnSession()
    },
  }
}

describe('moderation Rules', () => {
  it('resolves only typed scalar fields and encodes the kind in its section URL', () => {
    expect(resolveHilosModerationRuleRow(wireRule())).toEqual({
      id: 1,
      kind: 'first',
      rule: 'Rule 1',
    })
    expect(
      resolveHilosModerationRuleRow({
        rowKey: '1',
        slots: { rule: { id: [], kind: {}, rule: ['bad'] } },
      }),
    ).toEqual({ id: 0, kind: '', rule: '' })
    expect(moderationKindRulesPath('project/kind with spaces')).toBe(
      '/hilos/moderation/project%2Fkind%20with%20spaces/rules',
    )
  })

  it.each(['before start', 'after start'])(
    'uses one complete page answer %s without a viewport request',
    (timing) => {
      const h = setup()
      expect(h.rules.current.get()).toBe(false)
      expect(h.rules.controller.descriptor()).toBeNull()
      if (timing === 'before start') h.answer()
      h.start()
      h.start()
      if (timing === 'after start') h.answer()
      expect(h.rules.current.get()).toBe(true)
      expect(h.rules.controller.rows.get().map((view) => view.row)).toEqual([
        { id: 1, kind: 'first', rule: 'Rule 1' },
      ])
      expect(h.detail.row.get()?.kind).toBe('first')
      expect(h.registered).toHaveBeenCalledTimes(2)
      expect(h.viewports()).toEqual([])
      expect(h.rules.controller.descriptor()).toMatchObject({
        filter: { kind: 'first' },
        limit: 25,
        sort: [{ field: 'id', direction: 'asc' }],
        rendered: ['rule', 'id', 'kind'],
      })
      expect(h.rules.controller.frame.declaration).toMatchObject({
        search: { placeholder: 'Search rules…' },
      })
      expect(h.rules.controller.frame.columns.get()).toEqual([
        {
          key: 'rule',
          label: 'Rule',
          sortable: true,
          card: 'title',
          heightContent: 'text',
          reads: ['id', 'kind'],
        },
      ])
      for (const key of [
        'title',
        'mainAction',
        'bulkActions',
        'empty',
        'filteredEmpty',
        'filters',
      ])
        expect(h.rules.controller.frame.declaration).not.toHaveProperty(key)
      expect(
        h.sent.filter((frame) => frame.type === 'table_rendered'),
      ).toContainEqual({
        type: 'table_rendered',
        page: PAGE,
        tableKey: TABLE,
        rendered: ['rule', 'id', 'kind'],
      })
    },
  )

  it('keeps the URL kind through search, sort, filter replacement, Reset, pagination and reconnect', () => {
    const h = setup()
    h.start()
    h.answer([wireRule()], 70)
    const c = h.rules.controller
    c.setSearch('term')
    h.reply()
    c.setSort('rule')
    h.reply()
    expect(c.descriptor()).toMatchObject({
      filter: { kind: 'first', search: 'term' },
      sort: [{ field: 'rule', direction: 'asc' }],
    })
    c.setFilters({ kind: 'forged', search: 'kept' })
    h.reply()
    expect(h.viewports().at(-1)?.filter).toEqual({
      kind: 'first',
      search: 'kept',
    })
    c.resetFilters()
    h.reply([wireRule()], undefined, 70)
    expect(c.descriptor()?.filter).toEqual({ kind: 'first' })
    c.nextPage()
    h.reply([wireRule()], undefined, 70)
    c.prevPage()
    h.reply([wireRule()], undefined, 70)
    c.setPage(2)
    expect(h.viewports().at(-1)).toMatchObject({
      pageIndex: 2,
      filter: { kind: 'first' },
    })
    for (const frame of h.viewports())
      expect(frame.filter).toMatchObject({ kind: 'first' })
    const held = c.descriptor()
    h.reopen()
    expect(
      h.sent.filter((frame) => frame.type === 'page_subscribe').at(-1),
    ).toMatchObject({ page: PAGE, tableWindows: { [TABLE]: held } })
    expect(held).not.toHaveProperty('requestId')
  })

  it('resets provenance on kind/scope changes, ignores late tracked windows and refuses foreign row windows', () => {
    const h = setup()
    h.start()
    h.answer()
    h.rules.controller.setSearch('old')
    const old = String(h.viewports().at(-1)?.requestId)
    h.kind.set('second')
    expect(h.rules.current.get()).toBe(false)
    expect(h.rules.controller.descriptor()).toBeNull()
    h.pages.subscribe(PAGE, { kind: 'second' })
    h.reply([], old)
    expect(h.rules.current.get()).toBe(false)
    h.answer([wireRule(1, 'first')])
    expect(h.rules.current.get()).toBe(false)
    h.answer([])
    expect(h.rules.current.get()).toBe(true)
    expect(h.rules.controller.rows.get()).toEqual([])
    expect(h.rules.controller.descriptor()?.filter.kind).toBe('second')
    h.scopes.openPage(PAGE)
    expect(h.rules.current.get()).toBe(false)
    expect(h.rules.controller.descriptor()).toBeNull()
    h.receive('table_viewport_count', {
      page: PAGE,
      tableKey: TABLE,
      totalCount: 0,
      totalExact: true,
    })
    expect(h.rules.current.get()).toBe(false)
  })

  it('retains the controller Apply, append and count semantics with live values', () => {
    const h = setup()
    h.start()
    h.answer()
    const c = h.rules.controller
    h.receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_updated',
      rowKey: '1',
      row: wireRule(1, 'first', 'Updated'),
    })
    expect(c.rows.get()[0].row?.rule).toBe('Updated')
    expect(c.pendingCount.get()).toBe(0)
    h.receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_moved',
      rowKey: '1',
      position: 0,
      row: wireRule(1, 'first', 'Moved'),
    })
    expect(c.rows.get()[0].pending).toBe('move')
    expect(c.rows.get()[0].row?.rule).toBe('Moved')
    c.apply()
    expect(c.pendingCount.get()).toBe(0)
    h.receive('table_viewport_append', {
      page: PAGE,
      tableKey: TABLE,
      row: wireRule(2),
      totalCount: 2,
      totalExact: true,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 2 },
    })
    expect(c.rows.get().map((view) => view.row?.id)).toEqual([1, 2])
    h.receive('table_viewport_count', {
      page: PAGE,
      tableKey: TABLE,
      totalCount: 0,
      totalExact: true,
    })
    expect(c.rows.get()).toHaveLength(2)
    h.receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_removed',
      rowKey: '1',
      reason: 'deleted',
    })
    expect(c.rows.get()[0].pending).toBe('remove')
    c.apply()
    expect(c.rows.get()[0].placeholder).toBe(true)
    expect(h.rules.current.get()).toBe(true)
  })

  it('keeps refusals and freeze distinct from an empty success, including cold refusal', () => {
    const h = setup()
    h.start()
    h.receive('page_response', {
      page: PAGE,
      payload: {
        refusedWindows: { [TABLE]: { errorCode: 'source_unavailable' } },
      },
    })
    expect(h.rules.current.get()).toBe(true)
    expect(h.rules.controller.frame.body.get()).toBe('unavailable')
    h.answer([])
    expect(h.rules.controller.frame.body.get()).toBe('empty')
    h.receive('table_viewport_frozen', {
      page: PAGE,
      tableKey: TABLE,
      since: 1000,
    })
    expect(h.rules.controller.frozenSince.get()).toBe(1000)
    h.answer()
    expect(h.rules.controller.frozenSince.get()).toBeNull()
    h.receive('table_window_refused', {
      page: HilosPages.MODERATION_KIND,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    expect(h.rules.controller.frame.body.get()).toBe('rows')
  })

  it('releases both bindings before departure and an old disposer preserves the replacement', () => {
    const h = setup()
    h.start()
    h.answer()
    const c = h.rules.controller
    const ingest = vi.spyOn(c, 'ingestCount')
    h.pages.subscribe(HilosPages.MODERATION_KIND, { kind: 'first' })
    expect(h.connection.tableWindowDescriptors()).toEqual({})
    expect(h.rules.current.get()).toBe(false)
    expect(
      h.sent.filter((frame) => frame.type === 'page_subscribe').at(-1),
    ).not.toHaveProperty('tableWindows')
    h.pages.subscribe(PAGE, { kind: 'first' })
    const replacement = createHilosModerationRules(h, h.kind)
    cleanups.push(replacement.dispose)
    replacement.start()
    h.answer()
    h.rules.dispose()
    expect(h.connection.tableWindowDescriptors()[TABLE]).toEqual(
      replacement.controller.descriptor(),
    )
    h.receive('table_viewport_count', {
      page: PAGE,
      tableKey: TABLE,
      totalCount: 5,
      totalExact: true,
    })
    expect(ingest).not.toHaveBeenCalled()
    expect(c.rows.get()).toEqual([])
  })
})
