<!-- The Hilos user detail page (HilosPages.USER): one user's profile, presence,
and inline rename, inside the admin shell. The profile resolves from the
single-row detail table the subscription delivers, so a rename re-renders here
without a refresh; presence rides the reactive connection slot. Success is
state-driven (the committed name reaches the draft); a failure surfaces from the
backend fail ack (userActions.ts). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { HilosPages } from '@hilos/core'
import { HilosAdminPage, LoadingButton, useSignal } from '@hilos/vue'

import { clearRenameError, renameError, sendUserRename } from './userActions'
import { userDetail } from './userPage'

const NAME_MIN = 2
const NAME_MAX = 64

const detail = useSignal(userDetail)
const error = useSignal(renameError)

const editing = ref(false)
const draft = ref('')
const loading = ref(false)

const valid = computed(() => {
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})

function openEdit(): void {
  clearRenameError()
  draft.value = detail.value?.name ?? ''
  loading.value = false
  editing.value = true
}

function cancelEdit(): void {
  editing.value = false
  loading.value = false
  clearRenameError()
}

function submit(): void {
  const current = detail.value
  if (!current || !valid.value || loading.value) {
    return
  }
  // No change: close without a round-trip (also keeps the state-driven success
  // watch from waiting on a name that will never change).
  if (draft.value.trim() === current.name) {
    editing.value = false

    return
  }

  loading.value = sendUserRename(current.id, draft.value.trim());
}

// Success is state-driven: the rename has landed once the committed name (over
// the live table) reaches the submitted draft.
watch(
  () => detail.value?.name,
  (name) => {
    if (loading.value && name === draft.value.trim()) {
      loading.value = false
      editing.value = false
    }
  },
)

// A rejected rename releases the button and keeps the form open to retry.
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

        <form v-if="editing" class="mt-3" @submit.prevent="submit">
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
          <div
            v-if="error"
            class="alert alert-danger mt-2 mb-0"
            data-id="hilos-user-rename-error"
          >
            {{ error }}
          </div>
          <div class="d-flex gap-2 mt-3">
            <LoadingButton
              class="btn-primary"
              type="submit"
              :loading="loading"
              :disabled="!valid"
              data-id="hilos-user-save"
            >
              Save
            </LoadingButton>
            <button
              type="button"
              class="btn btn-outline-secondary"
              data-id="hilos-user-cancel"
              @click="cancelEdit"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="hilos-user-empty">
      Loading user…
    </p>
  </HilosAdminPage>
</template>
