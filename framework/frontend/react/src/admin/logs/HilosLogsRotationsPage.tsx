// HilosLogsRotationsPage — the framework Hilos rotation-history page
// (HilosPages.LOGS_ROTATIONS): what already lies in the log archive, what it weighs,
// and what the retention rule recommends carrying off before the installation runs
// out of room. A row is one batch ON ONE NODE — the same rotation moment on two
// machines is two directories, carried off apart — so the node column and the node
// filter exist only where nodes have names. Search, the node filter and the
// All / awaiting switch ride the open viewport filter map (server-side, no local
// filtering); the window is re-served by the page whenever the cluster picture or
// the rule moves. A recommended batch carries the one command of this screen: a
// modal saying where the batch lies and how to copy it off, and a confirmation that
// it was (HIL-483) — the badge then repaints when the holding node's next index
// arrives, not when the ack does. Deleting a taken batch is HIL-382, taking a
// confirmation back is HIL-759, and there is no way through to the viewer yet
// because it takes no batch address (HIL-388). All table logic, the row
// view-model, the empty-state discrimination and the wording are the core headless's
// (hilosLogRotations); this view owns only the markup, so a project mounts it by
// passing its HilosLogRotationsContext. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_PAGE_ROUTES,
  HILOS_ROTATION_STATE_DUE,
  HILOS_ROTATION_STATE_OPTIONS,
  HILOS_ROTATION_STATE_TAKEN,
  HilosPages,
  ROTATION_BATCH_AT_FIELD,
  ROTATION_BYTES_FIELD,
  ROTATION_FILTER_NODE,
  ROTATION_FILTER_STATE,
  ROTATION_NODE_FIELD,
  createHilosLogRotationsActions,
  createHilosLogRotationsHeader,
  createHilosLogRotationsTable,
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  rotationTakeoutAddress,
  rotationTakeoutCommand,
  rotationsEmptyState,
} from '@hilos/core'
import type {
  HilosLogRotationRow,
  HilosLogRotationsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosLogsRotationsPage}. */
export interface HilosLogsRotationsPageProps {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosLogRotationsContext
}

// The rule line leads to the general settings screen: the log settings page does
// not exist in the registry yet (HIL-391 adds it and re-points this link).
const SETTINGS_HREF = HILOS_PAGE_ROUTES[HilosPages.SETTINGS] ?? '/'

// The retention badge: a recommendation is a warning and not a fault, a taken
// batch is settled, and a kept one is the quiet default.
const RETENTION_CLASS: Record<string, string> = {
  [HILOS_ROTATION_STATE_DUE]: 'text-bg-warning',
  [HILOS_ROTATION_STATE_TAKEN]: 'text-bg-secondary',
}

/**
 * Declared as the loose HilosTableColumn rather than the row-typed form: the Files
 * column is three counts at once and belongs to no single field, so keying it to
 * one of them would name the column after a third of what it shows. The sortable
 * keys are the exported wire constants, which is where a typo would actually cost
 * something — they travel to the backend as the sort field.
 *
 * The node and weight columns drop out of the header below `lg`, where their
 * values move into the sub-line of the batch cell: a narrow screen gets a shorter
 * table rather than one that scrolls sideways.
 *
 * @param clustered Whether this installation names its nodes.
 */
