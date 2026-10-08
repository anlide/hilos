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
// name it sent over the live table, closing the modal); a failure surfaces from
// the backend fail ack inside the modal. The person's standing is one verdict
// (createHilosUserStanding, HIL-945): the badge beside the presence in the
// header shows the standing shown, and the access section draws the block, the
// freeze — a fact with no control — and the deletion from the same verdict. A
// window whose action takes something away — the merge, rights, the block, the
// deletion — first asks the server whether the administrator must confirm it is
// them, and opens on that step when it must (createHilosUserCardStepUp,
// HIL-1275). The takeover lives here too (HIL-1170, on the users list before):
// a section drawn while the installation allows impersonation, its button
// switched off with a reason on the person's own card and on whom the settings
// exclude, and a window — after the same confirmation step, operation
// `impersonate` — whose words follow the settings
// (hilosUserImpersonationSection). A success needs no word: the session rebinds
// and the strip rises. The buttons that only open a window stay live in a
// takeover that only looks; the confirmation in the window does not.
// Bootstrap classes only (styling-rules.md).
import { Fragment, useEffect, useMemo, useRef, useState } from 'react'
import {
  ACCOUNT_DELETION_TICK_MS,
  createHilosImpersonate,
  createHilosUserCardStepUp,
  createHilosUserLifecycle,
  focusInitial,
  HILOS_STEP_UP_COPY,
  createHilosUserStanding,
  HILOS_USER_IMPERSONATION_COPY,
  HILOS_USER_MERGED_COPY,
  HILOS_USER_LIFECYCLE_COPY,
  hilosStandingBadge,
  hilosUserImpersonationSection,
  hilosUserFrozenRow,
  hilosUserMergedNotice,
  hilosUserLifecycleSections,
  hilosUserLifecyclePrompt,
  submitHilosUserLifecycle,
  type HilosStepUpOpenOutcome,
  type HilosUserImpersonationSection,
  type HilosUserLifecycleChoice,
  type HilosUserLifecyclePrompt,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  hiddenAsWord,
  isHiddenValue,
  USER_IDENTITIES_FIELD,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserDetail,
  createHilosUserPhoto,
  createHilosUserRename,
  HILOS_ACCOUNT_MERGE_PASSWORD_COPY,
  HILOS_ACCOUNT_MERGE_SECOND_FACTOR_COPY,
  hilosSecondFactorFateChoices,
  type HilosSecondFactorFate,
  hilosPasswordFateChoices,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  Hideable,
  HilosMergeCandidateIdentity,
  HilosMergeCandidateRow,
  HilosPasswordFate,
  HilosUsersContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
} from '@hilos/core'

import { HilosStepUpStep } from '../../auth/HilosStepUpStep.js'
import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosAvatar } from '../../HilosAvatar.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
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

/**
 * The one field the modal edits: the display name — hidden for a viewer of the
 * admin view mode, and then the modal says so in place of the input.
 */
interface UserEditFields {
  name: Hideable<string>
}

