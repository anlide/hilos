// Shared node entity block and page-owned body.
import { useContext, useMemo, type ReactNode } from 'react'
import {
  daemonNodeLogPath,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  readHilosDaemonNodeHeading,
  readHilosDaemonNodeOverview,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { computedSignal, HilosPages, hilosTimeZone } from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/** Each node page supplies its own body under the answer’s head and tab row. */
interface HilosDaemonNodeShellProps {
  context: HilosDaemonContext
  page: string
  showLogLinks?: boolean
  children?: ReactNode
}

/** Present only the currently permitted node’s identity and heading. */
export function HilosDaemonNodeShell({
  context,
  page,
  showLogLinks = true,
  children,
}: HilosDaemonNodeShellProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonNodeShell requires a provided Hilos router.')
  const currentSource = useMemo(() => {
    const headingSource = readHilosDaemonNodeHeading(context.scopes)
    const overviewSource = readHilosDaemonNodeOverview(context.scopes)
    return computedSignal(() => {
      const scopePage = context.scopes.page()?.key
      const route = router.currentRoute.get()
      const heading = headingSource.get()
      if (
        scopePage !== HilosPages.DAEMON_NODE &&
        scopePage !== HilosPages.DAEMON_WORKERS &&
        scopePage !== HilosPages.DAEMON_AGENTS &&
        scopePage !== HilosPages.DAEMON_CRON &&
        scopePage !== HilosPages.DAEMON_HTTP_SERVER &&
        scopePage !== HilosPages.DAEMON_ENV &&
        scopePage !== HilosPages.DAEMON_ENV_ORPHANS
      )
        return null
      const nodeId = route.params.nodeId
      if (
        route.page !== scopePage ||
        router.pageError.get() !== null ||
        typeof nodeId !== 'string' ||
        nodeId === '' ||
        heading === null
      )
        return null
      const overview = overviewSource.get()
      if (
        scopePage === HilosPages.DAEMON_NODE &&
        overview !== null &&
        overview.nodeId !== nodeId
      )
        return null
      return { nodeId, heading }
    })
  }, [context, router])
  const name = useMemo(
    () => computedSignal(() => currentSource.get()?.nodeId),
    [currentSource],
  )
  // The hook re-renders this subtree before paint; the head reads the bound name.
  usePageInstanceName(context.scopes, HilosPages.DAEMON_NODE, name)
  const current = useSignal(currentSource)
  useSignal(hilosTimeZone)
  const heading = current?.heading
  const logPath =
    current && showLogLinks
      ? daemonNodeLogPath(router.resolvePath, current.nodeId)
      : undefined
  const silentSince = formatDaemonNodeSilentSince(heading ?? null, Date.now())
  return (
    <HilosAdminPage
      page={page}
      headKind="entity"
      breadcrumbLabel="Breadcrumb"
      tabsLabel="Node pages"
      headAction={
        heading?.clustered
          ? {
              page: HilosPages.DAEMON,
              params: {},
              label: 'Change on the diagram',
            }
          : undefined
      }
      headVisual={
        current ? (
          <span
            className="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-daemon-node-visual"
          >
            <i
              className={`bi ${heading?.clustered && heading.state === 'silent' ? 'bi-plug text-danger' : 'bi-hdd-network'}`}
            />
          </span>
        ) : undefined
      }
      headBadges={
        heading ? (
          heading.clustered ? (
            <>
              {heading.state !== null && (
                <span
                  className={`badge border ${stateClass(heading.state)}`}
                  data-id="hilos-daemon-node-state"
                >
                  {formatDaemonNodeState(heading.state)}
                </span>
              )}
              <span
                className="badge bg-body-tertiary text-body border"
                data-id="hilos-daemon-node-role"
              >
                Role: {heading.role ?? '—'}
              </span>
            </>
          ) : (
            <span
              className="badge bg-body-tertiary text-body border"
              data-id="hilos-daemon-node-solo"
            >
              Single installation
            </span>
          )
        ) : undefined
      }
      headDescription={
        current ? (
          <p
            className="small text-body-secondary mb-0 d-flex flex-wrap gap-3"
            data-id="hilos-daemon-node-description"
          >
            {heading?.clustered && silentSince && (
              <span data-id="hilos-daemon-node-silent">
                Silent since {silentSince}
              </span>
            )}
            {logPath && (
              <HilosLink to={logPath} data-id="hilos-daemon-node-log">
                Node log{' '}
                <i className="bi bi-box-arrow-up-right" aria-hidden="true" />
              </HilosLink>
            )}
          </p>
        ) : undefined
      }
    >
      {children}
    </HilosAdminPage>
  )
}

/** The backend’s cluster verdict is independent of the declared role. */
function stateClass(state: string): string {
  switch (state) {
    case 'leader':
      return 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
    case 'standby':
      return 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'
    case 'data':
      return 'bg-info-subtle text-info-emphasis border-info-subtle'
    case 'silent':
      return 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
    default:
      return 'bg-body-tertiary text-body'
  }
}
