import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'
import {
  createHilosModerationKind,
  createHilosModerationKindsTable,
} from '../../../src/admin/moderation/index.js'

const PAGE = 'hilos_moderation_kind'
const TABLE = 'hilosModerationKinds'
const cleanups: Array<() => void> = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
})

function wireRow(kind: string, patch: Record<string, unknown> = {}) {
  return {
    rowKey: kind,
    slots: {
      kind: {
        kind,
        title: `Title of ${kind}`,
        owner: 'framework',
        input: 'text',
        model: 'text-model',
        ownRuleCount: 0,
        profile: 'hilos.moderation.text',
        reasons: ['spam', 'threat'],
        ...patch,
      },
    },
  }
}

/** Only the socket is a double: parsing, page scope, replay and window binding are real. */
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
  const unregistered = vi.spyOn(connection, 'unregisterTableWindow')
  const detail = createHilosModerationKind({ connection, scopes }, kind)
  cleanups.push(() => {
    detail.dispose()
    connection.close()
  })

  function receive(type: string, data: unknown): void {
    listeners.get('message')?.({ data: JSON.stringify({ type, data }) })
  }
  function answer(key = kind.get(), patch: Record<string, unknown> = {}): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        windows: {
          [TABLE]: {
            rows: [wireRow(key, patch)],
            sort: [],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 1,
          },
        },
      },
    })
  }
  function delta(key: string, patch: Record<string, unknown>): void {
    receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_updated',
      rowKey: key,
      row: wireRow(key, patch),
    })
  }
  return {
    connection,
    scopes,
    pages,
    kind,
    detail,
    registered,
    unregistered,
    sent,
    receive,
    answer,
    delta,
    reopen() {
      connection.close()
      connection.connect()
      listeners.get('open')?.({})
      pages.releaseOnSession()
    },
  }
}

