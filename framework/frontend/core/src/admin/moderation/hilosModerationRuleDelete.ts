import {
  hilosTableRowEditSource,
  type HilosActionRun,
} from '../../conflict/rowEditSession.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type Scope } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import { type HilosModerationKindRow } from './hilosModeration.js'
import { type HilosModerationRulesContext } from './hilosModerationRuleAdd.js'
import { type HilosModerationRules } from './hilosModerationRules.js'

/** Tracked page action for deleting the exact rule shown for confirmation. */
export const HILOS_MODERATION_RULE_DELETE_ACTION =
  'hilos_moderation_rule_delete'

/** Words shared by the rule deletion window and its opener. */
export const HILOS_MODERATION_RULE_DELETE_COPY = {
  open: 'Delete rule',
  title: 'Delete rule',
  confirm: 'Delete',
  cancel: 'Cancel',
  refusalTitle: "Couldn't delete the rule",
  body: (title: string) => `This permanently removes the rule from ${title}.`,
  deleted: 'The rule no longer exists.',
  changed: 'The rule changed. Close this window and confirm again.',
} as const

/** The identity and exact text captured when the confirmation opens. */
export type HilosModerationRuleDeleteTarget = {
  id: number
  kind: string
  title: string
  expectedRule: string
}

/** A confirmation bound to one page scope, kind and focused rule. */
export interface HilosModerationRuleDelete {
  readonly openState: ReadonlySignal<boolean>
  readonly target: ReadonlySignal<HilosModerationRuleDeleteTarget | null>
  readonly saving: ReadonlySignal<boolean>
  /** Fit to submit regardless of a request in flight: the Delete button's look. */
  readonly submitAvailable: ReadonlySignal<boolean>
  /** Fit to submit with no request in flight: the submit handler's guard. */
  readonly canSubmit: ReadonlySignal<boolean>
  readonly notice: ReadonlySignal<{
    kind: 'deleted' | 'conflict'
    text: string
  } | null>
  start(): void
  open(rowKey: string): void
  submit(run: HilosActionRun): Promise<void>
  close(): void
  dispose(): void
}

/**
 * Follow a rule through the table's focus without an edit or merge session.
 *
 * @param context The project's page scopes and action lifecycle.
 * @param kind The kind delivered for the current rules page.
 * @param rules The rules window and its provenance.
 */
export function createHilosModerationRuleDelete(
  context: HilosModerationRulesContext,
  kind: ReadonlySignal<HilosModerationKindRow | null>,
  rules: HilosModerationRules,
): HilosModerationRuleDelete {
  const source = hilosTableRowEditSource(rules.controller, (row) =>
    String(row.id),
  )
  const openState = createSignal(false)
  const target = createSignal<HilosModerationRuleDeleteTarget | null>(null)
  const saving = createSignal(false)
  const notice =
    createSignal<ReturnType<HilosModerationRuleDelete['notice']['get']>>(null)
  let openedScope: Scope | undefined
  let generation = 0
  let teardown: Unsubscribe[] = []

  const submitAvailable = computedSignal(() => {
    const captured = target.get()
    const live = source.live.get()
    return (
      openState.get() &&
      captured !== null &&
      openedScope === context.scopes.page() &&
      openedScope?.key === HilosPages.MODERATION_KIND_RULES &&
      captured.kind === kind.get()?.kind &&
      rules.current.get() &&
      live?.id === captured.id &&
      live.kind === captured.kind &&
      live.rule === captured.expectedRule &&
      notice.get() === null
    )
  })
  const canSubmit = computedSignal(() => submitAvailable.get() && !saving.get())

  function reset(): void {
    generation++
    openedScope = undefined
    const held = openState.get()
    openState.set(false)
    target.set(null)
    saving.set(false)
    notice.set(null)
    if (held) source.close()
  }

  function observe(): void {
    const captured = target.get()
    if (!openState.get() || captured === null) return
    const live = source.live.get()
    if (live?.id !== captured.id || live.kind !== captured.kind) {
      notice.set({
        kind: 'deleted',
        text: HILOS_MODERATION_RULE_DELETE_COPY.deleted,
      })
    } else if (
      live.rule !== captured.expectedRule &&
      notice.get()?.kind !== 'deleted'
    ) {
      notice.set({
        kind: 'conflict',
        text: HILOS_MODERATION_RULE_DELETE_COPY.changed,
      })
    }
  }

  return {
    openState,
    target,
    saving,
    submitAvailable,
    canSubmit,
    notice,
    start() {
      if (teardown.length > 0) return
      if (
        openState.get() &&
        (openedScope !== context.scopes.page() ||
          target.get()?.kind !== kind.get()?.kind)
      )
        reset()
      observe()
      teardown = [
        subscribeSignal({ get: () => context.scopes.page() }, reset),
        subscribeSignal({ get: () => kind.get()?.kind }, reset),
        subscribeSignal(source.live, observe),
      ]
    },
    open(rowKey) {
      if (saving.get()) return
      reset()
      const scope = context.scopes.page()
      const currentKind = kind.get()
      if (
        scope?.key !== HilosPages.MODERATION_KIND_RULES ||
        currentKind === null ||
        currentKind.kind === '' ||
        !rules.current.get()
      )
        return
      const fresh = source.open(rowKey)
      if (
        fresh === null ||
        !Number.isInteger(fresh.id) ||
        fresh.id <= 0 ||
        fresh.kind !== currentKind.kind ||
        !rules.current.get()
      ) {
        source.close()
        return
      }
      openedScope = scope
      target.set({
        id: fresh.id,
        kind: fresh.kind,
        title: currentKind.title,
        expectedRule: fresh.rule,
      })
      openState.set(true)
    },
    async submit(run) {
      if (!canSubmit.get()) return
      const captured = target.get()!
      const attempt = generation
      saving.set(true)
      try {
        const success = await run(
          context.actions.dispatch(HILOS_MODERATION_RULE_DELETE_ACTION, {
            id: captured.id,
            kind: captured.kind,
            expectedRule: captured.expectedRule,
          }),
        )
        if (generation === attempt && success) reset()
      } finally {
        // A late reply must not release the focus or close a replacement window.
        if (generation === attempt) saving.set(false)
      }
    },
    close() {
      if (!saving.get()) reset()
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
      reset()
    },
  }
}
