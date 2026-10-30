import { useContext, useLayoutEffect, useMemo, useState } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosModerationKind,
  HILOS_MODERATION_COPY,
  type HilosModerationContext,
  type HilosModerationKind,
} from '@hilos/core/admin/moderation'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosTableEmptyState } from '../../HilosTableEmptyState.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/** Props for a declared moderation kind's read-only Overview. */
export interface HilosModerationKindPageProps {
  /** The project's connection and live page scopes. */
  context: HilosModerationContext
}

/** Bind the route's canonical kind window without a first-screen fetch. */
export function HilosModerationKindPage({
  context,
}: HilosModerationKindPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosModerationKindPage requires a provided Hilos router.')
  }
  const kind = useMemo(
    () => computedSignal(() => router.currentRoute.get().params.kind ?? ''),
    [router],
  )
  const [binding, setBinding] = useState<{
    context: HilosModerationContext
    kind: typeof kind
    detail: HilosModerationKind
  } | null>(null)

  useLayoutEffect(() => {
    // dispose() releases the controller permanently; every setup needs its own.
    const detail = createHilosModerationKind(context, kind)
    detail.start()
    setBinding({ context, kind, detail })
    return () => detail.dispose()
  }, [context, kind])

  return binding?.context === context && binding.kind === kind ? (
    <ModerationKindOverview context={context} detail={binding.detail} />
  ) : (
    <HilosAdminPage
      page={HilosPages.MODERATION_KIND}
      headKind="entity"
      tabsLabel="Kind pages"
    >
      <ModerationKindLoading />
    </HilosAdminPage>
  )
}

/** Present only the current scope's permitted row, including its live name. */
function ModerationKindOverview({
  context,
  detail,
}: HilosModerationKindPageProps & { detail: HilosModerationKind }) {
  const row = useSignal(detail.row)
  const unavailable = useSignal(detail.unavailable)
  const name = useMemo(
    () => computedSignal(() => detail.row.get()?.title),
    [detail],
  )
  usePageInstanceName(context.scopes, HilosPages.MODERATION_KIND, name)

  return (
    <HilosAdminPage
      page={HilosPages.MODERATION_KIND}
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
      {row ? (
        <div
          className="border rounded-3 px-3 py-2 mb-4"
          data-id="hilos-moderation-kind-overview"
        >
          <dl className="row small mb-0 g-0">
            <dt className="col-sm-3 fw-normal text-body-secondary py-1">
              Checked by
            </dt>
            <dd
              className="col-sm-9 mb-0 py-1 text-break"
              data-id="hilos-moderation-kind-model"
            >
              {row.input === 'image' ? 'Vision model' : 'Text model'} ·{' '}
              <HilosHideable value={row.model}>
                {(model) => <code>{model}</code>}
              </HilosHideable>
              <span className="text-body-secondary">
                {' '}
                — profile{' '}
                <code data-id="hilos-moderation-kind-profile">
                  {row.profile}
                </code>
              </span>
            </dd>
            <dt className="col-sm-3 fw-normal text-body-secondary py-1">
              Can refuse for
            </dt>
            <dd
              className="col-sm-9 mb-0 py-1 d-flex flex-wrap gap-1"
              data-id="hilos-moderation-kind-reasons"
            >
              {row.reasons.map((reason) => (
                <span
                  key={reason}
                  className="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle text-wrap text-break mw-100"
                >
                  {reason}
                </span>
              ))}
            </dd>
            <dt className="col-sm-3 fw-normal text-body-secondary py-1">
              Rules in force
            </dt>
            <dd
              className="col-sm-9 mb-0 py-1"
              data-id="hilos-moderation-kind-rules"
            >
              {row.ownRuleCount === 0 ? (
                <>
                  <span className="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                    {HILOS_MODERATION_COPY.builtIn}
                  </span>{' '}
                  <span className="text-body-secondary">
                    until you add a rule of your own
                  </span>
                </>
              ) : (
                <>
                  {row.ownRuleCount} {row.ownRuleCount === 1 ? 'rule' : 'rules'}{' '}
                  of your own
                </>
              )}
            </dd>
          </dl>
        </div>
      ) : unavailable ? (
        <HilosTableEmptyState
          controller={detail.controller}
          kind="unavailable"
        />
      ) : (
        <ModerationKindLoading />
      )}
    </HilosAdminPage>
  )
}

/** Reserve the read-only properties while their canonical window is pending. */
function ModerationKindLoading() {
  return (
    <div
      className="placeholder-glow border rounded-3 px-3 py-2 mb-4"
      role="status"
      aria-label="Loading moderation kind"
      data-id="hilos-moderation-kind-loading"
    >
      <span className="placeholder col-8 d-block mb-2" />
      <span className="placeholder col-6 d-block mb-2" />
      <span className="placeholder col-4 d-block" />
    </div>
  )
}
