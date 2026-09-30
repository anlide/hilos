<!-- The profile root's name window over the project's rename (HIL-1169); the agnostic flow owns every transition. -->
<script setup lang="ts">
import {
  focusInitial,
  HILOS_PROFILE_RENAME_COPY as COPY,
  HILOS_STEP_UP_COPY,
  type HilosProfileRenameFlow,
} from '@hilos/core'
import { computed, nextTick, ref, watch } from 'vue'
import ConflictActions from '../ConflictActions.vue'
import ConflictHeader from '../ConflictHeader.vue'
import HilosEditNotice from '../HilosEditNotice.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ flow: HilosProfileRenameFlow }>()
const step = useSignal(props.flow.step)
const draft = useSignal(props.flow.draft)
const edit = useSignal(props.flow.edit)
const notice = useSignal(props.flow.notice)
const valid = useSignal(props.flow.valid)
const busy = useSignal(props.flow.busy)
const refusal = useSignal(props.flow.refusal)
const asksBeforeClosing = useSignal(props.flow.asksBeforeClosing)
const stepUpOpening = useSignal(props.flow.stepUp.opening)
const stepUpBusy = useSignal(props.flow.stepUp.busy)
const stepUpRefusal = useSignal(props.flow.stepUp.refusal)
const body = ref<HTMLElement | null>(null)
const open = computed({
  get: () => step.value !== 'closed',
  set: (value: boolean) => {
    if (!value) props.flow.close()
  },
})
const saveLabel = computed(() => (edit.value.gone ? COPY.deleted : COPY.save))
const bounds = computed(() =>
  COPY.bounds
    .replace('{min}', String(props.flow.minLength))
    .replace('{max}', String(props.flow.maxLength)),
)

// The confirmation's body is replaced by the form: focus its field.
watch(step, (next, previous) => {
  if (previous !== 'step-up' || next !== 'form') return
  void nextTick(() => {
    const dialog = body.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) focusInitial(dialog)
  })
})
</script>

<template>
  <HilosModal
    v-model="open"
    :confirm-on-close="asksBeforeClosing"
    initial-focus="inner"
  >
    <template #header>
      <h2 v-if="step !== 'form'" class="modal-title h5 mb-0">
        {{ HILOS_STEP_UP_COPY.title }}
      </h2>
      <ConflictHeader v-else :title="COPY.title" :conflict="edit.conflict" />
    </template>

    <!-- This dialog's own voice: the page region behind it is under the backdrop,
    and the dialog is aria-modal, so from inside here that region is not there to
    be read. -->
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      data-id="profile-rename-live-assertive"
    >
      {{ step === 'form' ? refusal : stepUpRefusal }}
    </div>

    <div ref="body">
      <form
        v-if="step !== 'form'"
        id="hilos-profile-rename-step-up"
        data-id="profile-name-step-up"
        @submit.prevent="flow.confirmStepUp()"
      >
        <HilosStepUpStep :controller="flow.stepUp" />
      </form>
      <form v-else @submit.prevent="flow.save()">
        <label class="form-label" for="profile-name-field">{{
          COPY.label
        }}</label>
        <input
          id="profile-name-field"
          type="text"
          class="form-control"
          data-autofocus
          data-id="profile-name-input"
          :minlength="flow.minLength"
          :maxlength="flow.maxLength"
          :value="draft"
          @input="flow.draft.set(($event.target as HTMLInputElement).value)"
        />
        <div class="form-text">{{ bounds }}</div>
        <HilosEditNotice
          :kind="edit.notice?.kind ?? null"
          :text="notice"
          data-id="profile-edit-notice"
        />
        <HilosFormError :message="refusal" data-id="profile-rename-error" />
      </form>
    </div>

    <template #actions="{ requestClose }">
      <template v-if="step !== 'form'">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-name-step-up-cancel"
          @click="requestClose"
        >
          {{ COPY.cancel }}
        </button>
        <LoadingButton
          v-if="stepUpOpening !== null"
          type="submit"
          form="hilos-profile-rename-step-up"
          class="btn-primary"
          :loading="stepUpBusy"
          data-id="profile-name-step-up-confirm"
        >
          {{ HILOS_STEP_UP_COPY.confirm }}
        </LoadingButton>
      </template>
      <template v-else>
        <ConflictActions
          :conflict="edit.conflict"
          :disable-save="!valid || !edit.dirty || busy || edit.gone"
          :save-label="saveLabel"
          @save="flow.save()"
          @accept-mine="flow.keepMine()"
          @accept-theirs="flow.takeTheirs()"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-outline-secondary"
              :disabled="busy"
              data-id="profile-rename-cancel"
              @click="requestClose"
            >
              {{ COPY.cancel }}
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="busy"
              :disabled="disabled"
              data-id="profile-rename-save"
              @click="onSave"
            >
              {{ saveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </template>
  </HilosModal>
</template>
