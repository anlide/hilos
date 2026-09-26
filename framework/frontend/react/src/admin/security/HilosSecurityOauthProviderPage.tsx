// HilosSecurityOauthProviderPage — the framework Hilos OAuth provider page
// (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): one provider's fields inside the
// admin shell. The route {providerId} names the provider; the fields and providers
// tables are global, so the core headless presets their provider filter from the
// route and the server narrows both windows to it (createHilosOAuthProviderFields /
// createHilosOAuthProviderSummary). A provider the project does not declare narrows
// them to nothing, and the page says "provider not found". Each field shows its
// effective value and where it comes from and can be edited or reset to env / the
// recipe; the client secret is write-only — shown as set / not set, never read back,
// and its dialog always opens empty: it replaces, it does not show. The provider's
// recipe is shown as reference, without actions. Writes are tracked actions
// (createHilosSecurityOauthActions): the value redraws from the reactive table after
// the backend echo, never optimistically, and a refusal surfaces with the backend's
// domain phrase. Editing happens in a modal — inline forms are forbidden
// (rules-and-violations.md section E). Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
} from '@hilos/core'
import type { HilosOAuthFieldRow, HilosSecurityOauthContext } from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurityOauthProviderPage}. */
export interface HilosSecurityOauthProviderPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecurityOauthContext
}

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** Human-readable effective value of a field that is not the secret. */
function displayValue(row: HilosOAuthFieldRow): string {
  return row.value === null || row.value === '' ? '—' : row.value
}

