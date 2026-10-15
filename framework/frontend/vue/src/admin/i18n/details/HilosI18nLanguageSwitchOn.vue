<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  createHilosI18nLanguageSwitchOn,
  hilosI18nLanguageSwitchOn,
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

const copy = HILOS_I18N_LANGUAGE_SWITCH_ON_COPY
const verdict = computed(() => hilosI18nLanguageSwitchOn(props.card))
const action = useTrackedAction()
const { switchOn } = createHilosI18nLanguageSwitchOn(props.context)
const open = ref(false)
const openedId = ref<number | null>(null)
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
  () => open.value && !action.busy.value && props.card?.enabled === true,
)

watch(
  () => props.card,
  (card) => {
    if (card === null || (open.value && card.languageId !== openedId.value)) {
      open.value = false
    }
  },
)

function openWindow(): void {
  if (props.card === null) {
    return
  }
  action.clearError()
  openedId.value = props.card.languageId
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
    data-id="language-card-switch-on"
    @click="openWindow"
  >
    {{ copy.open }}
  </button>

  <HilosModal
    v-model="modalOpen"
    :title="copy.title(openedName)"
    :close-on-backdrop="!action.busy.value"
    :close-on-esc="!action.busy.value"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <div data-id="language-card-switch-on-error">
      <HilosActionError :action="action" :details-title="copy.refusalTitle" />
    </div>
    <HilosEditNotice
      :kind="elsewhere ? 'updated' : null"
      :text="copy.elsewhere"
      data-id="language-card-switch-on-notice"
    />
    <p>{{ copy.body }}</p>
    <div
      class="d-flex flex-wrap gap-3 py-2 border-top border-bottom my-2 small"
    >
      <div>
        <span class="text-body-secondary">{{ copy.localeLabel }}</span>
        <span
          v-if="verdict.locale !== 'on'"
          class="badge text-bg-warning-subtle text-warning-emphasis"
          data-id="language-card-switch-on-locale"
        >
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
          {{ copy.locale[verdict.locale] }}
        </span>
        <span v-else data-id="language-card-switch-on-locale">
          {{ copy.locale.on }}
        </span>
      </div>
      <div>
        <span class="text-body-secondary">{{ copy.namesLabel }}</span>
        <span class="fw-semibold" data-id="language-card-switch-on-names">
          {{ verdict.nameCount }}
        </span>
      </div>
    </div>
    <div
      v-if="verdict.warning !== null"
      class="alert alert-warning small py-2 mb-0"
      data-id="language-card-switch-on-warning"
    >
      {{ verdict.warning }}
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="action.busy.value"
        data-id="language-card-switch-on-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-primary"
        :loading="action.loading.value"
        :disabled="action.busy.value || elsewhere"
        data-id="language-card-switch-on-confirm"
        @click="confirm"
      >
        {{ copy.confirm }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
