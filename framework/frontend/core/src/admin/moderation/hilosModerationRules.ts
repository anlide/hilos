import { HilosPages } from '../../routing/hilosPages.js'
import { readNumber, readString } from '../../state/fieldReaders.js'
import { type Scope } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type TableViewportDescriptor } from '../../connection/HilosConnection.js'
import { type HilosModerationContext } from './hilosModeration.js'

/** Table key for a kind's own moderation rules. */
export const HILOS_MODERATION_RULES_TABLE = 'hilosModerationRules'

/** Slot carrying one rule. */
export const HILOS_MODERATION_RULE_SLOT = 'rule'

/** Wire keys of the moderation rule slot. */
export const HilosModerationRuleRowKey = {
  id: 'id',
  kind: 'kind',
  rule: 'rule',
} as const

/** One rule belonging to the kind in the address. */
export interface HilosModerationRuleRow {
  readonly id: number
  readonly kind: string
  readonly rule: string
}

/** Resolve a rule's inline slot without coercing structured values to text. */
export function resolveHilosModerationRuleRow(
  row: TableRow,
): HilosModerationRuleRow {
  const slot = row.slots[HILOS_MODERATION_RULE_SLOT]
  const record =
    typeof slot === 'object' && slot !== null && !Array.isArray(slot)
      ? (slot as Record<string, unknown>)
      : {}
  return {
    id: readNumber(record, HilosModerationRuleRowKey.id),
    kind: readString(record, HilosModerationRuleRowKey.kind),
    rule: readString(record, HilosModerationRuleRowKey.rule),
  }
}

/** The route-bound rules table and provenance of its accepted window. */
export interface HilosModerationRules {
  readonly controller: TableViewportController<HilosModerationRuleRow>
  readonly current: ReadonlySignal<boolean>
  start(): void
  dispose(): void
}

const RULES_COLUMNS: readonly HilosTableColumnOf<HilosModerationRuleRow>[] = [
  {
    key: HilosModerationRuleRowKey.rule,
    label: 'Rule',
    sortable: true,
    card: 'title',
    heightContent: 'text',
    reads: [HilosModerationRuleRowKey.id, HilosModerationRuleRowKey.kind],
  },
]

/** Keep the route constraint on held windows as well as explicit requests. */
class ModerationRulesViewportController extends TableViewportController<HilosModerationRuleRow> {
  constructor(
    options: ConstructorParameters<
      typeof TableViewportController<HilosModerationRuleRow>
    >[0],
    private readonly project: (
      descriptor: TableViewportDescriptor,
    ) => TableViewportDescriptor | null,
    private readonly accept: () => void,
    private readonly matches: (rows: readonly TableRow[]) => boolean,
  ) {
    super(options)
  }

  override descriptor(): TableViewportDescriptor | null {
    const descriptor = super.descriptor()
    return descriptor === null ? null : this.project(descriptor)
  }

  override ingestWindow(
    ...args: Parameters<
      TableViewportController<HilosModerationRuleRow>['ingestWindow']
    >
  ): void {
    if (!this.acceptsWindowResponse(args[7]) || !this.matches(args[0])) return
    super.ingestWindow(...args)
    this.accept()
  }

  override ingestRefusal(
    ...args: Parameters<
      TableViewportController<HilosModerationRuleRow>['ingestRefusal']
    >
  ): void {
    if (!this.acceptsWindowResponse(args[1])) return
    super.ingestRefusal(...args)
    this.accept()
  }
}

/**
 * Bind both initial and live rule windows to the route without a mount fetch.
 *
 * @param context The project's connection and page scopes.
 * @param kind The current route's kind key.
 */
export function createHilosModerationRules(
  context: HilosModerationContext,
  kind: ReadonlySignal<string>,
): HilosModerationRules {
  // A rule's id makes its slot an entity fragment at the normalizer boundary.
  // Resolve that page-owned reference before applying the public row projector;
  // never keep a second copy of the entity's values.
  const resolve = (row: TableRow): HilosModerationRuleRow => {
    const slot = row.slots[HILOS_MODERATION_RULE_SLOT]
    if (
      typeof slot === 'object' &&
      slot !== null &&
      !Array.isArray(slot) &&
      'type' in slot &&
      typeof slot.type === 'string' &&
      'id' in slot &&
      (typeof slot.id === 'number' || typeof slot.id === 'string')
    ) {
      const fields = context.scopes
        .page()
        ?.entities.signal({ type: slot.type, id: slot.id })
        .get()?.fields
      return resolveHilosModerationRuleRow({
        ...row,
        slots: { [HILOS_MODERATION_RULE_SLOT]: fields },
      })
    }
    return resolveHilosModerationRuleRow(row)
  }
  // Values stay in the controller; only the address of its last window is held here.
  const delivered = createSignal<{ scope: Scope; kind: string } | null>(null)
  const current: ReadonlySignal<boolean> = computedSignal(() => {
    const source = delivered.get()
    return (
      source !== null &&
      source.scope === context.scopes.page() &&
      source.scope.key === HilosPages.MODERATION_KIND_RULES &&
      source.kind === kind.get() &&
      controller.rows
        .get()
        .every((view) => view.placeholder || view.row?.kind === kind.get())
    )
  })
  const withKind = (descriptor: TableViewportDescriptor) => ({
    ...descriptor,
    filter: { ...descriptor.filter, kind: kind.get() },
  })
  const controller = new ModerationRulesViewportController(
    {
      resolve,
      frame: {
        columns: RULES_COLUMNS,
        rowHeight: { mode: 'lines', count: 3 },
        search: { placeholder: 'Search rules…' },
      },
      sendViewport: (descriptor) => {
        if (!current.get()) return
        context.connection.sendTableViewport(
          HilosPages.MODERATION_KIND_RULES,
          HILOS_MODERATION_RULES_TABLE,
          withKind(descriptor),
        )
      },
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(
          HilosPages.MODERATION_KIND_RULES,
          HILOS_MODERATION_RULES_TABLE,
          rendered,
        ),
    },
    (descriptor) => (current.get() ? withKind(descriptor) : null),
    () => {
      const scope = context.scopes.page()
      delivered.set(
        scope?.key === HilosPages.MODERATION_KIND_RULES && kind.get() !== ''
          ? { scope, kind: kind.get() }
          : null,
      )
    },
    (rows) =>
      context.scopes.page()?.key === HilosPages.MODERATION_KIND_RULES &&
      kind.get() !== '' &&
      rows.every((row) => resolve(row).kind === kind.get()),
  )
  let teardown: Unsubscribe[] = []

  function dispose(): void {
    for (const off of teardown.splice(0)) off()
    delivered.set(null)
    controller.dispose()
  }

  return {
    controller,
    current,
    start() {
      if (teardown.length > 0) return
      teardown = [
        subscribeSignal(kind, () => delivered.set(null)),
        subscribeSignal({ get: () => context.scopes.page() }, (scope) => {
          delivered.set(null)
          if (scope?.key !== HilosPages.MODERATION_KIND_RULES) dispose()
        }),
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.MODERATION_KIND_RULES,
            tableKey: HILOS_MODERATION_RULES_TABLE,
          },
          controller,
        ),
      ]
    },
    dispose,
  }
}

/** Path to the kind's own rules. */
export function moderationKindRulesPath(kind: string): string {
  return `/hilos/moderation/${encodeURIComponent(kind)}/rules`
}
