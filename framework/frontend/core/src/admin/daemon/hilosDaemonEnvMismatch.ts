// Framework headless table for the cluster environment verdict. The page
// supplies its node list; rows come from the page worker's picture mirror.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumber,
  readString,
} from '../../state/fieldReaders.js'
import { HIDDEN_VALUE, isHiddenValue } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  formatDaemonEnvValue,
  type HilosDaemonEnvSource,
  type HilosDaemonEnvValue,
  type HilosDaemonEnvValueView,
} from './hilosDaemonEnv.js'

const DAEMON_ENV_MISMATCH_TABLE = 'hilosDaemonEnvMismatch'
const DAEMON_ENV_MISMATCH_SLOT = 'key'

/** Row payload key of the server's verdict bucket. */
const DAEMON_ENV_MISMATCH_KIND_FIELD = 'kind'
/** Row payload key of the zero-based place in the complete verdict set. */
const DAEMON_ENV_MISMATCH_POSITION_FIELD = 'position'
/** Values or declarations differ across answering nodes. */
export const DAEMON_ENV_MISMATCH_DIVERGED = 'diverged'
/** Values agree but their sources differ across answering nodes. */
export const DAEMON_ENV_MISMATCH_SOURCES_DIFFER = 'sourcesDiffer'

/** Row payload key of the compared environment key. */
export const DAEMON_ENV_MISMATCH_KEY_FIELD = 'key'
/** Row payload key of the serving node's sensitivity verdict. */
export const DAEMON_ENV_MISMATCH_SENSITIVE_FIELD = 'sensitive'
/** Row payload key of the serving node's masked value. */
export const DAEMON_ENV_MISMATCH_VALUE_FIELD = 'value'
/** Row payload key of the per-node comparison cells. */
export const DAEMON_ENV_MISMATCH_CELLS_FIELD = 'cells'

/** Page-data key of the cluster environment mismatch summary. */
export const DAEMON_ENV_MISMATCH_DATA = 'envMismatch'

export const daemonEnvMismatchSummarySchema = z.strictObject({
  clustered: z.boolean(),
  servingNodeId: z.string(),
  comparison: z
    .strictObject({
      nodes: z.array(z.string()),
      answering: z.array(z.string()),
      diverged: z.number().int(),
      sourcesDiffer: z.number().int(),
      perNode: z.array(z.string()),
    })
    .nullable(),
})

export type HilosDaemonEnvMismatchSummary = z.infer<
  typeof daemonEnvMismatchSummarySchema
>

/**
 * Read the current page's environment mismatch summary, including resends.
 *
 * @param scopes The page scope manager.
 */