/**
 * The framework OAuth provider page: the provider's fields table with a per-field
 * edit (or replace) modal and a per-field reset, and its recipe as reference.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosSecurityOauthProviderPage({
  context,
}: HilosSecurityOauthProviderPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosSecurityOauthProviderPage requires a provided router: <HilosRouterContext.Provider value={router}>.',
    )
  }

  // The route provider, as a core signal both tables follow: navigating to another
  // provider sets their provider filter again and asks the server for its windows.
  const providerSignal = useMemo(
    () =>
      computedSignal(
        () =>
          (router.currentRoute.get().params.providerId as string | undefined) ??
          '',
      ),
    [router],
  )
  const providerKey = useSignal(providerSignal)

  const summary = useMemo(
    () => createHilosOAuthProviderSummary(context, providerSignal),
    [context, providerSignal],
  )
  const fields = useMemo(
    () => createHilosOAuthProviderFields(context, providerSignal),
    [context, providerSignal],
  )
  const actions = useMemo(
    () => createHilosSecurityOauthActions(context),
    [context],
  )

  useEffect(() => {
    summary.start()
    fields.start()

    return () => {
      summary.dispose()
      fields.dispose()
    }
  }, [summary, fields])

  const summaryRows = useSignal(summary.controller.rows)
  const summaryLoaded = useSignal(summary.controller.loaded)
  // The route provider's own row, or null until it arrives (or when it is not declared).
  const provider = summaryRows[0]?.row ?? null
  // A provider the project does not declare: the window came back empty.
  const notFound = summaryLoaded && summaryRows.length === 0

  const reset = useTrackedAction()

  function resetField(row: HilosOAuthFieldRow): void {
    void reset.run(actions.sendProviderReset(row.providerKey, row.field))
  }

  // Edit dialog: one field of the provider. The secret's dialog always opens empty.
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosOAuthFieldRow | null>(null)
  const [editValue, setEditValue] = useState('')
  const edit = useTrackedAction()

  function openEdit(row: HilosOAuthFieldRow): void {
    edit.clearError()
    setEditRow(row)
    setEditValue(row.secret ? '' : (row.value ?? ''))
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
    // The secret typed into the dialog is not kept once it is closed.
    setEditValue('')
  }

  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy) {
      return
    }
    if (
      await edit.run(
        actions.sendProviderSet(editRow.providerKey, editRow.field, editValue),
      )
    ) {
      closeEdit()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_OAUTH_PROVIDER}>
      {notFound ? (
        <div
          className="alert alert-warning"
          role="status"
          data-id="hilos-oauth-provider-not-found"
        >
          Provider not found. This application declares no OAuth provider{' '}
          <code>{providerKey}</code>.
        </div>
      ) : (
        <>
          <p className="text-body-secondary mb-3">
            <span className="fw-semibold text-body">{provider?.label}</span>
            <code className="ms-2 small">{providerKey}</code>
          </p>

          <HilosViewportTable
            controller={fields.controller}
            cells={{
              field: (row) => (
                <>
                  <div className="fw-semibold">{row.label}</div>
                  <code className="small text-body-secondary">{row.field}</code>
                </>
              ),
              value: (row) =>
                row.secret ? (
                  <span
                    className="text-body-secondary fst-italic"
                    data-id={`hilos-oauth-field-value-${row.field}`}
                  >
                    {row.setState ? 'Set' : 'Not set'}
                  </span>
                ) : (
                  <span data-id={`hilos-oauth-field-value-${row.field}`}>
                    {displayValue(row)}
                  </span>
                ),
              source: (row) => (
                <span className="badge text-bg-secondary-subtle text-secondary-emphasis">
                  {SOURCE_LABEL[row.source] ?? row.source}
                </span>
              ),
              [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                <>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-primary"
                    title={row.secret ? 'Replace' : 'Edit'}
                    aria-label={`${row.secret ? 'Replace' : 'Edit'} ${row.label}`}
                    data-id={`hilos-oauth-field-edit-${row.field}`}
                    onClick={() => openEdit(row)}
                  >
                    <i
                      className={row.secret ? 'bi bi-key' : 'bi bi-pencil'}
                      aria-hidden="true"
                    />
                  </button>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    title={`Reset ${row.label} to env/default`}
                    aria-label={`Reset ${row.label} to env/default`}
                    disabled={row.source !== 'db' || reset.busy}
                    data-id={`hilos-oauth-field-reset-${row.field}`}
                    onClick={() => resetField(row)}
                  >
                    <i
                      className="bi bi-arrow-counterclockwise"
                      aria-hidden="true"
                    />
                  </button>
                </>
              ),
            }}
          />

          {provider ? (
            <section
              className="card mt-4"
              aria-labelledby="hilos-oauth-recipe-heading"
              data-id="hilos-oauth-recipe"
            >
              <div className="card-body">
                <h2 id="hilos-oauth-recipe-heading" className="h6 mb-1">
                  Recipe
                </h2>
                <p className="small text-body-secondary mb-3">
                  {provider.builtIn
                    ? 'Shipped by Hilos for this provider. It is not edited here.'
                    : 'Declared by this application for a provider Hilos ships no recipe for.'}
                </p>
                <dl className="row small mb-0">
                  <dt className="col-sm-4">Authorization endpoint</dt>
                  <dd className="col-sm-8">
                    <code>{provider.authorizeUrl}</code>
                  </dd>
                  <dt className="col-sm-4">Token endpoint</dt>
                  <dd className="col-sm-8">
                    <code>{provider.tokenUrl}</code>
                  </dd>
                  <dt className="col-sm-4">Userinfo endpoint</dt>
                  <dd className="col-sm-8">
                    <code>{provider.userInfoUrl}</code>
                  </dd>
                  <dt className="col-sm-4">Account id field</dt>
                  <dd className="col-sm-8">
                    <code>{provider.subjectKey}</code>
                  </dd>
                  <dt className="col-sm-4">Email field</dt>
                  <dd className="col-sm-8">
                    <code>{provider.emailKey}</code>
                  </dd>
                  <dt className="col-sm-4">Name field</dt>
                  <dd className="col-sm-8 mb-0">
                    <code>{provider.nameKey}</code>
                  </dd>
                </dl>
              </div>
            </section>
          ) : null}
        </>
      )}

      <HilosModal
        open={editOpen}
        title={
          editRow
            ? `${editRow.secret ? 'Replace' : 'Edit'} · ${editRow.label}`
            : 'Edit field'
        }
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
              data-id="hilos-oauth-field-save"
              onClick={() => void submitEdit()}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={edit} detailsTitle="Couldn't save" />
        {editRow ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void submitEdit()
            }}
          >
            <label className="form-label" htmlFor="hilos-oauth-field-input">
              {editRow.label}
            </label>
            <input
              id="hilos-oauth-field-input"
              type={editRow.secret ? 'password' : 'text'}
              autoComplete={editRow.secret ? 'new-password' : 'off'}
              className="form-control"
              data-id="hilos-oauth-field-input"
              data-autofocus
              value={editValue}
              onChange={(event) => setEditValue(event.target.value)}
            />
            {editRow.secret ? (
              <p className="form-text mb-0">
                The current secret is never shown. What you enter replaces it.
              </p>
            ) : null}
          </form>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
