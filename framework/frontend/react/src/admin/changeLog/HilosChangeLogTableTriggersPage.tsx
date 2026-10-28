import { useContext, useId, useMemo } from 'react'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  formatChangeLogTrigger,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import type { HilosChangeLogReceiptContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** @param props Project connection and page scope. */
export function HilosChangeLogTableTriggersPage({
  context,
}: {
  context: HilosChangeLogReceiptContext
}) {
  const router = useContext(HilosRouterContext)!
  const route = useSignal(router.currentRoute)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  const triggersHeadingId = useId()
  const overviewPath = router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]:
      route.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? '',
  })
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLE_TRIGGERS}>
      <p>
        {overviewPath !== undefined && (
          <HilosLink
            to={overviewPath}
            data-id="hilos-change-log-table-overview"
          >
            Overview
          </HilosLink>
        )}
      </p>
      {table !== null && (
        <>
          <section className="mb-4" data-id="hilos-change-log-table-triggers">
            <h2 id={triggersHeadingId} className="h5">
              {copy.triggers}
            </h2>
            <p className="small text-body-secondary mb-2">
              {copy.triggersLead}
            </p>
            <div className="table-responsive d-none d-md-block">
              <table
                className="table table-sm align-middle mb-0"
                aria-labelledby={triggersHeadingId}
              >
                <thead>
                  <tr>
                    <th scope="col">{copy.trigger}</th>
                    <th scope="col">{copy.owner}</th>
                    <th scope="col">{copy.fires}</th>
                    <th scope="col">{copy.writesTo}</th>
                    <th scope="col">{copy.validFrom}</th>
                  </tr>
                </thead>
                <tbody>
                  {table.triggers.map((trigger) => (
                    <tr key={trigger.name}>
                      <td>
                        <code className="text-break">{trigger.name}</code>
                      </td>
                      <td>
                        <span className="badge bg-body-secondary text-body border">
                          {copy.service}
                        </span>
                      </td>
                      <td className="small text-nowrap">
                        {formatChangeLogTrigger(trigger).fires}
                      </td>
                      <td className="small">
                        {formatChangeLogTrigger(trigger).writesTo}
                      </td>
                      <td className="small text-nowrap">
                        {formatChangeLogTrigger(trigger).validFrom}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div
              className="d-md-none"
              role="list"
              aria-labelledby={triggersHeadingId}
            >
              {table.triggers.map((trigger) => (
                <div key={trigger.name} className="card mb-2" role="listitem">
                  <div className="card-body py-2 px-3">
                    <div className="d-flex flex-wrap align-items-center gap-1 fw-medium mb-1">
                      <code className="text-break">{trigger.name}</code>
                      <span className="badge bg-body-secondary text-body border">
                        {copy.service}
                      </span>
                    </div>
                    <dl className="row mb-0 small g-0">
                      <dt className="col-5 fw-normal text-body-secondary">
                        {copy.fires}
                      </dt>
                      <dd className="col-7 mb-0">
                        {formatChangeLogTrigger(trigger).fires}
                      </dd>
                      <dt className="col-5 fw-normal text-body-secondary">
                        {copy.writesTo}
                      </dt>
                      <dd className="col-7 mb-0">
                        {formatChangeLogTrigger(trigger).writesTo}
                      </dd>
                      <dt className="col-5 fw-normal text-body-secondary">
                        {copy.validFrom}
                      </dt>
                      <dd className="col-7 mb-0">
                        {formatChangeLogTrigger(trigger).validFrom}
                      </dd>
                    </dl>
                  </div>
                </div>
              ))}
            </div>
            <p className="small text-body-secondary mt-2 mb-0">
              {copy.triggersFootnote}
            </p>
          </section>
        </>
      )}
    </HilosAdminPage>
  )
}