export function readHilosDaemonEnvMismatchSummary(
  scopes: ScopeManager,
): ReadonlySignal<HilosDaemonEnvMismatchSummary | null> {
  const source = scopes.pageDataSignal(DAEMON_ENV_MISMATCH_DATA)
  return computedSignal(() => {
    const parsed = daemonEnvMismatchSummarySchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/** The node supplied a comparable fingerprint. */
export const DAEMON_ENV_CELL_KNOWN = 'known'
/** The node was silent or supplied no environment reading. */
export const DAEMON_ENV_CELL_UNKNOWN = 'unknown'
/** The node answered but did not declare this key. */
export const DAEMON_ENV_CELL_UNDECLARED = 'undeclared'

const valueSchema = z.discriminatedUnion('kind', [
  z.object({ kind: z.literal('text'), text: z.string() }),
  z.object({ kind: z.literal('none') }),
])
const cellsSchema = z.array(
  z.object({
    nodeId: z.string(),
    state: z.enum([
      DAEMON_ENV_CELL_KNOWN,
      DAEMON_ENV_CELL_UNKNOWN,
      DAEMON_ENV_CELL_UNDECLARED,
    ]),
    type: z.string().nullable(),
    source: z.string().nullable(),
    digest: z.string().nullable(),
  }),
)

/** A node the page names as one column of the verdict table. */
export interface HilosDaemonEnvMismatchNode {
  readonly nodeId: string
  readonly serving: boolean
}

/**
 * Extract the comparison nodes from the summary, identifying the serving node.
 *
 * @param summary Cluster environment mismatch summary, if available.
 */
export function daemonEnvMismatchNodes(
  summary: HilosDaemonEnvMismatchSummary | null,
): readonly HilosDaemonEnvMismatchNode[] {
  if (summary === null || summary.comparison === null) {
    return []
  }
  return summary.comparison.nodes.map((nodeId) => ({
    nodeId,
    serving: nodeId === summary.servingNodeId,
  }))
}

export type HilosDaemonEnvMismatchEmptyState = 'alone' | 'agree'

/**
 * Determine the empty-state message for the combined keys table.
 *
 * @param comparison Active comparison cluster summary.
 */
export function daemonEnvMismatchEmptyState(
  comparison: NonNullable<HilosDaemonEnvMismatchSummary['comparison']>,
): HilosDaemonEnvMismatchEmptyState {
  return comparison.answering.length < 2 ? 'alone' : 'agree'
}

/** The comparison reading for one node and one key. */
export interface HilosDaemonEnvMismatchCell {
  readonly nodeId: string
  readonly state:
    | typeof DAEMON_ENV_CELL_KNOWN
    | typeof DAEMON_ENV_CELL_UNKNOWN
    | typeof DAEMON_ENV_CELL_UNDECLARED
  readonly type: string | null
  readonly source: HilosDaemonEnvSource | null
  readonly digest: string | null
}

/** One key of either verdict bucket. */
export interface HilosDaemonEnvMismatchRow {
  readonly rowKey: string
  readonly key: string
  readonly kind:
    | typeof DAEMON_ENV_MISMATCH_DIVERGED
    | typeof DAEMON_ENV_MISMATCH_SOURCES_DIFFER
  readonly position: number
  readonly sensitive: boolean
  readonly value: HilosDaemonEnvValue | null
  readonly cells: readonly HilosDaemonEnvMismatchCell[]
}

/** Column addresses include virtual node cells without extending the row payload. */
type HilosDaemonEnvMismatchColumnRow = HilosDaemonEnvMismatchRow &
  Partial<Record<`node:${string}`, HilosDaemonEnvMismatchCell>>

/** Connection and page scope used by the combined verdict table. */
export interface HilosDaemonEnvMismatchContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** The combined table controller and its view mount lifecycle. */
export interface HilosDaemonEnvMismatchTable {
  readonly controller: TableViewportController<HilosDaemonEnvMismatchRow>
  start(): void
  dispose(): void
}

/** View-independent content of a comparison cell. */
export type HilosDaemonEnvMismatchCellView =
  | { readonly kind: 'unknown'; readonly text: '?' }
  | { readonly kind: 'undeclared'; readonly text: 'Not declared' }
  | {
      readonly kind: 'none'
      readonly text: '—'
      readonly source: 'missing'
    }
  | {
      readonly kind: 'value'
      readonly view: HilosDaemonEnvValueView
      readonly source: HilosDaemonEnvSource
    }
  | {
      readonly kind: 'digest'
      readonly text: string
      readonly source: HilosDaemonEnvSource
    }

/** Narrow a source supplied by a node to the five declared sources. */
function toEnvSource(value: string): HilosDaemonEnvSource {
  return value === 'process' ||
    value === 'env' ||
    value === 'example' ||
    value === 'default'
    ? value
    : 'missing'
}

/** Read one comparison row from its inline `key` slot. */
export function resolveHilosDaemonEnvMismatchRow(
  row: TableRow,
): HilosDaemonEnvMismatchRow {
  const raw = row.slots[DAEMON_ENV_MISMATCH_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const rawValue = slot[DAEMON_ENV_MISMATCH_VALUE_FIELD]
  const parsedValue = valueSchema.safeParse(rawValue)
  const parsedCells = cellsSchema.safeParse(
    slot[DAEMON_ENV_MISMATCH_CELLS_FIELD],
  )

  return {
    rowKey: String(row.rowKey),
    key: readString(slot, DAEMON_ENV_MISMATCH_KEY_FIELD),
    kind:
      slot[DAEMON_ENV_MISMATCH_KIND_FIELD] ===
      DAEMON_ENV_MISMATCH_SOURCES_DIFFER
        ? DAEMON_ENV_MISMATCH_SOURCES_DIFFER
        : DAEMON_ENV_MISMATCH_DIVERGED,
    position: readNumber(slot, DAEMON_ENV_MISMATCH_POSITION_FIELD),
    sensitive: readBoolean(slot, DAEMON_ENV_MISMATCH_SENSITIVE_FIELD),
    value:
      rawValue === null
        ? null
        : isHiddenValue(rawValue)
          ? HIDDEN_VALUE
          : parsedValue.success
            ? parsedValue.data
            : null,
    cells: parsedCells.success
      ? parsedCells.data.map((cell) => ({
          ...cell,
          source: cell.source === null ? null : toEnvSource(cell.source),
        }))
      : [],
  }
}

/**
 * Build the combined unfiltered viewport for the cluster environment verdict.
 *
 * @param context Connection and page scope.
 * @param nodes Current nodes in page order, including the serving node.
 */
export function createHilosDaemonEnvMismatchTable(
  context: HilosDaemonEnvMismatchContext,
  nodes: ReadonlySignal<readonly HilosDaemonEnvMismatchNode[]>,
): HilosDaemonEnvMismatchTable {
  const columns = (): HilosTableColumnOf<HilosDaemonEnvMismatchColumnRow>[] => [
    {
      key: DAEMON_ENV_MISMATCH_KEY_FIELD,
      label: 'Key',
      card: 'title',
      reads: [
        DAEMON_ENV_MISMATCH_SENSITIVE_FIELD,
        DAEMON_ENV_MISMATCH_KIND_FIELD,
      ],
    },
    ...nodes
      .get()
      .map((node): HilosTableColumnOf<HilosDaemonEnvMismatchColumnRow> => ({
        key: daemonEnvMismatchNodeColumnKey(node.nodeId),
        label: formatDaemonEnvMismatchNodeLabel(node),
        reads: [
          DAEMON_ENV_MISMATCH_CELLS_FIELD,
          DAEMON_ENV_MISMATCH_VALUE_FIELD,
          DAEMON_ENV_MISMATCH_KIND_FIELD,
        ],
      })),
  ]
  const frame: HilosTableFrame = { columns }
  const controller = new TableViewportController<HilosDaemonEnvMismatchRow>({
    resolve: resolveHilosDaemonEnvMismatchRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_ENV_MISMATCH,
        DAEMON_ENV_MISMATCH_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_ENV_MISMATCH,
        DAEMON_ENV_MISMATCH_TABLE,
        rendered,
      ),
    frame,
  })
  const teardown: Array<() => void> = []
  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.DAEMON_ENV_MISMATCH,
            tableKey: DAEMON_ENV_MISMATCH_TABLE,
          },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
    },
  }
}

