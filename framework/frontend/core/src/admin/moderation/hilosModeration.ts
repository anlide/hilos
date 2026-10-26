import { type Hideable } from '../../state/hiddenValue.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  readHideableString,
  readNumber,
  readString,
} from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** The moderation section binding context. */
export interface HilosModerationContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** One published kind on the moderation administration table. */
export interface HilosModerationKindRow {
  readonly kind: string
  readonly title: string
  readonly owner: string
  readonly input: string
  readonly model: Hideable<string>
  readonly ownRuleCount: number
  readonly profile: string
  readonly reasons: readonly string[]
}

/** Table key for moderation kinds. */
export const HILOS_MODERATION_KINDS_TABLE = 'hilosModerationKinds'

/** Slot key carrying the inline kind row. */
export const HILOS_MODERATION_KIND_SLOT = 'kind'

/** Wire keys of the moderation kind row slot. */
export const HilosModerationKindRowKey = {
  kind: 'kind',
  title: 'title',
  owner: 'owner',
  input: 'input',
  model: 'model',
  ownRuleCount: 'ownRuleCount',
  profile: 'profile',
  reasons: 'reasons',
} as const

/** Shared copy for the moderation kinds administration table. */
export const HILOS_MODERATION_COPY = {
  framework: 'Framework',
  project: 'Project',
  search: 'Search kinds…',
  empty: 'No moderation kinds declared.',
  open: 'Open',
  builtIn: 'Built-in',
} as const

/** Resolve the inline kind slot into its view-model. */
export function resolveHilosModerationKindRow(
  row: TableRow,
): HilosModerationKindRow {
  const slot = row.slots[HILOS_MODERATION_KIND_SLOT]
  const record =
    typeof slot === 'object' && slot !== null && !Array.isArray(slot)
      ? (slot as Record<string, unknown>)
      : {}
  const reasons = record[HilosModerationKindRowKey.reasons]

  return {
    kind:
      readString(record, HilosModerationKindRowKey.kind) || String(row.rowKey),
    title: readString(record, HilosModerationKindRowKey.title),
    owner: readString(record, HilosModerationKindRowKey.owner),
    input: readString(record, HilosModerationKindRowKey.input),
    model: readHideableString(record, HilosModerationKindRowKey.model),
    ownRuleCount: readNumber(record, HilosModerationKindRowKey.ownRuleCount),
    profile: readString(record, HilosModerationKindRowKey.profile),
    reasons:
      Array.isArray(reasons) &&
      reasons.every((reason): reason is string => typeof reason === 'string')
        ? reasons
        : [],
  }
}

/** The moderation kinds table handle used by all three views. */
export interface HilosModerationKindsTable {
  readonly controller: TableViewportController<HilosModerationKindRow>
  start(): void
  dispose(): void
}

const MODERATION_KINDS_COLUMNS: readonly HilosTableColumnOf<HilosModerationKindRow>[] =
  [
    {
      key: HilosModerationKindRowKey.title,
      label: 'Kind',
      sortable: true,
      card: 'title',
      reads: [HilosModerationKindRowKey.kind],
    },
    {
      key: HilosModerationKindRowKey.owner,
      label: 'Declared by',
      sortable: true,
    },
    {
      key: HilosModerationKindRowKey.model,
      label: 'Checked by',
      sortable: true,
      reads: [HilosModerationKindRowKey.input],
    },
    {
      key: HilosModerationKindRowKey.ownRuleCount,
      label: 'Rules',
      sortable: true,
    },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [HilosModerationKindRowKey.kind, HilosModerationKindRowKey.title],
    },
  ]

/**
 * What the moderation kinds table declares about its frame. No title: the page
 * heading already names it.
 */
const MODERATION_KINDS_FRAME: HilosTableFrame = {
  columns: MODERATION_KINDS_COLUMNS,
  search: { placeholder: HILOS_MODERATION_COPY.search },
  empty: { title: HILOS_MODERATION_COPY.empty },
}

/** Create and bind the server-windowed moderation kinds table. */
export function createHilosModerationKindsTable(
  context: HilosModerationContext,
): HilosModerationKindsTable {
  const controller = new TableViewportController<HilosModerationKindRow>({
    resolve: resolveHilosModerationKindRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.MODERATION,
        HILOS_MODERATION_KINDS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.MODERATION,
        HILOS_MODERATION_KINDS_TABLE,
        rendered,
      ),
    frame: MODERATION_KINDS_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.MODERATION,
            tableKey: HILOS_MODERATION_KINDS_TABLE,
          },
          controller,
        ),
      ]
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Path to a single moderation kind's view.
 *
 * @param kind Kind identifier.
 */
export function moderationKindPath(kind: string): string {
  return `/hilos/moderation/${encodeURIComponent(kind)}`
}
