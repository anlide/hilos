// HilosStatusComponentsPage — the Components tab of the framework Status
// section inside the admin shell. React counterpart of the Vue page: the shared
// section head with the Status tabs over the standard read-only server
// viewport, with its search, state filter and live/Apply strip. The core table
// owns the frame, the window and the row projection — a source gone stale reads
// as a neutral Unknown, never as a healthy state. The table is created and
// started in the effect and disposed by the cleanup of that same run, so a
// StrictMode replay or a new context gets a live table of its own and a
// discarded render leaves no listener behind. Bootstrap classes only.
import { useEffect, useState } from 'react'
import { HilosPages } from '@hilos/core/base'
import {
  createHilosStatusComponentsTable,
  HILOS_STATUS_COMPONENTS_COPY,
  STATUS_COMPONENT_AGENT_TYPE_FIELD,
  STATUS_COMPONENT_NAME_FIELD,
  STATUS_COMPONENT_STATE_FIELD,
  type HilosStatusComponentRow,
  type HilosStatusComponentsContext,
  type HilosStatusComponentsTable,
} from '@hilos/core/admin/status'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Props for {@link HilosStatusComponentsPage}. */
export interface HilosStatusComponentsPageProps {
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusComponentsContext
}

/** The badge of a component's state: its own tone, and a neutral one for Unknown. */
const STATE_CLASSES: Record<HilosStatusComponentRow['state'], string> = {
  operational: 'text-bg-success',
  checking: 'text-bg-secondary',
  partial_outage: 'text-bg-warning',
  major_outage: 'text-bg-danger',
  maintenance: 'text-bg-info',
  unknown: 'bg-body-secondary text-body-emphasis',
}

/**
 * The declared Status components and their current state, read only.
 *
 * @param props The project's scope, connection and action lifecycle.
 */
export function HilosStatusComponentsPage({
  context,
}: HilosStatusComponentsPageProps) {
  const [table, setTable] = useState<HilosStatusComponentsTable | null>(null)

  useEffect(() => {
    const created = createHilosStatusComponentsTable(context)
    created.start()
    setTable(created)

    return () => {
      created.dispose()
      setTable((current) => (current === created ? null : current))
    }
  }, [context])

  return (
    <HilosAdminPage
      page={HilosPages.STATUS_COMPONENTS}
      headKind="section"
      tabsLabel="Status pages"
    >
      {table && (
        <HilosViewportTable
          controller={table.controller}
          dataId="hilos-status-components-table"
          cells={{
            [STATUS_COMPONENT_NAME_FIELD]: (row) => (
              <div
                data-id={`hilos-status-component-${row.componentKey}`}
                className="text-break"
              >
                <span className="fw-semibold">{row.name}</span>
                {row.description !== null && (
                  <div className="small text-body-secondary">
                    {row.description}
                  </div>
                )}
              </div>
            ),
            [STATUS_COMPONENT_AGENT_TYPE_FIELD]: (row) => (
              <code className="text-break">{row.agentType}</code>
            ),
            [STATUS_COMPONENT_STATE_FIELD]: (row) => (
              <span
                className={`badge text-wrap ${STATE_CLASSES[row.state]}`}
                data-id={`hilos-status-component-state-${row.componentKey}`}
              >
                {HILOS_STATUS_COMPONENTS_COPY.states[row.state]}
              </span>
            ),
          }}
        />
      )}
    </HilosAdminPage>
  )
}
