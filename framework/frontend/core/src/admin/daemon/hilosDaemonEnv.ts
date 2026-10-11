// Framework headless environment tables for one Daemon node. Rows come from
// the node's local reading through server viewports, in catalog or file order.
// The page header, summaries, and empty states belong to the view.
// SCAFFOLD: HIL-1395 will mount these tables and draw their cells in Vue.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import {
  HIDDEN_VALUE,
  type HiddenValue,
  isHiddenValue,
} from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

const DAEMON_ENV_KEYS_TABLE = 'hilosDaemonEnvKeys'
const DAEMON_ENV_KEYS_SLOT = 'key'
const DAEMON_ENV_ORPHANS_TABLE = 'hilosDaemonEnvOrphans'
const DAEMON_ENV_ORPHANS_SLOT = 'orphan'

/** Viewport filter key identifying the node shown by the page. */
export const DAEMON_ENV_FILTER_NODE = 'node'
/** Viewport filter key selecting keys that need attention. */
export const DAEMON_ENV_FILTER_ATTENTION = 'attention'

/** Row payload key of the environment key. */
export const DAEMON_ENV_KEY_FIELD = 'key'
/** Row payload key of the declared value type. */
export const DAEMON_ENV_TYPE_FIELD = 'type'
/** Row payload key of the required flag. */
export const DAEMON_ENV_REQUIRED_FIELD = 'required'
/** Row payload key of the catalog declarer. */
export const DAEMON_ENV_DECLARED_BY_FIELD = 'declaredBy'
/** Row payload key of the sensitive flag. */
export const DAEMON_ENV_SENSITIVE_FIELD = 'sensitive'
/** Row payload key of the per-node flag. */
export const DAEMON_ENV_PER_NODE_FIELD = 'perNode'
/** Row payload key of the live value's source. */
export const DAEMON_ENV_SOURCE_FIELD = 'source'
/** Row payload key of the masked live value. */
export const DAEMON_ENV_VALUE_FIELD = 'value'
/** Row payload key of a value that differs on disk. */
export const DAEMON_ENV_DISK_FIELD = 'disk'
/** Row payload key of the nearest catalog key to an orphan. */
export const DAEMON_ENV_SIMILAR_TO_FIELD = 'similarTo'

/** Explanation of the node-local mark beside a key. */
export const DAEMON_ENV_PER_NODE_HINT =
  'Belongs to the node: each node has its own value'
/** Explanation of the sensitive mark beside a key. */
export const DAEMON_ENV_SENSITIVE_HINT =
  'The catalog declares this key sensitive'

/** The source that supplied an environment value. */
export type HilosDaemonEnvSource =
  | 'process'
  | 'env'
  | 'example'
  | 'default'
  | 'missing'

/** A value as the node may send it to an administrator or viewer. */
export type HilosDaemonEnvValue =
  | { readonly kind: 'text'; readonly text: string }
  | { readonly kind: 'secret'; readonly length: number }
  | { readonly kind: 'none' }
  | HiddenValue

/** One catalog key in the selected node's environment. */
export interface HilosDaemonEnvKeyRow {
  readonly rowKey: string
  readonly key: string
  readonly type: string
  readonly required: boolean
  readonly declaredBy: string
  readonly sensitive: boolean
  readonly perNode: boolean
  readonly source: HilosDaemonEnvSource
  readonly value: HilosDaemonEnvValue
  readonly disk: {
    readonly source: HilosDaemonEnvSource
    readonly value: HilosDaemonEnvValue
  } | null
}

/** One uncataloged line in the selected node's .env file. */
export interface HilosDaemonEnvOrphanRow {
  readonly rowKey: string
  readonly key: string
  readonly value: HilosDaemonEnvValue
  readonly similarTo: string | null
}

/** Connection and page scope used by both environment tables. */
export interface HilosDaemonEnvContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Table controller and its view mount lifecycle. */
export interface HilosDaemonEnvTable<TRow> {
  readonly controller: TableViewportController<TRow>
  start(): void
  dispose(): void
}

const valueSchema = z.discriminatedUnion('kind', [
  z.object({ kind: z.literal('text'), text: z.string() }),
  z.object({
    kind: z.literal('secret'),
    length: z.number().int().nonnegative(),
  }),
  z.object({ kind: z.literal('none') }),
])

/** Read one inline row slot, or an empty record when malformed. */
function recordSlot(value: unknown): Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/** Narrow an unknown source to the node reader's five values. */
function toEnvSource(value: unknown): HilosDaemonEnvSource {
  return value === 'process' ||
    value === 'env' ||
    value === 'example' ||
    value === 'default'
    ? value
    : 'missing'
}

/** Keep the hidden mark distinct from a missing or malformed value. */
function readEnvValue(value: unknown): HilosDaemonEnvValue {
  if (isHiddenValue(value)) {
    return HIDDEN_VALUE
  }
  const parsed = valueSchema.safeParse(value)
  return parsed.success ? parsed.data : { kind: 'none' }
}

