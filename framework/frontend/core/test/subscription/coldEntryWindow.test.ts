import { describe, expect, it } from 'vitest'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../src/subscription/bindPageScope.js'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'

/**
 * The cold entry, end to end on this side (HIL-642).
 *
 * Nothing here is a double but the socket: a real connection, a real page subscription, a
 * real controller, and the table bound where a view binds it — after the answer has already
 * arrived. That order is the whole difficulty and the reason the first shipping of this leaf
 * drew no rows in any demo: a page's answer carries the window, and the table that the window
 * belongs to is not listening yet when it lands.
 */
class ScriptedSocket implements WebSocketLike {
  static last: ScriptedSocket

  readonly sent: string[] = []

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(readonly url: string) {
    ScriptedSocket.last = this
  }

  send(data: string | ArrayBuffer | Blob): void {
    this.sent.push(String(data))
  }

  close(): void {}

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }

  removeEventListener(): void {}

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener(event)
    }
  }

  open(): void {
    this.emit('open')
  }

  message(text: string): void {
    this.emit('message', { data: text })
  }
}

const PAGE = 'hilos_settings'
const TABLE = 'settings'

/** The answer the browser context sends: the page's sections, windows among them. */
const WINDOW_ANSWER = JSON.stringify({
  type: 'page_response',
  data: {
    page: PAGE,
    payload: {
      windows: {
        [TABLE]: {
          rows: [
            { rowKey: 'example_string', slots: { setting: { key: 'a' } } },
          ],
          sort: [{ field: 'key', direction: 'asc' }],
          limit: 10,
          totalCount: 1,
          totalExact: true,
          firstAnchor: { key: 'a' },
          lastAnchor: { key: 'a' },
        },
      },
    },
  },
})

/** The page's own answer, sent right after and under the very same name. */
const PAGE_ANSWER = JSON.stringify({
  type: 'page_response',
  data: { page: PAGE, payload: { data: { heading: 'Settings' } } },
})

describe('the cold entry of a table', () => {
  it('draws the window when the table was bound before the answer arrived', () => {
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new ScriptedSocket(url),
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    const scopes = new ScopeManager()
    const pages = bindPageScope(connection, scopes)
    connection.connect()
    ScriptedSocket.last.open()
    pages.releaseOnSession()

    pages.subscribe(PAGE)
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
    })
    bindTableViewport(
      connection,
      scopes,
      { page: PAGE, tableKey: TABLE },
      controller,
    )

    ScriptedSocket.last.message(WINDOW_ANSWER)
    ScriptedSocket.last.message(PAGE_ANSWER)

    // The other order of the same two events — a view that mounted while the answer was
    // still on the wire — and the window has to land the same way.
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
      'example_string',
    ])
  })

  it('draws the window the page answered with, bound after the answer arrived', () => {
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new ScriptedSocket(url),
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    const scopes = new ScopeManager()
    const pages = bindPageScope(connection, scopes)
    connection.connect()
    ScriptedSocket.last.open()
    pages.releaseOnSession()

    pages.subscribe(PAGE)
    ScriptedSocket.last.message(WINDOW_ANSWER)
    ScriptedSocket.last.message(PAGE_ANSWER)

    // Only now does the view mount and the table bind — the order a router gives it.
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
    })
    bindTableViewport(
      connection,
      scopes,
      { page: PAGE, tableKey: TABLE },
      controller,
    )

    expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
      'example_string',
    ])
    expect(controller.loaded.get()).toBe(true)
    expect(controller.descriptor()?.limit).toBe(10)
  })
})
