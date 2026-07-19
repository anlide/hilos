<!-- The current user's profile page (HilosPages.PROFILE): shows the signed-in
user and edits the display name in a modal (edit-in-modal is a hard Hilos rule).
The committed name comes from the live self-connection data; the modal diffs a
draft against it through the core threeWayMerge, so a concurrent rename from
another tab surfaces as a conflict. Submit blocks the button (LoadingButton)
until the backend lands the rename (the committed name reaches the draft) or
rejects it (a framework action_error shown in the modal). Bootstrap classes
only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { threeWayMerge } from '@hilos/core'
import {
  ConflictActions,
  ConflictHeader,
  HilosModal,
  LoadingButton,
  useSignal,
} from '@hilos/vue'

import { currentUserId } from '../../bootstrap/session'
import { clearRenameError, renameError, sendRename } from './profileActions'
import { committedName, profileDetail } from './profilePage'

defineOptions({ name: 'ProfilePage' })

const NAME_MIN = 2
const NAME_MAX = 64

// The profile is a signed-in-only surface. The backend AUTHENTICATED page guard
// 401s an anonymous subscribe, and the framework auth-gate (HilosView) mounts the
// project sign-in surface in place off that 401 — a single owner of the sign-in
// form. This view must NOT render its own sign-in surface up front: that mounts a
// second AuthSurface instance which the gate's 401 then swaps out, resetting any
// in-progress input. Until the subscription answers (a snapshot or the 401), an
// anonymous session shows only a placeholder, never page content; the gate draws
// the form. Registering/logging in flips the session user id, the guard starts
// passing, and the preserved subscription resumes into the profile card.
const selfId = useSignal(currentUserId)
const isAuthenticated = computed(() => selfId.value !== null)

const detail = useSignal(profileDetail)
const committed = useSignal(committedName)
const error = useSignal(renameError)

const editing = ref(false)
const draft = ref('')
// The committed name captured when the modal opened — the 3-way merge baseline.
const baseline = ref('')
const loading = ref(false)

const merge = computed(() =>
  threeWayMerge(baseline.value, draft.value.trim(), committed.value),
)
const conflict = computed(() => merge.value.conflict)
const dirty = computed(() => draft.value.trim() !== baseline.value)
const valid = computed(() => {
  const trimmed = draft.value.trim()

  return trimmed.length >= NAME_MIN && trimmed.length <= NAME_MAX
})

function openEdit(): void {
  clearRenameError()
  baseline.value = committed.value
  draft.value = committed.value
  loading.value = false
  editing.value = true
}

function submit(): void {
  if (!valid.value || !dirty.value || conflict.value || loading.value) {
    return
  }

  loading.value = sendRename(draft.value.trim())
}

// Success is state-driven: while a submit is in flight, the rename has landed
// once the committed name reaches the draft. Outside the modal, keep the
// baseline synced so the next open starts fresh.
watch(committed, (name) => {
  if (loading.value && name === draft.value.trim()) {
    loading.value = false
    editing.value = false
  } else if (!editing.value) {
    baseline.value = name
    draft.value = name
  }
})

// A rejected rename arrives as a framework action_error: release the button and
// keep the modal open so the user can retry from their draft.
watch(error, (reason) => {
  if (reason !== null) {
    loading.value = false
  }
})

// Closing the modal clears the in-flight flag (the draft resets on the next open).
watch(editing, (open) => {
  if (!open) {
    loading.value = false
  }
})

// Conflict resolutions: each sets the baseline so the merge no longer conflicts.
function acceptMine(): void {
  baseline.value = committed.value
}

function acceptTheirs(): void {
  draft.value = committed.value
  baseline.value = committed.value
}

function mergeBoth(): void {
  const mine = draft.value.trim()
  const theirs = committed.value
  draft.value =
    mine !== '' && theirs !== '' && mine !== theirs
      ? `${mine} / ${theirs}`
      : mine || theirs
  baseline.value = committed.value
}
</script>

<template>
  <section v-if="isAuthenticated" data-id="profile-view">
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Profile</h1>
      <p class="mb-0 text-body-secondary">Your account.</p>
    </div>

    <div v-if="detail" class="card" data-id="profile-detail">
      <div
        class="card-body d-flex align-items-center justify-content-between gap-3"
      >
        <dl class="row flex-grow-1 mb-0">
          <dt class="col-sm-3">Name</dt>
          <dd class="col-sm-9 mb-0" data-id="profile-name">
            {{ detail.name }}
          </dd>
        </dl>
        <button
          type="button"
          class="btn btn-outline-primary btn-sm flex-shrink-0"
          data-id="profile-edit"
          @click="openEdit"
        >
          Edit
        </button>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      Loading profile…
    </p>

    <HilosModal v-model="editing" :confirm-on-close="dirty">
      <template #header>
        <ConflictHeader title="Change name" :conflict="conflict" />
      </template>

      <form @submit.prevent="submit">
        <label class="form-label" for="profile-name-field">Display name</label>
        <input
          id="profile-name-field"
          v-model="draft"
          type="text"
          class="form-control"
          data-autofocus
          data-id="profile-name-input"
          :minlength="NAME_MIN"
          :maxlength="NAME_MAX"
        />
        <div class="form-text">
          Between {{ NAME_MIN }} and {{ NAME_MAX }} characters.
        </div>
        <div
          v-if="conflict"
          class="alert alert-warning mt-2 mb-0"
          data-id="profile-conflict-note"
        >
          The name changed elsewhere to “{{ committed }}”. Choose how to
          resolve.
        </div>
        <div
          v-if="error"
          class="alert alert-danger mt-2 mb-0"
          data-id="profile-rename-error"
        >
          {{ error }}
        </div>
      </form>

      <template #actions>
        <ConflictActions
          :conflict="conflict"
          :disable-save="!valid || !dirty"
          @save="submit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
          @merge="mergeBoth"
        >
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="loading"
              :disabled="disabled"
              data-id="profile-rename-save"
              @click="onSave"
            >
              Save
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>
  </section>
  <!-- Anonymous, or the subscription reply hasn't landed yet: a placeholder, never
  page content. The framework auth-gate (HilosView) mounts the sign-in surface in
  place once the AUTHENTICATED guard answers 401 — the single owner of the form. -->
  <p v-else class="text-body-secondary" data-id="profile-loading">
    Loading profile…
  </p>
</template>
