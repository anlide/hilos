<!-- Both page doors keep this dialog mounted independently of their live source. -->
<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_DELETE_COPY,
  hilosI18nCountryDeleteLabel,
  type ActionHandle,
  type HilosI18nCountryDeleteTarget,
} from '@hilos/core'
import { computed, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  modelValue: boolean
  /** The target chosen on open; it outlives the server's deletion echo. */
  target: HilosI18nCountryDeleteTarget | null
  /** The current source, separate from the chosen target and window lifetime. */
  source: HilosI18nCountryDeleteTarget | null
  deleteCountry: (countryCode: string) => ActionHandle
}>()
const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  deleted: []
}>()

const copy = HILOS_I18N_COUNTRY_DELETE_COPY
const action = useTrackedAction()
const { locked } = useLookOnly()
const label = computed(() =>
  props.target === null ? '' : hilosI18nCountryDeleteLabel(props.target),
)
const modalOpen = computed({
  get: () => props.modelValue,
  set: (next: boolean) => {
    if (!action.busy.value) emit('update:modelValue', next)
  },
})
const elsewhere = computed(
  () =>
    props.modelValue &&
    !action.busy.value &&
    props.source?.code !== props.target?.code,
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) action.clearError()
  },
)

async function confirm(): Promise<void> {
  if (
    !props.modelValue ||
    props.target === null ||
    action.busy.value ||
    locked.value ||
    elsewhere.value
  ) {
    return
  }
  // A later dependency hint leaves this door open for the server's verdict.
  if (await action.run(props.deleteCountry(props.target.code))) {
    emit('update:modelValue', false)
    emit('deleted')
  }
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="copy.title(label)"
    :close-on-backdrop="!action.busy.value"
    :close-on-esc="!action.busy.value"
    initial-focus="dialog"
  >
    <div data-id="i18n-country-delete-window">
      <div data-id="i18n-country-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p>{{ copy.body }}</p>
      <div class="alert alert-warning small py-2 mb-0">{{ copy.note }}</div>
      <HilosEditNotice
        :kind="elsewhere ? 'deleted' : null"
        :text="copy.elsewhere"
        data-id="i18n-country-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="i18n-country-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere"
        data-id="i18n-country-delete-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
