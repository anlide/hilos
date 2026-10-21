<!-- The target and tracked action outlive the locale's own deletion echo. -->
<script setup lang="ts">
import {
  HILOS_I18N_LOCALE_DELETE_COPY,
  type HilosI18nLocaleDeleteTarget,
} from '@hilos/core/admin/i18n'
import { type ActionHandle } from '@hilos/core/base'
import { computed, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  modelValue: boolean
  /** Frozen identity and labels chosen on open. */
  target: HilosI18nLocaleDeleteTarget | null
  /** Current locale and language, or null when either disappeared. */
  source: HilosI18nLocaleDeleteTarget | null
  deleteLocale: (
    languageId: number,
    countryId: number | null,
    localeId: number,
  ) => ActionHandle
}>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean] }>()

const copy = HILOS_I18N_LOCALE_DELETE_COPY
const action = useTrackedAction()
const { locked } = useLookOnly()
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
    (props.source === null ||
      props.source.languageId !== props.target?.languageId ||
      props.source.countryId !== props.target?.countryId ||
      props.source.localeId !== props.target?.localeId),
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
  if (
    await action.run(
      props.deleteLocale(
        props.target.languageId,
        props.target.countryId,
        props.target.localeId,
      ),
    )
  ) {
    emit('update:modelValue', false)
  }
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="copy.title(target?.nativeName ?? '', target?.countryLabel ?? null)"
    :busy="action.busy.value"
    initial-focus="dialog"
  >
    <div data-id="i18n-locale-delete-window">
      <div data-id="i18n-locale-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p>
        {{ copy.body(target?.languageCode ?? '', target?.countryCode ?? null) }}
      </p>
      <div class="alert alert-warning small py-2 mb-0">{{ copy.note }}</div>
      <HilosEditNotice
        :kind="elsewhere ? 'deleted' : null"
        :text="copy.elsewhere"
        data-id="i18n-locale-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="i18n-locale-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere"
        data-id="i18n-locale-delete-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
