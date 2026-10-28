import { type RowEditNoticeKind } from '../../conflict/rowEdit.js'
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosRowEditSource,
  type HilosTrackedRowEdit,
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
import {
  type HilosModerationRuleRow,
  type HilosModerationRules,
} from './hilosModerationRules.js'

/** Tracked page action for updating the rule accepted by the edit session. */
export const HILOS_MODERATION_RULE_UPDATE_ACTION =
  'hilos_moderation_rule_update'

/** Words shared by the rule edit window and its opener. */
export const HILOS_MODERATION_RULE_EDIT_COPY = {
  open: 'Edit rule',
  title: (title: string) => `Edit rule · ${title}`,
  ruleLabel: 'Rule',
  cancel: 'Cancel',
  refusalTitle: "Couldn't save the rule",
  conflict: (rule: string) => `Rule changed elsewhere to "${rule}".`,
} as const

interface RuleFields {
  rule: string
}

/** Identity and title captured from the fresh row and current kind on open. */
export type HilosModerationRuleEditTarget = {
  id: number
  kind: string
  title: string
}

/** A tracked row edit bound to one Rules scope, kind and focused rule. */
export interface HilosModerationRuleEdit extends Omit<
  HilosTrackedRowEdit<HilosModerationRuleRow, RuleFields>,
  'open'
> {
  readonly target: ReadonlySignal<HilosModerationRuleEditTarget | null>
  readonly dirty: ReadonlySignal<boolean>
  readonly notice: ReadonlySignal<{
    kind: RowEditNoticeKind
    text: string
  } | null>
  /** Open from the fresh focused row, or refuse a stale scope or identity. */
  open(rowKey: string): boolean
}

/**
 * Follow and edit one rule through the table's focus and standard row session.
 *
 * @param context The project's page scopes and tracked action lifecycle.
 * @param kind The kind delivered for the current Rules page.
 * @param rules The rules window and its provenance.
 */
export function createHilosModerationRuleEdit(
  context: HilosModerationRulesContext,
  kind: ReadonlySignal<HilosModerationKindRow | null>,
  rules: HilosModerationRules,
): HilosModerationRuleEdit {
  const parent = hilosTableRowEditSource(rules.controller, (row) =>
    String(row.id),
  )
  const target = createSignal<HilosModerationRuleEditTarget | null>(null)
  let openedScope: Scope | undefined
  let teardown: Unsubscribe[] = []

  const current = computedSignal(() => {
    const held = target.get()
    return (
      held !== null &&
      openedScope === context.scopes.page() &&
      openedScope?.key === HilosPages.MODERATION_KIND_RULES &&
      held.kind === kind.get()?.kind &&
      rules.current.get()
    )
  })
  const source: HilosRowEditSource<HilosModerationRuleRow> = {
    open(rowKey) {
      const scope = context.scopes.page()
      const metadata = kind.get()
      if (
        scope?.key !== HilosPages.MODERATION_KIND_RULES ||
        metadata === null ||
        metadata.kind === '' ||
        !rules.current.get()
      )
        return null
      const fresh = parent.open(rowKey)
      if (
        fresh === null ||
        !Number.isInteger(fresh.id) ||
        fresh.id <= 0 ||
        String(fresh.id) !== rowKey ||
        fresh.kind !== metadata.kind ||
        !rules.current.get()
      ) {
        parent.close()
        return null
      }
      openedScope = scope
      target.set({ id: fresh.id, kind: fresh.kind, title: metadata.title })
      return fresh
    },
    live: computedSignal(() => {
      const held = target.get()
      const live = parent.live.get()
      return held !== null && live?.id === held.id && live.kind === held.kind
        ? live
        : undefined
    }),
    close() {
      parent.close()
      target.set(null)
      openedScope = undefined
    },
  }
  const edit = createHilosRowEdit<HilosModerationRuleRow, RuleFields>(source, {
    initial: { rule: '' },
    fields: (row) => ({ rule: row.rule }),
    valid: (form) => current.get() && form.rule.trim() !== '',
    notice: {
      conflict: (state) =>
        HILOS_MODERATION_RULE_EDIT_COPY.conflict(state.fields.rule.incoming),
    },
  })

  return {
    ...edit,
    target,
    dirty: computedSignal(() => edit.state.get().dirty),
    notice: computedSignal(() => {
      const notice = edit.state.get().notice
      return notice === null
        ? null
        : { kind: notice.kind, text: edit.noticeText.get() }
    }),
    open(rowKey) {
      if (edit.saving.get()) return false
      edit.close()
      return edit.open(rowKey)
    },
    save(run) {
      if (!current.get()) return Promise.resolve()
      const held = target.get()!
      return edit.save((draft) => {
        // The session's row argument is the opening row. Concurrency uses the
        // live row on which it just checked the verdict, including any choice.
        const live = source.live.get()!
        return run(
          context.actions.dispatch(HILOS_MODERATION_RULE_UPDATE_ACTION, {
            id: held.id,
            kind: held.kind,
            rule: draft.rule,
            expectedRule: live.rule,
          }),
        )
      })
    },
    close() {
      if (!edit.saving.get()) edit.close()
    },
    start() {
      if (teardown.length > 0) return
      if (
        edit.opened.get() &&
        (openedScope !== context.scopes.page() ||
          target.get()?.kind !== kind.get()?.kind)
      )
        edit.close()
      teardown = [
        subscribeSignal({ get: () => context.scopes.page() }, edit.close),
        subscribeSignal({ get: () => kind.get()?.kind }, edit.close),
      ]
      edit.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
      edit.dispose()
    },
  }
}
