import { HilosPages } from '../../routing/hilosPages.js'
import { type Scope } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  HILOS_MODERATION_KINDS_TABLE,
  HilosModerationKindRowKey,
  resolveHilosModerationKindRow,
  type HilosModerationContext,
  type HilosModerationKindRow,
} from './hilosModeration.js'

/** Read-only detail over the route's canonical server window. */
export interface HilosModerationKind {
  readonly row: ReadonlySignal<HilosModerationKindRow | null>
  readonly unavailable: ReadonlySignal<boolean>
  readonly controller: TableViewportController<HilosModerationKindRow>
  start(): void
  dispose(): void
}

/**
 * Bind the kind's first window and live values without another first-screen fetch.
 *
 * @param context The project's connection and page scopes.
 * @param kind The current route's kind key.
 * @param page The kind page whose metadata window is bound.
 */
export function createHilosModerationKind(
  context: HilosModerationContext,
  kind: ReadonlySignal<string>,
  page:
    | typeof HilosPages.MODERATION_KIND
    | typeof HilosPages.MODERATION_KIND_RULES = HilosPages.MODERATION_KIND,
): HilosModerationKind {
  const controller = new TableViewportController<HilosModerationKindRow>({
    resolve: resolveHilosModerationKindRow,
    // No client preset: the page already fixes kind and limit before its reply.
    // initialFilter would discard that canonical window and ask for it again.
    frame: {
      columns: Object.values(HilosModerationKindRowKey).map((key) => ({
        key,
        label: key,
      })),
    },
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        page,
        HILOS_MODERATION_KINDS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_MODERATION_KINDS_TABLE,
        rendered,
      ),
  })
  // Only provenance is held here; row values remain owned by the controller.
  const delivered = createSignal<{ scope: Scope; kind: string } | null>(null)
  const current = computedSignal(() => {
    const source = delivered.get()
    return (
      source !== null &&
      source.scope === context.scopes.page() &&
      source.scope.key === page &&
      source.kind === kind.get()
    )
  })
  const row = computedSignal(() => {
    if (!current.get()) return null
    const view = controller.rows
      .get()
      .find((view) => !view.placeholder && view.row?.kind === kind.get())
    return view?.row ?? null
  })
  let teardown: Unsubscribe[] = []

  function dispose(): void {
    for (const off of teardown.splice(0)) off()
    delivered.set(null)
    controller.dispose()
  }

  return {
    controller,
    row,
    unavailable: computedSignal(
      () => current.get() && controller.frame.body.get() === 'unavailable',
    ),
    start() {
      if (teardown.length > 0) return
      teardown = [
        subscribeSignal(kind, () => delivered.set(null)),
        subscribeSignal({ get: () => context.scopes.page() }, (scope) => {
          delivered.set(null)
          // Navigation gathers held windows before the outgoing view unmounts.
          // Release this binding now; a new scope for this same page keeps it.
          if (scope?.key !== page) dispose()
        }),
        subscribeSignal(controller.rows, () => {
          const scope = context.scopes.page()
          delivered.set(
            scope?.key === page ? { scope, kind: kind.get() } : null,
          )
        }),
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page,
            tableKey: HILOS_MODERATION_KINDS_TABLE,
          },
          controller,
        ),
      ]
    },
    dispose,
  }
}
