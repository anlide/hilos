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
  createHilosUserRename,
  HilosPages,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type HilosUsersContext,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '@hilos/core'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

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
    <div v-if="detail" class="card" data-id="hilos-user-detail">
      <div class="card-header d-flex align-items-center gap-2">
        <span
          class="rounded-circle flex-shrink-0"
          :class="detail.presence === 'online' ? 'bg-success' : 'bg-secondary'"
          style="width: 10px; height: 10px"
          aria-hidden="true"
        />
        <span class="h5 mb-0" data-id="hilos-user-name">{{ detail.name }}</span>
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
  </HilosAdminPage>
</template>
