<!-- HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
user's profile, presence, and rename, inside the admin shell. Editing happens in
a modal — inline forms are forbidden (rules-and-violations.md section E,
conflict-resolution.md); the modal hosts the rename form. The detail selector and
the rename action are the core headless's (createHilosUserDetail /
createHilosUserRename); this view owns only the markup, so a project mounts it by
passing its HilosUsersContext. The modal merges against the live row through the
shared row-edit helper (rowEdit.ts, conflict-resolution.md) and says what
happened elsewhere on one line of room held in advance (HilosEditNotice). Success
is state-driven (the committed name reaches the name it sent over the live
table, closing the modal); a failure surfaces from the backend fail ack inside the
modal. The person's standing is one verdict (createHilosUserStanding, HIL-945):
the badge beside the presence in the header shows the standing shown, and the
access section draws the block, the freeze — a fact with no control — and the
deletion from the same verdict. A window whose action takes something away —
the merge, rights, the block, the deletion — first asks the server whether the
administrator must confirm it is them, and opens on that step when it must
(createHilosUserCardStepUp, HIL-1275). The takeover lives here too (HIL-1170,
on the users list before): a section drawn while the installation allows
impersonation, its button switched off with a reason on the person's own card
and on whom the settings exclude, and a window — after the same confirmation
step, operation `impersonate` — whose words follow the settings
(hilosUserImpersonationSection). A success needs no word: the session rebinds
and the strip rises. A viewer of the admin view mode opens every window at once,
without the confirmation step, and the confirmation in the window stands
disabled by the mode (HIL-1263). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computed,
  nextTick,
  onMounted,
  onUnmounted,
  ref,
  watch,
  type Ref,
} from 'vue'

