import { useLayoutEffect, useState } from 'react'
import { HILOS_TABLE_ACTIONS_KEY, HilosPages } from '@hilos/core/base'
import {
  createHilosModerationKindsTable,
  HILOS_MODERATION_COPY,
  HilosModerationKindRowKey,
  moderationKindPath,
  type HilosModerationContext,
  type HilosModerationKindsTable,
} from '@hilos/core/admin/moderation'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Props for the moderation kinds administration page. */
export interface HilosModerationPageProps {
  /** The project's connection and live page scopes. */
  context: HilosModerationContext
}

/** Render the published kinds through the core's server-windowed table. */
export function HilosModerationPage({ context }: HilosModerationPageProps) {
  const [binding, setBinding] = useState<{
    context: HilosModerationContext
    table: HilosModerationKindsTable
  } | null>(null)

  useLayoutEffect(() => {
    // A controller belongs to one committed setup, including StrictMode replay.
    const table = createHilosModerationKindsTable(context)
    table.start()
    setBinding({ context, table })
    return () => {
      table.dispose()
      table.controller.dispose()
    }
  }, [context])

  return (
    <HilosAdminPage page={HilosPages.MODERATION} headKind="section">
      {binding?.context === context ? (
        <HilosViewportTable
          controller={binding.table.controller}
          dataId="hilos-moderation-kinds-table"
          cells={{
            [HilosModerationKindRowKey.title]: (row) => (
              <>
                <div className="fw-semibold">{row.title}</div>
                <code
                  className="small text-body-secondary"
                  data-id={`hilos-moderation-row-${row.kind}`}
                >
                  {row.kind}
                </code>
              </>
            ),
            [HilosModerationKindRowKey.owner]: (row) => (
              <span
                className={`badge border ${
                  row.owner === 'framework'
                    ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                    : 'bg-info-subtle text-info-emphasis border-info-subtle'
                }`}
              >
                {row.owner === 'framework'
                  ? HILOS_MODERATION_COPY.framework
                  : HILOS_MODERATION_COPY.project}
              </span>
            ),
            [HilosModerationKindRowKey.model]: (row) => (
              <>
                <div>
                  {row.input === 'image' ? 'Vision model' : 'Text model'}
                </div>
                <HilosHideable value={row.model}>
                  {(model) => (
                    <code className="small text-body-secondary">{model}</code>
                  )}
                </HilosHideable>
              </>
            ),
            [HilosModerationKindRowKey.ownRuleCount]: (row) =>
              row.ownRuleCount === 0 ? (
                <span className="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                  {HILOS_MODERATION_COPY.builtIn}
                </span>
              ) : (
                <span>
                  {row.ownRuleCount} {row.ownRuleCount === 1 ? 'rule' : 'rules'}
                </span>
              ),
            [HILOS_TABLE_ACTIONS_KEY]: (row) => (
              <HilosLink
                to={moderationKindPath(row.kind)}
                className="btn btn-sm btn-outline-primary"
                aria-label={`Open ${row.title}`}
                data-id={`hilos-moderation-open-${row.kind}`}
              >
                {HILOS_MODERATION_COPY.open}
              </HilosLink>
            ),
          }}
        />
      ) : null}
    </HilosAdminPage>
  )
}
