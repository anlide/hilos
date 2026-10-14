// Agents of one node, with a confirmation driven by the existing restart lifecycle.
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  createHilosDaemonAgentRestart,
  createHilosDaemonAgentsTable,
  DAEMON_AGENT_ID_FIELD,
  DAEMON_AGENT_WORKER_INDEX_FIELD,
  DAEMON_AGENT_PLACEMENT_FIELD,
  DAEMON_AGENT_OWNS_RT_FIELD,
  DAEMON_AGENT_OWNS_DB_FIELD,
  DAEMON_AGENT_PLACEMENT_POLICY,
  daemonAgentLogPath,
  daemonProcessesEmptyState,
  formatDaemonAgentKind,
  formatDaemonAgentOwnership,
  formatDaemonAgentWorker,
  formatDaemonNodeSilentSince,
  HilosPages,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonAgentRow,
  type HilosDaemonAgentsContext,
} from '@hilos/core'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useLookOnly } from '../../hilosLookOnly.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { HilosDaemonNodeLine } from './HilosDaemonNodeLine.js'

/** Props for the framework's agents page. */
export interface HilosDaemonAgentsPageProps {
  context: HilosDaemonAgentsContext
}

/** @param props The project's connection, page scope and action lifecycle. */
export function HilosDaemonAgentsPage({ context }: HilosDaemonAgentsPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonAgentsPage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  return (
    <HilosAdminPage page={HilosPages.DAEMON_AGENTS}>
      <Agents key={nodeId} context={context} nodeId={nodeId} />
    </HilosAdminPage>
  )
}

/** The controls read the look-only scope supplied by the admin page above them.
 * @param props The project's context and the route's node.
 */
