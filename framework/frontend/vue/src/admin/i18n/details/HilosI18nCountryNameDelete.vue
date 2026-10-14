<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  createHilosI18nCountryNameDelete,
  type HilosI18nCountryContext,
  type HilosI18nCountryNameDeleteTarget,
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
  context: HilosI18nCountryContext
  target: HilosI18nCountryNameDeleteTarget | null
  source: HilosI18nNameRow | null
}>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean] }>()
const copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY
const action = useTrackedAction()
const { locked } = useLookOnly()
// The sibling edit window may take focus only after this write has settled.
defineExpose({ busy: action.busy })
let disposed = false
onUnmounted(() => {
  disposed = true
})
const opened = shallowRef<HilosI18nCountryNameDeleteTarget | null>(null)
const modalOpen = computed({
  get: () => props.modelValue,
  set: (next: boolean) => {
    if (!action.busy.value) emit('update:modelValue', next)
  },
})
const missing = computed(() => {
  const target = opened.value
  const row = props.source
  if (!target || row?.code !== target.inLanguageCode || row.name === null)
    return true
  return (
    target.kind === 'override' &&
    row.corrections.find((item) => item.localeCode === target.localeCode)
      ?.name == null
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
  const { deleteName } = createHilosI18nCountryNameDelete(props.context)
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
    <div v-if="opened" data-id="i18n-country-name-delete-window">
      <div data-id="i18n-country-name-delete-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <p>
        <span
          >{{ opened.countryName }} ({{ opened.countryCode.toUpperCase() }}) ·
        </span>
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
        <div v-if="opened.kind === 'override'">
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
        <template v-if="opened.kind === 'base'">
          <p>{{ copy.cascade }}</p>
          <p class="mb-0">{{ copy.catalog }}</p>
        </template>
        <template v-else>{{ copy.overrideNote }}</template>
      </div>
      <HilosEditNotice
        :kind="missing && !action.busy.value ? 'deleted' : null"
        :text="copy.missing"
        data-id="i18n-country-name-delete-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="i18n-country-name-delete-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || missing"
        data-id="i18n-country-name-delete-confirm"
        @click="confirm"
      >
        {{ opened?.kind === 'base' ? copy.delete : copy.remove }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
