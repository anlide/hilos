// Browser sessions overlapping the selected period. The core owns the captured
// calendar context, the Period filter and its address, facets and paging; the
// shared head and table own navigation and mobile cards, so this page supplies
// cells only. Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useRef } from 'react'
import {
  ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  ANALYTICS_PERSON_ROUTE_PARAM,
  createHilosAnalyticsBrowserSessionListTable,
  formatAnalyticsBrowserSessionListPerson,
  formatAnalyticsBrowserSessionListTime,
  type HilosAnalyticsBrowserSessionListRow,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages, isHiddenValue } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** Props for {@link HilosAnalyticsBrowserSessionsPage}. */
export interface HilosAnalyticsBrowserSessionsPageProps {
  /** The project's connection and page scope. */
  context: HilosAnalyticsContext
}

/**
 * The Analytics Browser sessions page: sessions seen in the selected period.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosAnalyticsBrowserSessionsPage({
  context,
}: HilosAnalyticsBrowserSessionsPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosAnalyticsBrowserSessionsPage requires a provided Hilos router.',
    )
  }

  // The factory reads the route the page opened on and rewrites the address
  // of a Period change itself; no React copy of the filter is kept.
  const sessions = useMemo(
    () =>
      createHilosAnalyticsBrowserSessionListTable(context, {
        currentRoute: router.currentRoute,
        replacePath: (path) => router.replacePath(path),
      }),
    [context, router],
  )
  const lifecycle = useRef({ sessions, generation: 0 })

  // A table controller's disposal is final. Defer cleanup by one microtask so
  // React StrictMode's setup/cleanup/setup replay can reuse the live controller;
  // a real unmount or a context or router replacement still disposes it.
  useEffect(() => {
    lifecycle.current.sessions = sessions
    const generation = ++lifecycle.current.generation
    sessions.start()

    return () => {
      queueMicrotask(() => {
        const current = lifecycle.current
        if (
          current.sessions !== sessions ||
          current.generation === generation
        ) {
          sessions.dispose()
        }
      })
    }
  }, [sessions])

  // Neither account field may contribute to an address while either is hidden.
  const personPath = (
    row: HilosAnalyticsBrowserSessionListRow,
  ): string | undefined => {
    if (
      isHiddenValue(row.lastSignedInUserId) ||
      isHiddenValue(row.lastSignedInUserName) ||
      row.lastSignedInUserId === null
    )
      return undefined
    return router.resolvePath(HilosPages.ANALYTICS_PERSON, {
      [ANALYTICS_PERSON_ROUTE_PARAM]: String(row.lastSignedInUserId),
    })
  }

  return (
    <HilosAdminPage
      page={HilosPages.ANALYTICS_BROWSER_SESSIONS}
      headKind="section"
      tabsLabel="Analytics pages"
    >
      <HilosViewportTable
        controller={sessions.controller}
        dataId="hilos-analytics-browser-session-list-table"
        cells={{
          [ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD]: (row) => {
            const path = router.resolvePath(
              HilosPages.ANALYTICS_BROWSER_SESSION,
              { [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(row.rowKey) },
            )

            return path ? (
              <HilosLink
                to={path}
                data-id="hilos-analytics-browser-session-list-session"
                data-record={row.rowKey}
              >
                #{row.rowKey}
              </HilosLink>
            ) : (
              <span
                data-id="hilos-analytics-browser-session-list-session"
                data-record={row.rowKey}
              >
                #{row.rowKey}
              </span>
            )
          },
          [ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD]: (
            row,
          ) => (
            <span
              className="text-break"
              data-id="hilos-analytics-browser-session-list-person"
              data-record={row.rowKey}
            >
              <HilosHideable
                value={formatAnalyticsBrowserSessionListPerson(row)}
              >
                {(value) => {
                  const path = personPath(row)
                  return path ? <HilosLink to={path}>{value}</HilosLink> : value
                }}
              </HilosHideable>
            </span>
          ),
          [ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD]: (row) => (
            <span
              className="text-break"
              data-id="hilos-analytics-browser-session-list-browser"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.browser}>
                {(value) => value ?? ANALYTICS_NO_VALUE}
              </HilosHideable>
            </span>
          ),
          [ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD]: (row) => (
            <span
              className="text-nowrap"
              data-id="hilos-analytics-browser-session-list-first-seen"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.firstSeenTs}>
                {(value) =>
                  formatAnalyticsBrowserSessionListTime(
                    value,
                    sessions.periodContext,
                  )
                }
              </HilosHideable>
            </span>
          ),
          [ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD]: (row) => (
            <span
              className="text-nowrap"
              data-id="hilos-analytics-browser-session-list-last-seen"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.lastSeenTs}>
                {(value) =>
                  formatAnalyticsBrowserSessionListTime(
                    value,
                    sessions.periodContext,
                  )
                }
              </HilosHideable>
            </span>
          ),
          [ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD]: (row) => (
            <span
              data-id="hilos-analytics-browser-session-list-actions"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.actionCount} />
            </span>
          ),
        }}
      />
    </HilosAdminPage>
  )
}