import {
  ACCOUNT_DELETION_TICK_MS,
  createHilosImpersonate,
  createHilosUserCardStepUp,
  createHilosUserLifecycle,
  focusInitial,
  HILOS_STEP_UP_COPY,
  createHilosUserStanding,
  HILOS_USER_IMPERSONATION_COPY,
  HILOS_USER_LIFECYCLE_COPY,
  hilosStandingBadge,
  hilosUserImpersonationSection,
  hilosUserFrozenRow,
  hilosUserLifecycleSections,
  hilosUserLifecyclePrompt,
  submitHilosUserLifecycle,
  type HilosStepUpOpenOutcome,
  type HilosUserImpersonationSection,
  type HilosUserLifecycleChoice,
  type HilosUserLifecyclePrompt,
  createHilosUserDetail,
  createHilosUserPhoto,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserRename,
  HILOS_ACCOUNT_MERGE_PASSWORD_COPY,
  hilosPasswordFateChoices,
  HilosPages,
  hiddenAsWord,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  takeTheirsRowEdit,
  type Hideable,
  type HilosMergeCandidateIdentity,
  type HilosMergeCandidateRow,
  type HilosPasswordFate,
  type HilosUsersContext,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '@hilos/core'

import HilosStepUpStep from '../../auth/HilosStepUpStep.vue'
import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAvatar from '../../HilosAvatar.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

const NAME_MIN = 2
const NAME_MAX = 64

/**
 * The one field the modal edits: the display name — hidden for a viewer of the
 * admin view mode, and then the modal shows the mark in place of the input (F1).
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

const userDetail = createHilosUserDetail(props.context)
const userPhoto = createHilosUserPhoto(props.context)
const rename = createHilosUserRename(props.context)

const detail = useSignal(userDetail)
const photo = useSignal(userPhoto)
const error = useSignal(rename.renameError)
/** Move the focus into a window whose step changed under it. */
function focusWindow(body: Ref<HTMLElement | null>): void {
  void nextTick(() => {
    const dialog = body.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
}

const lifecycle = createHilosUserLifecycle(props.context)
const lifecycleAction = useTrackedAction()
const lifecyclePrompt = ref<HilosUserLifecyclePrompt | null>(null)
// The confirmation step the window opens with (HIL-1275): `ask` draws it,
// `refused` draws its refusal, `skip` the window's own content.
const lifecycleStepUp = createHilosUserCardStepUp(props.context)
const lifecycleStepUpBusy = useSignal(lifecycleStepUp.step.busy)
const lifecycleStepUpRefusal = useSignal(lifecycleStepUp.step.refusal)
const lifecycleProof = ref<HilosStepUpOpenOutcome>('skip')
// The window whose button waits for the server's word; a second press sends nothing.
const lifecycleOpening = ref<HilosUserLifecycleChoice | null>(null)
const lifecycleBody = ref<HTMLElement | null>(null)
watch(lifecycleProof, () => focusWindow(lifecycleBody))
const graceDays = useSignal(lifecycle.graceDays)
const lifecycleUserId = useSignal(lifecycle.currentUserId)
const userStanding = createHilosUserStanding(props.context)
const standing = useSignal(userStanding.standing)
const standingBadge = computed(() =>
  standing.value === null ? null : hilosStandingBadge(standing.value.shown),
)
const frozenRow = computed(() => hilosUserFrozenRow(standing.value))
const lifecycleNow = ref(Date.now())
watch(
  [
    () => detail.value?.deletionEffectiveAt,
    () => standing.value?.deletionEffectiveAt,
  ],
  () => {
    lifecycleNow.value = Date.now()
  },
)
const lifecycleCopy = HILOS_USER_LIFECYCLE_COPY
const lifecycleSections = computed(() =>
  hilosUserLifecycleSections(
    detail.value,
    lifecycleUserId.value,
    graceDays.value,
    lifecycleNow.value,
    standing.value,
  ),
)
const lifecycleOpen = computed({
  get: () => lifecyclePrompt.value !== null,
  set: (open: boolean) => {
    if (!open) closeLifecycle()
  },
})
let lifecycleTick: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  userStanding.start()
  lifecycleTick = setInterval(() => {
    lifecycleNow.value = Date.now()
  }, ACCOUNT_DELETION_TICK_MS)
})
onUnmounted(() => {
  userStanding.dispose()
  clearInterval(lifecycleTick)
})

async function openLifecycle(choice: HilosUserLifecycleChoice): Promise<void> {
  if (
    !detail.value ||
    lifecycleAction.busy.value ||
    lifecycleOpening.value !== null
  )
    return
  lifecycleAction.clearError()
  const prompt = hilosUserLifecyclePrompt(detail.value, choice, graceDays.value)
  lifecycleOpening.value = choice
  lifecycleProof.value = await lifecycleStepUp.open(choice)
  lifecycleOpening.value = null
  lifecyclePrompt.value = prompt
}

/** Send the step's proof; the window's own content follows a success. */
async function confirmLifecycleStep(): Promise<void> {
  if (
    lifecycleProof.value === 'ask' &&
    (await lifecycleStepUp.step.confirm()) &&
    lifecyclePrompt.value !== null
  ) {
    lifecycleProof.value = 'skip'
  }
}

function closeLifecycle(): void {
  if (!lifecycleAction.busy.value) lifecyclePrompt.value = null
}

async function submitLifecycle(): Promise<void> {
  if (
    !lifecyclePrompt.value ||
    lifecycleAction.busy.value ||
    detail.value?.id !== lifecyclePrompt.value.userId
  )
    return
  if (
    await lifecycleAction.run(
      submitHilosUserLifecycle(lifecycle, lifecyclePrompt.value),
    )
  )
    closeLifecycle()
}

// The takeover (HIL-1170): the section reads the installation's settings the
// page's first answer carried, the person's live standing and admin flag, and
// who stands behind this session. The window keeps the words it opened with.
const impersonate = createHilosImpersonate(props.context)
const impersonateAction = useTrackedAction()
const impersonateStepUp = createHilosUserCardStepUp(props.context)
const impersonateStepUpBusy = useSignal(impersonateStepUp.step.busy)
const impersonateStepUpRefusal = useSignal(impersonateStepUp.step.refusal)
const impersonateProof = ref<HilosStepUpOpenOutcome>('skip')
const impersonateOpening = ref(false)
const impersonateBody = ref<HTMLElement | null>(null)
watch(impersonateProof, () => focusWindow(impersonateBody))
const impersonationSettings = useSignal(lifecycle.impersonation)
const impersonation = computed(() =>
  hilosUserImpersonationSection(
    detail.value,
    lifecycleUserId.value,
    impersonationSettings.value,
    standing.value,
  ),
)
const impersonateTarget = ref<{
  userId: number
  section: HilosUserImpersonationSection
} | null>(null)
const impersonateOpen = computed({
  get: () => impersonateTarget.value !== null,
  set: (open: boolean) => {
    if (!open) closeImpersonate()
  },
})

async function openImpersonate(): Promise<void> {
  const section = impersonation.value
  if (
    !detail.value ||
    section === null ||
    impersonateAction.busy.value ||
    impersonateOpening.value
  )
    return
  const userId = detail.value.id
  impersonateAction.clearError()
  impersonateOpening.value = true
  impersonateProof.value = await impersonateStepUp.open('impersonate')
  impersonateOpening.value = false
  impersonateTarget.value = { userId, section }
}

/** Send the step's proof; the window's own content follows a success. */
async function confirmImpersonateStep(): Promise<void> {
  if (
    impersonateProof.value === 'ask' &&
    (await impersonateStepUp.step.confirm()) &&
    impersonateTarget.value !== null
  ) {
    impersonateProof.value = 'skip'
  }
}

function closeImpersonate(): void {
  if (!impersonateAction.busy.value) impersonateTarget.value = null
}

// Authoritative-backend: what the takeover changes — the strip, and this
// session becoming the person — arrives with the rebound session, so a success
// only closes the window; a refusal stays in it, and the driver toasts it.
async function submitImpersonate(): Promise<void> {
  const target = impersonateTarget.value
  if (
    target === null ||
    impersonateAction.busy.value ||
    detail.value?.id !== target.userId
  )
    return
  if (await impersonateAction.run(impersonate.start(target.userId)))
    closeImpersonate()
}

const mergeCandidates = createHilosMergeCandidates(props.context)
const mergeRows = useSignal(mergeCandidates.controller.rows)
const accountMerge = createHilosAccountMerge(props.context)
const mergeAction = useTrackedAction()
const mergeStepUp = createHilosUserCardStepUp(props.context)
const mergeStepUpBusy = useSignal(mergeStepUp.step.busy)
const mergeStepUpRefusal = useSignal(mergeStepUp.step.refusal)
const mergeProof = ref<HilosStepUpOpenOutcome>('skip')
const mergeOpening = ref(false)
const mergeBody = ref<HTMLElement | null>(null)
watch(mergeProof, () => focusWindow(mergeBody))
const currentUserId = useSignal(sessionUserId(props.context.scopes))
const mergeOpen = ref(false)
const mergeStep = ref<1 | 2>(1)
const selectedCandidateId = ref<number | null>(null)
const selectedSnapshot = ref<HilosMergeCandidateRow | null>(null)
const passwordFate = ref<HilosPasswordFate | null>(null)

const selectedCandidate = computed(() => {
  const selected = mergeRows.value.find(
    (entry) => entry.row?.id === selectedCandidateId.value,
  )

  return selected?.pending === 'remove' || selected?.placeholder
    ? null
    : (selected?.row ?? null)
})
const mergeSummaryCandidate = computed(
  () => selectedCandidate.value ?? selectedSnapshot.value,
)
const passwordChoiceRequired = computed(
  () =>
    detail.value?.hasPassword === true &&
    selectedCandidate.value?.hasPassword === true,
)
const passwordChoices = computed(() =>
  hilosPasswordFateChoices(detail.value, selectedCandidate.value),
)
const mergeGone = computed(
  () => mergeStep.value === 2 && selectedCandidate.value === null,
)
const mergeDisabled = computed(
  () =>
    mergeAction.busy.value ||
    mergeGone.value ||
    selectedCandidate.value === null ||
    (passwordChoiceRequired.value && passwordFate.value === null),
)

watch(mergeRows, () => {
  if (
    mergeStep.value === 1 &&
    selectedCandidateId.value !== null &&
    selectedCandidate.value === null
  ) {
    selectedCandidateId.value = null
  }
})

function identityTitle(identity: HilosMergeCandidateIdentity): string {
  return identity.provider ?? identity.type
}

async function openMerge(): Promise<void> {
  const survivor = detail.value
  if (!survivor || mergeOpening.value) {
    return
  }
  mergeCandidates.dispose()
  mergeAction.clearError()
  mergeStep.value = 1
  selectedCandidateId.value = null
  selectedSnapshot.value = null
  passwordFate.value = null
  mergeOpening.value = true
  mergeProof.value = await mergeStepUp.open('merge')
  mergeOpening.value = false
  mergeOpen.value = true
  // Other accounts are shown only once the administrator stands confirmed.
  if (mergeProof.value === 'skip') {
    mergeCandidates.start(survivor.id)
  }
}

/** Send the step's proof; the choice of an account follows a success. */
async function confirmMergeStep(): Promise<void> {
  const survivor = detail.value
  if (
    survivor &&
    mergeProof.value === 'ask' &&
    (await mergeStepUp.step.confirm()) &&
    mergeOpen.value
  ) {
    mergeProof.value = 'skip'
    mergeCandidates.start(survivor.id)
  }
}

function closeMerge(): void {
  mergeOpen.value = false
  mergeCandidates.dispose()
}

function chooseCandidate(row: HilosMergeCandidateRow): void {
  if (row.id === currentUserId.value) {
    return
  }
  selectedCandidateId.value = row.id
  passwordFate.value = null
  mergeAction.clearError()
}

function nextMergeStep(): void {
  if (!selectedCandidate.value) {
    return
  }
  selectedSnapshot.value = selectedCandidate.value
  mergeStep.value = 2
}

function previousMergeStep(): void {
  mergeStep.value = 1
  if (selectedCandidate.value === null) {
    selectedCandidateId.value = null
    selectedSnapshot.value = null
  }
  mergeAction.clearError()
}

async function submitMerge(): Promise<void> {
  const survivor = detail.value
  const loser = selectedCandidate.value
  if (!survivor || !loser || mergeDisabled.value) {
    return
  }
  const fate = passwordChoiceRequired.value
    ? (passwordFate.value ?? undefined)
    : undefined
  if (await mergeAction.run(accountMerge.merge(survivor.id, loser.id, fate))) {
    closeMerge()
  }
}

const editing = ref(false)
// The draft holds the name as the card has it: a hidden one stays the one hidden
// value, so the row-edit helper sees it unchanged and the modal is never dirty.
const draft = ref<Hideable<string>>('')
// The input edits the draft only while it is a name; a hidden one has no input.
const draftText = computed({
  get: () => (isHiddenValue(draft.value) ? '' : draft.value),
  set: (next: string) => {
    draft.value = next
  },
})
const loading = ref(false)
// The name the rename in flight sent — what the success watch waits for; null
// while nothing is in flight.
const sentName = ref<string | null>(null)
const editBaseline = ref<RowEditBaseline<UserEditFields>>(
  openRowEdit<UserEditFields>({ name: '' }),
)

const valid = computed(() => {
  if (isHiddenValue(draft.value)) {
    return false
  }
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})
// The live row is the card's own detail row, projected onto the name; gone
// once the card has no row any more.
const live = computed(() =>
  resolveRowEdit(
    detail.value ? { name: detail.value.name } : undefined,
    editBaseline.value,
    { name: isHiddenValue(draft.value) ? draft.value : draft.value.trim() },
  ),
)
const dirty = computed(() => live.value.dirty)
const editTitle = computed(() =>
  detail.value ? `Rename · ${hiddenAsWord(detail.value.name)}` : 'Rename user',
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() => noticeText(live.value))
const saveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))

