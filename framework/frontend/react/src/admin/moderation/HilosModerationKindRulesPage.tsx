import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import {
  computedSignal,
  HilosPages,
  type ReadonlySignal,
} from '@hilos/core/base'
import {
  createHilosModerationKind,
  createHilosModerationRuleAdd,
  createHilosModerationRuleDelete,
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  HILOS_MODERATION_COPY,
  HILOS_MODERATION_RULE_DELETE_COPY,
  HILOS_MODERATION_RULE_EDIT_COPY,
  HilosModerationRuleRowKey,
  type HilosModerationKind,
  type HilosModerationRuleAdd,
  type HilosModerationRuleDelete,
  type HilosModerationRuleEdit,
  type HilosModerationRules,
  type HilosModerationRulesContext,
} from '@hilos/core/admin/moderation'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosTableEmptyState } from '../../HilosTableEmptyState.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'
import { HilosModerationRuleAdd as RuleAdd } from './HilosModerationRuleAdd.js'
import { HilosModerationRuleDelete as RuleDelete } from './HilosModerationRuleDelete.js'
import { HilosModerationRuleEdit as RuleEdit } from './HilosModerationRuleEdit.js'

/** Props for a declared moderation kind's own-rules page. */
export interface HilosModerationKindRulesPageProps {
  /** The project's connection, live page scopes and tracked actions. */
  context: HilosModerationRulesContext
}

/** One effect setup owns both windows and every form that reads them. */
interface RulesBinding {
  readonly context: HilosModerationRulesContext
  readonly kind: ReadonlySignal<string>
  readonly detail: HilosModerationKind
  readonly rules: HilosModerationRules
  readonly add: HilosModerationRuleAdd
  readonly edit: HilosModerationRuleEdit
  readonly remove: HilosModerationRuleDelete
}

/** Bind canonical windows and core forms without fetching on mount. */
export function HilosModerationKindRulesPage({
  context,
}: HilosModerationKindRulesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosModerationKindRulesPage requires a provided Hilos router.',
    )
  }
  const kind = useMemo(
    () => computedSignal(() => router.currentRoute.get().params.kind ?? ''),
    [router],
  )
  const [binding, setBinding] = useState<RulesBinding | null>(null)

  useLayoutEffect(() => {
    // A disposed controller cannot be reused by StrictMode's next setup.
    const detail = createHilosModerationKind(
      context,
      kind,
      HilosPages.MODERATION_KIND_RULES,
    )
    const rules = createHilosModerationRules(context, kind, {
      openAdd: () => add.open(),
    })
    const add = createHilosModerationRuleAdd(context, detail.row, rules.current)
    const remove = createHilosModerationRuleDelete(context, detail.row, rules)
    const edit = createHilosModerationRuleEdit(context, detail.row, rules)
    detail.start()
    rules.start()
    add.start()
    remove.start()
    edit.start()
    setBinding({ context, kind, detail, rules, add, remove, edit })
    return () => {
      add.dispose()
      remove.dispose()
      edit.dispose()
      detail.dispose()
      rules.dispose()
    }
  }, [context, kind])

  return binding?.context === context && binding.kind === kind ? (
    <ModerationKindRules binding={binding} />
  ) : (
    <HilosAdminPage
      page={HilosPages.MODERATION_KIND_RULES}
      headKind="entity"
      tabsLabel="Kind pages"
    >
      <RulesLoading />
    </HilosAdminPage>
  )
}

