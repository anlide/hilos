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
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  USER_IDENTITIES_FIELD,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserRename,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosMergeCandidateIdentity,
  HilosMergeCandidateRow,
  HilosPasswordFate,
  HilosUsersContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

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
  const mergeCandidates = useMemo(
    () => createHilosMergeCandidates(context),
    [context],
  )
  const candidateRows = useSignal(mergeCandidates.controller.rows)
  const accountMerge = useMemo(
    () => createHilosAccountMerge(context),
    [context],
  )
  const currentUserIdSignal = useMemo(
    () => sessionUserId(context.scopes),
    [context],
  )
  const currentUserId = useSignal(currentUserIdSignal)
  const mergeAction = useTrackedAction()
  const [mergeOpen, setMergeOpen] = useState(false)
  const [mergeStep, setMergeStep] = useState<1 | 2>(1)
  const [selectedCandidateId, setSelectedCandidateId] = useState<number | null>(
    null,
  )
  const [selectedSnapshot, setSelectedSnapshot] =
    useState<HilosMergeCandidateRow | null>(null)
  const [passwordFate, setPasswordFate] = useState<HilosPasswordFate | null>(
    null,
  )
  const selectedEntry = candidateRows.find(
    (entry) => entry.row?.id === selectedCandidateId,
  )
  const selectedCandidate =
    selectedEntry?.pending === 'remove' || selectedEntry?.placeholder
      ? null
      : (selectedEntry?.row ?? null)
  const summaryCandidate = selectedCandidate ?? selectedSnapshot
  const passwordChoiceRequired =
    detail?.hasPassword === true && selectedCandidate?.hasPassword === true
  const mergeGone = mergeStep === 2 && selectedCandidate === null
  const mergeDisabled =
    mergeAction.busy ||
    mergeGone ||
    selectedCandidate === null ||
    (passwordChoiceRequired && passwordFate === null)

  useEffect(
    () => () => {
      mergeCandidates.dispose()
    },
    [mergeCandidates],
  )

  useEffect(() => {
    if (
      mergeStep === 1 &&
      selectedCandidateId !== null &&
      selectedCandidate === null
    ) {
      setSelectedCandidateId(null)
    }
  }, [candidateRows, mergeStep, selectedCandidateId, selectedCandidate])

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

  function identityTitle(identity: HilosMergeCandidateIdentity): string {
    return identity.provider ?? identity.type
  }

  function openMerge(): void {
    if (!detail) {
      return
    }
    mergeCandidates.dispose()
    mergeAction.clearError()
    setMergeStep(1)
    setSelectedCandidateId(null)
    setSelectedSnapshot(null)
    setPasswordFate(null)
    setMergeOpen(true)
    mergeCandidates.start(detail.id)
  }

  function closeMerge(): void {
    setMergeOpen(false)
    mergeCandidates.dispose()
  }

  function chooseCandidate(row: HilosMergeCandidateRow): void {
    if (row.id === currentUserId) {
      return
    }
    setSelectedCandidateId(row.id)
    setPasswordFate(null)
    mergeAction.clearError()
  }

  function nextMergeStep(): void {
    if (!selectedCandidate) {
      return
    }
    setSelectedSnapshot(selectedCandidate)
    setMergeStep(2)
  }

  function previousMergeStep(): void {
    setMergeStep(1)
    if (!selectedCandidate) {
      setSelectedCandidateId(null)
      setSelectedSnapshot(null)
    }
    mergeAction.clearError()
  }

  async function submitMerge(): Promise<void> {
    if (!detail || !selectedCandidate || mergeDisabled) {
      return
    }
    const fate = passwordChoiceRequired
      ? (passwordFate ?? undefined)
      : undefined
    if (
      await mergeAction.run(
        accountMerge.merge(detail.id, selectedCandidate.id, fate),
      )
    ) {
      closeMerge()
    }
  }

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
        <>
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
          {context.accountMerge ? (
            <section
              className="card border-danger mt-4"
              data-id="hilos-user-merge-zone"
            >
              <div className="card-body">
                <h2 className="h5">Merge another account into this one</h2>
                <p className="mb-3">
                  Its sign-in methods and messages move here; the other account
                  is closed for good.
                </p>
                <button
                  type="button"
                  className="btn btn-outline-danger"
                  data-id="hilos-user-merge-open"
                  onClick={openMerge}
                >
                  Merge an account into this…
                </button>
              </div>
            </section>
          ) : null}
        </>
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

      <HilosModal
        open={mergeOpen}
        title={
          detail ? `Merge an account into ${detail.name}` : 'Merge an account'
        }
        confirmOnClose={selectedCandidateId !== null}
        closeOnBackdrop={!mergeAction.busy}
        closeOnEsc={!mergeAction.busy}
        initialFocus="inner"
        size="wide"
        onClose={closeMerge}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={mergeAction.busy}
              data-id="hilos-user-merge-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            {mergeStep === 1 ? (
              <button
                type="button"
                className="btn btn-primary"
                disabled={selectedCandidate === null}
                data-id="hilos-user-merge-next"
                onClick={nextMergeStep}
              >
                Next
              </button>
            ) : (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={mergeAction.busy}
                  data-id="hilos-user-merge-back"
                  onClick={previousMergeStep}
                >
                  Back
                </button>
                <LoadingButton
                  className="btn-danger"
                  loading={mergeAction.loading}
                  disabled={mergeDisabled}
                  data-id="hilos-user-merge-confirm"
                  onClick={() => void submitMerge()}
                >
                  Merge
                </LoadingButton>
              </>
            )}
          </>
        )}
      >
        <div className="visually-hidden" role="alert" aria-live="assertive">
          {mergeAction.error}
        </div>
        <HilosActionError action={mergeAction} />
        {mergeStep === 1 ? (
          <div role="radiogroup" aria-label="Account to merge">
            <HilosViewportTable
              controller={mergeCandidates.controller}
              autofocusSearch
              cells={{
                [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                  <input
                    type="radio"
                    className="form-check-input"
                    aria-label={`Merge ${row.name}`}
                    data-id={`hilos-user-merge-row-${row.id}`}
                    checked={selectedCandidateId === row.id}
                    disabled={row.id === currentUserId}
                    onChange={() => chooseCandidate(row)}
                  />
                ),
                name: (row) => (
                  <>
                    {row.name}{' '}
                    <span className="text-body-secondary">#{row.id}</span>
                    {row.id === currentUserId ? (
                      <span className="badge text-bg-secondary ms-2">you</span>
                    ) : null}
                  </>
                ),
                [USER_IDENTITIES_FIELD]: (row) => (
                  <ul className="list-unstyled mb-0">
                    {row.identities.map((identity) => (
                      <li key={`${identity.type}:${identity.identifier}`}>
                        <span className="fw-medium">
                          {identityTitle(identity)}
                        </span>
                        {identity.type === 'passkey'
                          ? null
                          : ` · ${identity.identifier}`}
                        {identity.verified ? (
                          <>
                            <span aria-hidden="true"> ✓</span>
                            <span className="visually-hidden"> Verified</span>
                          </>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                ),
                lastActivity: (row) => row.lastActivity ?? '—',
              }}
            />
          </div>
        ) : summaryCandidate ? (
          <>
            <p data-id="hilos-user-merge-summary">
              <strong>
                {summaryCandidate.name} (#{summaryCandidate.id})
              </strong>{' '}
              will be merged into{' '}
              <strong>
                {detail?.name} (#{detail?.id})
              </strong>
              .
            </p>
            <ul>
              <li>
                Its sign-in methods and everything it wrote move to the
                survivor.
              </li>
              <li>
                The other account is closed for good; it cannot sign in and its
                open tabs sign out.
              </li>
              <li>This cannot be undone.</li>
            </ul>
            {mergeGone ? (
              <p className="text-danger" data-id="hilos-user-merge-gone">
                No longer available
              </p>
            ) : null}
            {passwordChoiceRequired ? (
              <fieldset className="mb-3">
                <legend className="h6">
                  Both accounts have a password. Which one stays?
                </legend>
                {(
                  [
                    ['survivor', 'The survivor password'],
                    ['loser', 'The other account password'],
                    ['none', 'Neither password; set a new one in Profile'],
                  ] as const
                ).map(([value, label]) => (
                  <div className="form-check" key={value}>
                    <input
                      id={`hilos-user-merge-fate-${value}-field`}
                      className="form-check-input"
                      type="radio"
                      name="hilos-user-merge-password-fate"
                      value={value}
                      checked={passwordFate === value}
                      data-id={`hilos-user-merge-fate-${value}`}
                      onChange={() => setPasswordFate(value)}
                    />
                    <label
                      className="form-check-label"
                      htmlFor={`hilos-user-merge-fate-${value}-field`}
                    >
                      {label}
                    </label>
                  </div>
                ))}
              </fieldset>
            ) : null}
          </>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