function openEdit(): void {
  rename.clearRenameError()
  const name = detail.value?.name ?? ''
  draft.value = name
  editBaseline.value = openRowEdit<UserEditFields>({ name })
  loading.value = false
  sentName.value = null
  editing.value = true
}

// Put a step of the helper into the modal: the snapshot moves, and a name the
// step takes lands in the input.
function applyStep(step: RowEditStep<UserEditFields>): void {
  editBaseline.value = step.baseline
  if (step.take.name !== undefined) {
    draft.value = step.take.name
  }
}

// The helper hands a step whenever the other side moved the name while the
// person left it alone, or both arrived at the same one; the modal applies it
// at once.
watch(
  () => live.value.settle,
  (settle) => {
    if (editing.value && settle) {
      applyStep(settle)
    }
  },
)

function acceptMine(): void {
  editBaseline.value = keepMineRowEdit(live.value, editBaseline.value)
}

function acceptTheirs(): void {
  applyStep(takeTheirsRowEdit(live.value, editBaseline.value))
}

// The modal's close path (Cancel / Esc / backdrop, through the discard guard).
function closeEdit(): void {
  editing.value = false
  loading.value = false
  sentName.value = null
  rename.clearRenameError()
}

function submit(): void {
  const current = detail.value
  const typed = draft.value
  if (
    !current ||
    isHiddenValue(typed) ||
    !valid.value ||
    loading.value ||
    live.value.gone
  ) {
    return
  }
  // No change: close without a round-trip (also keeps the state-driven success
  // watch from waiting on a name that will never change).
  if (!live.value.dirty) {
    closeEdit()

    return
  }

  const name = typed.trim()
  loading.value = rename.submitRename(current.id, name)
  sentName.value = loading.value ? name : null
}