function rotationColumns(clustered: boolean): HilosTableColumn[] {
  return [
    { key: ROTATION_BATCH_AT_FIELD, label: 'Batch', sortable: true },
    ...(clustered
      ? [
          {
            key: ROTATION_NODE_FIELD,
            label: 'Node',
            sortable: true,
            headerClass: 'd-none d-lg-table-cell',
          },
        ]
      : []),
    { key: 'files', label: 'Files' },
    {
      key: ROTATION_BYTES_FIELD,
      label: 'Weight',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    { key: 'retention', label: 'Retention' },
    { key: 'actions', label: '', headerClass: 'text-end' },
  ]
}

/**
 * The batch's own name is its rotation time; the archive directory under it is
 * what an operator types into scp, so both are in the cell.
 *
 * @param row The batch the cell speaks for.
 */
function batchTime(row: HilosLogRotationRow): string {
  return new Date(row.batchAt * 1000).toLocaleString()
}

/**
 * The badge weight of one batch's retention verdict.
 *
 * @param row The batch the badge speaks for.
 */
function retentionClass(row: HilosLogRotationRow): string {
  return RETENTION_CLASS[row.retentionState] ?? 'text-bg-light border'
}

/**
 * Only a recommended batch offers the takeout dialog — a kept one is not being
 * asked for, and a taken one has already been answered.
 *
 * @param row The batch the button would belong to.
 */
function offersTakeout(row: HilosLogRotationRow): boolean {
  return row.retentionState === HILOS_ROTATION_STATE_DUE
}

/**
 * The framework rotation-history screen: the rule in force, the windowed batch
 * table with its filters and empty states, and the carry-off dialog.
 *
 * @param props The project context (scope stores, connection, action lifecycle).
 */
export function HilosLogsRotationsPage({
  context,
}: HilosLogsRotationsPageProps) {
  const rotations = useMemo(
    () => createHilosLogRotationsTable(context),
    [context],
  )
  const rotationsTable = rotations.controller
  const rotationsActions = useMemo(
    () => createHilosLogRotationsActions(context),
    [context],
  )
  const headerHandle = useMemo(
    () => createHilosLogRotationsHeader(context),
    [context],
  )
  const header = useSignal(headerHandle.header)

  // Bind the server-windowed table and start listening for the header on mount; the
  // header also arrives once as the answer to the subscription.
  useEffect(() => {
    headerHandle.start()
    rotations.start()

    return () => {
      rotations.dispose()
      headerHandle.dispose()
    }
  }, [headerHandle, rotations])

  const rows = useSignal(rotationsTable.rows)
  const search = useSignal(rotationsTable.search)

  // The node column and the node filter exist only where nodes have names: in a
  // single-node installation a column repeating one name and a filter offering one
  // option would both be furniture for a choice that does not exist.
  const clustered = hasRotationNodes(header)
  const columns = rotationColumns(clustered)

  // Domain filters: the node and the state ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter.
  const [nodeFilter, setNodeFilter] = useState('')
  const [stateFilter, setStateFilter] = useState('')

  function setNode(value: string): void {
    setNodeFilter(value)
    rotationsTable.setFilter(ROTATION_FILTER_NODE, value)
  }

  function setState(value: string): void {
    setStateFilter(value)
    rotationsTable.setFilter(ROTATION_FILTER_STATE, value)
  }

  function clearFilters(): void {
    rotationsTable.setSearch('')
    setNode('')
    setState('')
  }

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const emptyState = rotationsEmptyState(
    header,
    rows.length,
    search !== '' || nodeFilter !== '' || stateFilter !== '',
  )

  // The takeout dialog: how to carry one batch off, and the button that records
  // that it was.
  const [takeoutOpen, setTakeoutOpen] = useState(false)
  // A snapshot of the row the dialog opened on, so a window re-served underneath it
  // (the page re-sends one whenever the picture moves) does not swap the batch the
  // operator is reading the address of.
  const [takeoutRow, setTakeoutRow] = useState<HilosLogRotationRow | null>(null)
  const takeout = useTrackedAction()
  const takeoutAddress =
    takeoutRow === null ? null : rotationTakeoutAddress(takeoutRow)
  const takeoutCommand =
    takeoutRow === null ? null : rotationTakeoutCommand(takeoutRow)

  const [legendOpen, setLegendOpen] = useState(false)

  function openTakeout(row: HilosLogRotationRow): void {
    takeout.clearError()
    setTakeoutRow(row)
    setTakeoutOpen(true)
  }

  async function submitTakeout(): Promise<void> {
    if (takeoutRow === null || takeout.busy) {
      return
    }
    // The dialog closes on the server's word and not on the click: the refusals
    // this can meet — the batch is gone, it is protected again — are the whole
    // reason the confirmation travels to the node that holds the directory.
    if (await takeout.run(rotationsActions.sendTakeoutConfirm(takeoutRow))) {
      setTakeoutOpen(false)
    }
  }

  return (
    <HilosAdminPage page={HilosPages.LOGS_ROTATIONS}>
      <div className="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mb-4">
        <i className="bi bi-sliders text-body-secondary" aria-hidden="true" />
        <div className="flex-grow-1">
          {header ? (
            <>
              <div className="fw-semibold small" data-id="hilos-rotation-rule">
                {formatRotationRule(header)}
              </div>
              <div className="small text-body-secondary">
                {formatRetentionRule(header)}
              </div>
            </>
          ) : (
            <div className="small text-body-secondary">
              The rule in force is not known yet.
            </div>
          )}
          <div className="small text-body-secondary">
            {clustered
              ? 'One rule for the whole cluster'
              : 'One rule for the installation'}
          </div>
        </div>
        <HilosLink
          to={SETTINGS_HREF}
          className="btn btn-sm btn-outline-secondary text-nowrap"
          data-id="hilos-rotation-settings"
        >
          Log settings
        </HilosLink>
      </div>

      <div className="d-flex flex-wrap align-items-end gap-2 mb-3">
        {clustered ? (
          <div>
            <label className="form-label" htmlFor="hilos-rotation-node">
              Node
            </label>
            <select
              id="hilos-rotation-node"
              className="form-select"
              value={nodeFilter}
              data-id="hilos-rotation-node"
              onChange={(event) => setNode(event.target.value)}
            >
              <option value="">All nodes</option>
              {(header?.nodes ?? []).map((node) => (
                <option key={node} value={node}>
                  {node}
                </option>
              ))}
            </select>
          </div>
        ) : null}
        <div
          className="btn-group btn-group-sm"
          role="group"
          aria-label="Retention state"
        >
          {HILOS_ROTATION_STATE_OPTIONS.map((option) => (
            <button
              key={option.value}
              type="button"
              className={`btn btn-outline-secondary${stateFilter === option.value ? ' active' : ''}`}
              aria-pressed={stateFilter === option.value}
              data-id={`hilos-rotation-state-${option.value || 'all'}`}
              onClick={() => setState(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <HilosViewportTable
        label="Rotation batches"
        controller={rotationsTable}
        columns={columns}
        searchable
        searchPlaceholder="Search by batch date or node…"
        row={(row: HilosLogRotationRow) => (
          <>
            <td>
              <div className="fw-semibold small">{batchTime(row)}</div>
              <code className="small text-body-secondary">{row.path}</code>
              {/* The sub-line carries whatever the hidden columns were carrying, so a
              narrow screen loses the layout and not the figures. It is there in a
              single-node installation too, where only the weight was hidden. */}
              <div className="small text-body-secondary d-lg-none">
                {clustered ? `${row.node} · ` : null}
                {formatRotationWeight(row)}
              </div>
            </td>
            {clustered ? (
              <td className="d-none d-lg-table-cell">{row.node}</td>
            ) : null}
            <td className="small">{formatRotationFileCounts(row)}</td>
            <td className="text-end d-none d-lg-table-cell">
              {formatRotationWeight(row)}
            </td>
            <td>
              <span className={`badge ${retentionClass(row)}`}>
                {formatRotationState(row)}
              </span>
            </td>
            <td className="text-end text-nowrap">
              {offersTakeout(row) ? (
                <button
                  type="button"
                  className="btn btn-sm btn-warning"
                  data-id="hilos-rotation-takeout"
                  onClick={() => openTakeout(row)}
                >
                  How to carry it off
                </button>
              ) : null}
            </td>
          </>
        )}
        empty={
          emptyState === 'unknown' ? (
            <div data-id="hilos-rotation-empty-unknown">
              <div className="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p className="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          ) : emptyState === 'unreadable' ? (
            <div data-id="hilos-rotation-empty-unreadable">
              <div className="fw-semibold">
                The log directory cannot be read
              </div>
              <p className="mb-0">
                No node could read its log store. Check the log directory
                setting and the permissions on it.
              </p>
            </div>
          ) : emptyState === 'nomatch' ? (
            <div data-id="hilos-rotation-empty-nomatch">
              <div className="fw-semibold">Nothing matches</div>
              <p className="mb-2">There are batches — just not these.</p>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="hilos-rotation-clear-filters"
                onClick={clearFilters}
              >
                Clear the filters
              </button>
            </div>
          ) : (
            <div data-id="hilos-rotation-empty-never">
              <div className="fw-semibold">Nothing has rotated yet</div>
              <p className="mb-0">
                The archive fills at the first rotation; until then there is
                nothing to carry off.
              </p>
            </div>
          )
        }
      />

      <p className="small text-body-secondary mt-2 mb-0">
        <button
          type="button"
          className="btn btn-link btn-sm p-0 align-baseline"
          data-id="hilos-rotation-legend"
          onClick={() => setLegendOpen(true)}
        >
          Files
        </button>
        — three numbers in a row: agent / worker / monopolistic worker.
      </p>

      <HilosModal
        open={takeoutOpen}
        title={
          takeoutRow
            ? `Carrying off the batch of ${batchTime(takeoutRow)}${takeoutRow.node ? ` · ${takeoutRow.node}` : ''}`
            : 'Carrying off a batch'
        }
        closeOnBackdrop={!takeout.busy}
        closeOnEsc={!takeout.busy}
        onClose={() => setTakeoutOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={takeout.busy}
              onClick={requestClose}
            >
              Close
            </button>
            <LoadingButton
              className="btn-primary"
              loading={takeout.loading}
              data-id="hilos-rotation-takeout-confirm"
              onClick={() => void submitTakeout()}
            >
              I have taken this batch
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={takeout} />
        <p>
          This batch is recommended for carrying off: it is older than the
          retention rule keeps. The system does <strong>not delete it</strong> —
          you copy it where you keep cold logs, and then confirm that you have.
        </p>
        {takeoutAddress && takeoutCommand ? (
          <>
            <div className="fw-semibold mb-1">Where it lies</div>
            <pre
              className="border rounded-2 p-2 bg-body-tertiary mb-3"
              data-id="hilos-rotation-takeout-path"
            >
              <code>{takeoutAddress}</code>
            </pre>
            <div className="fw-semibold mb-1">How to take it</div>
            <pre
              className="border rounded-2 p-2 bg-body-tertiary mb-3"
              data-id="hilos-rotation-takeout-command"
            >
              <code>{takeoutCommand}</code>
            </pre>
          </>
        ) : (
          // A node that reported no log root has no address to give, and this
          // screen must not offer its own: the page worker knows where ITS logs
          // live, and that directory is on the wrong machine. Confirming is still
          // possible — the operator may know the path from the node itself.
          <div className="alert alert-secondary small py-2">
            This node did not report where its logs live, so there is no address
            to copy from here. Look it up on the node itself.
          </div>
        )}
        {clustered && takeoutRow?.node ? (
          <div className="alert alert-warning small py-2 mb-0">
            The batch lies on node{' '}
            <span className="font-monospace">{takeoutRow.node}</span> and only
            there: logs do not converge anywhere. Take it from that node, and
            the confirmation covers this batch on this node.
          </div>
        ) : (
          <div className="alert alert-secondary small py-2 mb-0">
            Once confirmed, the batch becomes available to the cleaner — until
            then it will not be touched.
          </div>
        )}
      </HilosModal>

      <HilosModal
        open={legendOpen}
        title="What is in a batch"
        onClose={() => setLegendOpen(false)}
      >
        <p>
          A batch is one archive directory, written by one rotation on one node.
          The three numbers count the files in it by the stream that wrote them:
        </p>
        <ul className="mb-3">
          <li>
            <strong>agent</strong> — one file per agent that logged.
          </li>
          <li>
            <strong>worker</strong> — one per worker process, the monopolistic
            ones apart.
          </li>
          <li>
            <strong>monopolistic worker</strong> — the workers that hold work
            which cannot be done in two hands.
          </li>
        </ul>
        <p className="mb-0">
          The daemon's own two files are a fourth class and are not counted here
          — they belong to the node rather than to anything the installation
          runs. The weight column still includes them: that is what the
          directory costs.
        </p>
      </HilosModal>
    </HilosAdminPage>
  )
}
