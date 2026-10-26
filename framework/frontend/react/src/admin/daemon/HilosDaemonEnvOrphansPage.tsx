// Uncataloged .env lines of one Daemon node. The table has no title of its own.
import { useContext, useEffect, useMemo } from 'react'
import {
  createHilosDaemonEnvOrphansTable,
  DAEMON_ENV_KEY_FIELD,
  DAEMON_ENV_SIMILAR_TO_FIELD,
  DAEMON_ENV_VALUE_FIELD,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  readHilosDaemonEnvOrphansPage,
  type HilosDaemonEnvContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'

/** Props for the framework's environment orphans page. */
export interface HilosDaemonEnvOrphansPageProps {
  context: HilosDaemonEnvContext
  showLogLinks?: boolean
}

/** @param props The project's connection and page scope. */
export function HilosDaemonEnvOrphansPage({
  context,
  showLogLinks = true,
}: HilosDaemonEnvOrphansPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosDaemonEnvOrphansPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  const orphans = useMemo(
    () => createHilosDaemonEnvOrphansTable(context, nodeId),
    [context, nodeId],
  )
  const page = useSignal(
    useMemo(() => readHilosDaemonEnvOrphansPage(context.scopes), [context]),
  )
  useEffect(() => {
    orphans.start()
    return () => {
      orphans.dispose()
    }
  }, [orphans])

  return (
    <HilosDaemonNodeShell
      context={context}
      showLogLinks={showLogLinks}
      page={HilosPages.DAEMON_ENV_ORPHANS}
    >
      <HilosViewportTable
        key={`orphans-${nodeId}`}
        controller={orphans.controller}
        cells={{
          [DAEMON_ENV_KEY_FIELD]: (row) => (
            <code className="fw-semibold text-break">{row.key}</code>
          ),
          [DAEMON_ENV_VALUE_FIELD]: (row) => (
            <span className="d-inline-flex align-items-center gap-2 flex-wrap">
              <HilosDaemonEnvValue
                view={formatDaemonEnvValue(row.value, 'string', true)}
              />
              <span className="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                not in catalog
              </span>
            </span>
          ),
          [DAEMON_ENV_SIMILAR_TO_FIELD]: (row) => (
            <span className="small text-body-secondary text-break">
              {formatDaemonEnvSimilarTo(row)}
            </span>
          ),
        }}
        empty={
          page.nodeSilent ? (
            <span data-id="hilos-daemon-env-orphans-empty-silent">
              Nothing is read while the node is silent.
            </span>
          ) : (
            <span data-id="hilos-daemon-env-orphans-empty">
              Every line in .env is declared in the catalog.
            </span>
          )
        }
      />
    </HilosDaemonNodeShell>
  )
}
