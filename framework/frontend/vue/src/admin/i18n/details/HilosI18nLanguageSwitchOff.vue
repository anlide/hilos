<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  createHilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOff,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { computed, ref, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  context: HilosI18nLanguageContext
  card: HilosI18nLanguageCard | null
}>()

const copy = HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY
const verdict = computed(() => hilosI18nLanguageSwitchOff(props.card))
const action = useTrackedAction()
const { switchOff } = createHilosI18nLanguageSwitchOff(props.context)
const open = ref(false)
const openedName = ref('')
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
    if (card === null) {
      open.value = false
    }
  },
)

function openWindow(): void {
  if (props.card === null) {
    return
  }
  action.clearError()
  openedName.value = props.card.nativeName
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
    verdict.value.disabled
  ) {
    return
  }
  if (await action.run(switchOff(props.card.code))) {
    open.value = false
  }
}
</script>

<template>
  <template v-if="verdict.shown">
    <button
      type="button"
      class="btn btn-sm btn-outline-danger"
      :disabled="verdict.disabled"
      :aria-describedby="
        verdict.reason ? 'language-card-switch-off-reason' : undefined
      "
      data-id="language-card-switch-off"
      @click="openWindow"
    >
      {{ copy.open }}
    </button>
    <p
      v-if="verdict.reason"
      id="language-card-switch-off-reason"
      class="small text-body-secondary mt-1 mb-0"
      data-id="language-card-switch-off-reason"
    >
      {{ verdict.reason }}
    </p>
  </template>

  <HilosModal
    v-model="modalOpen"
    :title="copy.title(openedName)"
    :close-on-backdrop="!action.busy.value"
    :close-on-esc="!action.busy.value"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <div data-id="language-card-switch-off-error">
      <HilosActionError :action="action" :details-title="copy.refusalTitle" />
    </div>
    <p>{{ copy.body }}</p>
    <div class="alert alert-secondary small py-2 mb-0">{{ copy.note }}</div>
    <HilosEditNotice
      :kind="elsewhere ? 'updated' : null"
      :text="copy.elsewhere"
      data-id="language-card-switch-off-notice"
    />
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="language-card-switch-off-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere || verdict.disabled"
        data-id="language-card-switch-off-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
