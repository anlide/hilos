// Cluster comparison renders the existing summary and two verdict windows.
import { useEffect, useMemo } from 'react'
import type { ReactNode } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosDaemonEnvMismatchTables,
  daemonEnvMismatchEmptyState,
  daemonEnvMismatchNodeColumnKey,
  daemonEnvMismatchNodes,
  DAEMON_ENV_MISMATCH_KEY_FIELD,
  DAEMON_ENV_SENSITIVE_HINT,
  readHilosDaemonEnvMismatchSummary,
  type HilosDaemonEnvMismatchContext,
  type HilosDaemonEnvMismatchRow,
} from '@hilos/core/admin/daemon'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonEnvMismatchCell } from './HilosDaemonEnvMismatchCell.js'

/** Props for the framework's environment mismatch page. */
export interface HilosDaemonEnvMismatchPageProps {
  context: HilosDaemonEnvMismatchContext
}

/** @param props The project's connection and page scope. */
export function HilosDaemonEnvMismatchPage({
  context,
}: HilosDaemonEnvMismatchPageProps) {
  const summarySignal = useMemo(
    () => readHilosDaemonEnvMismatchSummary(context.scopes),
    [context],
  )
  const summary = useSignal(summarySignal)
  const tables = useMemo(
    () =>
      createHilosDaemonEnvMismatchTables(
        context,
        computedSignal(() => daemonEnvMismatchNodes(summarySignal.get())),
      ),
    [context, summarySignal],
  )
  const clustered = summary?.clustered === true
  useEffect(() => {
    if (clustered) tables.start()
    return () => tables.dispose()
  }, [tables, clustered])
  const nodes = daemonEnvMismatchNodes(summary)
  const comparison = summary?.comparison ?? null
  const emptyState =
    comparison === null ? null : daemonEnvMismatchEmptyState(comparison)
  const cells = (
    valuesMatch: boolean,
  ): Record<string, (row: HilosDaemonEnvMismatchRow) => ReactNode> => ({
    [DAEMON_ENV_MISMATCH_KEY_FIELD]: (row) => (
      <>
        <code className="fw-semibold text-break">{row.key}</code>
        {row.sensitive && (
          <>
            <i
              className="bi bi-shield-lock text-body-secondary ms-1"
              title={DAEMON_ENV_SENSITIVE_HINT}
              aria-hidden="true"
            />
            <span className="visually-hidden">{DAEMON_ENV_SENSITIVE_HINT}</span>
          </>
        )}
      </>
    ),
    ...Object.fromEntries(
      nodes.map((node) => [
        daemonEnvMismatchNodeColumnKey(node.nodeId),
        (row: HilosDaemonEnvMismatchRow) => (
          <HilosDaemonEnvMismatchCell
            row={row}
            nodeId={node.nodeId}
            servingNodeId={summary?.servingNodeId ?? null}
            valuesMatch={valuesMatch}
          />
        ),
      ]),
    ),
  })
  return (
    <HilosAdminPage
      page={HilosPages.DAEMON_ENV_MISMATCH}
      headKind="section"
      tabsLabel="Daemon pages"
    >
      {summary !== null && !summary.clustered ? (
        <div
          className="alert alert-secondary d-flex align-items-start gap-3 py-3"
          data-id="hilos-daemon-env-mismatch-standalone"
        >
          <i className="bi bi-slash-circle fs-4" aria-hidden="true" />
          <div>
            <div className="fw-semibold mb-1">
              This page does not exist on a single-node installation
            </div>
            <p className="small mb-0">
              There is one node,{' '}
              <code
                className="text-break"
                data-id="hilos-daemon-env-mismatch-standalone-node"
              >
                {summary.servingNodeId}
              </code>
              , and nothing to compare it with.
            </p>
          </div>
        </div>
      ) : summary === null || comparison === null ? (
        <div data-id="hilos-daemon-env-mismatch-waiting">
          <p className="text-body-secondary">
            Waiting for the cluster picture…
          </p>
          <HilosSkeleton />
        </div>
      ) : (
        <>
          <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
            <div className="col">
              <div
                className={`border rounded-3 p-3 h-100 ${comparison.answering.length < nodes.length ? 'border-warning' : ''}`}
                data-id="hilos-daemon-env-mismatch-tile-answering"
                data-tone={
                  comparison.answering.length < nodes.length ? 'warning' : ''
                }
              >
                <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                  <i className="bi bi-hdd-network" aria-hidden="true" />
                  <span className="small">Nodes answering</span>
                </div>
                <div
                  className={`fs-5 lh-1 mb-1 ${comparison.answering.length < nodes.length ? 'text-warning-emphasis' : ''}`}
                >{`${comparison.answering.length} of ${nodes.length}`}</div>
                <div className="small text-body-secondary">
                  Silent nodes show as ?
                </div>
              </div>
            </div>
            <div className="col">
              <div
                className={`border rounded-3 p-3 h-100 border-${comparison.diverged > 0 ? 'danger' : 'success'}`}
                data-id="hilos-daemon-env-mismatch-tile-diverged"
                data-tone={comparison.diverged > 0 ? 'danger' : 'success'}
              >
                <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                  <i className="bi bi-exclamation-octagon" aria-hidden="true" />
                  <span className="small">Diverged</span>
                </div>
                <div
                  className={`fs-5 lh-1 mb-1 text-${comparison.diverged > 0 ? 'danger' : 'success'}-emphasis`}
                >
                  {comparison.diverged}
                </div>
                <div className="small text-body-secondary">
                  Nodes report different values
                </div>
              </div>
            </div>
            <div className="col">
              <div
                className={`border rounded-3 p-3 h-100 border-${comparison.sourcesDiffer > 0 ? 'warning' : 'success'}`}
                data-id="hilos-daemon-env-mismatch-tile-sources"
                data-tone={comparison.sourcesDiffer > 0 ? 'warning' : 'success'}
              >
                <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                  <i className="bi bi-node-plus" aria-hidden="true" />
                  <span className="small">Different sources</span>
                </div>
                <div
                  className={`fs-5 lh-1 mb-1 text-${comparison.sourcesDiffer > 0 ? 'warning' : 'success'}-emphasis`}
                >
                  {comparison.sourcesDiffer}
                </div>
                <div className="small text-body-secondary">
                  Same value from different sources
                </div>
              </div>
            </div>
            <div className="col">
              <div
                className="border rounded-3 p-3 h-100"
                data-id="hilos-daemon-env-mismatch-tile-per-node"
                data-tone=""
              >
                <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                  <i className="bi bi-check-circle" aria-hidden="true" />
                  <span className="small">Differ by design</span>
                </div>
                <div className="fs-5 lh-1 mb-1">
                  {comparison.perNode.length}
                </div>
                <div className="small text-body-secondary">
                  Each node has its own; that is normal
                </div>
              </div>
            </div>
          </div>
          <HilosViewportTable
            controller={tables.diverged}
            cells={cells(false)}
            empty={
              emptyState === 'alone' ? (
                <div data-id="hilos-daemon-env-mismatch-empty-alone">
                  <div className="fw-semibold">
                    Fewer than two nodes answer, so there is nothing to compare
                    yet.
                  </div>
                </div>
              ) : (
                <div data-id="hilos-daemon-env-mismatch-empty-agree">
                  <div className="fw-semibold">
                    The answering nodes agree on every key they share.
                  </div>
                </div>
              )
            }
          />
          {comparison.sourcesDiffer > 0 && (
            <div className="mt-4">
              <HilosViewportTable
                controller={tables.sourcesDiffer}
                cells={cells(true)}
              />
            </div>
          )}
          {comparison.perNode.length > 0 && (
            <>
              <h2 className="h6 text-uppercase text-body-secondary mb-2 mt-4">
                Differ by design
              </h2>
              <div
                className="d-flex flex-wrap gap-1 mb-3"
                data-id="hilos-daemon-env-mismatch-per-node"
              >
                {comparison.perNode.map((key) => (
                  <span
                    key={key}
                    className="badge bg-body-tertiary text-body border font-monospace text-wrap text-break"
                    data-id={`hilos-daemon-env-mismatch-per-node-${key}`}
                  >
                    {key}
                  </span>
                ))}
              </div>
            </>
          )}
        </>
      )}
    </HilosAdminPage>
  )
}
