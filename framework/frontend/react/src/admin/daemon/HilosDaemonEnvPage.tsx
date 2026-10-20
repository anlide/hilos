// Environment inspection uses the node's page answer and its two table windows.
import { Fragment, useContext, useEffect, useMemo } from 'react'
import {
  createHilosDaemonEnvKeysTable,
  createHilosDaemonEnvOrphansTable,
  daemonEnvNodeHeading,
  daemonEnvSourceBadge,
  daemonEnvSummary,
  DAEMON_ENV_FILTER_ATTENTION,
  DAEMON_ENV_KEY_FIELD,
  DAEMON_ENV_TYPE_FIELD,
  DAEMON_ENV_VALUE_FIELD,
  DAEMON_ENV_REQUIRED_FIELD,
  DAEMON_ENV_DECLARED_BY_FIELD,
  DAEMON_ENV_SIMILAR_TO_FIELD,
  DAEMON_ENV_LADDER,
  DAEMON_ENV_PER_NODE_HINT,
  DAEMON_ENV_SENSITIVE_HINT,
  formatDaemonEnvDeclaredBy,
  formatDaemonEnvDrift,
  formatDaemonEnvDriftNotice,
  formatDaemonEnvRequired,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  readHilosDaemonEnvPage,
  type HilosDaemonEnvContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosDaemonNodeLine } from './HilosDaemonNodeLine.js'
import { HilosDaemonEnvValue } from './HilosDaemonEnvValue.js'
import { HilosDaemonEnvSourceBadge } from './HilosDaemonEnvSourceBadge.js'

/** Props for the framework's environment page. */
export interface HilosDaemonEnvPageProps {
  context: HilosDaemonEnvContext
}

/** @param keys Catalog names in the order reported by the node. */
function keyNames(keys: readonly string[]) {
  return keys.map((key, index) => (
    <Fragment key={key}>
      {index > 0 && ', '}
      <code className="text-break">{key}</code>
    </Fragment>
  ))
}