function Agents({
  context,
  nodeId,
}: HilosDaemonAgentsPageProps & { nodeId: string }) {
  const agents = useMemo(
    () => createHilosDaemonAgentsTable(context, nodeId),
    [context, nodeId],
  )
  const restart = useMemo(
    () => createHilosDaemonAgentRestart(context, agents.controller, nodeId),
    [context, agents, nodeId],
  )
  const heading = useSignal(
    useMemo(() => readHilosDaemonNodeHeading(context.scopes), [context]),
  )
  const reported = useSignal(
    useMemo(() => readDaemonProcessesReported(context.scopes), [context]),
  )
  const rows = useSignal(agents.controller.rows)
  const tableBody = useSignal(agents.controller.frame.body)
  const frozenSince = useSignal(agents.controller.frozenSince)
  const restartOpen = useSignal(restart.opened)
  const restartTarget = useSignal(restart.target)
  const canRestart = useSignal(restart.canSubmit)
  const restartAction = useTrackedAction()
  const { busy, loading } = restartAction
  const [identity, setIdentity] = useState<HilosDaemonAgentRow | null>(null)
  const lookOnly = useLookOnly()
  const unavailable =
    heading === null ||
    heading.state === 'silent' ||
    tableBody === 'loading' ||
    tableBody === 'unavailable' ||
    frozenSince !== null
  const restartUnavailable =
    unavailable || restartTarget === undefined || restartTarget.restarting
  const policyWarning =
    heading?.clustered === true &&
    identity?.placement === DAEMON_AGENT_PLACEMENT_POLICY
  const restartCommand =
    `php cli.php agent:restart ${identity?.agentId ?? ''}` +
    (heading?.clustered === true ? ` --node=${nodeId}` : '')
  const emptyState = daemonProcessesEmptyState(heading, reported)
  const silentSince = formatDaemonNodeSilentSince(heading, Date.now())

  useEffect(() => {
    agents.start()
    return () => {
      restart.close()
      // Closing a pending confirmation is refused; leaving its page still releases focus.
      agents.controller.releaseFocus()
      agents.dispose()
    }
  }, [agents, restart])

  function openRestart(row: HilosDaemonAgentRow): void {
    if (lookOnly.locked || busy || row.restarting || unavailable) return
    restart.open(row.rowKey)
    if (restart.opened.get()) {
      setIdentity(restart.target.get() ?? null)
      restartAction.clearError()
    }
  }

  function closeRestart(): void {
    if (!busy) restart.close()
  }

  async function submitRestart(): Promise<void> {
    if (lookOnly.locked || busy || !restart.canSubmit.get()) return
    if (await restartAction.run(restart.sendRestart())) restart.close()
  }

  return (
    <>
      <HilosDaemonNodeLine nodeId={nodeId} heading={heading} />
      <div className="hilos-stack">
        <p className="small text-body-secondary invisible" aria-hidden="true">
          This node has been silent since September 30, 2026, 11:59 PM. The
          agents below are from its last report.
        </p>
        {silentSince !== null && rows.length > 0 && (
          <p
            className="small text-body-secondary"
            data-id="hilos-daemon-agents-silent"
          >
            This node has been silent since {silentSince}. The agents below are
            from its last report.
          </p>
        )}
      </div>
      <HilosViewportTable
        controller={agents.controller}
        cells={{
          [DAEMON_AGENT_ID_FIELD]: (row) => (
            <>
              <code className="fw-semibold text-break">{row.agentId}</code>
              {row.restarting && (
                <span
                  className="badge text-bg-warning ms-2"
                  data-id={`hilos-daemon-agent-restarting-${row.rowKey}`}
                >
                  Restarting
                </span>
              )}
            </>
          ),
          [DAEMON_AGENT_WORKER_INDEX_FIELD]: (row) => (
            <code>{formatDaemonAgentWorker(row)}</code>
          ),
          [DAEMON_AGENT_PLACEMENT_FIELD]: (row) => (
            <span className="small">
              {formatDaemonAgentKind(row, heading?.clustered === true)}
            </span>
          ),
          [DAEMON_AGENT_OWNS_RT_FIELD]: (row) => (
            <code
              className={`small ${row.ownsRt.length === 0 ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonAgentOwnership(row.ownsRt)}
            </code>
          ),
          [DAEMON_AGENT_OWNS_DB_FIELD]: (row) => (
            <code
              className={`small ${row.ownsDb.length === 0 ? 'text-body-secondary' : ''}`}
            >
              {formatDaemonAgentOwnership(row.ownsDb)}
            </code>
          ),
          actions: (row) => (
            <>
              <HilosLink
                to={daemonAgentLogPath(nodeId, row)}
                className="small me-2"
                data-id={`hilos-daemon-agent-log-${row.rowKey}`}
              >
                Log
              </HilosLink>
              <LoadingButton
                className="btn-sm btn-outline-secondary"
                disabled={busy || row.restarting || unavailable}
                data-id={`hilos-daemon-agent-restart-${row.rowKey}`}
                onClick={() => openRestart(row)}
              >
                Restart
              </LoadingButton>
            </>
          ),
        }}
        empty={
          emptyState === 'waiting' ? (
            <div data-id="hilos-daemon-agents-empty-waiting">
              <div className="fw-semibold">
                The node has not reported its agents yet
              </div>
              <p className="mb-0">
                Nothing has arrived from this node yet — not zero agents.
              </p>
            </div>
          ) : emptyState === 'silent' ? (
            <div data-id="hilos-daemon-agents-empty-silent">
              <div className="fw-semibold">
                The node is silent and never reported its agents
              </div>
              <p className="mb-0">
                Its agents will appear when it reports again.
              </p>
            </div>
          ) : (
            <div data-id="hilos-daemon-agents-empty-none">
              <div className="fw-semibold">The node reports no agents</div>
            </div>
          )
        }
      />
      <HilosModal
        open={restartOpen}
        title="Restart agent"
        initialFocus="dialog"
        closeOnBackdrop={!busy}
        closeOnEsc={!busy}
        onClose={closeRestart}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={busy}
              data-id="hilos-daemon-restart-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={loading}
              disabled={busy || !canRestart}
              data-id="hilos-daemon-restart-confirm"
              onClick={() => void submitRestart()}
            >
              Restart
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={restartAction}
          detailsTitle="Couldn't restart the agent"
        />
        <p>
          Agent{' '}
          <code data-id="hilos-daemon-restart-agent">{identity?.agentId}</code>{' '}
          on node <code data-id="hilos-daemon-restart-node">{nodeId}</code> will
          be stopped and will immediately start again under its placement rules.
        </p>
        <p className="text-body-secondary">
          Neighboring agents on the same worker are unaffected.
        </p>
        {policyWarning && (
          <p
            className="alert alert-warning"
            data-id="hilos-daemon-restart-policy"
          >
            This policy-placed agent may come back on a different node.
          </p>
        )}
        <div className="border rounded p-2 bg-body-tertiary">
          <div className="small text-body-secondary mb-1">
            The same from a terminal:
          </div>
          <code className="text-break" data-id="hilos-daemon-restart-cli">
            {restartCommand}
          </code>
        </div>
        <p
          className={`small text-body-secondary mt-2 mb-0 ${restartUnavailable ? '' : 'invisible'}`}
          aria-hidden={!restartUnavailable}
          data-id="hilos-daemon-restart-unavailable"
        >
          This agent has disappeared or is currently unavailable for restart.
        </p>
      </HilosModal>
    </>
  )
}