/** @param nodeId Id of a node named by the page. */
export function daemonEnvMismatchNodeColumnKey(
  nodeId: string,
): `node:${string}` {
  return `node:${nodeId}`
}

/** @param node Node named in a table header. */
export function formatDaemonEnvMismatchNodeLabel(
  node: HilosDaemonEnvMismatchNode,
): string {
  return node.serving ? `${node.nodeId} · this node` : node.nodeId
}

/** Return the cell for a node, if this row contains one. */
export function daemonEnvMismatchCellOf(
  row: HilosDaemonEnvMismatchRow,
  nodeId: string,
): HilosDaemonEnvMismatchCell | null {
  return row.cells.find((cell) => cell.nodeId === nodeId) ?? null
}

/** Find the shortest prefix that separates different labels within this row. */
export function daemonEnvMismatchDigestLength(
  row: HilosDaemonEnvMismatchRow,
): number {
  const digests = [
    ...new Set(
      row.cells
        .filter((cell) => cell.state === DAEMON_ENV_CELL_KNOWN)
        .map((cell) => cell.digest)
        .filter((digest): digest is string => digest !== null),
    ),
  ]
  let length = 4
  while (
    length < 16 &&
    digests.some((digest, index) =>
      digests
        .slice(index + 1)
        .some((other) => digest.slice(0, length) === other.slice(0, length)),
    )
  ) {
    length += 1
  }
  return length
}

/** Whether known cells disagree about the catalog value type. */
export function daemonEnvMismatchTypesDiffer(
  row: HilosDaemonEnvMismatchRow,
): boolean {
  return (
    new Set(
      row.cells
        .filter((cell) => cell.state === DAEMON_ENV_CELL_KNOWN)
        .map((cell) => cell.type),
    ).size > 1
  )
}

/**
 * Give a node cell its text or masked serving-node value.
 *
 * @param row Compared key.
 * @param nodeId Node named by the column.
 * @param servingNodeId Node whose local value the row may carry.
 */
export function daemonEnvMismatchCellView(
  row: HilosDaemonEnvMismatchRow,
  nodeId: string,
  servingNodeId: string | null,
): HilosDaemonEnvMismatchCellView {
  const cell = daemonEnvMismatchCellOf(row, nodeId)
  if (cell === null || cell.state === DAEMON_ENV_CELL_UNKNOWN) {
    return { kind: 'unknown', text: '?' }
  }
  if (cell.state === DAEMON_ENV_CELL_UNDECLARED) {
    return { kind: 'undeclared', text: 'Not declared' }
  }
  const source = cell.source ?? 'missing'
  if (source === 'missing') {
    return { kind: 'none', text: '—', source }
  }
  if (
    row.value !== null &&
    (nodeId === servingNodeId ||
      row.kind === DAEMON_ENV_MISMATCH_SOURCES_DIFFER)
  ) {
    return {
      kind: 'value',
      view: formatDaemonEnvValue(row.value, cell.type ?? 'string', false),
      source,
    }
  }
  const text = `#${(cell.digest ?? '').slice(0, daemonEnvMismatchDigestLength(row))}`
  return {
    kind: 'digest',
    text: daemonEnvMismatchTypesDiffer(row) ? `${text} · ${cell.type}` : text,
    source,
  }
}

/** Whether a known value-and-type pair occurs only on this node. */
export function daemonEnvMismatchStandsOut(
  row: HilosDaemonEnvMismatchRow,
  nodeId: string,
): boolean {
  const cell = daemonEnvMismatchCellOf(row, nodeId)
  if (cell?.state === DAEMON_ENV_CELL_UNDECLARED) {
    return true
  }
  return (
    cell?.state === DAEMON_ENV_CELL_KNOWN &&
    !row.cells.some(
      (other) =>
        other !== cell &&
        other.state === DAEMON_ENV_CELL_KNOWN &&
        other.type === cell.type &&
        other.digest === cell.digest,
    )
  )
}

/** Whether the node read a placeholder from .env.example. */
export function daemonEnvMismatchFromExample(
  row: HilosDaemonEnvMismatchRow,
  nodeId: string,
): boolean {
  const cell = daemonEnvMismatchCellOf(row, nodeId)
  return cell?.state === DAEMON_ENV_CELL_KNOWN && cell.source === 'example'
}
