import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import {
  CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogColumnModeCard,
  createHilosChangeLogTableColumnsTable,
  formatChangeLogColumnStorage,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import { computedSignal, HilosPages } from '@hilos/core/base'
import type {
  HilosChangeLogReceiptContext,
  HilosChangeLogTableColumn,
  HilosChangeLogTableColumnsTable,
} from '@hilos/core/admin/change-log'

import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogTableShell } from './HilosChangeLogTableShell.js'

/** The current schema window and the generation owning its viewport. */
interface ColumnsWindow {
  readonly table: HilosChangeLogTableColumnsTable
  readonly generation: number
}

/**
 * Draw the badge of the mode a column enters the journal with, or a dash.
 *
 * @param mode The column's declared mode, or null for an untracked table.
 */
function modeBadge(mode: HilosChangeLogTableColumn['mode']): ReactNode {
  const card = changeLogColumnModeCard(mode)
  return card ? (
    <span className={`badge text-wrap ${card.tone}`}>
      <i className={`bi ${card.icon} me-1`} aria-hidden="true" />
      {card.label}
    </span>
  ) : (
    '—'
  )
}

/** @param props Project connection and page scope. */
export function HilosChangeLogTableColumnsPage({
  context,
}: {
  context: HilosChangeLogReceiptContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosChangeLogTableColumnsPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const tableId = route.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? ''
  const scope = useSignal(
    useMemo(() => computedSignal(() => context.scopes.page()), [context]),
  )
  const currentSource = useMemo(() => {
    const section = context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    return computedSignal(() => {
      const currentRoute = router.currentRoute.get()
      if (
        context.scopes.page()?.key !== HilosPages.CHANGE_LOG_TABLE_COLUMNS ||
        currentRoute.page !== HilosPages.CHANGE_LOG_TABLE_COLUMNS ||
        router.pageError.get() !== null
      )
        return null
      const table = readHilosChangeLogTable(section.get())
      return table?.name === currentRoute.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
        ? table
        : null
    })
  }, [context, router])
  const table = useSignal(currentSource)
  const tableName = table?.name
  const [viewport, setViewport] = useState<ColumnsWindow | null>(null)
  const generations = useRef(0)
  const hasNoise = table?.columns.some(({ mode }) => mode === 'noise') === true

  // A new scope is a new answer even at the same address. Bind after the
  // router publishes its route and clears the buffered departing answer.
  useEffect(() => {
    if (tableName === undefined || tableName !== tableId) return
    const next = createHilosChangeLogTableColumnsTable(context, {
      table: tableId,
    })
    next.start()
    generations.current += 1
    setViewport({ table: next, generation: generations.current })
    return () => {
      next.dispose()
      setViewport(null)
    }
  }, [context, scope, tableId, tableName])

  return (
    <HilosChangeLogTableShell
      context={context}
      page={HilosPages.CHANGE_LOG_TABLE_COLUMNS}
    >
      {viewport && (
        <section className="mb-4" data-id="hilos-change-log-table-columns">
          <HilosViewportTable
            key={viewport.generation}
            controller={viewport.table.controller}
            cells={{
              [CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD]: (row) => (
                <code className="text-break">{row.name}</code>
              ),
              [CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD]: (row) => (
                <span className="small">
                  {formatChangeLogColumnStorage(row)}
                </span>
              ),
              [CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD]: (row) =>
                modeBadge(row.mode),
            }}
          />
          {hasNoise && (
            <p className="small text-body-secondary mt-2 mb-0">
              {copy.noisyNote}
            </p>
          )}
        </section>
      )}
    </HilosChangeLogTableShell>
  )
}
