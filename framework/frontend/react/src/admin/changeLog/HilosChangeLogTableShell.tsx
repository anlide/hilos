import { useContext, useMemo, type ReactNode } from 'react'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogTableJournalSince,
  formatChangeLogTableOwner,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import { computedSignal, HilosPages } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/** The current table page supplies its body under the shared entity head. */
interface HilosChangeLogTableShellProps {
  context: HilosChangeLogReceiptContext
  page: string
  children?: ReactNode
}

/** Present only the current page's permitted table declaration and name. */
export function HilosChangeLogTableShell({
  context,
  page,
  children,
}: HilosChangeLogTableShellProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosChangeLogTableShell requires a provided Hilos router.',
    )
  const currentSource = useMemo(() => {
    const section = context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    return computedSignal(() => {
      const scopePage = context.scopes.page()?.key
      const route = router.currentRoute.get()
      if (
        (scopePage !== HilosPages.CHANGE_LOG_TABLE &&
          scopePage !== HilosPages.CHANGE_LOG_TABLE_COLUMNS &&
          scopePage !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS &&
          scopePage !== HilosPages.CHANGE_LOG_TABLE_HISTORY) ||
        route.page !== scopePage ||
        router.pageError.get() !== null
      )
        return null
      const table = readHilosChangeLogTable(section.get())
      return table?.name === route.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
        ? table
        : null
    })
  }, [context, router])
  const name = useMemo(
    () => computedSignal(() => currentSource.get()?.name),
    [currentSource],
  )
  usePageInstanceName(context.scopes, HilosPages.CHANGE_LOG_TABLE, name)
  const table = useSignal(currentSource)
  const since = table ? changeLogTableJournalSince(table) : null
  return (
    <HilosAdminPage
      page={page}
      headKind="entity"
      headTitleClass="font-monospace fs-5"
      tabsLabel="Table pages"
      headVisual={
        table ? (
          <span
            className="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-change-log-table-head-icon"
          >
            <i className="bi bi-table" />
          </span>
        ) : undefined
      }
      headBadges={
        table ? (
          <span
            className="d-inline-flex flex-wrap gap-2"
            data-id="hilos-change-log-table-head-badges"
          >
            <span className="badge bg-body-secondary text-body border">
              {formatChangeLogTableOwner(table.owner)}
            </span>
            {table.journaled && (
              <span className="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                <i className="bi bi-journal-check me-1" aria-hidden="true" />
                {copy.journaled}
              </span>
            )}
          </span>
        ) : undefined
      }
      headDescription={
        table ? (
          <p
            className="text-body-secondary mb-0"
            data-id="hilos-change-log-table-head-description"
          >
            {!table.journaled
              ? copy.untracked
              : since !== null
                ? `Journaled since migration ${String(since).padStart(3, '0')}`
                : null}
          </p>
        ) : undefined
      }
    >
      {table ? children : null}
    </HilosAdminPage>
  )
}
