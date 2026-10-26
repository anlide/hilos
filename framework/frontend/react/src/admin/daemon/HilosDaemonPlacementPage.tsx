import { useEffect, useMemo } from 'react'
import {
  createHilosDaemonPlacementView,
  readHilosDaemonOverview,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonPlacement } from './HilosDaemonPlacement.js'

/** Props for the separate agent placement page. */
export interface HilosDaemonPlacementPageProps {
  context: HilosDaemonContext
}

/**
 * @param props The project's connection and page scope.
 */
export function HilosDaemonPlacementPage({
  context,
}: HilosDaemonPlacementPageProps) {
  const overviewSignal = useMemo(
    () => readHilosDaemonOverview(context.scopes),
    [context],
  )
  const placement = useMemo(
    () => createHilosDaemonPlacementView(context, overviewSignal),
    [context, overviewSignal],
  )
  const overview = useSignal(overviewSignal)
  useEffect(() => {
    placement.start()
    return () => placement.dispose()
  }, [placement])
  return (
    <HilosAdminPage
      page={HilosPages.DAEMON_PLACEMENT}
      headKind="section"
      tabsLabel="Daemon pages"
    >
      <HilosDaemonPlacement view={placement} overview={overview} />
    </HilosAdminPage>
  )
}
