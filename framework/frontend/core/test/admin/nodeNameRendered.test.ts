import { describe, expect, it } from 'vitest'
import {
  createHilosBackupsTable,
  type HilosBackupsContext,
} from '../../src/admin/backup/hilosBackups.js'
import { createHilosLogKeysTable } from '../../src/admin/logs/hilosLogKeys.js'
import { createHilosLogNodesTable } from '../../src/admin/logs/hilosLogNodes.js'
import {
  createHilosLogRotationsTable,
  type HilosLogRotationsHeader,
} from '../../src/admin/logs/hilosLogRotations.js'
import { createHilosLogWorkersTable } from '../../src/admin/logs/hilosLogWorkers.js'
import { createHilosMaintenanceNodesTable } from '../../src/admin/maintenance/hilosMaintenanceNodes.js'
import { ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'
import { PAGE_SIGNAL_SCHEMAS } from '../../src/subscription/bindPageScope.js'

/** Only the socket is a double; parsing, binding and table updates are real. */
class Socket implements WebSocketLike {
  readonly sent: string[] = []
  private readonly listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()

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

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }

  message(type: string, data: unknown): void {
    this.emit('message', { data: JSON.stringify({ type, data }) })
  }
}

const nodes = [{ nodeId: 'node-1', nodeName: 'North' }]
const rotationsHeader: HilosLogRotationsHeader = {
  available: true,
  nodes,
  rotationCron: null,
  rotationMaxAgeSeconds: 0,
  rotationMaxLiveSizeBytes: 0,
  retentionKeepBatches: 0,
  retentionMaxAgeSeconds: 0,
  preset: null,
  presetEdited: false,
}

const cases = [
  {
    page: HilosPages.MAINTENANCE_NODES,
    tableKey: 'hilosMaintenanceNodes',
    slot: 'node',
    rowKey: 'node-1',
    identity: 'nodeId',
    name: 'nodeName',
    column: 'nodeId',
    reads: ['nodeName'],
    sort: 'nodeId',
    fields: { nodeId: 'node-1', phase: 'inactive', reportState: 'current' },
    create: (context: HilosBackupsContext) =>
      createHilosMaintenanceNodesTable(context),
  },
  {
    page: HilosPages.LOGS,
    tableKey: 'hilosLogNodes',
    slot: 'node',
    rowKey: 'node-1',
    identity: 'nodeId',
    name: 'nodeName',
    column: 'nodeId',
    reads: ['nodeName'],
    sort: 'nodeId',
    fields: { nodeId: 'node-1', available: true },
    create: (context: HilosBackupsContext) => createHilosLogNodesTable(context),
  },
  {
    page: HilosPages.LOGS_KEYS,
    tableKey: 'hilosLogKeys',
    slot: 'stream',
    rowKey: 'node-1:worker-0.log',
    identity: 'node',
    name: 'nodeName',
    column: 'node',
    reads: ['nodeName'],
    sort: 'key',
    fields: { node: 'node-1', key: 'worker-0.log' },
    create: (context: HilosBackupsContext) =>
      createHilosLogKeysTable(
        context,
        createSignal({ available: true, nodes }),
      ),
  },
  {
    page: HilosPages.LOGS_WORKERS,
    tableKey: 'hilosLogWorkers',
    slot: 'stream',
    rowKey: 'node-1:worker-0.log',
    identity: 'node',
    name: 'nodeName',
    column: 'node',
    reads: ['nodeName'],
    sort: 'key',
    fields: { node: 'node-1', key: 'worker-0.log' },
    create: (context: HilosBackupsContext) =>
      createHilosLogWorkersTable(
        context,
        createSignal({ available: true, nodes }),
      ),
  },
  ...[true, false].map((showNodeColumn) => ({
    page: HilosPages.LOGS_ROTATIONS,
    tableKey: 'hilosLogRotations',
    slot: 'batch',
    rowKey: 'node-1:1800000000',
    identity: 'node',
    name: 'nodeName',
    column: showNodeColumn ? 'node' : 'actions',
    reads: showNodeColumn
      ? ['nodeName']
      : [
          'batchAt',
          'node',
          'nodeName',
          'path',
          'absolutePath',
          'retentionState',
          'pruneNotBefore',
        ],
    sort: 'batchAt',
    fields: { node: 'node-1', batchAt: 1800000000000 },
    create: (context: HilosBackupsContext) =>
      createHilosLogRotationsTable(
        context,
        createSignal({
          ...rotationsHeader,
          nodes: showNodeColumn ? nodes : [],
        }),
      ),
  })),
  {
    page: HilosPages.BACKUP,
    tableKey: 'hilosBackups',
    slot: 'backup',
    rowKey: 'backup-1',
    identity: 'holderNode',
    name: 'holderNodeName',
    column: 'createdAt',
    reads: ['holderNode', 'holderNodeName'],
    sort: 'createdAt',
    fields: {
      holderNode: 'node-1',
      createdAt: '2026-07-20T10:00:00.000Z',
      finished: true,
    },
    create: (context: HilosBackupsContext) =>
      createHilosBackupsTable(context, { openCreate: () => undefined }),
  },
]