/** Present current data and delegate table and modal behavior to the SDK. */
function ModerationKindRules({ binding }: { binding: RulesBinding }) {
  const { context, detail, rules, add, edit, remove } = binding
  const row = useSignal(detail.row)
  const unavailable = useSignal(detail.unavailable)
  const current = useSignal(rules.current)
  const body = useSignal(rules.controller.frame.body)
  const name = useMemo(
    () => computedSignal(() => detail.row.get()?.title),
    [detail],
  )
  usePageInstanceName(context.scopes, HilosPages.MODERATION_KIND, name)

  return (
    <HilosAdminPage
      page={HilosPages.MODERATION_KIND_RULES}
      headKind="entity"
      tabsLabel="Kind pages"
      headVisual={
        row ? (
          <span
            className="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
            data-id="hilos-moderation-kind-visual"
          >
            <i className="bi bi-shield-check" />
          </span>
        ) : undefined
      }
      headBadges={
        row ? (
          <span
            className={`badge border ${
              row.owner === 'framework'
                ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                : 'bg-info-subtle text-info-emphasis border-info-subtle'
            }`}
            data-id="hilos-moderation-kind-owner"
          >
            {row.owner === 'framework'
              ? HILOS_MODERATION_COPY.framework
              : HILOS_MODERATION_COPY.project}
          </span>
        ) : undefined
      }
      headDescription={
        row ? (
          <code
            className="text-body-secondary text-break"
            data-id="hilos-moderation-kind-key"
          >
            {row.kind}
          </code>
        ) : undefined
      }
    >
      {unavailable ? (
        <HilosTableEmptyState
          controller={detail.controller}
          kind="unavailable"
        />
      ) : row && current ? (
        <>
          <HilosViewportTable
            controller={rules.controller}
            dataId="hilos-moderation-rules-table"
            cells={{
              [HilosModerationRuleRowKey.rule]: (rule) => rule.rule,
              actions: (rule) => (
                <div className="d-flex gap-1 justify-content-end">
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-primary"
                    title={HILOS_MODERATION_RULE_EDIT_COPY.open}
                    aria-label={HILOS_MODERATION_RULE_EDIT_COPY.open}
                    data-id={`hilos-moderation-rule-edit-open-${rule.id}`}
                    onClick={() => edit.open(String(rule.id))}
                  >
                    <i className="bi bi-pencil" aria-hidden="true" />
                    <span className="d-md-none ms-1">Edit</span>
                  </button>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-danger"
                    title={HILOS_MODERATION_RULE_DELETE_COPY.open}
                    aria-label={HILOS_MODERATION_RULE_DELETE_COPY.open}
                    data-id={`hilos-moderation-rule-delete-open-${rule.id}`}
                    onClick={() => remove.open(String(rule.id))}
                  >
                    <i className="bi bi-trash" aria-hidden="true" />
                    <span className="d-md-none ms-1">
                      {HILOS_MODERATION_RULE_DELETE_COPY.confirm}
                    </span>
                  </button>
                </div>
              ),
            }}
            empty={
              <div className="text-center py-4" role="status">
                <i
                  className="bi bi-list-check fs-2 text-body-secondary"
                  aria-hidden="true"
                />
                <p className="fw-semibold mb-1">No rules of your own yet</p>
                <p className="text-body-secondary mb-0">
                  {row.title} is checked by its built-in rules below.
                </p>
              </div>
            }
          />
          {row.ownRuleCount === 0 &&
          body !== 'loading' &&
          body !== 'unavailable' ? (
            <div
              className="border rounded-3 p-3 mt-3 bg-body-tertiary"
              data-id="hilos-moderation-rules-built-in"
            >
              <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 className="fw-semibold small text-body-secondary mb-0">
                  Built-in rules
                </h2>
                <span className="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                  {HILOS_MODERATION_COPY.builtIn}
                </span>
              </div>
              <p className="small text-body-secondary mb-2">
                They come with the kind and apply until you add a rule of your
                own. Your first rule replaces them all.
              </p>
              <ul className="small text-body-secondary mb-0 ps-3 text-break">
                {row.defaultRules.map((rule, index) => (
                  <li key={index}>{rule}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </>
      ) : (
        <RulesLoading />
      )}
      <RuleAdd add={add} />
      <RuleDelete remove={remove} />
      <RuleEdit edit={edit} />
    </HilosAdminPage>
  )
}

/** Hold the rules surface until both canonical windows belong to this route. */
function RulesLoading() {
  return (
    <div
      className="placeholder-glow border rounded-3 px-3 py-2 mb-4"
      role="status"
      aria-label="Loading moderation rules"
      data-id="hilos-moderation-rules-loading"
    >
      <span className="placeholder col-8 d-block mb-2" />
      <span className="placeholder col-6 d-block mb-2" />
      <span className="placeholder col-4 d-block" />
    </div>
  )
}
