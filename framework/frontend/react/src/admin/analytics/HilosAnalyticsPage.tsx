// HilosAnalyticsPage — the Analytics section overview (HilosPages.ANALYTICS):
// three aggregates from the core's captured snapshot. The shared head draws
// the catalog identity; counts and periods stay together for this page opening.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import {
  analyticsOverviewTiles,
  createHilosAnalyticsOverview,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosAnalyticsPage}. */
export interface HilosAnalyticsPageProps {
  /** The project's connection and page scope. */
  context: HilosAnalyticsContext
}

/**
 * The Analytics section overview: visitors this week, today, and this hour
 * from the first valid answer.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosAnalyticsPage({ context }: HilosAnalyticsPageProps) {
  const overview = useMemo(
    () => createHilosAnalyticsOverview(context.scopes),
    [context.scopes],
  )

  useEffect(() => {
    overview.start()
    return () => overview.dispose()
  }, [overview])

  const snapshot = useSignal(overview.snapshot)
  const tiles = useMemo(
    () =>
      snapshot === undefined ? undefined : analyticsOverviewTiles(snapshot),
    [snapshot],
  )

  return (
    <HilosAdminPage
      page={HilosPages.ANALYTICS}
      headKind="section"
      tabsLabel="Analytics pages"
    >
      {tiles === undefined ? (
        <div
          className="row row-cols-1 row-cols-sm-3 g-3 placeholder-glow"
          aria-hidden="true"
          data-id="hilos-analytics-overview-skeleton"
        >
          {['week', 'day', 'hour'].map((key) => (
            <div key={key} className="col">
              <div className="border rounded-3 p-3 h-100">
                <span className="placeholder col-7 d-block mb-2 rounded"></span>
                <span className="placeholder placeholder-lg col-3 d-block mb-2 rounded"></span>
                <span className="placeholder col-9 d-block rounded"></span>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div
          className="row row-cols-1 row-cols-sm-3 g-3 mb-4"
          data-id="hilos-analytics-overview-tiles"
        >
          {tiles.map((tile) => (
            <div key={tile.key} className="col">
              <dl
                className="border rounded-3 p-3 h-100 mb-0"
                data-id={`hilos-analytics-visitors-${tile.key}`}
              >
                <dt className="small text-body-secondary fw-normal">
                  {tile.label}
                </dt>
                <dd
                  className="fs-4 fw-semibold lh-sm my-1"
                  data-id={`hilos-analytics-visitors-${tile.key}-count`}
                >
                  {tile.count}
                </dd>
                <dd
                  className="small text-body-secondary mb-0"
                  data-id={`hilos-analytics-visitors-${tile.key}-since`}
                >
                  {tile.since}
                </dd>
              </dl>
            </div>
          ))}
        </div>
      )}
    </HilosAdminPage>
  )
}