// Success is state-driven: the rename has landed once the committed name (over
// the live table) reaches the name it sent, which closes the modal. The draft
// is not part of it: Take theirs while the rename flies rewrites the draft, and
// the modal still waits for its own name.
watch(
  () => detail.value?.name,
  (name) => {
    if (loading.value && name === sentName.value) {
      loading.value = false
      sentName.value = null
      editing.value = false
    }
  },
)

// A rejected rename releases the button, forgets the name it sent, and keeps
// the modal open to retry.
watch(error, (reason) => {
  if (reason !== null) {
    loading.value = false
    sentName.value = null
  }
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.USER">
    <template v-if="detail">
      <div class="card" data-id="hilos-user-detail">
        <div class="card-header d-flex align-items-center gap-2">
          <HilosAvatar
            :name="isHiddenValue(detail.name) ? '' : detail.name"
            :photo="photo"
            size="md"
          />
          <span class="h5 mb-0" data-id="hilos-user-name"
            ><HilosHideable :value="detail.name"
          /></span>
          <span class="badge text-bg-secondary">{{ detail.presence }}</span>
          <span
            v-if="standingBadge !== null"
            class="badge"
            :class="`text-bg-${standingBadge.tone}`"
            data-id="user-standing-badge"
          >
            <i
              class="bi me-1"
              :class="standingBadge.icon"
              aria-hidden="true"
            ></i
            >{{ standingBadge.label }}
          </span>
          <button
            type="button"
            class="btn btn-outline-primary btn-sm ms-auto"
            data-id="hilos-user-edit"
            @click="openEdit"
          >
            Edit
          </button>
        </div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-3">User ID</dt>
            <dd class="col-sm-9" data-id="hilos-user-id">{{ detail.id }}</dd>
            <dt class="col-sm-3">Online sessions</dt>
            <dd class="col-sm-9" data-id="hilos-user-sessions">
              {{ detail.onlineSessionCount }}
            </dd>
            <template v-if="detail.lastActivity">
              <dt class="col-sm-3">Last activity</dt>
              <dd class="col-sm-9" data-id="hilos-user-last-activity">
                {{ detail.lastActivity }}
              </dd>
            </template>
          </dl>
        </div>
      </div>
      <section
        v-for="section in lifecycleSections"
        :key="section.key"
        class="card mt-4"
        :data-id="`hilos-user-${section.key}`"
      >
        <div class="card-body">
          <h2 class="h5">{{ section.title }}</h2>
          <template v-for="row in section.rows" :key="row.key">
            <div class="d-flex flex-wrap align-items-start gap-3 py-2">
              <div class="flex-grow-1" :data-id="`hilos-user-${row.key}-state`">
                <h3 class="h6 mb-1">
                  {{ row.title }}
                  <span class="badge text-bg-secondary">{{
                    row.state ? lifecycleCopy.yes : lifecycleCopy.no
                  }}</span>
                </h3>
                <p class="small text-body-secondary mb-0">{{ row.hint }}</p>
              </div>
              <div>
                <LoadingButton
                  class="btn-sm"
                  :class="
                    lifecycleCopy.confirmations[row.choice].danger
                      ? 'btn-outline-danger'
                      : 'btn-primary'
                  "
                  opens-window
                  :loading="lifecycleOpening === row.choice"
                  :disabled="row.disabled"
                  :aria-describedby="`hilos-user-${row.key}-reason`"
                  :data-id="`hilos-user-${row.key}-open`"
                  @click="openLifecycle(row.choice)"
                >
                  {{ lifecycleCopy[row.choice] }}
                </LoadingButton>
                <div class="hilos-stack small text-body-secondary mt-1">
                  <span class="invisible" aria-hidden="true">{{
                    row.reasonSpace
                  }}</span>
                  <span
                    :id="`hilos-user-${row.key}-reason`"
                    :data-id="`hilos-user-${row.key}-reason`"
                    >{{ row.reason }}</span
                  >
                </div>
              </div>
            </div>
            <!-- The freeze stands between the block and the deletion, and offers
          nothing to press: only the person's own acceptance lifts it. -->
            <div
              v-if="row.key === 'block' && frozenRow !== null"
              class="d-flex flex-wrap align-items-start gap-3 py-2"
            >
              <div class="flex-grow-1" data-id="hilos-user-frozen-state">
                <h3 class="h6 mb-1">
                  {{ frozenRow.title }}
                  <span class="badge text-bg-secondary">{{
                    frozenRow.state ? lifecycleCopy.yes : lifecycleCopy.no
                  }}</span>
                </h3>
                <p
                  v-if="frozenRow.hint !== null"
                  class="small text-body-secondary mb-0"
                >
                  {{ frozenRow.hint }}
                </p>
                <ul
                  v-if="frozenRow.lapsed.length > 0"
                  class="list-unstyled small text-body-secondary mb-0"
                  data-id="hilos-user-frozen-lapsed"
                >
                  <li v-for="line in frozenRow.lapsed" :key="line">
                    {{ line }}
                  </li>
                </ul>
              </div>
            </div>
          </template>
        </div>
      </section>
      <section
        v-if="context.accountMerge"
        class="card border-danger mt-4"
        data-id="hilos-user-merge-zone"
      >
        <div class="card-body">
          <h2 class="h5">Merge another account into this one</h2>
          <p class="mb-3">
            Its sign-in methods and messages move here; the other account is
            closed for good.
          </p>
          <LoadingButton
            class="btn-outline-danger"
            opens-window
            :loading="mergeOpening"
            data-id="hilos-user-merge-open"
            @click="openMerge"
          >
            Merge an account into this…
          </LoadingButton>
        </div>
      </section>
      <section v-if="impersonation !== null" class="card mt-4">
        <div class="card-body">
          <h2 class="h5">{{ impersonation.title }}</h2>
          <div class="d-flex flex-wrap align-items-start gap-3 py-2">
            <div class="flex-grow-1">
              <h3 class="h6 mb-1">{{ impersonation.rowTitle }}</h3>
              <p class="small text-body-secondary mb-0">
                {{ impersonation.hint }}
              </p>
            </div>
            <div>
              <LoadingButton
                class="btn-sm btn-primary"
                opens-window
                :loading="impersonateOpening"
                :disabled="impersonation.disabled"
                aria-describedby="hilos-user-impersonate-reason"
                data-id="hilos-user-impersonate-open"
                @click="openImpersonate"
              >
                {{ HILOS_USER_IMPERSONATION_COPY.open }}
              </LoadingButton>
              <div class="hilos-stack small text-body-secondary mt-1">
                <span class="invisible" aria-hidden="true">{{
                  impersonation.reasonSpace
                }}</span>
                <span
                  id="hilos-user-impersonate-reason"
                  data-id="hilos-user-impersonate-reason"
                  >{{ impersonation.reason }}</span
                >
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>
    <p v-else class="text-body-secondary" data-id="hilos-user-empty">
      Loading user…
    </p>

    <HilosModal
      v-model="lifecycleOpen"
      :title="
        lifecycleProof === 'skip'
          ? lifecyclePrompt?.title
          : HILOS_STEP_UP_COPY.title
      "
      initial-focus="inner"
      :close-on-backdrop="!lifecycleAction.busy.value"
      :close-on-esc="!lifecycleAction.busy.value"
      @cancel="closeLifecycle"
    >
      <div ref="lifecycleBody">
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{
            lifecycleProof === 'skip'
              ? lifecycleAction.error.value
              : lifecycleStepUpRefusal
          }}
        </div>
        <form
          v-if="lifecycleProof !== 'skip'"
          id="hilos-user-lifecycle-proof"
          data-id="hilos-user-lifecycle-step-up"
          @submit.prevent="confirmLifecycleStep()"
        >
          <HilosStepUpStep :controller="lifecycleStepUp.step" />
        </form>
        <template v-else>
          <p v-for="paragraph in lifecyclePrompt?.paragraphs" :key="paragraph">
            {{ paragraph }}
          </p>
          <div data-id="hilos-user-lifecycle-error">
            <HilosActionError
              :action="lifecycleAction"
              details-title="Account change refused"
            />
          </div>
        </template>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="lifecycleAction.busy.value"
          data-id="hilos-user-lifecycle-cancel"
          @click="requestClose"
        >
          {{ lifecycleCopy.cancel }}
        </button>
        <LoadingButton
          v-if="lifecycleProof === 'ask'"
          class="btn-primary"
          type="submit"
          form="hilos-user-lifecycle-proof"
          :loading="lifecycleStepUpBusy"
          data-id="hilos-user-lifecycle-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <LoadingButton
          v-else-if="lifecycleProof === 'skip'"
          :class="lifecyclePrompt?.danger ? 'btn-danger' : 'btn-primary'"
          :loading="lifecycleAction.loading.value"
          :disabled="
            lifecycleAction.busy.value || detail?.id !== lifecyclePrompt?.userId
          "
          data-id="hilos-user-lifecycle-confirm"
          @click="submitLifecycle"
          >{{ lifecyclePrompt?.confirm }}</LoadingButton
        >
      </template>
    </HilosModal>

    <HilosModal
      v-model="impersonateOpen"
      :title="
        impersonateProof === 'skip'
          ? impersonateTarget?.section.windowTitle
          : HILOS_STEP_UP_COPY.title
      "
      initial-focus="inner"
      :close-on-backdrop="!impersonateAction.busy.value"
      :close-on-esc="!impersonateAction.busy.value"
      @cancel="closeImpersonate"
    >
      <div ref="impersonateBody">
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{
            impersonateProof === 'skip'
              ? impersonateAction.error.value
              : impersonateStepUpRefusal
          }}
        </div>
        <form
          v-if="impersonateProof !== 'skip'"
          id="hilos-user-impersonate-proof"
          data-id="hilos-user-impersonate-step-up"
          @submit.prevent="confirmImpersonateStep()"
        >
          <HilosStepUpStep :controller="impersonateStepUp.step" />
        </form>
        <template v-else-if="impersonateTarget !== null">
          <p
            v-for="paragraph in impersonateTarget.section.paragraphs"
            :key="paragraph"
          >
            {{ paragraph }}
          </p>
          <div class="alert alert-secondary small py-2">
            {{ impersonateTarget.section.note }}
          </div>
          <div data-id="hilos-user-impersonate-error">
            <HilosActionError
              :action="impersonateAction"
              details-title="Couldn't impersonate this person"
            />
          </div>
        </template>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="impersonateAction.busy.value"
          data-id="hilos-user-impersonate-cancel"
          @click="requestClose"
        >
          {{ HILOS_USER_IMPERSONATION_COPY.cancel }}
        </button>
        <LoadingButton
          v-if="impersonateProof === 'ask'"
          class="btn-primary"
          type="submit"
          form="hilos-user-impersonate-proof"
          :loading="impersonateStepUpBusy"
          data-id="hilos-user-impersonate-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <LoadingButton
          v-else-if="impersonateProof === 'skip'"
          class="btn-primary"
          :loading="impersonateAction.loading.value"
          :disabled="
            impersonateAction.busy.value ||
            detail?.id !== impersonateTarget?.userId
          "
          data-id="hilos-user-impersonate-confirm"
          @click="submitImpersonate"
          >{{ HILOS_USER_IMPERSONATION_COPY.confirm }}</LoadingButton
        >
      </template>
    </HilosModal>

    <HilosModal v-model="editing" :confirm-on-close="dirty" @cancel="closeEdit">
      <template #header>
        <ConflictHeader :title="editTitle" :conflict="live.conflict" />
      </template>
      <!-- The refusal is announced from here and not from the row that shows
      it: a role arriving together with its text is not announced at all
      (accessibility.md). The region lives inside the dialog because the dialog
      is aria-modal, which hides the page under it from a screen reader. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="hilos-user-live-assertive"
      >
        {{ error }}
      </div>
      <HilosFormError :message="error" data-id="hilos-user-rename-error" />
      <form @submit.prevent="submit">
        <template v-if="isHiddenValue(draft)">
          <div class="form-label">Display name</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <label class="form-label" for="hilos-user-name-field">
            Display name
          </label>
          <input
            id="hilos-user-name-field"
            v-model="draftText"
            type="text"
            class="form-control"
            :minlength="NAME_MIN"
            :maxlength="NAME_MAX"
            data-id="hilos-user-name-input"
            data-autofocus
          />
          <div class="form-text">
            Between {{ NAME_MIN }} and {{ NAME_MAX }} characters.
          </div>
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-user-edit-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!valid || !dirty || loading || live.gone"
          :save-label="saveLabel"
          @save="submit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="loading"
              data-id="hilos-user-cancel"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="loading"
              :disabled="disabled"
              data-id="hilos-user-save"
              @click="onSave"
            >
              {{ saveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>

    <HilosModal
      v-model="mergeOpen"
      :title="
        mergeProof !== 'skip'
          ? HILOS_STEP_UP_COPY.title
          : detail
            ? `Merge an account into ${hiddenAsWord(detail.name)}`
            : 'Merge an account'
      "
      :confirm-on-close="selectedCandidateId !== null"
      :close-on-backdrop="!mergeAction.busy.value"
      :close-on-esc="!mergeAction.busy.value"
      initial-focus="inner"
      size="wide"
      @cancel="closeMerge"
    >
      <div
        ref="mergeBody"
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
      >
        {{
          mergeProof === 'skip' ? mergeAction.error.value : mergeStepUpRefusal
        }}
      </div>
      <HilosActionError
        :action="mergeAction"
        details-title="Couldn't merge the accounts"
      />
      <form
        v-if="mergeProof !== 'skip'"
        id="hilos-user-merge-proof"
        data-id="hilos-user-merge-step-up"
        @submit.prevent="confirmMergeStep()"
      >
        <HilosStepUpStep :controller="mergeStepUp.step" />
      </form>
      <template v-else-if="mergeStep === 1">
        <div role="radiogroup" aria-label="Account to merge">
          <HilosViewportTable
            :controller="mergeCandidates.controller"
            :autofocus-search="true"
          >
            <template #cell-actions="{ row }">
              <input
                type="radio"
                class="form-check-input"
                :aria-label="
                  isHiddenValue(row.name)
                    ? `Merge #${row.id}`
                    : `Merge ${row.name}`
                "
                :data-id="`hilos-user-merge-row-${row.id}`"
                :checked="selectedCandidateId === row.id"
                :disabled="row.id === currentUserId"
                @change="chooseCandidate(row)"
              />
            </template>
            <template #cell-name="{ row }">
              <HilosHideable :value="row.name" />
              <span class="text-body-secondary">#{{ row.id }}</span>
              <span
                v-if="row.id === currentUserId"
                class="badge text-bg-secondary ms-2"
                >you</span
              >
            </template>
            <template #cell-identities="{ row }">
              <HilosHideable :value="row.identities">
                <template #default="{ value: identities }">
                  <ul class="list-unstyled mb-0">
                    <li
                      v-for="identity in identities"
                      :key="`${identity.type}:${identity.identifier}`"
                    >
                      <span class="fw-medium">{{
                        identityTitle(identity)
                      }}</span>
                      <template v-if="identity.type !== 'passkey'">
                        · {{ identity.identifier }}
                      </template>
                      <template v-if="identity.verified">
                        <span aria-hidden="true"> ✓</span
                        ><span class="visually-hidden"> Verified</span>
                      </template>
                    </li>
                  </ul>
                </template>
              </HilosHideable>
            </template>
            <template #cell-lastActivity="{ row }">{{
              row.lastActivity ?? '—'
            }}</template>
          </HilosViewportTable>
        </div>
      </template>
      <template v-else-if="mergeSummaryCandidate">
        <p data-id="hilos-user-merge-summary">
          <strong
            ><HilosHideable :value="mergeSummaryCandidate.name" /> (#{{
              mergeSummaryCandidate.id
            }})</strong
          >
          will be merged into
          <strong
            ><HilosHideable v-if="detail" :value="detail.name" /> (#{{
              detail?.id
            }})</strong
          >.
        </p>
        <ul>
          <li>
            Its sign-in methods and everything it wrote move to the survivor.
          </li>
          <li>
            The other account is closed for good; it cannot sign in and its open
            tabs sign out.
          </li>
          <li>This cannot be undone.</li>
        </ul>
        <p v-if="mergeGone" class="text-danger" data-id="hilos-user-merge-gone">
          No longer available
        </p>
        <fieldset v-if="passwordChoiceRequired" class="mb-3">
          <legend class="h6">
            {{ HILOS_ACCOUNT_MERGE_PASSWORD_COPY.legend }}
          </legend>
          <div
            v-for="choice in passwordChoices"
            :key="choice.value"
            class="form-check"
          >
            <input
              :id="`hilos-user-merge-fate-${choice.value}-field`"
              v-model="passwordFate"
              class="form-check-input"
              type="radio"
              name="hilos-user-merge-password-fate"
              :value="choice.value"
              :data-id="`hilos-user-merge-fate-${choice.value}`"
              :aria-describedby="
                choice.removes.length > 0
                  ? `hilos-user-merge-fate-${choice.value}-removes`
                  : undefined
              "
            />
            <label
              class="form-check-label"
              :for="`hilos-user-merge-fate-${choice.value}-field`"
              >{{ choice.label }}</label
            >
            <div
              v-if="choice.removes.length > 0"
              :id="`hilos-user-merge-fate-${choice.value}-removes`"
              :data-id="`hilos-user-merge-fate-${choice.value}-removes`"
              class="form-text text-danger"
            >
              <div v-for="removal in choice.removes" :key="removal">
                {{ removal }}
              </div>
            </div>
          </div>
        </fieldset>
      </template>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="mergeAction.busy.value"
          data-id="hilos-user-merge-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          v-if="mergeProof === 'ask'"
          class="btn-primary"
          type="submit"
          form="hilos-user-merge-proof"
          :loading="mergeStepUpBusy"
          data-id="hilos-user-merge-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <button
          v-else-if="mergeProof === 'skip' && mergeStep === 1"
          type="button"
          class="btn btn-primary"
          :disabled="selectedCandidate === null"
          data-id="hilos-user-merge-next"
          @click="nextMergeStep"
        >
          Next
        </button>
        <template v-else-if="mergeProof === 'skip'">
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="mergeAction.busy.value"
            data-id="hilos-user-merge-back"
            @click="previousMergeStep"
          >
            Back
          </button>
          <LoadingButton
            class="btn-danger"
            :loading="mergeAction.loading.value"
            :disabled="mergeDisabled"
            data-id="hilos-user-merge-confirm"
            @click="submitMerge"
          >
            Merge
          </LoadingButton>
        </template>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
