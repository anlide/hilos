<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  createHilosI18nCountrySwitchOn,
  hilosI18nCountryLabel,
  hilosI18nCountrySwitchOn,
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

const copy = HILOS_I18N_COUNTRY_SWITCH_ON_COPY
const verdict = computed(() => hilosI18nCountrySwitchOn(props.card))
const action = useTrackedAction()
const { switchOn } = createHilosI18nCountrySwitchOn(props.context)
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
  () => open.value && !action.busy.value && props.card?.enabled === true,
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
  if (await action.run(switchOn(openedId.value))) {
    open.value = false
  }
}
</script>

<template>
  <button
    v-if="verdict.shown"
    type="button"
    class="btn btn-sm btn-outline-primary"
    data-id="country-card-switch-on"
    @click="openWindow"
  >
    {{ copy.open }}
  </button>

  <HilosModal
    v-model="modalOpen"
    :title="copy.title(openedLabel)"
    :close-on-backdrop="!action.busy.value"
    :close-on-esc="!action.busy.value"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <div data-id="country-card-switch-on-error">
      <HilosActionError :action="action" :details-title="copy.refusalTitle" />
    </div>
    <HilosEditNotice
      :kind="elsewhere ? 'updated' : null"
      :text="copy.elsewhere"
      data-id="country-card-switch-on-notice"
    />
    <p>{{ copy.body }}</p>
    <div
      v-if="verdict.note !== null"
      :class="[
        'alert small py-2 mb-0',
        verdict.defaultLocale === 'off' ? 'alert-warning' : 'alert-secondary',
      ]"
      data-id="country-card-switch-on-note"
    >
      {{ verdict.note }}
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="country-card-switch-on-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-primary"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere"
        data-id="country-card-switch-on-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
