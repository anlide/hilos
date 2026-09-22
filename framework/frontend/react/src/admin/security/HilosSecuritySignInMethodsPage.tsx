// HilosSecuritySignInMethodsPage — the framework sign-in methods page
// (HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427): one row per method the project
// wired, each with a switch, whether the installation can serve it, and — for a
// provider — a link to its own screen. The table, the row view-model and the switch
// round-trip are the core headless's (createHilosSignInMethodsTable /
// createHilosSignInMethodsActions); this view owns only the markup, so a project
// mounts it by passing its HilosSignInMethodsContext.
// The switch is a tracked action: it dispatches the one-method switch, the refusal
// toasts ("at least one sign-in method must stay on") and the switch goes back, and
// the switch redraws from the live enabled set when it arrives — that set, not the
// row, is what says a method is on (the same set every sign-in surface reshapes
// from). The screen is built from text: the mockup has no node for it yet (D-093).
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HilosPages,
  HilosSignInMethodRowKey,
  createHilosSignInMethodsActions,
  createHilosSignInMethodsTable,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosSignInMethodRow,
  HilosSignInMethodsContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecuritySignInMethodsPage}. */
export interface HilosSecuritySignInMethodsPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSignInMethodsContext
}

/** The provider's own screen (its {providerId} route param is the key). */
function providerPath(providerKey: string): string {
  return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
    providerId: providerKey,
  })
}

/**
 * The framework sign-in methods page: the methods table with a switch per method,
 * its readiness, and a link to a provider's own screen.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosSecuritySignInMethodsPage({
  context,
}: HilosSecuritySignInMethodsPageProps) {
  const methods = useMemo(
    () => createHilosSignInMethodsTable(context),
    [context],
  )
  const actions = useMemo(
    () => createHilosSignInMethodsActions(context),
    [context],
  )
  const enabledKeys = useSignal(methods.enabledKeys)

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    methods.start()

    return () => methods.dispose()
  }, [methods])

  // One tracked runner for every switch: a single in-flight guard across rows is
  // enough, and the busy flag disables every switch while one write is settling.
  const toggle = useTrackedAction()
  const [pendingMethodKey, setPendingMethodKey] = useState<string | null>(null)

  /** Whether the method is on now, by the live set rather than the row. */
  function isOn(row: HilosSignInMethodRow): boolean {
    return enabledKeys.includes(row.methodKey)
  }

  // Dispatch the switch as a tracked action. Nothing is set optimistically: the
  // switch follows the live set on success and remains on it after a refusal.
  async function toggleEnabled(
    row: HilosSignInMethodRow,
    next: boolean,
  ): Promise<void> {
    setPendingMethodKey(row.methodKey)
    try {
      await toggle.run(actions.sendMethodSet(row.methodKey, next))
    } finally {
      setPendingMethodKey(null)
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_SIGN_IN_METHODS}>
      <HilosViewportTable
        controller={methods.controller}
        cells={{
          [HilosSignInMethodRowKey.methodKey]: (row) => (
            <>
              <div className="fw-semibold">{row.label}</div>
              <code className="small text-body-secondary">{row.methodKey}</code>
            </>
          ),
          [HilosSignInMethodRowKey.enabled]: (row) => (
            <HilosSwitch
              className="mb-0"
              checked={isOn(row)}
              busy={pendingMethodKey === row.methodKey}
              disabled={toggle.busy}
              aria-label={`Enable ${row.label}`}
              dataId={`hilos-sign-in-method-enabled-${row.methodKey}`}
              onToggle={(next) => void toggleEnabled(row, next)}
            />
          ),
          [HilosSignInMethodRowKey.ready]: (row) => (
            <>
              {row.ready ? (
                <span className="badge text-bg-success-subtle text-success-emphasis">
                  {row.providerKey === null ? 'Ready' : 'Configured'}
                </span>
              ) : (
                <span className="badge text-bg-warning-subtle text-warning-emphasis">
                  {row.providerKey === null
                    ? 'Nothing to send with'
                    : 'Not configured'}
                </span>
              )}
              {row.providerKey !== null ? (
                <HilosLink
                  to={providerPath(row.providerKey)}
                  className="btn btn-sm btn-link"
                  data-id={`hilos-sign-in-method-provider-${row.methodKey}`}
                >
                  Configure
                </HilosLink>
              ) : null}
            </>
          ),
        }}
      />
    </HilosAdminPage>
  )
}
