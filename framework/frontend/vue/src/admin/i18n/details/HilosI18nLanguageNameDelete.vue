<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  createHilosI18nLanguageNameDelete,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core'
import { computed, onUnmounted, shallowRef, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  modelValue: boolean
  context: HilosI18nLanguageContext
  target: HilosI18nLanguageNameDeleteTarget | null
  source: HilosI18nNameRow | null
}>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean] }>()
const copy = HILOS_I18N_LANGUAGE_NAME_DELETE_COPY
const action = useTrackedAction()
const { locked } = useLookOnly()
let disposed = false
onUnmounted(() => {
  disposed = true
})
const opened = shallowRef<HilosI18nLanguageNameDeleteTarget | null>(null)
const modalOpen = computed({
  get: () => props.modelValue,
  set: (next: boolean) => {
    if (!action.busy.value) emit('update:modelValue', next)
  },
})
const missing = computed(() => {
  const target = opened.value
  const row = props.source
  if (!target || row?.inLanguageId !== target.inLanguageId || row.name === null)
    return true
  return (
    (target.kind === 'base' && row.nameId !== target.nameId) ||
    (target.kind === 'override' &&
      row.corrections.find((item) => item.localeId === target.localeId)
        ?.nameId !== target.nameId)
  )
})
watch(
  () => props.modelValue,
  (open) => {
    if (open && props.target) {
      opened.value = { ...props.target }
      action.clearError()
    }
  },
  { immediate: true },
)

async function confirm(): Promise<void> {
  if (
    !props.modelValue ||
    !opened.value ||
    action.busy.value ||
    locked.value ||
    missing.value
  )
    return
  const target = opened.value
  const { deleteName } = createHilosI18nLanguageNameDelete(props.context)
  if (await action.run(deleteName(target))) {
    // A route change may have closed this confirmation while its reply was in flight.
    if (!disposed && opened.value === target) emit('update:modelValue', false)
  }
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="opened ? copy.title(opened) : ''"
    :close-on-backdrop="!action.busy.value"
    :close-on-esc="!action.busy.value"
    initial-focus="dialog"
  >
    <div v-if="opened" data-id="i18n-language-name-delete-window">
      <div data-id="i18n-language-name-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p>
        <span
          >{{ opened.inLanguageNativeName }} ({{ opened.inLanguageCode }})</span
        >
        <span v-if="opened.kind === 'override'">
          · {{ opened.localeCode }}</span
        >
      </p>
      <p>{{ opened.kind === 'base' ? copy.baseBody : copy.overrideBody }}</p>
      <div class="py-2 border-top border-bottom my-2 small text-break">
        <div>
          <span class="text-body-secondary me-1">{{ copy.current }}</span>
          <span v-if="opened.expectedName === ''" class="fw-semibold"
            >{{ copy.empty
            }}<span class="visually-hidden"
              >. {{ copy.emptyDescription }}</span
            ></span
          >
          <span v-else class="fw-semibold">{{ opened.expectedName }}</span>
        </div>
        <div v-if="opened.kind === 'base'">
          <span class="text-body-secondary me-1">{{ copy.fallback }}</span>
          <span class="fw-semibold">{{ opened.languageNativeName }}</span>
        </div>
        <div v-else>
          <span class="text-body-secondary me-1">{{ copy.inherited }}</span>
          <span v-if="source?.name === ''" class="fw-semibold"
            >{{ copy.empty
            }}<span class="visually-hidden"
              >. {{ copy.emptyDescription }}</span
            ></span
          >
          <span v-else class="fw-semibold">{{
            source?.name ?? copy.missing
          }}</span>
        </div>
      </div>
      <div
        class="alert small py-2 mb-0"
        :class="opened.kind === 'base' ? 'alert-warning' : 'alert-secondary'"
      >
        {{ opened.kind === 'base' ? copy.cascade : copy.overrideNote }}
      </div>
      <HilosEditNotice
        :kind="missing && !action.busy.value ? 'deleted' : null"
        :text="copy.missing"
        data-id="i18n-language-name-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="i18n-language-name-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || missing"
        data-id="i18n-language-name-delete-confirm"
        @click="confirm"
      >
        {{ opened?.kind === 'base' ? copy.delete : copy.remove }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
