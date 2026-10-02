// HilosSecurityStepUpPage — the framework page of operations that ask for
// confirmation (HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor:
// one row per operation the step-up directory declares, with whether the
// framework or the project declared it and a switch. The table, the row
// view-model and the switch round-trip are the core headless's
// (createHilosSecurityStepUpTable / createHilosSecurityStepUpActions); this view
// owns only the markup, so a project mounts it by passing its
// HilosTwoFactorContext. The switch is a tracked action: a spinner on its own
// row while it flies, the other switches dimmed, and the switch redraws from the
// table's live row. The page heading names the table. The screen is built from
// text: the mockup still draws the list on the two-factor page (D-143).
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  isHiddenValue,
} from '@hilos/core'
import type {
  HilosStepUpOperationRow,
  HilosTwoFactorContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurityStepUpPage}. */
export interface HilosSecurityStepUpPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}

/**
 * The page of operations that ask for confirmation.
 *
 * @param props The project's two-step verification context.
 */
export function HilosSecurityStepUpPage({
  context,
}: HilosSecurityStepUpPageProps) {
  const operations = useMemo(
    () => createHilosSecurityStepUpTable(context),
    [context],
  )
  const operationActions = useMemo(
    () => createHilosSecurityStepUpActions(context),
    [context],
  )

  useEffect(() => {
    operations.start()

    return () => operations.dispose()
  }, [operations])

  const operationToggle = useTrackedAction()
  const [pendingOperationKey, setPendingOperationKey] = useState<string | null>(
    null,
  )

  async function toggleOperation(
    row: HilosStepUpOperationRow,
    enabled: boolean,
  ): Promise<void> {
    setPendingOperationKey(row.operationKey)
    try {
      await operationToggle.run(
        operationActions.sendOperationSet(row.operationKey, enabled),
      )
    } finally {
      setPendingOperationKey(null)
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_STEP_UP}>
      <HilosViewportTable
        dataId="hilos-step-up-table"
        controller={operations.controller}
        cells={{
          operationKey: (row) => (
            <span
              className="fw-semibold"
              data-id={`hilos-step-up-row-${row.operationKey}`}
            >
              {row.label}
            </span>
          ),
          owner: (row) => (
            <span className="badge text-bg-light border">
              {row.owner === 'framework'
                ? HILOS_STEP_UP_ADMIN_COPY.framework
                : HILOS_STEP_UP_ADMIN_COPY.project}
            </span>
          ),
          enabled: (row) => {
            if (isHiddenValue(row.enabled)) {
              return <HilosHiddenMark />
            }

            return (
              <HilosSwitch
                className="mb-0"
                checked={row.enabled}
                busy={pendingOperationKey === row.operationKey}
                disabled={operationToggle.busy}
                aria-label={`Require confirmation for ${row.label}`}
                dataId={`hilos-step-up-switch-${row.operationKey}`}
                onToggle={(enabled) => void toggleOperation(row, enabled)}
              />
            )
          },
        }}
      />
    </HilosAdminPage>
  )
}
