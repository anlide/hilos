<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  type HilosI18nLanguageWindow,
} from '@hilos/core/admin/i18n'
import { computed, onMounted, onUnmounted, watch } from 'vue'

import ConflictActions from '../../../ConflictActions.vue'
import ConflictHeader from '../../../ConflictHeader.vue'
import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{ window: HilosI18nLanguageWindow }>()
const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
const opened = useSignal(props.window.opened)
const mode = useSignal(props.window.mode)
const row = useSignal(props.window.row)
const live = useSignal(props.window.live)
const form = useSignal(props.window.form)
const state = useSignal(props.window.state)
const frozen = useSignal(props.window.frozen)
const plate = useSignal(props.window.plate)
const notice = useSignal(props.window.notice)
const canSave = useSignal(props.window.canSave)
const saveLabel = useSignal(props.window.saveLabel)
const action = useTrackedAction()
const busy = computed(() => action.busy.value)
const title = computed(() =>
  copy.title[mode.value ?? 'view'](row.value?.nativeName ?? ''),
)
const shown = computed(() =>
  mode.value === 'view' ? (live.value ?? row.value ?? form.value) : form.value,
)
const disabled = computed(() => frozen.value || state.value.gone || busy.value)
const modalOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) closeWindow()
  },
})

onMounted(() => props.window.start())
onUnmounted(() => props.window.dispose())
watch(
  mode,
  (next, previous) => {
    if (next !== null && (previous === null || previous === undefined))
      action.clearError()
  },
  { immediate: true },
)

function closeWindow(): void {
  if (!busy.value) props.window.close()
}

function changeName(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    props.window.patchForm({ nativeName: event.target.value })
}

function changeRtl(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    props.window.patchForm({ rtl: event.target.checked })
}

async function submit(): Promise<void> {
  if (mode.value === 'edit') await props.window.save(action.run)
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="title"
    :confirm-on-close="mode === 'edit' && state.dirty"
    :busy="busy"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <template #header>
      <div data-id="i18n-language-edit-title">
        <ConflictHeader
          v-if="mode === 'edit'"
          :title="title"
          :conflict="state.conflict"
        />
        <h5 v-else class="modal-title mb-0">{{ title }}</h5>
      </div>
    </template>
    <div data-id="i18n-language-edit-window">
      <form @submit.prevent="submit">
        <div class="row g-2">
          <div class="col-4">
            <label
              class="form-label small fw-semibold"
              for="i18n-language-edit-code"
              >{{ copy.labels.code }}</label
            >
            <input
              id="i18n-language-edit-code"
              class="form-control form-control-sm"
              :value="row?.code"
              disabled
              aria-describedby="i18n-language-code-hint"
              data-id="i18n-language-edit-code"
            />
            <div id="i18n-language-code-hint" class="form-text">
              <i class="bi bi-database-check me-1" aria-hidden="true"></i>
              {{ copy.hints.code }}
            </div>
          </div>
          <div class="col-8">
            <label
              class="form-label small fw-semibold"
              for="i18n-language-edit-native-name"
              >{{ copy.labels.nativeName }}</label
            >
            <input
              id="i18n-language-edit-native-name"
              class="form-control form-control-sm"
              :value="shown.nativeName"
              :maxlength="HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX"
              :disabled="disabled"
              aria-describedby="i18n-language-name-hint"
              data-id="i18n-language-edit-native-name"
              @input="changeName"
            />
            <div id="i18n-language-name-hint" class="form-text">
              {{ copy.hints.nativeName[mode === 'view' ? 'view' : 'edit'] }}
            </div>
          </div>
        </div>
        <div class="form-check mt-2">
          <input
            id="i18n-language-edit-rtl"
            class="form-check-input"
            type="checkbox"
            :checked="shown.rtl"
            :disabled="disabled"
            :aria-describedby="
              mode === 'view' ? 'i18n-language-rtl-hint' : undefined
            "
            data-id="i18n-language-edit-rtl"
            @change="changeRtl"
          />
          <label class="form-check-label small" for="i18n-language-edit-rtl">
            {{ copy.labels.rtl }}
          </label>
        </div>
        <div
          v-if="mode === 'view'"
          id="i18n-language-rtl-hint"
          class="form-text"
        >
          {{ copy.hints.rtl }}
        </div>
      </form>
      <div data-id="i18n-language-edit-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <HilosEditNotice
        :kind="notice?.kind ?? null"
        :text="notice?.text ?? ''"
        data-id="i18n-language-edit-notice"
      />
      <div
        class="alert small py-2 mb-0 d-flex gap-2"
        :class="plate === 'on' ? 'alert-warning' : 'alert-info'"
        :data-kind="plate"
        data-id="i18n-language-edit-plate"
      >
        <i
          class="bi flex-shrink-0 mt-1"
          :class="plate === 'on' ? 'bi-lock' : 'bi-pencil'"
          aria-hidden="true"
        ></i>
        <div>
          <p class="mb-0">
            <strong>{{ copy.plate[plate].lead }}</strong>
            {{ copy.plate[plate].text }}
          </p>
          <p v-if="plate !== 'on'" class="mt-2 mb-0">{{ copy.plate.code }}</p>
        </div>
      </div>
    </div>
    <template #actions="{ requestClose }">
      <ConflictActions
        v-if="mode === 'edit'"
        :conflict="state.conflict"
        :disable-save="!canSave"
        :save-label="saveLabel"
        @save="submit"
        @accept-mine="window.keepMine"
        @accept-theirs="window.takeTheirs"
      >
        <template #cancel-button>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="busy"
            data-id="i18n-language-edit-cancel"
            @click="requestClose"
          >
            {{ copy.cancel }}
          </button>
        </template>
        <template #save-button="{ disabled: saveDisabled, onSave }">
          <LoadingButton
            class="btn-primary"
            :loading="action.loading.value"
            :disabled="saveDisabled"
            data-id="i18n-language-edit-submit"
            @click="onSave"
            >{{ saveLabel }}</LoadingButton
          >
        </template>
      </ConflictActions>
      <button
        v-else
        type="button"
        class="btn btn-secondary"
        data-id="i18n-language-edit-close"
        @click="requestClose"
      >
        {{ copy.close }}
      </button>
    </template>
  </HilosModal>
</template>
