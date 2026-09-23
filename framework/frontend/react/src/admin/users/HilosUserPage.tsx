// HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
// user's profile, presence, and rename, inside the admin shell. Editing happens
// in a modal — inline forms are forbidden (rules-and-violations.md section E,
// conflict-resolution.md); the modal hosts the rename form. The detail selector
// and the rename action are the core headless's (createHilosUserDetail /
// createHilosUserRename); this view owns only the markup, so a project mounts it
// by passing its HilosUsersContext. The modal merges against the live row
// through the shared row-edit helper (rowEdit.ts, conflict-resolution.md) and
// says what happened elsewhere on one line of room held in advance
// (HilosEditNotice). Success is state-driven (the committed name reaches the
// draft over the live table, closing the modal); a failure surfaces from the
// backend fail ack inside the modal. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HilosPages,
  createHilosUserDetail,
  createHilosUserRename,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosUsersContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
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

/** The one field the modal edits: the display name. */
interface UserEditFields {
  name: string
}

/** The one line the modal says about the other side, for what the helper found. */
function noticeText(live: RowEditState<UserEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return `Changed elsewhere to "${live.fields.name.incoming}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

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
  const [editBaseline, setEditBaseline] = useState<
    RowEditBaseline<UserEditFields>
  >(() => openRowEdit<UserEditFields>({ name: '' }))

  const trimmed = draft.trim()
  const valid = trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
  // The live row is the card's own detail row, projected onto the name; gone
  // once the card has no row any more.
  const live = resolveRowEdit(
    detail ? { name: detail.name } : undefined,
    editBaseline,
    { name: trimmed },
  )
  const dirty = live.dirty
  const editTitle = detail ? `Rename · ${detail.name}` : 'Rename user'
  const editNotice = live.notice?.kind ?? null
  const editNoticeText = noticeText(live)
  const saveLabel = live.gone ? 'Deleted' : 'Save'

  function openEdit(): void {
    rename.clearRenameError()
    const name = detail?.name ?? ''
    setDraft(name)
    setEditBaseline(openRowEdit<UserEditFields>({ name }))
    setLoading(false)
    setEditing(true)
  }

  // Put a step of the helper into the modal: the snapshot moves, and a name
  // the step takes lands in the input.
  function applyStep(step: RowEditStep<UserEditFields>): void {
    setEditBaseline(step.baseline)
    if (step.take.name !== undefined) {
      setDraft(step.take.name)
    }
  }

  // The helper hands a step whenever the other side moved the name while the
  // person left it alone, or both arrived at the same one; the modal applies
  // it at once.
  const settle = live.settle
  useEffect(() => {
    if (editing && settle) {
      applyStep(settle)
    }
  }, [editing, settle])

  function acceptMine(): void {
    setEditBaseline(keepMineRowEdit(live, editBaseline))
  }

  function acceptTheirs(): void {
    applyStep(takeTheirsRowEdit(live, editBaseline))
  }

  // The modal's close path (Cancel / Esc / backdrop, through the discard guard).
  function closeEdit(): void {
    setEditing(false)
    setLoading(false)
    rename.clearRenameError()
  }

  function submit(): void {
    if (!detail || !valid || loading || live.gone) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (!live.dirty) {
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
              aria-hidden="true"
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
        confirmOnClose={dirty}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
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
            <ConflictActions
              conflict={live.conflict}
              disableSave={!valid || !dirty || loading || live.gone}
              mergeable={false}
              saveLabel={saveLabel}
              onSave={submit}
              onAcceptMine={acceptMine}
              onAcceptTheirs={acceptTheirs}
              saveButton={({ disabled, onSave }) => (
                <LoadingButton
                  className="btn-primary"
                  loading={loading}
                  disabled={disabled}
                  data-id="hilos-user-save"
                  onClick={onSave}
                >
                  {saveLabel}
                </LoadingButton>
              )}
            />
          </>
        )}
      >
        {/* The refusal is announced from here and not from the row that shows
            it: a role arriving together with its text is not announced at all
            (accessibility.md). The region lives inside the dialog because the
            dialog is aria-modal, which hides the page under it from a screen
            reader. */}
        <div
          className="visually-hidden"
          role="alert"
          aria-live="assertive"
          data-id="hilos-user-live-assertive"
        >
          {error}
        </div>
        <HilosFormError message={error} dataId="hilos-user-rename-error" />
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
            data-autofocus
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
          />
          <div className="form-text">
            Between {NAME_MIN} and {NAME_MAX} characters.
          </div>
          <HilosEditNotice
            kind={editNotice}
            text={editNoticeText}
            dataId="hilos-user-edit-notice"
          />
        </form>
      </HilosModal>
    </HilosAdminPage>
  )
}