/** @param props The project's connection and page scope. */
export function HilosDaemonEnvPage({ context }: HilosDaemonEnvPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosDaemonEnvPage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const nodeId = route.params.nodeId ?? ''
  const keys = useMemo(
    () => createHilosDaemonEnvKeysTable(context, nodeId),
    [context, nodeId],
  )
  const orphans = useMemo(
    () => createHilosDaemonEnvOrphansTable(context, nodeId),
    [context, nodeId],
  )
  const page = useSignal(
    useMemo(() => readHilosDaemonEnvPage(context.scopes), [context]),
  )
  const filter = useSignal(keys.controller.filter)
  const heading = daemonEnvNodeHeading(page)
  const summary =
    !page.nodeSilent && page.environment !== null
      ? daemonEnvSummary(page.environment.keys)
      : null
  const silentMessage =
    'This node is silent. Only the node itself reads its environment, so nothing is shown until it answers again.'
  const missingMessage =
    "in the launch environment or in the files. The node's next start — a planned restart, a move, a crash — will be refused."
  const driftMessage =
    'A live daemon does not pick up a file edit: it applies when the node restarts.'
  const hasAlarms =
    summary !== null &&
    (summary.missingRequired.length > 0 ||
      summary.fromExample.length > 0 ||
      summary.drifted.length > 0)
  useEffect(() => {
    keys.start()
    orphans.start()
    return () => {
      keys.dispose()
      orphans.dispose()
    }
  }, [keys, orphans])

  return (
    <HilosAdminPage page={HilosPages.DAEMON_ENV}>
      <HilosDaemonNodeLine nodeId={nodeId} heading={heading} />
      <div className="hilos-stack mb-3">
        <p
          className="small text-body-secondary mb-0 invisible"
          aria-hidden="true"
        >
          {silentMessage}
        </p>
        {page.nodeSilent && (
          <p
            className="small text-body-secondary mb-0"
            data-id="hilos-daemon-env-silent"
          >
            {silentMessage}
          </p>
        )}
      </div>
      {summary !== null && (
        <div className="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div className="col">
            <div className="border rounded-3 p-3 h-100">
              <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                <i className="bi bi-list-ul" aria-hidden="true" />
                <span className="small">Keys in catalog</span>
              </div>
              <div
                className="fs-5 lh-1 mb-1 text-break"
                data-id="hilos-daemon-env-keys"
              >
                {summary.keyCount}
              </div>
              <div className="small text-body-secondary">
                {'Declared by the framework and the project'}
              </div>
            </div>
          </div>
          <div className="col">
            <div
              className={`border rounded-3 p-3 h-100 ${summary.missingRequired.length > 0 ? 'border-danger' : ''}`}
            >
              <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                <i className="bi bi-exclamation-octagon" aria-hidden="true" />
                <span className="small">Required without a value</span>
              </div>
              <div
                className={`fs-5 lh-1 mb-1 text-break ${summary.missingRequired.length > 0 ? 'text-danger-emphasis' : ''}`}
                data-id="hilos-daemon-env-missing"
              >
                {summary.missingRequired.length}
              </div>
              <div className="small text-body-secondary">
                {summary.missingRequired.length > 0
                  ? 'The node will not start with this set'
                  : 'All present'}
              </div>
            </div>
          </div>
          <div className="col">
            <div
              className={`border rounded-3 p-3 h-100 ${summary.fromExample.length > 0 ? 'border-warning' : ''}`}
            >
              <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                <i className="bi bi-file-earmark-text" aria-hidden="true" />
                <span className="small">From the sample</span>
              </div>
              <div
                className={`fs-5 lh-1 mb-1 text-break ${summary.fromExample.length > 0 ? 'text-warning-emphasis' : ''}`}
                data-id="hilos-daemon-env-example"
              >
                {summary.fromExample.length}
              </div>
              <div className="small text-body-secondary">
                {summary.fromExample.length > 0
                  ? '.env.example instead of a setting'
                  : 'The sample is not read'}
              </div>
            </div>
          </div>
          <div className="col">
            <div
              className={`border rounded-3 p-3 h-100 ${summary.drifted.length > 0 ? 'border-warning' : ''}`}
            >
              <div className="d-flex align-items-center gap-2 text-body-secondary mb-1">
                <i className="bi bi-arrow-repeat" aria-hidden="true" />
                <span className="small">Drifted from disk</span>
              </div>
              <div
                className={`fs-5 lh-1 mb-1 text-break ${summary.drifted.length > 0 ? 'text-warning-emphasis' : ''}`}
                data-id="hilos-daemon-env-drift"
              >
                {summary.drifted.length}
              </div>
              <div className="small text-body-secondary">
                {summary.drifted.length > 0
                  ? 'The file was edited, the process was not restarted'
                  : 'The file and the process agree'}
              </div>
            </div>
          </div>
        </div>
      )}
      {/* The invisible alarm holds the room; any number of live alarms scrolls inside it. */}
      <div
        className="position-relative mb-3"
        data-id="hilos-daemon-env-alarms-room"
      >
        <div
          className="alert alert-danger d-flex align-items-start gap-3 py-2 small mb-0 invisible"
          aria-hidden="true"
        >
          <i
            className="bi bi-exclamation-octagon fs-5 flex-shrink-0"
            aria-hidden="true"
          />
          <div>
            <code>KEY</code> — the catalog declares it required, and it has no
            value {missingMessage}
          </div>
        </div>
        {hasAlarms && summary !== null && (
          <div
            className="position-absolute top-0 start-0 w-100 h-100 overflow-auto"
            tabIndex={0}
            role="region"
            aria-label="Environment alerts"
            data-id="hilos-daemon-env-alarms"
          >
            {summary.missingRequired.length > 0 && (
              <div
                className="alert alert-danger d-flex align-items-start gap-3 py-2 small mb-2"
                data-id="hilos-daemon-env-alarm-missing"
              >
                <i
                  className="bi bi-exclamation-octagon fs-5 flex-shrink-0"
                  aria-hidden="true"
                />
                <div>
                  {keyNames(summary.missingRequired)} — the catalog{' '}
                  {summary.missingRequired.length > 1
                    ? 'declares them required, and they have no value'
                    : 'declares it required, and it has no value'}{' '}
                  {missingMessage}
                </div>
              </div>
            )}
            {summary.fromExample.length > 0 && (
              <div
                className="alert alert-warning d-flex align-items-start gap-3 py-2 small mb-2"
                data-id="hilos-daemon-env-alarm-example"
              >
                <i
                  className="bi bi-file-earmark-text fs-5 flex-shrink-0"
                  aria-hidden="true"
                />
                <div>
                  {summary.fromExample.length > 1
                    ? 'The values of'
                    : 'The value of'}{' '}
                  {keyNames(summary.fromExample)} came from{' '}
                  <code>.env.example</code>.
                </div>
              </div>
            )}
            {summary.drifted.map((row) => {
              const notice = formatDaemonEnvDriftNotice(row)
              return (
                notice !== null && (
                  <div
                    key={row.key}
                    className="alert alert-warning d-flex align-items-start gap-3 py-2 small mb-2"
                    data-id={`hilos-daemon-env-alarm-drift-${row.key}`}
                  >
                    <i
                      className="bi bi-arrow-repeat fs-5 flex-shrink-0"
                      aria-hidden="true"
                    />
                    <div>
                      In the process{' '}
                      <code className="text-break">{notice.key}</code> is{' '}
                      <code className="text-break">{notice.process}</code>; on
                      disk it is already{' '}
                      <code className="text-break">{notice.disk}</code>.{' '}
                      {driftMessage}
                    </div>
                  </div>
                )
              )
            })}
          </div>
        )}
      </div>
      <HilosViewportTable
        key={`keys-${nodeId}`}
        controller={keys.controller}
        cells={{
          [DAEMON_ENV_KEY_FIELD]: (row) => (
            <>
              <code className="fw-semibold text-break">{row.key}</code>
              {row.perNode && (
                <>
                  <i
                    className="bi bi-hdd-network text-body-secondary ms-1"
                    title={DAEMON_ENV_PER_NODE_HINT}
                    aria-hidden="true"
                  />
                  <span className="visually-hidden">
                    {DAEMON_ENV_PER_NODE_HINT}
                  </span>
                </>
              )}
              {row.sensitive && (
                <>
                  <i
                    className="bi bi-shield-lock text-body-secondary ms-1"
                    title={DAEMON_ENV_SENSITIVE_HINT}
                    aria-hidden="true"
                  />
                  <span className="visually-hidden">
                    {DAEMON_ENV_SENSITIVE_HINT}
                  </span>
                </>
              )}
            </>
          ),
          [DAEMON_ENV_TYPE_FIELD]: (row) => (
            <span className="small text-body-secondary">{row.type}</span>
          ),
          [DAEMON_ENV_VALUE_FIELD]: (row) => (
            <span className="d-inline-flex align-items-center gap-2 flex-wrap">
              <HilosDaemonEnvValue
                view={formatDaemonEnvValue(row.value, row.type, row.sensitive)}
                required={row.required}
              />
              <HilosDaemonEnvSourceBadge source={row.source} />
              {formatDaemonEnvDrift(row) !== null && (
                <span className="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle text-wrap text-break">
                  {formatDaemonEnvDrift(row)}
                </span>
              )}
            </span>
          ),
          [DAEMON_ENV_REQUIRED_FIELD]: (row) =>
            row.required ? (
              <>
                <i
                  className="bi bi-check-lg text-body-secondary"
                  aria-hidden="true"
                />
                <span className="visually-hidden">
                  {formatDaemonEnvRequired(row)}
                </span>
              </>
            ) : (
              <>
                <span className="text-body-secondary" aria-hidden="true">
                  ·
                </span>
                <span className="visually-hidden">Optional</span>
              </>
            ),
          [DAEMON_ENV_DECLARED_BY_FIELD]: (row) => (
            <span className="small text-body-secondary">
              {formatDaemonEnvDeclaredBy(row)}
            </span>
          ),
        }}
        empty={
          page.nodeSilent ? (
            <span data-id="hilos-daemon-env-keys-empty-silent">
              Nothing is read while the node is silent.
            </span>
          ) : filter[DAEMON_ENV_FILTER_ATTENTION] === true ? (
            <span data-id="hilos-daemon-env-keys-empty-attention">
              No key needs attention.
            </span>
          ) : (
            <>No rows.</>
          )
        }
      />
      <h2
        className="h6 text-uppercase text-body-secondary mb-2 mt-4"
        id="hilos-daemon-env-ladder-heading"
      >
        Reading ladder
      </h2>
      <div
        className="border rounded-3 overflow-hidden mb-3"
        data-id="hilos-daemon-env-ladder"
      >
        <div className="table-responsive">
          <table
            className="table table-sm align-middle mb-0"
            aria-labelledby="hilos-daemon-env-ladder-heading"
          >
            <tbody>
              {DAEMON_ENV_LADDER.map((source, index) => (
                <tr key={source}>
                  <td className="text-center text-body-secondary small text-nowrap px-3">
                    {source === 'missing' ? '' : index + 1}
                  </td>
                  <td className="text-nowrap">
                    <HilosDaemonEnvSourceBadge source={source} />
                  </td>
                  <td className="small text-body-secondary text-break w-100">
                    {daemonEnvSourceBadge(source).hint}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
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
    </HilosAdminPage>
  )
}