/**
 * Read one catalog key slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonEnvKeyRow(
  row: TableRow,
): HilosDaemonEnvKeyRow {
  const slot = recordSlot(row.slots[DAEMON_ENV_KEYS_SLOT])
  const disk = slot[DAEMON_ENV_DISK_FIELD]
  const diskFields =
    typeof disk === 'object' && disk !== null && !Array.isArray(disk)
      ? recordSlot(disk)
      : null

  return {
    rowKey: String(row.rowKey),
    key: readString(slot, DAEMON_ENV_KEY_FIELD),
    type: readString(slot, DAEMON_ENV_TYPE_FIELD),
    required: readBoolean(slot, DAEMON_ENV_REQUIRED_FIELD),
    declaredBy: readString(slot, DAEMON_ENV_DECLARED_BY_FIELD),
    sensitive: readBoolean(slot, DAEMON_ENV_SENSITIVE_FIELD),
    perNode: readBoolean(slot, DAEMON_ENV_PER_NODE_FIELD),
    source: toEnvSource(slot[DAEMON_ENV_SOURCE_FIELD]),
    value: readEnvValue(slot[DAEMON_ENV_VALUE_FIELD]),
    disk:
      diskFields === null
        ? null
        : {
            source: toEnvSource(diskFields[DAEMON_ENV_SOURCE_FIELD]),
            value: readEnvValue(diskFields[DAEMON_ENV_VALUE_FIELD]),
          },
  }
}

/**
 * Read one orphan slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonEnvOrphanRow(
  row: TableRow,
): HilosDaemonEnvOrphanRow {
  const slot = recordSlot(row.slots[DAEMON_ENV_ORPHANS_SLOT])
  return {
    rowKey: String(row.rowKey),
    key: readString(slot, DAEMON_ENV_KEY_FIELD),
    value: readEnvValue(slot[DAEMON_ENV_VALUE_FIELD]),
    similarTo: readStringOrNull(slot, DAEMON_ENV_SIMILAR_TO_FIELD),
  }
}

/** Build one environment viewport with the shared node filter and lifecycle. */
function createEnvironmentTable<TRow>(
  context: HilosDaemonEnvContext,
  nodeId: string,
  tableKey: string,
  resolve: (row: TableRow) => TRow,
  frame: HilosTableFrame,
): HilosDaemonEnvTable<TRow> {
  const controller = new TableViewportController<TRow>({
    resolve,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_ENV,
        tableKey,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_ENV,
        tableKey,
        rendered,
      ),
    initialFilter: { [DAEMON_ENV_FILTER_NODE]: nodeId },
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
          { page: HilosPages.DAEMON_ENV, tableKey },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Build the catalog-key viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonEnvKeysTable(
  context: HilosDaemonEnvContext,
  nodeId: string,
): HilosDaemonEnvTable<HilosDaemonEnvKeyRow> {
  const columns: HilosTableColumnOf<HilosDaemonEnvKeyRow>[] = [
    {
      key: DAEMON_ENV_KEY_FIELD,
      label: 'Key',
      card: 'title',
      reads: [DAEMON_ENV_PER_NODE_FIELD, DAEMON_ENV_SENSITIVE_FIELD],
    },
    { key: DAEMON_ENV_TYPE_FIELD, label: 'Type' },
    {
      key: DAEMON_ENV_VALUE_FIELD,
      label: 'Value and source',
      reads: [
        DAEMON_ENV_SOURCE_FIELD,
        DAEMON_ENV_SENSITIVE_FIELD,
        DAEMON_ENV_TYPE_FIELD,
        DAEMON_ENV_DISK_FIELD,
      ],
    },
    { key: DAEMON_ENV_REQUIRED_FIELD, label: 'Required' },
    { key: DAEMON_ENV_DECLARED_BY_FIELD, label: 'Declared by' },
  ]
  const frame: HilosTableFrame = {
    title: 'Catalog keys',
    search: { placeholder: 'Key or value' },
    filters: [
      {
        kind: 'toggle',
        key: DAEMON_ENV_FILTER_ATTENTION,
        label: 'Needs attention only',
        on: true,
      },
    ],
    columns,
  }
  return createEnvironmentTable(
    context,
    nodeId,
    DAEMON_ENV_KEYS_TABLE,
    resolveHilosDaemonEnvKeyRow,
    frame,
  )
}

/**
 * Build the uncataloged .env viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonEnvOrphansTable(
  context: HilosDaemonEnvContext,
  nodeId: string,
): HilosDaemonEnvTable<HilosDaemonEnvOrphanRow> {
  const columns: HilosTableColumnOf<HilosDaemonEnvOrphanRow>[] = [
    { key: DAEMON_ENV_KEY_FIELD, label: 'Line in file', card: 'title' },
    { key: DAEMON_ENV_VALUE_FIELD, label: 'Value' },
    { key: DAEMON_ENV_SIMILAR_TO_FIELD, label: 'Looks like' },
  ]
  return createEnvironmentTable(
    context,
    nodeId,
    DAEMON_ENV_ORPHANS_TABLE,
    resolveHilosDaemonEnvOrphanRow,
    { title: 'Orphans in .env', columns },
  )
}

/** The content of one value cell, without view-specific markup. */
export type HilosDaemonEnvValueView =
  | { readonly kind: 'hidden' }
  | { readonly kind: 'secret'; readonly text: string }
  | { readonly kind: 'none'; readonly text: '—' }
  | { readonly kind: 'empty' }
  | { readonly kind: 'boolean'; readonly on: boolean }
  | { readonly kind: 'text'; readonly text: string; readonly numeric: boolean }

/**
 * Format a masked value for the cell without exposing hidden content.
 *
 * @param value Value received from the node.
 * @param type Catalog type of the key.
 * @param sensitive Whether the catalog marks this key sensitive.
 */
export function formatDaemonEnvValue(
  value: HilosDaemonEnvValue,
  type: string,
  sensitive: boolean,
): HilosDaemonEnvValueView {
  if (isHiddenValue(value)) {
    return { kind: 'hidden' }
  }
  if (value.kind === 'secret') {
    return { kind: 'secret', text: `Set · ${value.length} chars` }
  }
  if (value.kind === 'none') {
    return sensitive
      ? { kind: 'secret', text: 'Not set' }
      : { kind: 'none', text: '—' }
  }
  if (value.text === '') {
    return { kind: 'empty' }
  }
  if (type === 'boolean') {
    const normalized = value.text.trim().toLowerCase()
    if (['1', 'true', 'yes', 'on'].includes(normalized)) {
      return { kind: 'boolean', on: true }
    }
    if (['0', 'false', 'no', 'off'].includes(normalized)) {
      return { kind: 'boolean', on: false }
    }
  }
  return {
    kind: 'text',
    text: value.text,
    numeric: type === 'integer' || type === 'float',
  }
}

/**
 * Give the source badge and the reading ladder the same words.
 *
 * @param source Origin of the effective or on-disk value.
 */
export function daemonEnvSourceBadge(source: HilosDaemonEnvSource): {
  label: string
  tone: 'primary' | 'success' | 'warning' | 'secondary' | 'danger'
  hint: string
} {
  switch (source) {
    case 'process':
      return {
        label: 'Launch environment',
        tone: 'primary',
        hint: 'Set by whoever started the daemon: compose, systemd, a shell. .env does not override it.',
      }
    case 'env':
      return {
        label: '.env',
        tone: 'success',
        hint: 'Set in the .env file next to the project.',
      }
    case 'example':
      return {
        label: '.env.example',
        tone: 'warning',
        hint: 'Came from the sample file: a placeholder, not a setting.',
      }
    case 'default':
      return {
        label: 'Catalog default',
        tone: 'secondary',
        hint: 'No value anywhere; the default declared in the catalog is read.',
      }
    case 'missing':
      return {
        label: 'No value',
        tone: 'danger',
        hint: 'Not in the launch environment, not in the files, and no default.',
      }
  }
}

/**
 * Name the differing on-disk value and its source, if one exists.
 *
 * @param row Catalog key being drawn.
 */
export function formatDaemonEnvDrift(row: HilosDaemonEnvKeyRow): string | null {
  if (row.disk === null) {
    return null
  }
  const value = row.disk.value
  const text = isHiddenValue(value)
    ? 'hidden'
    : value.kind === 'text'
      ? value.text === ''
        ? 'empty'
        : value.text
      : value.kind === 'secret'
        ? `set · ${value.length} chars`
        : 'no value'
  return `On disk: ${text} · ${daemonEnvSourceBadge(row.disk.source).label}`
}

/**
 * Classify what requires attention, with missing values before sample and drift.
 *
 * @param row Catalog key being drawn.
 */
export function daemonEnvTrouble(
  row: HilosDaemonEnvKeyRow,
): 'missing' | 'example' | 'drift' | null {
  if (row.required && row.source === 'missing') {
    return 'missing'
  }
  if (row.source === 'example') {
    return 'example'
  }
  return row.disk === null ? null : 'drift'
}

/** @param row Catalog key being drawn. */
export function formatDaemonEnvRequired(
  row: HilosDaemonEnvKeyRow,
): 'Required' | 'Optional' {
  return row.required ? 'Required' : 'Optional'
}

/** @param row Catalog key being drawn. */
export function formatDaemonEnvDeclaredBy(row: HilosDaemonEnvKeyRow): string {
  return row.declaredBy === 'framework'
    ? 'Framework'
    : row.declaredBy === 'project'
      ? 'Project'
      : row.declaredBy
}

/** @param row Uncataloged line being drawn. */
export function formatDaemonEnvSimilarTo(row: HilosDaemonEnvOrphanRow): string {
  return row.similarTo ?? '—'
}