describe('moderation kind detail', () => {
  it.each(['before mount', 'after mount'])(
    'uses the one page answer %s without a viewport fetch',
    (timing) => {
      const { detail, answer, sent, registered } = setup()
      expect(detail.row.get()).toBeNull()
      if (timing === 'before mount') answer()
      detail.start()
      detail.start()
      if (timing === 'after mount') answer()

      expect(detail.row.get()).toEqual({
        kind: 'first',
        title: 'Title of first',
        owner: 'framework',
        input: 'text',
        model: 'text-model',
        ownRuleCount: 0,
        profile: 'hilos.moderation.text',
        reasons: ['spam', 'threat'],
      })
      expect(registered).toHaveBeenCalledTimes(1)
      expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
        [],
      )
      expect(sent.filter((frame) => frame.type === 'table_rendered')).toEqual([
        {
          type: 'table_rendered',
          page: PAGE,
          tableKey: TABLE,
          rendered: [
            'kind',
            'title',
            'owner',
            'input',
            'model',
            'ownRuleCount',
            'profile',
            'reasons',
          ],
        },
      ])
      expect(detail.controller.descriptor()?.limit).toBe(1)
    },
  )

  it('clears route and scope projections, drops late kinds, and never revives a previous kind on switching back', () => {
    const { detail, kind, scopes, answer, delta, pages, sent } = setup()
    detail.start()
    answer()
    kind.set('second')
    expect(detail.row.get()).toBeNull()
    delta('first', { model: 'late-old-model' })
    expect(detail.row.get()).toBeNull()
    kind.set('first')
    expect(detail.row.get()).toBeNull()
    answer()
    expect(detail.row.get()?.kind).toBe('first')
    scopes.openPage(PAGE)
    expect(detail.row.get()).toBeNull()
    answer()
    expect(detail.row.get()?.kind).toBe('first')
    pages.subscribe(HilosPages.MODERATION)
    answer()
    expect(detail.row.get()).toBeNull()
    expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual([])
  })

  it('keeps the binding across a same-page kind and scope switch for one canonical answer', () => {
    const { detail, kind, pages, answer, sent, registered, unregistered } =
      setup()
    detail.start()
    answer()
    kind.set('second')
    pages.subscribe(PAGE, { kind: 'second' })
    expect(detail.row.get()).toBeNull()
    expect(registered).toHaveBeenCalledTimes(1)
    expect(unregistered).not.toHaveBeenCalled()
    expect(
      sent.filter((frame) => frame.type === 'page_subscribe').at(-1),
    ).toEqual({
      type: 'page_subscribe',
      page: PAGE,
      params: { kind: 'second' },
      tableWindows: { [TABLE]: detail.controller.descriptor() },
    })
    answer('first')
    expect(detail.row.get()).toBeNull()
    answer('second')
    expect(detail.row.get()?.kind).toBe('second')
    expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual([])
  })

  it.each(['before navigation', 'after subscribe'])(
    'releases the detail before root subscribe and preserves a root mounted %s',
    (timing) => {
      const {
        detail,
        answer,
        connection,
        scopes,
        pages,
        sent,
        receive,
        registered,
        unregistered,
      } = setup()
      detail.start()
      answer()
      expect(connection.tableWindowDescriptors()[TABLE].limit).toBe(1)
      const ingest = vi.spyOn(detail.controller, 'ingestSubscriptionWindow')
      const count = vi.spyOn(detail.controller, 'ingestCount')
      const root = createHilosModerationKindsTable({ connection, scopes })
      cleanups.push(() => {
        root.dispose()
        root.controller.dispose()
      })
      if (timing === 'before navigation') root.start()

      // Real navigation sends in this stack, before the outgoing Vue unmount.
      pages.subscribe(HilosPages.MODERATION)
      expect(
        sent.filter((frame) => frame.type === 'page_subscribe').at(-1),
      ).toEqual({
        type: 'page_subscribe',
        page: HilosPages.MODERATION,
        params: {},
      })
      expect(unregistered).toHaveBeenCalledExactlyOnceWith(
        TABLE,
        detail.controller,
      )
      expect(detail.row.get()).toBeNull()
      expect(detail.controller.rows.get()).toEqual([])
      if (timing === 'after subscribe') root.start()
      receive('page_response', {
        page: HilosPages.MODERATION,
        payload: {
          windows: {
            [TABLE]: {
              rows: ['first', 'second', 'third', 'fourth'].map((key) =>
                wireRow(key),
              ),
              sort: [],
              totalCount: 4,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
            },
          },
        },
      })
      expect(root.controller.rows.get().map((view) => view.row?.kind)).toEqual([
        'first',
        'second',
        'third',
        'fourth',
      ])
      expect(root.controller.descriptor()).toMatchObject({
        filter: {},
        sort: null,
        limit: 25,
      })
      // The later unmount must not unregister the replacement under the same key.
      detail.dispose()
      expect(registered).toHaveBeenCalledTimes(2)
      expect(unregistered).toHaveBeenCalledTimes(1)
      expect(connection.tableWindowDescriptors()).toEqual({
        [TABLE]: root.controller.descriptor(),
      })

      // Returning to the old address cannot revive an unbound mount or its listeners.
      const reset = vi.spyOn(detail.controller, 'resetWindowRequests')
      pages.subscribe(PAGE, { kind: 'first' })
      answer()
      receive('table_viewport_count', {
        page: PAGE,
        tableKey: TABLE,
        totalCount: 9,
        totalExact: true,
      })
      expect(ingest).not.toHaveBeenCalled()
      expect(count).not.toHaveBeenCalled()
      expect(reset).not.toHaveBeenCalled()
      expect(detail.row.get()).toBeNull()
      expect(detail.controller.rows.get()).toEqual([])
      expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
        [],
      )
    },
  )

  it('keeps model redaction and values live on the existing row without Apply', () => {
    const { detail, answer, delta } = setup()
    detail.start()
    answer('first', { model: { _hidden: true } })
    expect(detail.row.get()?.model).toBe(HIDDEN_VALUE)
    delta('first', { model: 'new-model', ownRuleCount: 3 })
    expect(detail.row.get()?.model).toBe('new-model')
    expect(detail.row.get()?.ownRuleCount).toBe(3)
    expect(detail.controller.pendingCount.get()).toBe(0)
    delta('first', { model: { _hidden: true }, ownRuleCount: 0 })
    expect(detail.row.get()?.model).toBe(HIDDEN_VALUE)
    expect(detail.row.get()?.ownRuleCount).toBe(0)
  })

  it('reports its held window on reconnect and accepts the complete refreshed answer without a viewport ask', () => {
    const { detail, answer, sent, reopen } = setup()
    detail.start()
    answer()
    reopen()
    const subscribe = sent
      .filter((frame) => frame.type === 'page_subscribe')
      .at(-1)
    expect(subscribe).toEqual({
      type: 'page_subscribe',
      page: PAGE,
      params: { kind: 'first' },
      tableWindows: { [TABLE]: detail.controller.descriptor() },
    })
    answer('first', { model: 'reconnected-model', ownRuleCount: 2 })
    expect(detail.row.get()?.model).toBe('reconnected-model')
    expect(detail.row.get()?.ownRuleCount).toBe(2)
    expect(sent.filter((frame) => frame.type === 'table_viewport')).toEqual([])
  })

  it('keeps refusal, freeze and removed placeholders distinct from a successful Built-in overview', () => {
    const { detail, answer, receive, scopes } = setup()
    detail.start()
    receive('page_response', {
      page: PAGE,
      payload: {
        refusedWindows: {
          [TABLE]: { errorCode: 'source_unavailable' },
        },
      },
    })
    expect(detail.row.get()).toBeNull()
    expect(detail.unavailable.get()).toBe(true)
    answer()
    expect(detail.unavailable.get()).toBe(false)
    receive('table_viewport_frozen', {
      page: PAGE,
      tableKey: TABLE,
      since: 1000,
    })
    expect(detail.controller.frozenSince.get()).toBe(1000)
    expect(detail.row.get()?.kind).toBe('first')
    answer()
    expect(detail.controller.frozenSince.get()).toBeNull()
    receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind: 'row_removed',
      rowKey: 'first',
      reason: 'deleted',
      own: true,
    })
    expect(detail.row.get()).toBeNull()
    receive('table_window_refused', {
      page: PAGE,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    expect(detail.unavailable.get()).toBe(true)
    scopes.openPage(PAGE)
    expect(detail.row.get()).toBeNull()
    expect(detail.unavailable.get()).toBe(false)
  })

  it('releases the descriptor, listeners and row projection on disposal', () => {
    const { detail, answer, delta, connection, unregistered } = setup()
    detail.start()
    answer()
    expect(Object.keys(connection.tableWindowDescriptors())).toEqual([TABLE])
    detail.dispose()
    expect(unregistered).toHaveBeenCalledTimes(1)
    expect(connection.tableWindowDescriptors()).toEqual({})
    delta('first', { ownRuleCount: 5 })
    answer()
    expect(detail.row.get()).toBeNull()
    expect(detail.controller.rows.get()).toEqual([])
  })
})
