import { type HilosActionRun } from '../../conflict/rowEditSession.js'
import { type ActionLifecycle } from '../../connection/actionLifecycle.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type Scope } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import {
  type HilosModerationContext,
  type HilosModerationKindRow,
} from './hilosModeration.js'

/** The rules page's context adds tracked writes to the read-only section. */
export interface HilosModerationRulesContext extends HilosModerationContext {
  readonly actions: ActionLifecycle
}

/** Tracked page action for creating a moderation rule. */
export const HILOS_MODERATION_RULE_ADD_ACTION = 'hilos_moderation_rule_add'

/** Words shared by the rule creation form and its opener. */
export const HILOS_MODERATION_RULE_ADD_COPY = {
  open: 'Add rule',
  ruleLabel: 'Rule',
  save: 'Save',
  cancel: 'Cancel',
  refusalTitle: "Couldn't add the rule",
  title: (title: string) => `Add rule · ${title}`,
} as const

/** A rule creation session bound to the kind and page scope it opened on. */
export interface HilosModerationRuleAdd {
  readonly openState: ReadonlySignal<boolean>
  readonly target: ReadonlySignal<{ kind: string; title: string } | null>
  readonly rule: ReadonlySignal<string>
  readonly dirty: ReadonlySignal<boolean>
  readonly saving: ReadonlySignal<boolean>
  /** Fit to submit regardless of a request in flight: the Save button's look. */
  readonly submitAvailable: ReadonlySignal<boolean>
  /** Fit to submit with no request in flight: the submit handler's guard. */
  readonly canSubmit: ReadonlySignal<boolean>
  start(): void
  open(): void
  setRule(value: string): void
  submit(run: HilosActionRun): Promise<void>
  close(): void
  dispose(): void
}

/**
 * Create a rule without merging or holding a second copy of the table rows.
 *
 * @param context The project's page scopes and action lifecycle.
 * @param source The kind delivered for the current rules page.
 * @param current Whether the rules window belongs to the current page and kind.
 */
export function createHilosModerationRuleAdd(
  context: HilosModerationRulesContext,
  source: ReadonlySignal<HilosModerationKindRow | null>,
  current: ReadonlySignal<boolean>,
): HilosModerationRuleAdd {
  const openState = createSignal(false)
  const target = createSignal<{ kind: string; title: string } | null>(null)
  const rule = createSignal('')
  const saving = createSignal(false)
  const dirty = computedSignal(() => rule.get() !== '')
  let openedScope: Scope | undefined
  let generation = 0
  let teardown: Unsubscribe[] = []

  const submitAvailable = computedSignal(() => {
    const captured = target.get()
    return (
      openState.get() &&
      captured !== null &&
      openedScope === context.scopes.page() &&
      openedScope?.key === HilosPages.MODERATION_KIND_RULES &&
      captured.kind === source.get()?.kind &&
      current.get() &&
      rule.get().trim() !== ''
    )
  })
  const canSubmit = computedSignal(() => submitAvailable.get() && !saving.get())

  function reset(): void {
    generation++
    openedScope = undefined
    openState.set(false)
    target.set(null)
    rule.set('')
    saving.set(false)
  }

  return {
    openState,
    target,
    rule,
    dirty,
    saving,
    submitAvailable,
    canSubmit,
    start() {
      if (teardown.length > 0) return
      if (
        openState.get() &&
        (openedScope !== context.scopes.page() ||
          target.get()?.kind !== source.get()?.kind)
      )
        reset()
      teardown = [
        subscribeSignal({ get: () => context.scopes.page() }, reset),
        subscribeSignal({ get: () => source.get()?.kind }, reset),
      ]
    },
    open() {
      if (saving.get()) return
      const scope = context.scopes.page()
      const row = source.get()
      if (
        scope?.key !== HilosPages.MODERATION_KIND_RULES ||
        row === null ||
        row.kind === '' ||
        !current.get()
      )
        return
      reset()
      openedScope = scope
      target.set({ kind: row.kind, title: row.title })
      openState.set(true)
    },
    setRule(value) {
      if (openState.get() && !saving.get()) rule.set(value)
    },
    async submit(run) {
      if (!canSubmit.get()) return
      const captured = target.get()!
      const value = rule.get()
      const attempt = generation
      saving.set(true)
      try {
        const success = await run(
          context.actions.dispatch(HILOS_MODERATION_RULE_ADD_ACTION, {
            kind: captured.kind,
            rule: value,
          }),
        )
        if (generation === attempt && success) reset()
      } finally {
        // An old reply must not release or close a replacement session.
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
