<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  createHilosI18nLanguageDelete,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'
import { computed, ref, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  modelValue: boolean
  context: HilosI18nLanguageContext
  source: Pick<
    HilosI18nLanguageRow,
    'languageId' | 'code' | 'nativeName' | 'canDelete' | 'deleteReason'
  > | null
  actionName:
    | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
    | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION
}>()
const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  deleted: []
}>()

const copy = HILOS_I18N_LANGUAGE_DELETE_COPY
const action = useTrackedAction()
const { locked } = useLookOnly()
const openedId = ref<number | null>(null)
const openedName = ref('')
const openedAction = ref(props.actionName)
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
    props.source?.languageId !== openedId.value,
)

watch(
  () => props.modelValue,
  (open) => {
    if (open && props.source !== null) {
      openedId.value = props.source.languageId
      openedName.value = props.source.nativeName
      openedAction.value = props.actionName
      action.clearError()
    }
  },
  { immediate: true },
)

async function confirm(): Promise<void> {
  if (
    !props.modelValue ||
    openedId.value === null ||
    action.busy.value ||
    locked.value ||
    elsewhere.value
  ) {
    return
  }
  // The opening hint may have changed. Let the server explain its live verdict.
  const { deleteLanguage } = createHilosI18nLanguageDelete(
    props.context,
    openedAction.value,
  )
  if (await action.run(deleteLanguage(openedId.value))) {
    emit('update:modelValue', false)
    emit('deleted')
  }
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="copy.title(openedName)"
    :busy="action.busy.value"
    initial-focus="dialog"
  >
    <div data-id="i18n-language-delete-window">
      <div data-id="i18n-language-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p>{{ copy.body }}</p>
      <div class="alert alert-secondary small py-2 mb-0">{{ copy.note }}</div>
      <HilosEditNotice
        :kind="elsewhere ? 'deleted' : null"
        :text="copy.elsewhere"
        data-id="i18n-language-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="i18n-language-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere"
        data-id="i18n-language-delete-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
