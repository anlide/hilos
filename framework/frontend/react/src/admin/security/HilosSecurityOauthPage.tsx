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
// optimistically, and a refusal surfaces with the backend's domain phrase. The
// modal holds the address row in focus and merges against it through the shared
// row-edit helper (rowEdit.ts, conflict-resolution.md), saying what happened
// elsewhere on one line of room held in advance (HilosEditNotice). The ↺ resets
// the address to env only through a confirm dialog built like the settings orphan
// delete, holding the same row in focus. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosOAuthProviderRowKey,
  HilosPages,
  createHilosOAuthProvidersTable,
  createHilosOAuthRedirect,
  createHilosOauthRedirectEdit,
  createHilosSecurityOauthActions,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosOAuthProviderRow,
  HilosOAuthRedirectRow,
  HilosSecurityOauthContext,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
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
  // Edit dialog: the return address, as the core window has it; this view
  // binds the input.
  const editor = useMemo(
    () => createHilosOauthRedirectEdit(redirect.controller, actions),
    [redirect, actions],
  )

  useEffect(() => {
    providers.start()
    redirect.start()
    editor.start()

    return () => {
      editor.dispose()
      providers.dispose()
      redirect.dispose()
    }
  }, [providers, redirect, editor])

  const redirectRows = useSignal(redirect.controller.rows)
  // The one return-address row, once its window has arrived.
  const redirectRow = redirectRows[0]?.row ?? null

  const editOpen = useSignal(editor.opened)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const canSave = useSignal(editor.canSave)
  const edit = useTrackedAction()
  const editHidden = editForm.hidden
  const editValue = editForm.text
  const setEditValue = (text: string) => editor.patchForm({ text })
  const editNotice = live.notice?.kind ?? null

  // The live row the open reset dialog is about: the row the table holds in
  // focus, which the server follows wherever it goes; undefined once the row is
  // gone.
  const liveRow = useSignal(redirect.controller.focusedRow)

  // Reset dialog: the address back to env, only on confirm. It reads the same live
  // row the edit dialog does — one dialog is open at a time, and the focus is one.
  const [resetOpen, setResetOpen] = useState(false)
  const [resetRow, setResetRow] = useState<HilosOAuthRedirectRow | null>(null)
  const reset = useTrackedAction()
  const resetShown = liveRow ?? resetRow
  const resetGone = liveRow === undefined || liveRow.source !== 'db'

  function openEdit(): void {
    if (!redirectRow) {
      return
    }
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    edit.clearError()
    editor.open(redirectRow.key)
  }

  function closeEdit(): void {
    editor.close()
  }

  function acceptMine(): void {
    editor.keepMine()
  }

  function acceptTheirs(): void {
    editor.takeTheirs()
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with the entered value.
  function submitEdit(): void {
    void editor.save(edit.run)
  }

  function openReset(): void {
    if (!redirectRow) {
      return
    }
    // Flush pending and take the row into focus; a row that is gone declines to
    // open.
    const fresh = redirect.controller.focusRow(redirectRow.key)
    if (!fresh) {
      return
    }
    reset.clearError()
    setResetRow(fresh)
    setResetOpen(true)
  }

  function closeReset(): void {
    setResetOpen(false)
    redirect.controller.releaseFocus()
  }

  async function submitReset(): Promise<void> {
    if (!resetRow || reset.busy || resetGone) {
      return
    }
    if (await reset.run(actions.sendRedirectReset())) {
      closeReset()
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
                  <HilosHideable value={redirectRow.value} />
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
                disabled={redirectRow?.source !== 'db'}
                data-id="hilos-oauth-redirect-reset"
                onClick={openReset}
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
        confirmOnClose={live.dirty}
        aria-label="Edit · Return address"
        onClose={closeEdit}
        header={
          <ConflictHeader
            title="Edit · Return address"
            conflict={live.conflict}
          />
        }
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!canSave}
            saveLabel={editSaveLabel}
            onSave={() => void submitEdit()}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={edit.busy}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.loading}
                disabled={disabled}
                data-id="hilos-oauth-redirect-save"
                onClick={onSave}
              >
                {editSaveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError action={edit} detailsTitle="Couldn't save" />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void submitEdit()
          }}
        >
          {editHidden ? (
            <div className="mb-3">
              <div className="form-label">Return address</div>
              <HilosHiddenMark />
            </div>
          ) : (
            <>
              <label
                className="form-label"
                htmlFor="hilos-oauth-redirect-input"
              >
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
            </>
          )}
          <HilosEditNotice
            kind={editNotice}
            text={editNoticeText}
            dataId="hilos-oauth-redirect-edit-notice"
          />
        </form>
      </HilosModal>

      <HilosModal
        open={resetOpen}
        title="Reset · Return address"
        closeOnBackdrop={!reset.busy}
        closeOnEsc={!reset.busy}
        initialFocus="dialog"
        onClose={closeReset}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={reset.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={reset.loading}
              disabled={reset.busy || resetGone}
              data-id="hilos-oauth-redirect-reset-confirm"
              onClick={() => void submitReset()}
            >
              Reset
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={reset}
          detailsTitle="Couldn't reset the return address"
        />
        {resetShown ? (
          <dl className="row mb-0">
            <dt className="col-4">Now</dt>
            <dd
              className="col-8 text-break"
              data-id="hilos-oauth-redirect-reset-now"
            >
              {resetShown.setState ? (
                <HilosHideable value={resetShown.value} />
              ) : (
                'Not set'
              )}
            </dd>
            <dt className="col-4">Back to</dt>
            <dd className="col-8" data-id="hilos-oauth-redirect-reset-default">
              the env value — empty when env has none
            </dd>
          </dl>
        ) : null}
        {resetGone ? (
          <p
            className="mb-0 mt-2 text-body-secondary"
            data-id="hilos-oauth-redirect-reset-gone"
          >
            Already reset elsewhere.
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
