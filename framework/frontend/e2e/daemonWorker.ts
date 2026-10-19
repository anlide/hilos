import type { Page, WebSocket } from '@playwright/test'

export interface MonopolisticWorker {
  readonly index: number
  readonly pid: number
  readonly kind: string
  readonly agentIds: readonly string[]
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * Read the selected worker only from the real Workers page's table window.
 *
 * @param page An administrator's page, before navigation to Workers.
 * @param agentId The one agent whose monopolistic worker is selected.
 * @param gotoWorkers Navigate through the consumer's existing page helper.
 * @param waitFor Wait for the predicate using the consumer's Playwright runtime.
 */
export async function readMonopolisticWorker(
  page: Page,
  agentId: string,
  gotoWorkers: (page: Page) => Promise<void>,
  waitFor: (predicate: () => boolean) => Promise<void>,
): Promise<MonopolisticWorker> {
  let worker: MonopolisticWorker | undefined
  const watch = (socket: WebSocket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return
      let envelope: Record<string, unknown>
      try {
        envelope = record(JSON.parse(frame.payload))
      } catch {
        return
      }
      const data = record(envelope.data)
      if (data.page !== 'hilos_daemon_workers') return
      const window =
        envelope.type === 'page_response'
          ? record(record(record(data.payload).windows).hilosDaemonWorkers)
          : data.tableKey === 'hilosDaemonWorkers' &&
              envelope.type === 'table_window'
            ? data
            : {}
      if (!Array.isArray(window.rows)) return
      for (const row of window.rows) {
        const fields = record(record(record(row).slots).worker)
        if (
          fields.kind !== 'monopolistic' ||
          !Array.isArray(fields.agentIds) ||
          fields.agentIds.length !== 1 ||
          fields.agentIds[0] !== agentId
        )
          continue
        if (
          !Number.isInteger(fields.index) ||
          typeof fields.index !== 'number' ||
          fields.index <= 0 ||
          !Number.isInteger(fields.pid) ||
          typeof fields.pid !== 'number' ||
          fields.pid <= 1
        )
          throw new Error(
            'The Workers window carries an invalid monopolistic worker identity',
          )
        worker = {
          index: fields.index,
          pid: fields.pid,
          kind: fields.kind,
          agentIds: [agentId],
        }
      }
    })
  }
  page.on('websocket', watch)
  try {
    await gotoWorkers(page)
    await waitFor(() => worker !== undefined)
    if (worker === undefined)
      throw new Error('The selected monopolistic worker never arrived')
    return worker
  } finally {
    page.off('websocket', watch)
  }
}
