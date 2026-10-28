import { describe, expect, it } from 'vitest'
import {
  createHilosMaintenanceWordsTable,
  HILOS_MAINTENANCE_WORDS_COPY,
  HILOS_MAINTENANCE_WORDS_SLOT,
  HILOS_MAINTENANCE_WORDS_TABLE,
  MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
  MAINTENANCE_WORDS_MESSAGE_FIELD,
  MAINTENANCE_WORDS_OPERATION_FIELD,
  MAINTENANCE_WORDS_TITLE_FIELD,
  MAINTENANCE_WORDS_USES_DEFAULT_FIELD,
  resolveHilosMaintenanceWordsRow,
} from '../../../src/admin/maintenance/hilosMaintenanceWords.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type TableViewportDescriptor,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import {
  HilosPages,
  type HilosPageKey,
} from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../../src/protocol/constants.js'

/** Build a maintenance words row whose inline `stub` slot carries the given fields. */
function wordsRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { stub: slot } }
}

/** Minimal connection recording table viewports and rendered field declarations. */
class MaintenanceWordsConnection {
  readonly emitter = new Emitter<HilosConnectionEventMap>()
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
  readonly connection: HilosConnection

  private activeListeners = 0

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
    } as unknown as HilosConnection
  }

  emit<K extends keyof HilosConnectionEventMap>(
    event: K,
    payload: HilosConnectionEventMap[K],
  ): void {
    this.emitter.emit(event, payload)
  }

  emitPageResponse(page: string, rows: TableRow[]): void {
    this.emit('projectSignal', {
      kind: 'project',
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page,
        payload: {
          windows: {
            hilosMaintenanceWords: {
              rows,
              sort: [],
              limit: 10,
              totalCount: rows.length,
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

  listenerCount(): number {
    return this.activeListeners
  }

  private on<K extends keyof HilosConnectionEventMap>(
    event: K,
    listener: (payload: HilosConnectionEventMap[K]) => void,
  ): () => void {
    const unsubscribe = this.emitter.on(event, listener)
    this.activeListeners += 1
    return () => {
      this.activeListeners -= 1
      unsubscribe()
    }
  }
}

describe('resolveHilosMaintenanceWordsRow', () => {
  it('resolves an own entry with copy phrases and usesDefault false', () => {
    const row = resolveHilosMaintenanceWordsRow(
      wordsRow('manual_maintenance', {
        operation: 'manual_maintenance',
        title: 'Closed for maintenance',
        message:
          'The application is closed for maintenance. Please check back later.',
        bannerMessage:
          'Manual maintenance is on: the system is closed to visitors. Turn it off in the Maintenance section.',
        usesDefault: false,
      }),
    )

    expect(row).toEqual({
      operation: 'manual_maintenance',
      title: 'Closed for maintenance',
      message:
        'The application is closed for maintenance. Please check back later.',
      bannerMessage:
        'Manual maintenance is on: the system is closed to visitors. Turn it off in the Maintenance section.',
      usesDefault: false,
    })
  })

  it('resolves a substituted entry with null copy and usesDefault true', () => {
    const row = resolveHilosMaintenanceWordsRow(
      wordsRow('restore', {
        operation: 'restore',
        title: null,
        message: null,
        bannerMessage: null,
        usesDefault: true,
      }),
    )

    expect(row).toEqual({
      operation: 'restore',
      title: null,
      message: null,
      bannerMessage: null,
      usesDefault: true,
    })
  })

  it('reads usesDefault strictly from the payload and does not compute it from absent copy', () => {
    const row = resolveHilosMaintenanceWordsRow(
      wordsRow('backup', {
        operation: 'backup',
        title: null,
        message: null,
        bannerMessage: null,
        usesDefault: false,
      }),
    )

    expect(row.usesDefault).toBe(false)
    expect(row.title).toBeNull()
    expect(row.message).toBeNull()
    expect(row.bannerMessage).toBeNull()
  })

  it('falls back to the row key when operation is absent in slot and defaults copy to null', () => {
    const row = resolveHilosMaintenanceWordsRow(wordsRow('default', undefined))

    expect(row).toEqual({
      operation: 'default',
      title: null,
      message: null,
      bannerMessage: null,
      usesDefault: false,
    })
  })

  it('treats non-string nullable fields as null', () => {
    const row = resolveHilosMaintenanceWordsRow(
      wordsRow('custom', {
        operation: 'custom',
        title: 123,
        message: {},
        bannerMessage: true,
        usesDefault: false,
      }),
    )

    expect(row.title).toBeNull()
    expect(row.message).toBeNull()
    expect(row.bannerMessage).toBeNull()
    expect(row.usesDefault).toBe(false)
  })
})

describe('wire keys and copy constants', () => {
  it('keeps payload keys, table key, and slot name byte-identical to backend contracts', () => {
    expect(HILOS_MAINTENANCE_WORDS_TABLE).toBe('hilosMaintenanceWords')
    expect(HILOS_MAINTENANCE_WORDS_SLOT).toBe('stub')
    expect(MAINTENANCE_WORDS_OPERATION_FIELD).toBe('operation')
    expect(MAINTENANCE_WORDS_TITLE_FIELD).toBe('title')
    expect(MAINTENANCE_WORDS_MESSAGE_FIELD).toBe('message')
    expect(MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD).toBe('bannerMessage')
    expect(MAINTENANCE_WORDS_USES_DEFAULT_FIELD).toBe('usesDefault')
  })

  it('matches the exact wire copy phrases', () => {
    expect(HILOS_MAINTENANCE_WORDS_COPY).toEqual({
      title: 'Words used to announce maintenance',
      noOwnEntry: 'No own entry — uses default',
      empty: 'No words registered.',
    })
  })
})

describe('createHilosMaintenanceWordsTable', () => {
  it('declares the frame without search, filters, actions, sortability, or row height cap', () => {
    const connection = new MaintenanceWordsConnection()
    const scopes = new ScopeManager()
    const table = createHilosMaintenanceWordsTable({
      connection: connection.connection,
      scopes,
      actions: {} as unknown as ActionLifecycle,
    })

    const declaration = table.controller.frame.declaration
    const columns = table.controller.frame.columns.get()

    expect(declaration?.title).toBe(HILOS_MAINTENANCE_WORDS_COPY.title)
    expect(declaration?.empty).toEqual({
      title: HILOS_MAINTENANCE_WORDS_COPY.empty,
    })
    expect(declaration?.search).toBeUndefined()
    expect(declaration?.filters).toBeUndefined()
    expect(declaration?.mainAction).toBeUndefined()
    expect(declaration?.bulkActions).toBeUndefined()
    expect(declaration?.rowHeight).toBeUndefined()

    expect(columns.map((c) => c.key)).toEqual([
      'operation',
      'title',
      'message',
      'bannerMessage',
    ])
    expect(columns.map((c) => c.label)).toEqual([
      'Operation',
      'Title',
      'Message',
      'Banner',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.card).toBe('field')
    expect(columns[1]?.reads).toEqual(['usesDefault'])
    expect(columns[2]?.card).toBe('field')
    expect(columns[3]?.card).toBe('field')
    for (const column of columns) {
      expect(column.sortable).toBeFalsy()
    }
  })

  it('attaches rowMessage only to rows where usesDefault is true', () => {
    const connection = new MaintenanceWordsConnection()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.MAINTENANCE_WORDS)
    const table = createHilosMaintenanceWordsTable({
      connection: connection.connection,
      scopes,
      actions: {} as unknown as ActionLifecycle,
    })

    table.start()
    connection.emitPageResponse(HilosPages.MAINTENANCE_WORDS, [
      wordsRow('default', {
        operation: 'default',
        title: 'Maintenance in progress',
        message: 'The application is briefly unavailable.',
        bannerMessage: 'Maintenance has finished and is being verified.',
        usesDefault: false,
      }),
      wordsRow('restore', {
        operation: 'restore',
        title: null,
        message: null,
        bannerMessage: null,
        usesDefault: true,
      }),
    ])

    const rows = table.controller.rows.get()
    expect(rows).toHaveLength(2)

    // First row: own entry, no row message
    expect(rows[0]?.row?.usesDefault).toBe(false)
    expect(rows[0]?.rowMessage).toBeNull()

    // Second row: substituted entry, merged tail starting from title
    expect(rows[1]?.row?.usesDefault).toBe(true)
    expect(rows[1]?.rowMessage).toEqual({
      fromColumn: 'title',
      text: HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    })

    table.dispose()
  })

  it('binds and unbinds subscriptions cleanly on start and dispose', () => {
    const connection = new MaintenanceWordsConnection()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.MAINTENANCE_WORDS)
    const table = createHilosMaintenanceWordsTable({
      connection: connection.connection,
      scopes,
      actions: {} as unknown as ActionLifecycle,
    })

    expect(connection.listenerCount()).toBe(0)
    expect(connection.registered.size).toBe(0)
    table.start()
    expect(connection.listenerCount()).toBeGreaterThan(0)
    expect(connection.registered.has(HILOS_MAINTENANCE_WORDS_TABLE)).toBe(true)

    table.dispose()
    expect(connection.listenerCount()).toBe(0)
    expect(connection.registered.size).toBe(0)
  })

  it('ingests the first window from page_response without sending a mount request', () => {
    const connection = new MaintenanceWordsConnection()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.MAINTENANCE_WORDS)
    const table = createHilosMaintenanceWordsTable({
      connection: connection.connection,
      scopes,
      actions: {} as unknown as ActionLifecycle,
    })

    table.start()

    // No requestWindow / sendTableViewport triggered on start
    expect(connection.viewports).toEqual([])
    expect(connection.rendered).toEqual([])

    // Simulate page_response delivery containing the first window
    connection.emitPageResponse(HilosPages.MAINTENANCE_WORDS, [
      wordsRow('manual_maintenance', {
        operation: 'manual_maintenance',
        title: 'Closed for maintenance',
        message: 'The application is closed for maintenance.',
        bannerMessage: 'Manual maintenance is on.',
        usesDefault: false,
      }),
    ])

    // Controller received the row
    expect(table.controller.rows.get()).toHaveLength(1)
    expect(table.controller.rows.get()[0]?.row?.operation).toBe(
      'manual_maintenance',
    )

    // Rendered fields sent once, but still no separate viewport fetch
    expect(connection.rendered).toEqual([
      {
        page: HilosPages.MAINTENANCE_WORDS,
        tableKey: HILOS_MAINTENANCE_WORDS_TABLE,
        fields: [
          'operation',
          'title',
          'usesDefault',
          'message',
          'bannerMessage',
        ],
      },
    ])
    expect(connection.viewports).toEqual([])

    table.dispose()
  })

  it('respects a custom page scope argument', () => {
    const connection = new MaintenanceWordsConnection()
    const scopes = new ScopeManager()
    const customPage = 'custom_page' as unknown as HilosPageKey
    scopes.openPage(customPage)
    const table = createHilosMaintenanceWordsTable(
      {
        connection: connection.connection,
        scopes,
        actions: {} as unknown as ActionLifecycle,
      },
      customPage,
    )

    table.start()
    connection.emitPageResponse(customPage, [
      wordsRow('default', {
        operation: 'default',
        title: 'Maintenance',
        message: 'In progress',
        bannerMessage: 'Almost done',
        usesDefault: false,
      }),
    ])

    expect(table.controller.rows.get()).toHaveLength(1)
    expect(connection.rendered).toEqual([
      {
        page: customPage,
        tableKey: HILOS_MAINTENANCE_WORDS_TABLE,
        fields: [
          'operation',
          'title',
          'usesDefault',
          'message',
          'bannerMessage',
        ],
      },
    ])

    table.dispose()
  })
})
