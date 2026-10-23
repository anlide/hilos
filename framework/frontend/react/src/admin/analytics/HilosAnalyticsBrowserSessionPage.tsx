// HilosAnalyticsBrowserSessionPage — one browser session's page in the Analytics
// section (HilosPages.ANALYTICS_BROWSER_SESSION): the session's header and the
// actions recorded in it. The admin shell draws the one heading (the session's
// number from the page's answer), the lead, and the table's accessible name; the
// header comes whole with that answer, and the table is the core's actions
// viewport, preset to the session in the address. A viewer of the admin view
// mode sees Hidden in place of the header and of every value. The core owns the
// words, the table and the header, so a project mounts this view by passing its
// HilosAnalyticsContext. Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo } from 'react'
import {
  ANALYTICS_ACTION_CREATED_TS_FIELD,
  ANALYTICS_ACTION_NAME_FIELD,
  ANALYTICS_ACTION_PAGE_NAME_FIELD,
  ANALYTICS_BROWSER_SESSION_COPY,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  createHilosAnalyticsActionsTable,
  formatAnalyticsLastSignedIn,
  formatAnalyticsTime,
  hilosAnalyticsBrowserSession,
  readAnalyticsRouteId,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosAnalyticsBrowserSessionPage}. */
export interface HilosAnalyticsBrowserSessionPageProps {
  /** The project's connection and page scope. */
  context: HilosAnalyticsContext
}

/**
 * The browser-session page of the Analytics section: the header and the
 * session's actions.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosAnalyticsBrowserSessionPage({
  context,
}: HilosAnalyticsBrowserSessionPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosAnalyticsBrowserSessionPage requires a provided Hilos router.',
    )
  }

  const route = useSignal(router.currentRoute)
  useSignal(hilosTimeZone)
  const browserSessionId = readAnalyticsRouteId(
    route.params[ANALYTICS_BROWSER_SESSION_ROUTE_PARAM],
  )
  const header = useSignal(
    useMemo(
      () => hilosAnalyticsBrowserSession(context.scopes),
      [context.scopes],
    ),
  )
  const actions = useMemo(
    () =>
      browserSessionId === null
        ? null
        : createHilosAnalyticsActionsTable(context, {
            page: HilosPages.ANALYTICS_BROWSER_SESSION,
            browserSessionId,
          }),
    [context, browserSessionId],
  )

  // Bind the session's table after the render that published its route, once
  // the router has dropped the previous page's buffered answer, and release it
  // when the number changes, disappears, or the page unmounts.
  useEffect(() => {
    if (actions === null) {
      return
    }
    actions.start()

    return () => actions.dispose()
  }, [actions])

  return (
    <HilosAdminPage page={HilosPages.ANALYTICS_BROWSER_SESSION}>
      <div
        data-id="hilos-analytics-browser-session-header"
        className="mb-4 text-break"
      >
        {header === undefined ? (
          <HilosSkeleton lines={[6, 8]} />
        ) : (
          <HilosHideable value={header}>
            {(value) => (
              <>
                <p
                  data-id="hilos-analytics-browser-session-browser"
                  className="mb-2"
                >
                  {value.browser ?? ANALYTICS_NO_VALUE}
                </p>
                <p
                  data-id="hilos-analytics-browser-session-person"
                  className="mb-0"
                >
                  {value.lastSignedInUserId !== null ? (
                    <>
                      <span>
                        {ANALYTICS_BROWSER_SESSION_COPY.lastSignedIn}:
                      </span>{' '}
                    </>
                  ) : null}
                  {formatAnalyticsLastSignedIn(value)}
                </p>
              </>
            )}
          </HilosHideable>
        )}
      </div>
      {actions !== null ? (
        <HilosViewportTable
          key={browserSessionId}
          controller={actions.controller}
          dataId="hilos-analytics-actions-table"
          cells={{
            [ANALYTICS_ACTION_CREATED_TS_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-action-when"
                data-record={row.rowKey}
              >
                <HilosHideable value={row.createdTs}>
                  {(value) => (
                    <span data-created-ts={value}>
                      {formatAnalyticsTime(value)}
                    </span>
                  )}
                </HilosHideable>
              </span>
            ),
            [ANALYTICS_ACTION_PAGE_NAME_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-action-page"
                data-record={row.rowKey}
                className="text-break"
              >
                <HilosHideable value={row.pageName}>
                  {(value) => value ?? ANALYTICS_NO_VALUE}
                </HilosHideable>
              </span>
            ),
            [ANALYTICS_ACTION_NAME_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-action-name"
                data-record={row.rowKey}
                className="text-break"
              >
                <HilosHideable value={row.actionName} />
              </span>
            ),
          }}
        />
      ) : null}
    </HilosAdminPage>
  )
}
