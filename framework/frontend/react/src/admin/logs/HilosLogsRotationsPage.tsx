// HilosLogsRotationsPage — the framework Hilos rotation-history page
// (HilosPages.LOGS_ROTATIONS): what already lies in the log archive, what it weighs,
// and what the retention rule recommends carrying off before the installation runs out
// of room. A row is one batch ON ONE NODE — the same rotation moment on two machines is
// two directories, carried off apart — so the node column, the node filter and the node
// half of the search hint exist only where nodes have names. Search, the node filter
// and the All / awaiting switch ride the open viewport filter map (server-side, no
// local filtering); the switch shows the table's own filter and lives in the address
// too (HIL-903), so the overview banner opens it on Awaiting and a reload keeps what
// is on the screen; the window is re-served by the page whenever the cluster picture or
// the rule moves. A recommended batch carries the first of this screen's two commands:
// a modal saying where the batch lies and how to copy it off, and a confirmation that
// it was (HIL-483) — the badge then repaints when the holding node's next index
// arrives, not when the ack does. A taken batch carries the other half of that
// (HIL-759): a trigger that takes the word back while the batch is still on disk,
// behind a modal naming when its node's cleaner may first delete it. Deleting a taken
// batch is HIL-382, and there is no way through to the viewer yet because it takes no
// batch address (HIL-388). All table logic, the row view-model, the empty-state
// discrimination and the wording are the core headless's (hilosLogRotations); this view
// owns only the markup, so a project mounts it by passing its HilosLogRotationsContext.
// Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  HILOS_PAGE_ROUTES,
  HILOS_ROTATION_STATE_CARRYING,
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
  rotationsSearchPlaceholder,
} from '@hilos/core'
import type {
  HilosLogRotationRow,
  HilosLogRotationsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosLongText } from '../../HilosLongText.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosLogsRotationsPage}. */
export interface HilosLogsRotationsPageProps {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosLogRotationsContext
}

// The rule line leads to the Logs section's own settings screen, where the
// values this bar reports are set.
const LOG_SETTINGS_HREF = HILOS_PAGE_ROUTES[HilosPages.LOGS_SETTINGS]

// The retention badge: a batch on its way is in motion and not in trouble, a
// recommendation is a warning and not a fault, a taken batch is settled, and a kept
// one is the quiet default. Neither row action reads this map — both compare with
// the state they act on, so a carrying row offers neither by construction.
const RETENTION_CLASS: Record<string, string> = {
  [HILOS_ROTATION_STATE_CARRYING]: 'text-bg-info',
  [HILOS_ROTATION_STATE_DUE]: 'text-bg-warning',
  [HILOS_ROTATION_STATE_TAKEN]: 'text-bg-secondary',
}

/**
 * Declared as the loose HilosTableColumn rather than the row-typed form: the Files
 * column is four counts at once and belongs to no single field, so keying it to
 * one of them would name the column after a quarter of what it shows. The sortable
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
 * Taking the word back is offered on a taken batch and on no other: it is the
 * correction of a click, and there is nothing to correct anywhere else.
 *
 * @param row The batch the trigger would belong to.
 */
function offersUndo(row: HilosLogRotationRow): boolean {
  return row.retentionState === HILOS_ROTATION_STATE_TAKEN
}

/**
 * The framework rotation-history screen: the rule in force, the windowed batch
 * table with its filters and empty states, and the two dialogs — carrying a batch
 * off, and taking that word back.
 *
 * @param props The project context (scope stores, connection, action lifecycle).
 */
