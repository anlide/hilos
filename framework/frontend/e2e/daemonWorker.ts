import type { Page } from '@playwright/test'
import { watchSocketFrames } from './socket.js'

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
 * Read the selected worker only from the real Workers page's table windows.
 *
 * The table shows one page of rows at a time, ordered by worker index, and a
 * node can run more workers than one page holds. While the worker has not
 * arrived and the page shown says more rows follow, press Next as a person
 * would. The page's first answer carries an empty window before the table asks
 * for its rows, and Next may first echo the page it leaves, so only a window
 * with rows that starts no earlier than the last one counts.
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
  // Rows of the set before the latest page shown, or -1 until one arrives.
  let pageStart = -1
  let morePages = false
  const observer = watchSocketFrames(page, (frame) => {
    if (frame.raw === undefined) return
    if (frame.wire === undefined)
      throw new Error('Invalid Workers socket evidence')
    const envelope = record(frame.wire)
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
    if (
      window.rows.length > 0 &&
      window.totalExact === true &&
      typeof window.rowsBefore === 'number' &&
      typeof window.totalCount === 'number' &&
      window.rowsBefore >= pageStart
    ) {
      pageStart = window.rowsBefore
      morePages = window.rowsBefore + window.rows.length < window.totalCount
    }
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
  try {
    await gotoWorkers(page)
    await waitFor(() => worker !== undefined || pageStart >= 0)
    while (worker === undefined && morePages) {
      const left = pageStart
      await page.getByTestId('hilos-table-next').click()
      await waitFor(() => worker !== undefined || pageStart > left)
    }
    if (worker === undefined)
      throw new Error('The selected monopolistic worker never arrived')
    return worker
  } finally {
    observer.stop()
  }
}
