// People who signed into browser sessions in the selected period. The core
// owns the captured calendar context, Period filter, facets and paging; the
// shared table draws its desktop rows and mobile cards. Hidden names keep the
// public person-number link. Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useRef } from 'react'
import {
  ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
  ANALYTICS_PERSON_ROUTE_PARAM,
  ANALYTICS_PERSON_ROW_KEY_FIELD,
  ANALYTICS_PERSON_SESSION_COUNT_FIELD,
  createHilosAnalyticsPeopleTable,
  formatAnalyticsPeopleTime,
  formatAnalyticsPersonName,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages, isHiddenValue } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** Props for {@link HilosAnalyticsPeoplePage}. */
export interface HilosAnalyticsPeoplePageProps {
  /** The project's connection and page scope. */
  context: HilosAnalyticsContext
}

/**
 * The Analytics People page: people active in the selected period.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosAnalyticsPeoplePage({
  context,
}: HilosAnalyticsPeoplePageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosAnalyticsPeoplePage requires a provided Hilos router.',
    )
  }

  const people = useMemo(
    () =>
      createHilosAnalyticsPeopleTable(context, {
        page: HilosPages.ANALYTICS_PEOPLE,
      }),
    [context],
  )
  const lifecycle = useRef({ people, generation: 0 })

  // A table controller's disposal is final. Defer cleanup by one microtask so
  // React StrictMode's setup/cleanup/setup replay can reuse the live controller;
  // a real unmount or context replacement still disposes it.
  useEffect(() => {
    lifecycle.current.people = people
    const generation = ++lifecycle.current.generation
    people.start()

    return () => {
      queueMicrotask(() => {
        const current = lifecycle.current
        if (current.people !== people || current.generation === generation) {
          people.dispose()
        }
      })
    }
  }, [people])

  return (
    <HilosAdminPage
      page={HilosPages.ANALYTICS_PEOPLE}
      headKind="section"
      tabsLabel="Analytics pages"
    >
      <HilosViewportTable
        controller={people.controller}
        dataId="hilos-analytics-people-table"
        cells={{
          [ANALYTICS_PERSON_ROW_KEY_FIELD]: (row) => {
            const path = router.resolvePath(HilosPages.ANALYTICS_PERSON, {
              [ANALYTICS_PERSON_ROUTE_PARAM]: String(row.rowKey),
            })
            const name = formatAnalyticsPersonName(row)

            return (
              <span
                className="text-break"
                data-id="hilos-analytics-person-name"
                data-record={row.rowKey}
              >
                {path ? (
                  <HilosLink
                    to={path}
                    data-id="hilos-analytics-person-link"
                    data-record={row.rowKey}
                  >
                    {name}
                  </HilosLink>
                ) : (
                  <span
                    data-id="hilos-analytics-person-link"
                    data-record={row.rowKey}
                  >
                    {name}
                  </span>
                )}
                {isHiddenValue(row.name) ? (
                  <span className="ms-1">
                    {' '}
                    <HilosHideable value={row.name} />
                  </span>
                ) : null}
              </span>
            )
          },
          [ANALYTICS_PERSON_SESSION_COUNT_FIELD]: (row) => (
            <span
              data-id="hilos-analytics-person-sessions"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.sessionCount} />
            </span>
          ),
          [ANALYTICS_PERSON_LAST_SEEN_TS_FIELD]: (row) => (
            <span
              data-id="hilos-analytics-person-last-seen"
              data-record={row.rowKey}
            >
              <HilosHideable value={row.lastSeenTs}>
                {(value) =>
                  formatAnalyticsPeopleTime(value, people.periodContext)
                }
              </HilosHideable>
            </span>
          ),
        }}
      />
    </HilosAdminPage>
  )
}
