<!-- HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
user's profile, presence, and rename, inside the admin shell. Editing happens in
a modal — inline forms are forbidden (rules-and-violations.md section E,
conflict-resolution.md); the modal hosts the rename form. The detail selector and
the rename action are the core headless's (createHilosUserDetail /
createHilosUserRename); this view owns only the markup, so a project mounts it by
passing its HilosUsersContext. The modal merges against the live row through the
shared row-edit helper (rowEdit.ts, conflict-resolution.md) and says what
happened elsewhere on one line of room held in advance (HilosEditNotice). Success
is state-driven (the committed name reaches the draft over the live table,
closing the modal); a failure surfaces from the backend fail ack inside the
modal. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import {
  createHilosUserDetail,
  createHilosAccountMerge,
  createHilosMergeCandidates,
  createHilosUserRename,
  HilosPages,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  sessionUserId,
  takeTheirsRowEdit,
  type HilosMergeCandidateIdentity,
  type HilosMergeCandidateRow,
  type HilosPasswordFate,
  type HilosUsersContext,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '@hilos/core'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
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

const userDetail = createHilosUserDetail(props.context)
const rename = createHilosUserRename(props.context)

const detail = useSignal(userDetail)
const error = useSignal(rename.renameError)

const mergeCandidates = createHilosMergeCandidates(props.context)
const mergeRows = useSignal(mergeCandidates.controller.rows)
const accountMerge = createHilosAccountMerge(props.context)
const mergeAction = useTrackedAction()
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

function openMerge(): void {
  const survivor = detail.value
  if (!survivor) {
    return
  }
  mergeCandidates.dispose()
  mergeAction.clearError()
  mergeStep.value = 1
  selectedCandidateId.value = null
  selectedSnapshot.value = null
  passwordFate.value = null
  mergeOpen.value = true
  mergeCandidates.start(survivor.id)
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
const draft = ref('')
const loading = ref(false)
const editBaseline = ref<RowEditBaseline<UserEditFields>>(
  openRowEdit<UserEditFields>({ name: '' }),
)

const valid = computed(() => {
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})
// The live row is the card's own detail row, projected onto the name; gone
// once the card has no row any more.
const live = computed(() =>
  resolveRowEdit(
    detail.value ? { name: detail.value.name } : undefined,
    editBaseline.value,
    { name: draft.value.trim() },
  ),
)
const dirty = computed(() => live.value.dirty)
const editTitle = computed(() =>
  detail.value ? `Rename · ${detail.value.name}` : 'Rename user',
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
  rename.clearRenameError()
}

function submit(): void {
  const current = detail.value
  if (!current || !valid.value || loading.value || live.value.gone) {
    return
  }
  // No change: close without a round-trip (also keeps the state-driven success
  // watch from waiting on a name that will never change).
  if (!live.value.dirty) {
    closeEdit()

    return
  }

  loading.value = rename.submitRename(current.id, draft.value.trim())
}

// Success is state-driven: the rename has landed once the committed name (over
// the live table) reaches the submitted draft, which closes the modal.
watch(
  () => detail.value?.name,
  (name) => {
    if (loading.value && name === draft.value.trim()) {
      loading.value = false
      editing.value = false
    }
  },
)

// A rejected rename releases the button and keeps the modal open to retry.
watch(error, (reason) => {
  if (reason !== null) {
    loading.value = false
  }
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.USER">
    <template v-if="detail">
      <div class="card" data-id="hilos-user-detail">
        <div class="card-header d-flex align-items-center gap-2">
          <span
            class="rounded-circle flex-shrink-0"
            :class="
              detail.presence === 'online' ? 'bg-success' : 'bg-secondary'
            "
            style="width: 10px; height: 10px"
            aria-hidden="true"
          />
          <span class="h5 mb-0" data-id="hilos-user-name">{{
            detail.name
          }}</span>
          <span class="badge text-bg-secondary">{{ detail.presence }}</span>
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
          <button
            type="button"
            class="btn btn-outline-danger"
            data-id="hilos-user-merge-open"
            @click="openMerge"
          >
            Merge an account into this…
          </button>
        </div>
      </section>
    </template>
    <p v-else class="text-body-secondary" data-id="hilos-user-empty">
      Loading user…
    </p>

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
        <label class="form-label" for="hilos-user-name-field">
          Display name
        </label>
        <input
          id="hilos-user-name-field"
          v-model="draft"
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
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-user-edit-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="loading"
          data-id="hilos-user-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!valid || !dirty || loading || live.gone"
          :mergeable="false"
          :save-label="saveLabel"
          @save="submit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
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
        detail ? `Merge an account into ${detail.name}` : 'Merge an account'
      "
      :confirm-on-close="selectedCandidateId !== null"
      :close-on-backdrop="!mergeAction.busy.value"
      :close-on-esc="!mergeAction.busy.value"
      initial-focus="inner"
      size="wide"
      @cancel="closeMerge"
    >
      <div class="visually-hidden" role="alert" aria-live="assertive">
        {{ mergeAction.error.value }}
      </div>
      <HilosActionError
        :action="mergeAction"
        details-title="Couldn't merge the accounts"
      />
      <template v-if="mergeStep === 1">
        <div role="radiogroup" aria-label="Account to merge">
          <HilosViewportTable
            :controller="mergeCandidates.controller"
            :autofocus-search="true"
          >
            <template #cell-actions="{ row }">
              <input
                type="radio"
                class="form-check-input"
                :aria-label="`Merge ${row.name}`"
                :data-id="`hilos-user-merge-row-${row.id}`"
                :checked="selectedCandidateId === row.id"
                :disabled="row.id === currentUserId"
                @change="chooseCandidate(row)"
              />
            </template>
            <template #cell-name="{ row }">
              {{ row.name }}
              <span class="text-body-secondary">#{{ row.id }}</span>
              <span
                v-if="row.id === currentUserId"
                class="badge text-bg-secondary ms-2"
                >you</span
              >
            </template>
            <template #cell-identities="{ row }">
              <ul class="list-unstyled mb-0">
                <li
                  v-for="identity in row.identities"
                  :key="`${identity.type}:${identity.identifier}`"
                >
                  <span class="fw-medium">{{ identityTitle(identity) }}</span>
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
            <template #cell-lastActivity="{ row }">{{
              row.lastActivity ?? '—'
            }}</template>
          </HilosViewportTable>
        </div>
      </template>
      <template v-else-if="mergeSummaryCandidate">
        <p data-id="hilos-user-merge-summary">
          <strong
            >{{ mergeSummaryCandidate.name }} (#{{
              mergeSummaryCandidate.id
            }})</strong
          >
          will be merged into
          <strong>{{ detail?.name }} (#{{ detail?.id }})</strong>.
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
            Both accounts have a password. Which one stays?
          </legend>
          <div
            v-for="choice in [
              ['survivor', 'The survivor password'],
              ['loser', 'The other account password'],
              ['none', 'Neither password; set a new one in Profile'],
            ] as const"
            :key="choice[0]"
            class="form-check"
          >
            <input
              :id="`hilos-user-merge-fate-${choice[0]}-field`"
              v-model="passwordFate"
              class="form-check-input"
              type="radio"
              name="hilos-user-merge-password-fate"
              :value="choice[0]"
              :data-id="`hilos-user-merge-fate-${choice[0]}`"
            />
            <label
              class="form-check-label"
              :for="`hilos-user-merge-fate-${choice[0]}-field`"
              >{{ choice[1] }}</label
            >
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
        <button
          v-if="mergeStep === 1"
          type="button"
          class="btn btn-primary"
          :disabled="selectedCandidate === null"
          data-id="hilos-user-merge-next"
          @click="nextMergeStep"
        >
          Next
        </button>
        <template v-else>
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
