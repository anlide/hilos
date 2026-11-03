import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import {
  CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  createHilosChangeLogTableTriggersTable,
  formatChangeLogTrigger,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableTriggersTable,
} from '@hilos/core/admin/change-log'
import { computedSignal, HilosPages } from '@hilos/core/base'

import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogTableShell } from './HilosChangeLogTableShell.js'

/** The current trigger window and the generation owning its viewport. */
interface TriggersWindow {
  readonly table: HilosChangeLogTableTriggersTable
  readonly generation: number
}

/** @param props Project connection and page scope. */
export function HilosChangeLogTableTriggersPage({
  context,
}: {
  context: HilosChangeLogReceiptContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosChangeLogTableTriggersPage requires a provided Hilos router.',
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
        context.scopes.page()?.key !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS ||
        currentRoute.page !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS ||
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
  const [viewport, setViewport] = useState<TriggersWindow | null>(null)
  const generations = useRef(0)

  // A new scope is a new answer even at the same address. Bind after the
  // router publishes its route and clears the buffered departing answer.
  useEffect(() => {
    if (tableName === undefined || tableName !== tableId) return
    const next = createHilosChangeLogTableTriggersTable(context, {
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
      page={HilosPages.CHANGE_LOG_TABLE_TRIGGERS}
    >
      {viewport && (
        <section className="mb-4" data-id="hilos-change-log-table-triggers">
          <p className="small text-body-secondary mb-2">{copy.triggersLead}</p>
          <HilosViewportTable
            key={viewport.generation}
            controller={viewport.table.controller}
            cells={{
              [CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD]: (row) => (
                <code className="text-break">{row.name}</code>
              ),
              [CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD]: () => (
                <span className="badge bg-body-secondary text-body border">
                  {copy.service}
                </span>
              ),
              [CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD]: (row) => (
                <span className="small text-nowrap">
                  {formatChangeLogTrigger(row).fires}
                </span>
              ),
              [CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD]: (row) => (
                <span className="small">
                  {formatChangeLogTrigger(row).writesTo}
                </span>
              ),
              [CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD]: (row) => (
                <span className="small text-nowrap">
                  {formatChangeLogTrigger(row).validFrom}
                </span>
              ),
            }}
          />
          <p className="small text-body-secondary mt-2 mb-0">
            {copy.triggersFootnote}
          </p>
        </section>
      )}
    </HilosChangeLogTableShell>
  )
}