export function HilosLogsRotationsPage({
  context,
}: HilosLogsRotationsPageProps) {
  // The navigator the switch reads its entry value from and writes its choice to;
  // mounted without one, the screen opens on All and leaves the address alone.
  const router = useContext(HilosRouterContext)
  const rotations = useMemo(
    () => createHilosLogRotationsTable(context, router ?? undefined),
    [context, router],
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

  // The search hint follows the same header, so the field never offers a dimension
  // the list cannot match on.
  const searchPlaceholder = rotationsSearchPlaceholder(header)

  // Domain filters: the node and the state ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter. The state is
  // read from the table itself rather than kept here, so the generic "Reset filters"
  // moves the switch along with the rows.
  const [nodeFilter, setNodeFilter] = useState('')
  const stateFilter = useSignal(rotations.state)

  function setNode(value: string): void {
    setNodeFilter(value)
    rotationsTable.setFilter(ROTATION_FILTER_NODE, value)
  }

  function setState(value: string): void {
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

  // Taking the word back (HIL-759). The judge is the physical batch and never a
  // timer in this tab — the node refuses only when the directory is gone.
  const [undoOpen, setUndoOpen] = useState(false)
  const [undoRow, setUndoRow] = useState<HilosLogRotationRow | null>(null)
  const undo = useTrackedAction()
  // What the batch's own node promises: the instant its cleaner may first take it.
  // One word for one actor on this screen — the class behind it is a pruner, but
  // the operator has been reading "cleaner" since the takeout modal. Null is the
  // installation that told it not to wait, and that is said in words too: a blank
  // would read as "we do not know" rather than "at any moment".
  const undoDeadline =
    undoRow?.pruneNotBefore == null
      ? 'The cleaner may delete this batch as soon as it next runs.'
      : `The cleaner may delete this batch after ${new Date(undoRow.pruneNotBefore * 1000).toLocaleString()}.`

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

  function openUndo(row: HilosLogRotationRow): void {
    undo.clearError()
    setUndoRow(row)
    setUndoOpen(true)
  }

  async function submitUndo(): Promise<void> {
    if (undoRow === null || undo.busy) {
      return
    }
    // Closes on the server's word, like the confirmation: the one refusal this can
    // meet — the batch is no longer on the node — is exactly what the operator has
    // to see instead of a modal that closed as though it had worked.
    if (await undo.run(rotationsActions.sendTakeoutUndo(undoRow))) {
      setUndoOpen(false)
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
          to={LOG_SETTINGS_HREF}
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
        searchPlaceholder={searchPlaceholder}
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
              {/* A link by sight and a button by nature, the way the legend trigger
              below is: the design asks for a link because withdrawing is not the
              action the row is there for, but this one opens a dialog and navigates
              nowhere, so an <a href="#"> would answer a ctrl-click with a pointless
              new tab and announce itself to a screen reader as a link. */}
              {offersUndo(row) ? (
                <button
                  type="button"
                  className="btn btn-link btn-sm p-0 align-baseline"
                  data-id="hilos-rotation-undo"
                  onClick={() => openUndo(row)}
                >
                  I did not carry this one off
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
                {clustered
                  ? 'No node could read its log store.'
                  : 'The log store could not be read.'}{' '}
                Check the log directory setting and the permissions on it.
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
        — four numbers in a row: daemon / agent / worker / monopolistic worker.
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
        initialFocus="dialog"
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
            {/* The spacing below each block is this modal's layout; the block
                itself is the same bare one every long output gets. */}
            <div className="mb-3">
              <HilosLongText
                kind="output"
                text={takeoutAddress}
                dataId="hilos-rotation-takeout-path"
              />
            </div>
            <div className="fw-semibold mb-1">How to take it</div>
            <div className="mb-3">
              <HilosLongText
                kind="output"
                text={takeoutCommand}
                dataId="hilos-rotation-takeout-command"
              />
            </div>
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
            Once confirmed, the batch becomes available to the cleaner — but not
            straight away: this node keeps a confirmed batch for a while, and
            you can take the confirmation back for as long as the batch is
            there.
          </div>
        )}
      </HilosModal>

      <HilosModal
        open={undoOpen}
        title="Has the batch not been carried off?"
        closeOnBackdrop={!undo.busy}
        closeOnEsc={!undo.busy}
        initialFocus="dialog"
        onClose={() => setUndoOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={undo.busy}
              onClick={requestClose}
            >
              Leave it as it is
            </button>
            <LoadingButton
              className="btn-primary"
              loading={undo.loading}
              data-id="hilos-rotation-undo-confirm"
              onClick={() => void submitUndo()}
            >
              Withdraw the acknowledgement
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={undo} />
        <p>
          Your word that you have taken it is the only thing that lets the
          cleaner delete this batch. Take that word back and the batch returns
          to the list of the ones recommended for carrying off.
        </p>
        <p
          className="small text-body-secondary"
          data-id="hilos-rotation-undo-deadline"
        >
          {undoDeadline}
        </p>
        <div className="alert alert-secondary small py-2 mb-0">
          It can only be taken back while the batch is still there. Once the
          cleaner has passed there is nothing to bring back — which is exactly
          why deleting waits for your word.
        </div>
      </HilosModal>

      <HilosModal
        open={legendOpen}
        title="What is in a batch"
        initialFocus="dialog"
        onClose={() => setLegendOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="hilos-rotation-legend-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <p>
          A batch is one archive directory, written by one rotation on one node.
          The four numbers count the files in it by the stream that wrote them:
        </p>
        <ul className="mb-3">
          <li>
            <strong>daemon</strong> — the node's own two streams, daemon.log and
            daemon-error.log. The raw pair beside them stays live through an
            ordinary rotation and joins a batch only when the daemon restarts,
            the one pass that takes everything.
          </li>
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
          The four numbers add up to every file of the batch, and the weight
          beside them is what those files cost. Nothing in a batch goes unnamed:
          a class left out of the counts but kept in the weight makes the two
          stop agreeing, and the row reads as a counting error.
        </p>
      </HilosModal>
    </HilosAdminPage>
  )
}