/** The one line the modal says about the other side, for what the helper found. */
function noticeText(live: RowEditState<UserEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return `Changed elsewhere to "${hiddenAsWord(live.fields.name.incoming)}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/**
 * Move the focus into a window whose step changed under it; the modal itself
 * places focus only when it opens.
 *
 * @param body An element inside the window, drawn at the new step.
 */
function focusWindow(body: HTMLElement | null): void {
  const dialog = body?.closest<HTMLElement>('[role="dialog"]')
  if (dialog) {
    focusInitial(dialog)
  }
}

/**
 * The framework user-detail admin page: profile, presence, and a modal rename.
 *
 * @param props The project context (scopes, connection, user collection).
 */
export function HilosUserPage({ context }: HilosUserPageProps) {
  const userDetail = useMemo(() => createHilosUserDetail(context), [context])
  const userPhoto = useMemo(() => createHilosUserPhoto(context), [context])
  const rename = useMemo(() => createHilosUserRename(context), [context])

  const detail = useSignal(userDetail)
  const detailRef = useRef(detail)
  useEffect(() => {
    detailRef.current = detail
  }, [detail])
  const photo = useSignal(userPhoto)
  const error = useSignal(rename.renameError)
  const lifecycle = useMemo(() => createHilosUserLifecycle(context), [context])
  const lifecycleAction = useTrackedAction()
  const [lifecyclePrompt, setLifecyclePrompt] =
    useState<HilosUserLifecyclePrompt | null>(null)
  const graceDays = useSignal(lifecycle.graceDays)
  const lifecycleUserId = useSignal(lifecycle.currentUserId)
  const userStanding = useMemo(
    () => createHilosUserStanding(context),
    [context],
  )
  const standing = useSignal(userStanding.standing)
  useEffect(() => {
    userStanding.start()

    return () => userStanding.dispose()
  }, [userStanding])
  const standingBadge =
    standing === null ? null : hilosStandingBadge(standing.shown)
  const frozenRow = hilosUserFrozenRow(standing)
  // A merged account (HIL-1292): the notice under the header stands in for
  // every action section, which the core leaves empty for it.
  const mergedNotice = hilosUserMergedNotice(standing)
  const mergedCopy = HILOS_USER_MERGED_COPY
  const [lifecycleNow, setLifecycleNow] = useState(() => Date.now())
  useEffect(() => {
    setLifecycleNow(Date.now())
  }, [detail?.deletionEffectiveAt, standing?.deletionEffectiveAt])
  const lifecycleCopy = HILOS_USER_LIFECYCLE_COPY
  const lifecycleSections = hilosUserLifecycleSections(
    detail,
    lifecycleUserId,
    graceDays,
    lifecycleNow,
    standing,
  )
  useEffect(() => {
    const tick = setInterval(
      () => setLifecycleNow(Date.now()),
      ACCOUNT_DELETION_TICK_MS,
    )
    return () => clearInterval(tick)
  }, [])

  // The confirmation step the window opens with (HIL-1275): `ask` draws it,
  // `refused` draws its refusal, `skip` the window's own content.
  const lifecycleStepUp = useMemo(
    () =>
      createHilosUserCardStepUp(context, () => {
        if (lifecycleProofRef.current === 'ask') {
          lifecycleProofRef.current = 'skip'
          setLifecycleProof('skip')
        }
      }),
    [context],
  )
  const lifecycleStepUpBusy = useSignal(lifecycleStepUp.step.busy)
  const lifecycleStepUpRefusal = useSignal(lifecycleStepUp.step.refusal)
  const [lifecycleProof, setLifecycleProof] =
    useState<HilosStepUpOpenOutcome>('skip')
  // The proof as the async confirmation sees it, past the render it was set in.
  const lifecycleProofRef = useRef<HilosStepUpOpenOutcome>('skip')
  function moveLifecycleProof(next: HilosStepUpOpenOutcome): void {
    lifecycleProofRef.current = next
    setLifecycleProof(next)
  }
  // The window whose button waits for the server's word; a second press sends nothing.
  const [lifecycleOpening, setLifecycleOpening] =
    useState<HilosUserLifecycleChoice | null>(null)
  const lifecycleOpeningRef = useRef(false)
  const lifecycleBody = useRef<HTMLDivElement>(null)
  useEffect(() => focusWindow(lifecycleBody.current), [lifecycleProof])

  async function openLifecycle(
    choice: HilosUserLifecycleChoice,
  ): Promise<void> {
    if (!detail || lifecycleAction.busy || lifecycleOpeningRef.current) return
    lifecycleAction.clearError()
    const prompt = hilosUserLifecyclePrompt(detail, choice, graceDays)
    lifecycleOpeningRef.current = true
    setLifecycleOpening(choice)
    const proof = await lifecycleStepUp.open(choice)
    lifecycleOpeningRef.current = false
    setLifecycleOpening(null)
    moveLifecycleProof(proof)
    setLifecyclePrompt(prompt)
  }

  /** Send the step's proof; the window's own content follows a success. */
  async function confirmLifecycleStep(): Promise<void> {
    if (
      lifecycleProofRef.current === 'ask' &&
      (await lifecycleStepUp.step.confirm()) &&
      lifecycleProofRef.current === 'ask'
    ) {
      moveLifecycleProof('skip')
    }
  }

  function closeLifecycle(): void {
    if (!lifecycleAction.busy) setLifecyclePrompt(null)
  }

  async function submitLifecycle(): Promise<void> {
    if (
      !lifecyclePrompt ||
      lifecycleAction.busy ||
      detail?.id !== lifecyclePrompt.userId
    )
      return
    if (
      await lifecycleAction.run(
        submitHilosUserLifecycle(lifecycle, lifecyclePrompt),
      )
    )
      closeLifecycle()
  }

  // The takeover (HIL-1170): the section reads the installation's settings the
  // page's first answer carried, the person's live standing and admin flag, and
  // who stands behind this session. The window keeps the words it opened with.
  const impersonate = useMemo(() => createHilosImpersonate(context), [context])
  const impersonateAction = useTrackedAction()
  const impersonateStepUp = useMemo(
    () =>
      createHilosUserCardStepUp(context, () => {
        if (
          impersonateProofRef.current === 'ask' &&
          impersonateTargetRef.current !== null
        ) {
          impersonateProofRef.current = 'skip'
          setImpersonateProof('skip')
        }
      }),
    [context],
  )
  const impersonateStepUpBusy = useSignal(impersonateStepUp.step.busy)
  const impersonateStepUpRefusal = useSignal(impersonateStepUp.step.refusal)
  const [impersonateProof, setImpersonateProof] =
    useState<HilosStepUpOpenOutcome>('skip')
  // The proof and the target as the async confirmation sees them, past the
  // render they were set in.
  const impersonateProofRef = useRef<HilosStepUpOpenOutcome>('skip')
  function moveImpersonateProof(next: HilosStepUpOpenOutcome): void {
    impersonateProofRef.current = next
    setImpersonateProof(next)
  }
  const [impersonateOpening, setImpersonateOpening] = useState(false)
  const impersonateOpeningRef = useRef(false)
  const impersonateBody = useRef<HTMLDivElement>(null)
  useEffect(() => focusWindow(impersonateBody.current), [impersonateProof])
  const impersonationSettings = useSignal(lifecycle.impersonation)
  const impersonation = useMemo(
    () =>
      hilosUserImpersonationSection(
        detail,
        lifecycleUserId,
        impersonationSettings,
        standing,
      ),
    [detail, lifecycleUserId, impersonationSettings, standing],
  )
  const [impersonateTarget, setImpersonateTarget] = useState<{
    userId: number
    section: HilosUserImpersonationSection
  } | null>(null)
  const impersonateTargetRef = useRef<typeof impersonateTarget>(null)
  function showImpersonateTarget(next: typeof impersonateTarget): void {
    impersonateTargetRef.current = next
    setImpersonateTarget(next)
  }

  async function openImpersonate(): Promise<void> {
    const section = impersonation
    if (
      !detail ||
      section === null ||
      impersonateAction.busy ||
      impersonateOpeningRef.current
    )
      return
    const userId = detail.id
    impersonateAction.clearError()
    impersonateOpeningRef.current = true
    setImpersonateOpening(true)
    const proof = await impersonateStepUp.open('impersonate')
    impersonateOpeningRef.current = false
    setImpersonateOpening(false)
    moveImpersonateProof(proof)
    showImpersonateTarget({ userId, section })
  }

  /** Send the step's proof; the window's own content follows a success. */
  async function confirmImpersonateStep(): Promise<void> {
    if (
      impersonateProofRef.current === 'ask' &&
      (await impersonateStepUp.step.confirm()) &&
      impersonateTargetRef.current !== null
    ) {
      moveImpersonateProof('skip')
    }
  }

  function closeImpersonate(): void {
    if (!impersonateAction.busy) showImpersonateTarget(null)
  }

  useEffect(() => {
    if (impersonateTargetRef.current === null) {
      return
    }
    if (
      (impersonation === null || impersonation.disabled) &&
      !impersonateAction.busy
    ) {
      showImpersonateTarget(null)
      return
    }
    if (impersonation !== null) {
      showImpersonateTarget({
        userId: impersonateTargetRef.current.userId,
        section: impersonation,
      })
    }
  }, [impersonation, impersonateAction.busy])

  // Authoritative-backend: what the takeover changes — the strip, and this
  // session becoming the person — arrives with the rebound session, so a success
  // only closes the window; a refusal stays in it, and the driver toasts it.
  async function submitImpersonate(): Promise<void> {
    const target = impersonateTarget
    if (
      target === null ||
      impersonation === null ||
      impersonation.disabled ||
      impersonateAction.busy ||
      detail?.id !== target.userId
    )
      return
    if (await impersonateAction.run(impersonate.start(target.userId)))
      showImpersonateTarget(null)
  }

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
  // Whether the window stands open, as the async confirmation sees it.
  const mergeOpenRef = useRef(false)
  function showMerge(open: boolean): void {
    mergeOpenRef.current = open
    setMergeOpen(open)
  }
  const mergeStepUp = useMemo(
    () =>
      createHilosUserCardStepUp(context, () => {
        const survivor = detailRef.current
        if (
          survivor &&
          mergeProofRef.current === 'ask' &&
          mergeOpenRef.current
        ) {
          mergeProofRef.current = 'skip'
          setMergeProof('skip')
          mergeCandidates.start(survivor.id)
        }
      }),
    [context],
  )
  const mergeStepUpBusy = useSignal(mergeStepUp.step.busy)
  const mergeStepUpRefusal = useSignal(mergeStepUp.step.refusal)
  const [mergeProof, setMergeProof] = useState<HilosStepUpOpenOutcome>('skip')
  const mergeProofRef = useRef<HilosStepUpOpenOutcome>('skip')
  function moveMergeProof(next: HilosStepUpOpenOutcome): void {
    mergeProofRef.current = next
    setMergeProof(next)
  }
  const [mergeOpening, setMergeOpening] = useState(false)
  const mergeOpeningRef = useRef(false)
  const mergeBody = useRef<HTMLDivElement>(null)
  useEffect(() => focusWindow(mergeBody.current), [mergeProof])
  const [mergeStep, setMergeStep] = useState<1 | 2>(1)
  const [selectedCandidateId, setSelectedCandidateId] = useState<number | null>(
    null,
  )
  const [selectedSnapshot, setSelectedSnapshot] =
    useState<HilosMergeCandidateRow | null>(null)
  const [passwordFate, setPasswordFate] = useState<HilosPasswordFate | null>(
    null,
  )
  const [secondFactorFate, setSecondFactorFate] =
    useState<HilosSecondFactorFate | null>(null)
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
  const passwordChoices = hilosPasswordFateChoices(detail, selectedCandidate)
  const secondFactorChoices = hilosSecondFactorFateChoices(
    detail,
    selectedCandidate,
  )
  useEffect(() => {
    setSecondFactorFate(null)
  }, [detail?.hasSecondFactor, selectedCandidate?.hasSecondFactor])
  const mergeGone = mergeStep === 2 && selectedCandidate === null
  const mergeDisabled =
    mergeAction.busy ||
    mergeGone ||
    selectedCandidate === null ||
    (passwordChoiceRequired && passwordFate === null) ||
    typeof detail?.hasSecondFactor !== 'boolean' ||
    typeof selectedCandidate?.hasSecondFactor !== 'boolean' ||
    (secondFactorChoices.length > 0 && secondFactorFate === null)

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
  // A hidden name stays the one hidden value, so the row-edit helper sees it
  // unchanged and the modal is never dirty.
  const [draft, setDraft] = useState<Hideable<string>>('')
  const [loading, setLoading] = useState(false)
  // The name the rename in flight sent — what the success effect waits for;
  // null while nothing is in flight.
  const [sentName, setSentName] = useState<string | null>(null)
  const [editBaseline, setEditBaseline] = useState<
    RowEditBaseline<UserEditFields>
  >(() => openRowEdit<UserEditFields>({ name: '' }))

  const draftHidden = isHiddenValue(draft)
  const trimmed = draftHidden ? '' : draft.trim()
  const valid =
    !draftHidden && trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
  // The live row is the card's own detail row, projected onto the name; gone
  // once the card has no row any more.
  const live = resolveRowEdit(
    detail ? { name: detail.name } : undefined,
    editBaseline,
    { name: draftHidden ? draft : trimmed },
  )
  const dirty = live.dirty
  const editTitle = detail
    ? `Rename · ${hiddenAsWord(detail.name)}`
    : 'Rename user'
  const editNotice = live.notice?.kind ?? null
  const editNoticeText = noticeText(live)
  const saveLabel = live.gone ? 'Deleted' : 'Save'

  function identityTitle(identity: HilosMergeCandidateIdentity): string {
    return identity.provider ?? identity.type
  }

  async function openMerge(): Promise<void> {
    if (!detail || mergeOpeningRef.current) {
      return
    }
    const survivorId = detail.id
    mergeCandidates.dispose()
    mergeAction.clearError()
    setMergeStep(1)
    setSelectedCandidateId(null)
    setSelectedSnapshot(null)
    setPasswordFate(null)
    setSecondFactorFate(null)
    mergeOpeningRef.current = true
    setMergeOpening(true)
    const proof = await mergeStepUp.open('merge')
    mergeOpeningRef.current = false
    setMergeOpening(false)
    moveMergeProof(proof)
    showMerge(true)
    // Other accounts are shown only once the administrator stands confirmed.
    if (proof === 'skip') {
      mergeCandidates.start(survivorId)
    }
  }

  /** Send the step's proof; the choice of an account follows a success. */
  async function confirmMergeStep(): Promise<void> {
    if (!detail || mergeProofRef.current !== 'ask') {
      return
    }
    const survivorId = detail.id
    if (
      (await mergeStepUp.step.confirm()) &&
      mergeOpenRef.current &&
      mergeProofRef.current === 'ask'
    ) {
      moveMergeProof('skip')
      mergeCandidates.start(survivorId)
    }
  }

  function closeMerge(): void {
    showMerge(false)
    mergeCandidates.dispose()
  }

  function chooseCandidate(row: HilosMergeCandidateRow): void {
    if (row.id === currentUserId) {
      return
    }
    setSelectedCandidateId(row.id)
    setPasswordFate(null)
    setSecondFactorFate(null)
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
    if (
      !detail ||
      !selectedCandidate ||
      mergeDisabled ||
      typeof detail.hasSecondFactor !== 'boolean' ||
      typeof selectedCandidate.hasSecondFactor !== 'boolean'
    ) {
      return
    }
    const fate = passwordChoiceRequired
      ? (passwordFate ?? undefined)
      : undefined
    if (
      await mergeAction.run(
        accountMerge.merge(
          detail.id,
          selectedCandidate.id,
          fate,
          secondFactorFate ?? undefined,
          detail.hasSecondFactor,
          selectedCandidate.hasSecondFactor,
        ),
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
    setSentName(null)
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
    setSentName(null)
    rename.clearRenameError()
  }

  // A merge that lands under an open window closes it (HIL-1292): there is
  // nothing left to do over the account. A window whose action is in flight
  // stays, and the server's answer comes as usual - a refusal.
  const merged = mergedNotice !== null
  useEffect(() => {
    if (!merged) {
      return
    }
    if (!loading) closeEdit()
    if (!mergeAction.busy) closeMerge()
    closeLifecycle()
    closeImpersonate()
    // Only the merge landing closes the windows; the flags it reads are the
    // ones the windows themselves move, so they are not what this effect follows.
  }, [merged])

  function submit(): void {
    if (!detail || !valid || loading || live.gone || live.conflict) {
      return
    }
    // No change: close without a round-trip (also keeps the state-driven success
    // watch from waiting on a name that will never change).
    if (!live.dirty) {
      closeEdit()

      return
    }

    const sent = rename.submitRename(detail.id, trimmed)
    setLoading(sent)
    setSentName(sent ? trimmed : null)
  }

  // Success is state-driven: the rename has landed once the committed name (over
  // the live table) reaches the name it sent; that closes the modal. The draft
  // is not part of it: Take theirs while the rename flies rewrites the draft,
  // and the modal still waits for its own name.
  const committedName = detail?.name
  useEffect(() => {
    if (loading && committedName === sentName) {
      setLoading(false)
      setSentName(null)
      setEditing(false)
    }
  }, [committedName, loading, sentName])

  // A rejected rename releases the button, forgets the name it sent, and keeps
  // the modal open to retry.
  useEffect(() => {
    if (error !== null) {
      setLoading(false)
      setSentName(null)
    }
  }, [error])

  return (
    <HilosAdminPage page={HilosPages.USER}>
      {detail ? (
        <>
          <div className="card" data-id="hilos-user-detail">
            <div className="card-header d-flex align-items-center gap-2">
              <HilosAvatar
                name={isHiddenValue(detail.name) ? '' : detail.name}
                photo={photo}
                size="md"
              />
              <span className="h5 mb-0" data-id="hilos-user-name">
                <HilosHideable value={detail.name} />
              </span>
              <span className="badge text-bg-secondary">{detail.presence}</span>
              {standingBadge !== null && (
                <span
                  className={`badge text-bg-${standingBadge.tone}`}
                  data-id="user-standing-badge"
                >
                  <i
                    className={`bi ${standingBadge.icon} me-1`}
                    aria-hidden="true"
                  />
                  {standingBadge.label}
                </span>
              )}
              {mergedNotice === null ? (
                <button
                  type="button"
                  className="btn btn-outline-primary btn-sm ms-auto"
                  data-id="hilos-user-edit"
                  onClick={openEdit}
                >
                  Edit
                </button>
              ) : null}
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
          {/* A merged account says where it went and offers nothing to press:
          every action over it is refused by the server (HIL-1292). */}
          {mergedNotice !== null ? (
            <div
              className="alert alert-secondary mt-4"
              role="status"
              data-id="hilos-user-merged"
            >
              {mergedNotice.userId !== null ? (
                <>
                  {mergedCopy.into} <HilosHideable value={mergedNotice.name} />{' '}
                  (#{mergedNotice.userId}).{' '}
                  {mergedNotice.path !== null ? (
                    <HilosLink
                      className="alert-link"
                      data-id="hilos-user-merged-link"
                      to={mergedNotice.path}
                    >
                      {mergedCopy.open}
                    </HilosLink>
                  ) : null}
                </>
              ) : (
                mergedCopy.gone
              )}
            </div>
          ) : null}
          {lifecycleSections.map((section) => (
            <section
              key={section.key}
              className="card mt-4"
              data-id={`hilos-user-${section.key}`}
            >
              <div className="card-body">
                <h2 className="h5">{section.title}</h2>
                {section.rows.map((row) => (
                  <Fragment key={row.key}>
                    <div className="d-flex flex-wrap align-items-start gap-3 py-2">
                      <div
                        className="flex-grow-1"
                        data-id={`hilos-user-${row.key}-state`}
                      >
                        <h3 className="h6 mb-1">
                          {row.title}{' '}
                          <span className="badge text-bg-secondary">
                            {row.state ? lifecycleCopy.yes : lifecycleCopy.no}
                          </span>
                        </h3>
                        <p className="small text-body-secondary mb-0">
                          {row.hint}
                        </p>
                      </div>
                      <div>
                        <LoadingButton
                          className={`btn-sm ${lifecycleCopy.confirmations[row.choice].danger ? 'btn-outline-danger' : 'btn-primary'}`}
                          opensWindow
                          loading={lifecycleOpening === row.choice}
                          disabled={row.disabled}
                          aria-describedby={`hilos-user-${row.key}-reason`}
                          data-id={`hilos-user-${row.key}-open`}
                          onClick={() => void openLifecycle(row.choice)}
                        >
                          {lifecycleCopy[row.choice]}
                        </LoadingButton>
                        <div className="hilos-stack small text-body-secondary mt-1">
                          <span className="invisible" aria-hidden="true">
                            {row.reasonSpace}
                          </span>
                          <span
                            id={`hilos-user-${row.key}-reason`}
                            data-id={`hilos-user-${row.key}-reason`}
                          >
                            {row.reason}
                          </span>
                        </div>
                      </div>
                    </div>
                    {/* The freeze stands between the block and the deletion,
                    and offers nothing to press: only the person's own
                    acceptance lifts it. */}
                    {row.key === 'block' && frozenRow !== null && (
                      <div className="d-flex flex-wrap align-items-start gap-3 py-2">
                        <div
                          className="flex-grow-1"
                          data-id="hilos-user-frozen-state"
                        >
                          <h3 className="h6 mb-1">
                            {frozenRow.title}{' '}
                            <span className="badge text-bg-secondary">
                              {frozenRow.state
                                ? lifecycleCopy.yes
                                : lifecycleCopy.no}
                            </span>
                          </h3>
                          {frozenRow.hint !== null && (
                            <p className="small text-body-secondary mb-0">
                              {frozenRow.hint}
                            </p>
                          )}
                          {frozenRow.lapsed.length > 0 && (
                            <ul
                              className="list-unstyled small text-body-secondary mb-0"
                              data-id="hilos-user-frozen-lapsed"
                            >
                              {frozenRow.lapsed.map((line) => (
                                <li key={line}>{line}</li>
                              ))}
                            </ul>
                          )}
                        </div>
                      </div>
                    )}
                  </Fragment>
                ))}
              </div>
            </section>
          ))}
          {context.accountMerge && mergedNotice === null ? (
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
                <LoadingButton
                  className="btn-outline-danger"
                  opensWindow
                  loading={mergeOpening}
                  data-id="hilos-user-merge-open"
                  onClick={() => void openMerge()}
                >
                  Merge an account into this…
                </LoadingButton>
              </div>
            </section>
          ) : null}
          {impersonation !== null ? (
            <section className="card mt-4">
              <div className="card-body">
                <h2 className="h5">{impersonation.title}</h2>
                <div className="d-flex flex-wrap align-items-start gap-3 py-2">
                  <div className="flex-grow-1">
                    <h3 className="h6 mb-1">{impersonation.rowTitle}</h3>
                    <p className="small text-body-secondary mb-0">
                      {impersonation.hint}
                    </p>
                  </div>
                  <div>
                    <LoadingButton
                      className="btn-sm btn-primary"
                      opensWindow
                      loading={impersonateOpening}
                      disabled={impersonation.disabled}
                      aria-describedby="hilos-user-impersonate-reason"
                      data-id="hilos-user-impersonate-open"
                      onClick={() => void openImpersonate()}
                    >
                      {HILOS_USER_IMPERSONATION_COPY.open}
                    </LoadingButton>
                    <div className="hilos-stack small text-body-secondary mt-1">
                      <span className="invisible" aria-hidden="true">
                        {impersonation.reasonSpace}
                      </span>
                      <span
                        id="hilos-user-impersonate-reason"
                        data-id="hilos-user-impersonate-reason"
                      >
                        {impersonation.reason}
                      </span>
                    </div>
                  </div>
                </div>
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
        open={lifecyclePrompt !== null}
        onClose={closeLifecycle}
        title={
          lifecycleProof === 'skip'
            ? lifecyclePrompt?.title
            : HILOS_STEP_UP_COPY.title
        }
        initialFocus="inner"
        closeOnBackdrop={!lifecycleAction.busy}
        closeOnEsc={!lifecycleAction.busy}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={lifecycleAction.busy}
              data-id="hilos-user-lifecycle-cancel"
              onClick={requestClose}
            >
              {lifecycleCopy.cancel}
            </button>
            {lifecycleProof === 'ask' ? (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form="hilos-user-lifecycle-proof"
                loading={lifecycleStepUpBusy}
                data-id="hilos-user-lifecycle-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : lifecycleProof === 'skip' ? (
              <LoadingButton
                className={
                  lifecyclePrompt?.danger ? 'btn-danger' : 'btn-primary'
                }
                loading={lifecycleAction.loading}
                disabled={
                  lifecycleAction.busy || detail?.id !== lifecyclePrompt?.userId
                }
                data-id="hilos-user-lifecycle-confirm"
                onClick={() => void submitLifecycle()}
              >
                {lifecyclePrompt?.confirm}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <div ref={lifecycleBody}>
          <div className="visually-hidden" role="alert" aria-live="assertive">
            {lifecycleProof === 'skip'
              ? lifecycleAction.error
              : lifecycleStepUpRefusal}
          </div>
          {lifecycleProof === 'skip' ? (
            <>
              {lifecyclePrompt?.paragraphs.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
              <div data-id="hilos-user-lifecycle-error">
                <HilosActionError
                  action={lifecycleAction}
                  detailsTitle="Account change refused"
                />
              </div>
            </>
          ) : (
            <form
              id="hilos-user-lifecycle-proof"
              data-id="hilos-user-lifecycle-step-up"
              onSubmit={(event) => {
                event.preventDefault()
                void confirmLifecycleStep()
              }}
            >
              <HilosStepUpStep controller={lifecycleStepUp.step} />
            </form>
          )}
        </div>
      </HilosModal>

      <HilosModal
        open={impersonateTarget !== null}
        onClose={closeImpersonate}
        title={
          impersonateProof === 'skip'
            ? impersonateTarget?.section.windowTitle
            : HILOS_STEP_UP_COPY.title
        }
        initialFocus="inner"
        closeOnBackdrop={!impersonateAction.busy}
        closeOnEsc={!impersonateAction.busy}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={impersonateAction.busy}
              data-id="hilos-user-impersonate-cancel"
              onClick={requestClose}
            >
              {HILOS_USER_IMPERSONATION_COPY.cancel}
            </button>
            {impersonateProof === 'ask' ? (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form="hilos-user-impersonate-proof"
                loading={impersonateStepUpBusy}
                data-id="hilos-user-impersonate-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : impersonateProof === 'skip' ? (
              <LoadingButton
                className="btn-primary"
                loading={impersonateAction.loading}
                disabled={
                  impersonateAction.busy ||
                  impersonation === null ||
                  impersonation.disabled ||
                  detail?.id !== impersonateTarget?.userId
                }
                data-id="hilos-user-impersonate-confirm"
                onClick={() => void submitImpersonate()}
              >
                {HILOS_USER_IMPERSONATION_COPY.confirm}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <div ref={impersonateBody}>
          <div className="visually-hidden" role="alert" aria-live="assertive">
            {impersonateProof === 'skip'
              ? impersonateAction.error
              : impersonateStepUpRefusal}
          </div>
          {impersonateProof !== 'skip' ? (
            <form
              id="hilos-user-impersonate-proof"
              data-id="hilos-user-impersonate-step-up"
              onSubmit={(event) => {
                event.preventDefault()
                void confirmImpersonateStep()
              }}
            >
              <HilosStepUpStep controller={impersonateStepUp.step} />
            </form>
          ) : impersonateTarget !== null ? (
            <>
              {impersonateTarget.section.paragraphs.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
              <div className="alert alert-secondary small py-2">
                {impersonateTarget.section.note}
              </div>
              <div data-id="hilos-user-impersonate-error">
                <HilosActionError
                  action={impersonateAction}
                  detailsTitle="Couldn't impersonate this person"
                />
              </div>
            </>
          ) : null}
        </div>
      </HilosModal>

      <HilosModal
        open={editing}
        confirmOnClose={dirty}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!valid || !dirty || loading || live.gone}
            saveLabel={saveLabel}
            onSave={submit}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={loading}
                data-id="hilos-user-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
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
          {draftHidden ? (
            <>
              <div className="form-label">Display name</div>
              <HilosHiddenMark />
            </>
          ) : (
            <>
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
            </>
          )}
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
          mergeProof !== 'skip'
            ? HILOS_STEP_UP_COPY.title
            : detail
              ? `Merge an account into ${hiddenAsWord(detail.name)}`
              : 'Merge an account'
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
            {mergeProof === 'ask' ? (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form="hilos-user-merge-proof"
                loading={mergeStepUpBusy}
                data-id="hilos-user-merge-step-up-confirm"
              >
                {HILOS_STEP_UP_COPY.confirm}
              </LoadingButton>
            ) : mergeProof === 'refused' ? null : mergeStep === 1 ? (
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
        <div
          ref={mergeBody}
          className="visually-hidden"
          role="alert"
          aria-live="assertive"
        >
          {mergeProof === 'skip' ? mergeAction.error : mergeStepUpRefusal}
        </div>
        <HilosActionError
          action={mergeAction}
          detailsTitle="Couldn't merge the accounts"
        />
        {mergeProof !== 'skip' ? (
          <form
            id="hilos-user-merge-proof"
            data-id="hilos-user-merge-step-up"
            onSubmit={(event) => {
              event.preventDefault()
              void confirmMergeStep()
            }}
          >
            <HilosStepUpStep controller={mergeStepUp.step} />
          </form>
        ) : mergeStep === 1 ? (
          <div role="radiogroup" aria-label="Account to merge">
            <HilosViewportTable
              controller={mergeCandidates.controller}
              autofocusSearch
              cells={{
                [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                  <input
                    type="radio"
                    className="form-check-input"
                    aria-label={
                      isHiddenValue(row.name)
                        ? `Merge #${row.id}`
                        : `Merge ${row.name}`
                    }
                    data-id={`hilos-user-merge-row-${row.id}`}
                    checked={selectedCandidateId === row.id}
                    disabled={row.id === currentUserId}
                    onChange={() => chooseCandidate(row)}
                  />
                ),
                name: (row) => (
                  <>
                    <HilosHideable value={row.name} />{' '}
                    <span className="text-body-secondary">#{row.id}</span>
                    {row.id === currentUserId ? (
                      <span className="badge text-bg-secondary ms-2">you</span>
                    ) : null}
                  </>
                ),
                [USER_IDENTITIES_FIELD]: (row) => (
                  <HilosHideable value={row.identities}>
                    {(identities) => (
                      <ul className="list-unstyled mb-0">
                        {identities.map((identity) => (
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
                                <span className="visually-hidden">
                                  {' '}
                                  Verified
                                </span>
                              </>
                            ) : null}
                          </li>
                        ))}
                      </ul>
                    )}
                  </HilosHideable>
                ),
                lastActivity: (row) => row.lastActivity ?? '—',
              }}
            />
          </div>
        ) : summaryCandidate ? (
          <>
            <p data-id="hilos-user-merge-summary">
              <strong>
                <HilosHideable value={summaryCandidate.name} /> (#
                {summaryCandidate.id})
              </strong>{' '}
              will be merged into{' '}
              <strong>
                {detail ? <HilosHideable value={detail.name} /> : ''} (#
                {detail?.id})
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
            <p
              data-id="hilos-user-merge-second-factor-summary"
              role="status"
              aria-live="polite"
            >
              {HILOS_ACCOUNT_MERGE_SECOND_FACTOR_COPY.survivor}{' '}
              {detail?.hasSecondFactor === true
                ? 'Yes'
                : detail?.hasSecondFactor === false
                  ? 'No'
                  : 'Hidden'}
              . {HILOS_ACCOUNT_MERGE_SECOND_FACTOR_COPY.loser}{' '}
              {summaryCandidate?.hasSecondFactor === true
                ? 'Yes'
                : summaryCandidate?.hasSecondFactor === false
                  ? 'No'
                  : 'Hidden'}
              .
            </p>
            {secondFactorChoices.length > 0 ? (
              <fieldset className="mb-3">
                <legend className="h6">
                  {HILOS_ACCOUNT_MERGE_SECOND_FACTOR_COPY.legend}
                </legend>
                <p className="form-text">
                  {HILOS_ACCOUNT_MERGE_SECOND_FACTOR_COPY.trusts}
                </p>
                {secondFactorChoices.map((choice) => (
                  <div className="form-check" key={choice.value}>
                    <input
                      id={`hilos-user-merge-second-factor-${choice.value}-field`}
                      className="form-check-input"
                      type="radio"
                      name="hilos-user-merge-second-factor"
                      value={choice.value}
                      checked={secondFactorFate === choice.value}
                      data-id={`hilos-user-merge-second-factor-${choice.value}`}
                      onChange={() => setSecondFactorFate(choice.value)}
                      aria-describedby={`hilos-user-merge-second-factor-${choice.value}-consequence`}
                    />
                    <label
                      className="form-check-label"
                      htmlFor={`hilos-user-merge-second-factor-${choice.value}-field`}
                    >
                      {choice.label}
                    </label>
                    <p
                      className="form-text"
                      id={`hilos-user-merge-second-factor-${choice.value}-consequence`}
                    >
                      {choice.consequence}
                    </p>
                  </div>
                ))}
              </fieldset>
            ) : null}
            {passwordChoiceRequired ? (
              <fieldset className="mb-3">
                <legend className="h6">
                  {HILOS_ACCOUNT_MERGE_PASSWORD_COPY.legend}
                </legend>
                {passwordChoices.map((choice) => (
                  <div className="form-check" key={choice.value}>
                    <input
                      id={`hilos-user-merge-fate-${choice.value}-field`}
                      className="form-check-input"
                      type="radio"
                      name="hilos-user-merge-password-fate"
                      value={choice.value}
                      checked={passwordFate === choice.value}
                      data-id={`hilos-user-merge-fate-${choice.value}`}
                      aria-describedby={
                        choice.removes.length > 0
                          ? `hilos-user-merge-fate-${choice.value}-removes`
                          : undefined
                      }
                      onChange={() => setPasswordFate(choice.value)}
                    />
                    <label
                      className="form-check-label"
                      htmlFor={`hilos-user-merge-fate-${choice.value}-field`}
                    >
                      {choice.label}
                    </label>
                    {choice.removes.length > 0 ? (
                      <div
                        id={`hilos-user-merge-fate-${choice.value}-removes`}
                        data-id={`hilos-user-merge-fate-${choice.value}-removes`}
                        className="form-text text-danger"
                      >
                        {choice.removes.map((removal) => (
                          <div key={removal}>{removal}</div>
                        ))}
                      </div>
                    ) : null}
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
