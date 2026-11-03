// Cluster maintenance reports: the core owns the window, the facts, the row
// message of an unavailable report and the copy; this view presents them under
// Maintenance's shared section head. Read only — the order and window changes stay
// the core's, through the table's own Apply. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo } from 'react'
import {
  createHilosMaintenanceNodesTable,
  hilosMaintenanceNodePresentation,
  MAINTENANCE_NODE_ID_FIELD,
  MAINTENANCE_NODE_IS_LEADER_FIELD,
  MAINTENANCE_NODE_PHASE_FIELD,
  MAINTENANCE_NODE_QUIESCED_FIELD,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosNodeName } from '../HilosNodeName.js'

/** Props for {@link HilosMaintenanceNodesPage}. */
export interface HilosMaintenanceNodesPageProps {
  /** The project's shared maintenance context. */
  context: HilosMaintenanceContext
}

/**
 * What every cluster node reported about maintenance, read only.
 *
 * @param props The project's connection, scopes and action lifecycle.
 */
export function HilosMaintenanceNodesPage({
  context,
}: HilosMaintenanceNodesPageProps) {
  const nodes = useMemo(
    () => createHilosMaintenanceNodesTable(context),
    [context],
  )

  useEffect(() => {
    nodes.start()

    return () => nodes.dispose()
  }, [nodes])

  return (
    <HilosAdminPage
      page={HilosPages.MAINTENANCE_NODES}
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <HilosViewportTable
        controller={nodes.controller}
        dataId="hilos-maintenance-nodes-table"
        cells={{
          [MAINTENANCE_NODE_ID_FIELD]: (row) => (
            <span data-id={`hilos-maintenance-nodes-row-${row.nodeId}`}>
              <HilosNodeName nodeId={row.nodeId} nodeName={row.nodeName} />
            </span>
          ),
          [MAINTENANCE_NODE_QUIESCED_FIELD]: (row) => {
            const { quiesced } = hilosMaintenanceNodePresentation(row)

            return (
              <span
                className={
                  quiesced.tone ? `text-${quiesced.tone}-emphasis` : undefined
                }
              >
                {quiesced.text}
              </span>
            )
          },
          [MAINTENANCE_NODE_PHASE_FIELD]: (row) => {
            const { phase } = hilosMaintenanceNodePresentation(row)

            return (
              <span
                className={phase.badge ? 'badge text-bg-secondary' : undefined}
              >
                {phase.text}
              </span>
            )
          },
          [MAINTENANCE_NODE_IS_LEADER_FIELD]: (row) =>
            hilosMaintenanceNodePresentation(row).role,
        }}
      />
    </HilosAdminPage>
  )
}
