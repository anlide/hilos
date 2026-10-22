<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  type HilosI18nCountryAdd,
} from '@hilos/core/admin/i18n'
import { computed, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'
import HilosI18nCountryFields from '../details/HilosI18nCountryFields.vue'

const props = defineProps<{ add: HilosI18nCountryAdd }>()
const copy = HILOS_I18N_COUNTRY_ADD_COPY
const step = useSignal(props.add.step)
const form = useSignal(props.add.form)
const kind = useSignal(props.add.kind)
const catalogSize = useSignal(props.add.catalogSize)
const submitAvailable = useSignal(props.add.submitAvailable)
const formAction = useTrackedAction()
const confirmAction = useTrackedAction()
const { locked } = useLookOnly()
const dirty = computed(
  () =>
    form.value.code !== '' ||
    form.value.currencySymbol !== '' ||
    form.value.currencyCode !== '',
)
const formOpen = computed({
  get: () => step.value === 'form',
  set: (next: boolean) => {
    if (!next) props.add.close()
  },
})
const confirmOpen = computed({
  get: () => step.value === 'confirm',
  set: (next: boolean) => {
    if (!next) props.add.close()
  },
})

watch(step, (next) => {
  if (next === 'form') formAction.clearError()
  if (next === 'confirm') confirmAction.clearError()
})

async function submit(): Promise<void> {
  if (locked.value) return
  await props.add.submit(formAction.run)
}

async function confirm(): Promise<void> {
  if (locked.value) return
  await props.add.confirm(confirmAction.run)
}
</script>

<template>
  <HilosModal
    v-model="formOpen"
    :title="copy.title"
    :confirm-on-close="dirty"
    initial-focus="inner"
    :busy="formAction.busy.value"
    @cancel="add.close"
  >
    <form id="i18n-country-add-form" @submit.prevent="submit">
      <HilosI18nCountryFields
        id-prefix="i18n-country-add"
        mode="add"
        :code="form.code"
        :currency-symbol="form.currencySymbol"
        :currency-code="form.currencyCode"
        @update:code="add.setCode"
        @update:currency-symbol="add.setCurrencySymbol"
        @update:currency-code="add.setCurrencyCode"
      />
    </form>
    <div class="mt-3" data-id="i18n-country-add-error">
      <HilosActionError
        :action="formAction"
        :details-title="copy.refusalTitle"
      />
    </div>
    <div
      v-if="kind"
      class="alert small py-2 mb-0 d-flex gap-2"
      :class="kind === 'known' ? 'alert-success' : 'alert-warning'"
      :data-kind="kind"
      data-id="i18n-country-add-plate"
    >
      <i
        class="bi flex-shrink-0 mt-1"
        :class="kind === 'known' ? 'bi-database-check' : 'bi-question-circle'"
        aria-hidden="true"
      ></i>
      <div>
        <strong>{{ kind === 'known' ? copy.knownLead : copy.ownLead }}</strong>
        {{ kind === 'known' ? copy.knownRest : copy.ownRest }}
      </div>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="formAction.busy.value"
        data-id="i18n-country-add-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-primary"
        type="submit"
        form="i18n-country-add-form"
        :loading="formAction.busy.value"
        :disabled="!submitAvailable"
        data-id="i18n-country-add-submit"
      >
        {{ copy.submit }}
      </LoadingButton>
    </template>
  </HilosModal>

  <HilosModal
    v-model="confirmOpen"
    :title="copy.confirmTitle"
    :busy="confirmAction.busy.value"
    initial-focus="dialog"
    @cancel="add.close"
  >
    <div
      class="d-flex align-items-center gap-3 mb-3"
      data-id="i18n-country-confirm"
    >
      <span
        class="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fs-4 px-3 py-2 text-uppercase"
        data-id="i18n-country-confirm-code"
      >
        {{ form.code }}
      </span>
      <div>
        <div class="fw-semibold">
          {{ form.currencySymbol }} {{ form.currencyCode }}
        </div>
        <div class="small text-body-secondary">{{ copy.entered }}</div>
      </div>
    </div>
    <p>{{ copy.catalogLine(form.code, catalogSize ?? 0) }}</p>
    <ul class="mb-2 ps-3">
      <li v-for="item in copy.consequences" :key="item">{{ item }}</li>
    </ul>
    <div data-id="i18n-country-confirm-error">
      <HilosActionError
        :action="confirmAction"
        :details-title="copy.refusalTitle"
      />
    </div>
    <div
      class="alert alert-secondary small py-2 mb-0"
      data-id="i18n-country-confirm-note"
    >
      {{ copy.confirmNote }}
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="confirmAction.busy.value"
        data-id="i18n-country-confirm-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-warning"
        :loading="confirmAction.busy.value"
        :disabled="!submitAvailable"
        data-id="i18n-country-confirm-submit"
        @click="confirm"
      >
        {{ copy.confirmSubmit }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
