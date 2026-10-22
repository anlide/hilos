<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_SWITCH_OFF_COPY,
  createHilosI18nCountrySwitchOff,
  hilosI18nCountryLabel,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from '@hilos/core/admin/i18n'
import { computed, ref, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  context: HilosI18nCountryContext
  card: HilosI18nCountryCard | null
}>()

const copy = HILOS_I18N_COUNTRY_SWITCH_OFF_COPY
const action = useTrackedAction()
const { switchOff } = createHilosI18nCountrySwitchOff(props.context)
const open = ref(false)
const openedId = ref<number | null>(null)
const openedLabel = ref('')
const modalOpen = computed({
  get: () => open.value,
  set: (next: boolean) => {
    if (next || !action.busy.value) {
      open.value = next
    }
  },
})
const elsewhere = computed(
  () => open.value && !action.busy.value && props.card?.enabled === false,
)

watch(
  () => props.card,
  (card) => {
    if (card === null || (open.value && card.countryId !== openedId.value)) {
      open.value = false
    }
  },
)

function openWindow(): void {
  if (props.card === null) {
    return
  }
  action.clearError()
  openedId.value = props.card.countryId
  openedLabel.value = hilosI18nCountryLabel(props.card)
  open.value = true
}

function closeWindow(): void {
  if (!action.busy.value) {
    open.value = false
  }
}

async function confirm(): Promise<void> {
  if (
    action.busy.value ||
    elsewhere.value ||
    props.card === null ||
    openedId.value === null
  ) {
    return
  }
  if (await action.run(switchOff(openedId.value))) {
    open.value = false
  }
}
</script>

<template>
  <button
    v-if="card?.enabled === true"
    type="button"
    class="btn btn-sm btn-outline-danger"
    data-id="country-card-switch-off"
    @click="openWindow"
  >
    {{ copy.open }}
  </button>

  <HilosModal
    v-model="modalOpen"
    :title="copy.title(openedLabel)"
    :busy="action.busy.value"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <div data-id="country-card-switch-off-error">
      <HilosActionError :action="action" :details-title="copy.refusalTitle" />
    </div>
    <p>{{ copy.body }}</p>
    <div
      v-if="card"
      class="py-2 border-top border-bottom my-2 small"
      data-id="country-card-switch-off-counts"
    >
      <span class="text-body-secondary">{{ copy.localeCount }}</span>
      {{ ' ' }}
      <span
        class="fw-semibold"
        data-id="country-card-switch-off-locale-count"
        >{{ card.summary.localeCount }}</span
      >
      <span class="text-body-secondary ms-2">{{ copy.nameCount }}</span>
      {{ ' ' }}
      <span class="fw-semibold" data-id="country-card-switch-off-name-count">{{
        card.summary.nameCount
      }}</span>
    </div>
    <div class="alert alert-secondary small py-2 mb-0">{{ copy.note }}</div>
    <HilosEditNotice
      :kind="elsewhere ? 'updated' : null"
      :text="copy.elsewhere"
      data-id="country-card-switch-off-notice"
    />
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="country-card-switch-off-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.busy.value"
        :disabled="elsewhere"
        data-id="country-card-switch-off-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
