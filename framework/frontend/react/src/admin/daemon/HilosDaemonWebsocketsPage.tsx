// The addressed node's WebSocket count, carried by its page scope.
import { useContext, useMemo } from 'react'
import {
  DAEMON_NODE_STATE_SILENT,
  formatDaemonNumber,
  readHilosDaemonNodeHeading,
  readHilosDaemonWebsockets,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { computedSignal, HilosPages } from '@hilos/core/base'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'

/** The project's existing connection and scopes, with optional log suppression. */
export interface HilosDaemonWebsocketsPageProps {
  context: HilosDaemonContext
  showLogLinks?: boolean
}

/** Render the count from this node page's single permitted answer. */
export function HilosDaemonWebsocketsPage({
  context,
  showLogLinks = true,
}: HilosDaemonWebsocketsPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosDaemonWebsocketsPage requires a provided Hilos router.',
    )
  const currentSource = useMemo(() => {
    const websockets = readHilosDaemonWebsockets(context.scopes)
    const nodeHeading = readHilosDaemonNodeHeading(context.scopes)
    return computedSignal(() => {
      const route = router.currentRoute.get()
      const heading = nodeHeading.get()
      return context.scopes.page()?.key === HilosPages.DAEMON_WEBSOCKETS &&
        route.page === HilosPages.DAEMON_WEBSOCKETS &&
        router.pageError.get() === null &&
        typeof route.params.nodeId === 'string' &&
        route.params.nodeId !== '' &&
        heading !== null
        ? { heading, websockets: websockets.get() }
        : null
    })
  }, [context, router])
  const current = useSignal(currentSource)
  const data = current?.websockets
  const count = data?.dataNode ? '—' : formatDaemonNumber(data?.connections)
  const dataNodeNote = 'Data nodes do not accept browsers'
  const waitingNote = 'Waiting for the node report'
  const silentNote = 'At the time of the last report'
  const liveNote = 'Open on this node right now'
  const note = data?.dataNode
    ? dataNodeNote
    : data?.connections == null
      ? waitingNote
      : current?.heading.state === DAEMON_NODE_STATE_SILENT
        ? silentNote
        : liveNote
  return (
    <HilosDaemonNodeShell
      context={context}
      page={HilosPages.DAEMON_WEBSOCKETS}
      showLogLinks={showLogLinks}
    >
      <div className="row row-cols-1 row-cols-md-2 g-2 mb-3">
        <div className="col">
          <div
            className="border rounded-3 p-3 h-100"
            data-id="hilos-daemon-websockets-tile"
          >
            <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
              <i className="bi bi-plug" aria-hidden="true" />
              <span className="small">WebSocket connections now</span>
            </div>
            <div
              className="fs-5 lh-1 mb-1 text-break"
              data-id="hilos-daemon-websockets-count"
            >
              {count}
            </div>
            {/* Every known caption holds its room in the same grid cell. */}
            <div className="hilos-stack small text-body-secondary">
              {[dataNodeNote, waitingNote, silentNote, liveNote].map(
                (message) => (
                  <div
                    key={message}
                    className="invisible"
                    aria-hidden="true"
                    inert
                  >
                    {message}
                  </div>
                ),
              )}
              <div data-id="hilos-daemon-websockets-note">{note}</div>
            </div>
          </div>
        </div>
      </div>
    </HilosDaemonNodeShell>
  )
}
