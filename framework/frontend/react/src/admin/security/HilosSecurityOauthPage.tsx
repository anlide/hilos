// HilosSecurityOauthPage — the framework Hilos OAuth providers page
// (HilosPages.SECURITY_OAUTH, HIL-286): the providers table inside the admin shell,
// with the one return address every provider redirects back to above it. One row per
// provider the project declares (built from its provider directory, not a hardcoded
// list): whether it can sign anyone in, where its client id comes from, whether a
// secret is in force, and a link to its configuration page. The tables, the row
// view-models and the round-trips are the core headless's
// (createHilosOAuthProvidersTable / createHilosOAuthRedirect /
// createHilosSecurityOauthActions); this view owns only the markup, so a project
// mounts it by passing its HilosSecurityOauthContext. The address is edited in a
// modal — inline forms are forbidden (rules-and-violations.md section E) — as a
// tracked action: it redraws from the reactive table after the backend echo, never
// optimistically, and a refusal surfaces with the backend's domain phrase.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosOAuthProviderRowKey,
  HilosPages,
  createHilosOAuthProvidersTable,
  createHilosOAuthRedirect,
  createHilosSecurityOauthActions,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosOAuthProviderRow,
  HilosSecurityOauthContext,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurityOauthPage}. */
export interface HilosSecurityOauthPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecurityOauthContext
}

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** The provider's configuration page path (its {providerId} route param is the key). */
function providerPath(row: HilosOAuthProviderRow): string {
  return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
    providerId: row.providerKey,
  })
}

/**
 * The framework OAuth providers page: the shared return address with its edit
 * modal and reset, above the providers table with a link to each provider's page.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosSecurityOauthPage({
  context,
}: HilosSecurityOauthPageProps) {
  const providers = useMemo(
    () => createHilosOAuthProvidersTable(context),
    [context],
  )
  const redirect = useMemo(() => createHilosOAuthRedirect(context), [context])
  const actions = useMemo(
    () => createHilosSecurityOauthActions(context),
    [context],
  )

  // Bind both server-windowed tables to the connection on mount, request their
  // first windows, and unbind on unmount.
  useEffect(() => {
    providers.start()
    redirect.start()

    return () => {
      providers.dispose()
      redirect.dispose()
    }
  }, [providers, redirect])

  const redirectRows = useSignal(redirect.controller.rows)
  // The one return-address row, once its window has arrived.
  const redirectRow = redirectRows[0]?.row ?? null

  const reset = useTrackedAction()

  function resetRedirect(): void {
    void reset.run(actions.sendRedirectReset())
  }

  // Edit dialog: the return address.
  const [editOpen, setEditOpen] = useState(false)
  const [editValue, setEditValue] = useState('')
  const edit = useTrackedAction()

  function openEdit(): void {
    edit.clearError()
    setEditValue(redirectRow?.value ?? '')
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
  }

  async function submitEdit(): Promise<void> {
    if (edit.busy) {
      return
    }
    if (await edit.run(actions.sendRedirectSet(editValue))) {
      closeEdit()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_OAUTH}>
      <section
        className="card mb-4"
        aria-labelledby="hilos-oauth-redirect-heading"
      >
        <div className="card-body">
          <div className="d-flex justify-content-between align-items-start gap-3">
            <div>
              <h2 id="hilos-oauth-redirect-heading" className="h6 mb-1">
                Return address
              </h2>
              <p className="small text-body-secondary mb-2">
                Where every provider sends the browser back after sign-in.
                Register this address with each provider.
              </p>
              {redirectRow?.setState ? (
                <code data-id="hilos-oauth-redirect-value">
                  {redirectRow.value}
                </code>
              ) : (
                <span
                  className="text-body-secondary fst-italic"
                  data-id="hilos-oauth-redirect-value"
                >
                  Not set
                </span>
              )}
              {redirectRow ? (
                <span className="badge text-bg-secondary-subtle text-secondary-emphasis ms-2">
                  {SOURCE_LABEL[redirectRow.source] ?? redirectRow.source}
                </span>
              ) : null}
            </div>
            <div className="d-flex gap-1 flex-shrink-0">
              <button
                type="button"
                className="btn btn-sm btn-outline-primary"
                title="Edit"
                aria-label="Edit return address"
                data-id="hilos-oauth-redirect-edit"
                onClick={openEdit}
              >
                <i className="bi bi-pencil" aria-hidden="true" />
              </button>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                title="Reset return address to env"
                aria-label="Reset return address to env"
                disabled={redirectRow?.source !== 'db' || reset.busy}
                data-id="hilos-oauth-redirect-reset"
                onClick={resetRedirect}
              >
                <i
                  className="bi bi-arrow-counterclockwise"
                  aria-hidden="true"
                />
              </button>
            </div>
          </div>
        </div>
      </section>

      <HilosViewportTable
        controller={providers.controller}
        cells={{
          [HilosOAuthProviderRowKey.label]: (row) => (
            <>
              <div className="fw-semibold">{row.label}</div>
              <code className="small text-body-secondary">
                {row.providerKey}
              </code>
            </>
          ),
          [HilosOAuthProviderRowKey.configured]: (row) =>
            row.configured ? (
              <span className="badge text-bg-success-subtle text-success-emphasis">
                Configured
              </span>
            ) : (
              <span
                className="badge text-bg-warning-subtle text-warning-emphasis"
                title={`${row.missingFields} required field(s) not set`}
              >
                {row.missingFields} missing
              </span>
            ),
          [HilosOAuthProviderRowKey.clientIdSource]: (row) => (
            <span className="badge text-bg-secondary-subtle text-secondary-emphasis">
              {SOURCE_LABEL[row.clientIdSource] ?? row.clientIdSource}
            </span>
          ),
          [HilosOAuthProviderRowKey.secretSet]: (row) => (
            <span className="text-body-secondary fst-italic">
              {row.secretSet ? 'Set' : 'Not set'}
            </span>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <HilosLink
              to={providerPath(row)}
              className="btn btn-sm btn-outline-primary"
              aria-label={`Configure ${row.label}`}
              data-id={`hilos-oauth-provider-open-${row.providerKey}`}
            >
              Configure
            </HilosLink>
          ),
        }}
      />

      <HilosModal
        open={editOpen}
        title="Edit · Return address"
        onClose={closeEdit}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={edit.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={edit.loading}
              disabled={edit.busy}
              data-id="hilos-oauth-redirect-save"
              onClick={() => void submitEdit()}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={edit} />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void submitEdit()
          }}
        >
          <label className="form-label" htmlFor="hilos-oauth-redirect-input">
            Return address
          </label>
          <input
            id="hilos-oauth-redirect-input"
            type="url"
            className="form-control"
            placeholder="https://app.example/auth/callback"
            data-id="hilos-oauth-redirect-input"
            data-autofocus
            value={editValue}
            onChange={(event) => setEditValue(event.target.value)}
          />
        </form>
      </HilosModal>
    </HilosAdminPage>
  )
}
