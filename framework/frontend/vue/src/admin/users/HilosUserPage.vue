<!-- HilosUserPage — the framework Hilos user-detail page (HilosPages.USER): one
user's profile, presence, and rename, inside the admin shell. Editing happens in
a modal — inline forms are forbidden (rules-and-violations.md section E,
conflict-resolution.md); the modal hosts the rename form. The detail selector and
the rename action are the core headless's (createHilosUserDetail /
createHilosUserRename); this view owns only the markup, so a project mounts it by
passing its HilosUsersContext. Success is state-driven (the committed name reaches
the draft over the live table, closing the modal); a failure surfaces from the
backend fail ack inside the modal. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import {
  createHilosUserDetail,
  createHilosUserRename,
  HilosPages,
  type HilosUsersContext,
} from '@hilos/core'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

const NAME_MIN = 2
const NAME_MAX = 64

const userDetail = createHilosUserDetail(props.context)
const rename = createHilosUserRename(props.context)

const detail = useSignal(userDetail)
const error = useSignal(rename.renameError)

const editing = ref(false)
const draft = ref('')
const loading = ref(false)

const valid = computed(() => {
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})
const dirty = computed(() => {
  const current = detail.value

  return !!current && draft.value.trim() !== current.name
})

function openEdit(): void {
  rename.clearRenameError()
  draft.value = detail.value?.name ?? ''
  loading.value = false
  editing.value = true
}

// The modal's close path (Cancel / Esc / backdrop, through the discard guard).
function closeEdit(): void {
  editing.value = false
  loading.value = false
  rename.clearRenameError()
}

function submit(): void {
  const current = detail.value
  if (!current || !valid.value || loading.value) {
    return
  }
  // No change: close without a round-trip (also keeps the state-driven success
  // watch from waiting on a name that will never change).
  if (draft.value.trim() === current.name) {
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

    <HilosModal
      v-model="editing"
      :title="detail ? `Rename · ${detail.name}` : 'Rename user'"
      :confirm-on-close="dirty"
      @cancel="closeEdit"
    >
      <div
        v-if="error"
        class="alert alert-danger"
        role="alert"
        data-id="hilos-user-rename-error"
      >
        {{ error }}
      </div>
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
        />
        <div class="form-text">
          Between {{ NAME_MIN }} and {{ NAME_MAX }} characters.
        </div>
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
        <LoadingButton
          class="btn-primary"
          :loading="loading"
          :disabled="!valid || !dirty"
          data-id="hilos-user-save"
          @click="submit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