describe('rendered node names', () => {
  it.each(cases)(
    '$tableKey declares the $column label and receives a name change in place',
    (test) => {
      const socket = new Socket()
      const connection = new HilosConnection({
        url: 'ws://test/ws',
        projectSchemas: PAGE_SIGNAL_SCHEMAS,
        webSocketFactory: () => socket,
      })
      const scopes = new ScopeManager()
      scopes.openPage(test.page)
      const table = test.create({
        connection,
        scopes,
        actions: new ActionLifecycle(connection),
      })
      connection.connect()
      socket.emit('open')
      table.start()

      try {
        const fields = { ...test.fields, [test.name]: 'North' }
        const row = { rowKey: test.rowKey, slots: { [test.slot]: fields } }
        const secondKey = test.rowKey
          .replace('node-1', 'node-2')
          .replace('backup-1', 'backup-2')
        const window = {
          rows: [
            row,
            {
              rowKey: secondKey,
              slots: {
                [test.slot]: {
                  ...fields,
                  [test.identity]: 'node-2',
                  [test.name]: 'East',
                },
              },
            },
          ],
          totalCount: 2,
          totalExact: true,
          limit: 25,
          rowsBefore: 0,
          sort: [{ field: test.sort, direction: 'desc' }],
          firstAnchor: {
            [test.sort]: fields[test.sort as keyof typeof fields],
          },
          lastAnchor: {
            [test.sort]: fields[test.sort as keyof typeof fields],
          },
        }
        socket.message('page_response', {
          page: test.page,
          payload: { windows: { [test.tableKey]: window } },
        })
        if (test.tableKey === 'hilosBackups') {
          // Backup opens with the reader-zone preset and requests that window.
          const request = socket.sent
            .map((text) => JSON.parse(text))
            .filter((frame) => frame.type === 'table_viewport')
            .at(-1)
          socket.message('table_window', {
            page: test.page,
            tableKey: test.tableKey,
            ...window,
            requestId: request.requestId,
          })
        }
        const controller = table.controller
        expect(
          controller.frame.columns
            .get()
            .find((column) => column.key === test.column)?.reads,
        ).toEqual(test.reads)
        const frames = socket.sent.map((text) => JSON.parse(text))
        const declarationType =
          test.tableKey === 'hilosBackups' ? 'table_viewport' : 'table_rendered'
        const declarations = frames.filter(
          (frame) => frame.type === declarationType,
        )
        expect(declarations).toHaveLength(1)
        expect(declarations[0]).toMatchObject({
          type: declarationType,
          page: test.page,
          tableKey: test.tableKey,
          rendered: controller.descriptor()?.rendered,
        })
        expect(declarations[0].rendered).toEqual(
          expect.arrayContaining([test.identity, test.name]),
        )
        const descriptor = controller.descriptor()
        const footer = controller.frame.footer.get()
        const sentCount = socket.sent.length
        for (const name of ['South', null]) {
          socket.message('table_viewport_delta', {
            page: test.page,
            tableKey: test.tableKey,
            kind: 'row_updated',
            rowKey: test.rowKey,
            row: {
              ...row,
              slots: { [test.slot]: { ...fields, [test.name]: name } },
            },
          })
          expect(controller.rows.get().map((view) => view.rowKey)).toEqual([
            test.rowKey,
            secondKey,
          ])
          expect(controller.rows.get()[0].row).toMatchObject({
            [test.identity]: 'node-1',
            [test.name]: name,
          })
          expect(controller.pendingCount.get()).toBe(0)
          expect(controller.descriptor()).toEqual(descriptor)
          expect(controller.frame.footer.get()).toEqual(footer)
          expect(socket.sent).toHaveLength(sentCount)
        }
      } finally {
        table.dispose()
        table.controller.dispose()
        connection.close()
      }
    },
  )
})
