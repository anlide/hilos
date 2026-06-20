// HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
// user's profile, presence, and rename, inside the admin shell. Editing happens
// in a modal — inline forms are forbidden (rules-and-violations.md section E,
// conflict-resolution.md); the modal hosts the rename form. The detail selector
// and the rename action are the core headless's (createHilosUserDetail /
// createHilosUserRename); this view owns only the markup, so a project mounts it
// by passing its HilosUsersContext. Success is state-driven (the committed name
// reaches the draft over the live table, closing the modal); a failure surfaces
// from the backend fail ack inside the modal. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HilosPages,
  createHilosUserDetail,
  createHilosUserRename,
} from '@hilos/core'
import type { HilosUsersContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosUserPage}. */
export interface HilosUserPageProps {
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}

const NAME_MIN = 2
const NAME_MAX = 64

/**
 * The framework user-detail admin page: profile, presence, and a modal rename.
 *
 * @param props The project context (scopes, connection, user collection).
 */
export function HilosUserPage({ context }: HilosUserPageProps) {
  const userDetail = useMemo(() => createHilosUserDetail(context), [context])
  const rename = useMemo(() => createHilosUserRename(context), [context])

  const detail = useSignal(userDetail)
  const error = useSignal(rename.renameError)

  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [loading, setLoading] = useState(false)

  const trimmed = draft.trim()
  const valid = trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
  const dirty = !!detail && trimmed !== detail.name

  function openEdit(): void {
    rename.clearRenameError()
    setDraft(detail?.name ?? '')
    setLoading(false)
    setEditing(true)
  }

  // The modal's close path (Cancel / Esc / backdrop, through the discard guard).
  function closeEdit(): void {
    setEditing(false)
    setLoading(false)
    rename.clearRenameError()
  }

  function submit(): void {
    if (!detail || !valid || loading) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (trimmed === detail.name) {
      closeEdit()

      return
    }

    setLoading(rename.submitRename(detail.id, trimmed))
  }

  // Success is state-driven: the rename has landed once the committed name (over
  // the live table) reaches the submitted draft; that closes the modal.
  const committedName = detail?.name
  useEffect(() => {
    if (loading && committedName === draft.trim()) {
      setLoading(false)
      setEditing(false)
    }
  }, [committedName, loading, draft])

  // A rejected rename releases the button and keeps the modal open to retry.
  useEffect(() => {
    if (error !== null) {
      setLoading(false)
    }
  }, [error])

  return (
    <HilosAdminPage page={HilosPages.USER}>
      {detail ? (
        <div className="card" data-id="hilos-user-detail">
          <div className="card-header d-flex align-items-center gap-2">
            <span
              className={`rounded-circle flex-shrink-0 ${
                detail.presence === 'online' ? 'bg-success' : 'bg-secondary'
              }`}
              style={{ width: '10px', height: '10px' }}
            />
            <span className="h5 mb-0" data-id="hilos-user-name">
              {detail.name}
            </span>
            <span className="badge text-bg-secondary">{detail.presence}</span>
            <button
              type="button"
              className="btn btn-outline-primary btn-sm ms-auto"
              data-id="hilos-user-edit"
              onClick={openEdit}
            >
              Edit
            </button>
          </div>
          <div className="card-body">
            <dl className="row mb-0">
              <dt className="col-sm-3">User ID</dt>
              <dd className="col-sm-9" data-id="hilos-user-id">
                {detail.id}
              </dd>
              <dt className="col-sm-3">Online sessions</dt>
              <dd className="col-sm-9" data-id="hilos-user-sessions">
                {detail.onlineSessionCount}
              </dd>
              {detail.lastActivity ? (
                <>
                  <dt className="col-sm-3">Last activity</dt>
                  <dd className="col-sm-9" data-id="hilos-user-last-activity">
                    {detail.lastActivity}
                  </dd>
                </>
              ) : null}
            </dl>
          </div>
        </div>
      ) : (
        <p className="text-body-secondary" data-id="hilos-user-empty">
          Loading user…
        </p>
      )}

      <HilosModal
        open={editing}
        title={detail ? `Rename · ${detail.name}` : 'Rename user'}
        confirmOnClose={dirty}
        onClose={closeEdit}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={loading}
              data-id="hilos-user-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={loading}
              disabled={!valid || !dirty}
              data-id="hilos-user-save"
              onClick={submit}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        {error ? (
          <div
            className="alert alert-danger"
            role="alert"
            data-id="hilos-user-rename-error"
          >
            {error}
          </div>
        ) : null}
        <form
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <label className="form-label" htmlFor="hilos-user-name-field">
            Display name
          </label>
          <input
            id="hilos-user-name-field"
            type="text"
            className="form-control"
            minLength={NAME_MIN}
            maxLength={NAME_MAX}
            data-id="hilos-user-name-input"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
          />
          <div className="form-text">
            Between {NAME_MIN} and {NAME_MAX} characters.
          </div>
        </form>
      </HilosModal>
    </HilosAdminPage>
  )
}
