<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_ADD_COPY,
  createHilosI18nBuiltInLanguages,
  type HilosI18nLanguageAdd,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { computed, watch } from 'vue'

import HilosActionError from '../../../HilosActionError.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  add: HilosI18nLanguageAdd
  context: HilosI18nLanguageContext
}>()
const copy = HILOS_I18N_LANGUAGE_ADD_COPY
const step = useSignal(props.add.step)
const form = useSignal(props.add.form)
const known = useSignal(props.add.known)
const complete = useSignal(props.add.complete)
const submitAvailable = useSignal(props.add.submitAvailable)
const catalog = useSignal(createHilosI18nBuiltInLanguages(props.context))
const formAction = useTrackedAction()
const confirmAction = useTrackedAction()
const { locked } = useLookOnly()
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

function changeCode(event: Event): void {
  if (event.target instanceof HTMLInputElement) {
    props.add.setCode(event.target.value)
  }
}

function changeNativeName(event: Event): void {
  if (event.target instanceof HTMLInputElement) {
    props.add.setNativeName(event.target.value)
  }
}

function changeRtl(event: Event): void {
  if (event.target instanceof HTMLInputElement) {
    props.add.setRtl(event.target.checked)
  }
}

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
    :busy="formAction.busy.value"
    @cancel="add.close"
  >
    <form id="i18n-language-add-form" @submit.prevent="submit">
      <div class="row g-2">
        <div class="col-12 col-sm-4">
          <label
            class="form-label small fw-semibold"
            for="i18n-language-add-code"
          >
            {{ copy.codeLabel }}
          </label>
          <input
            id="i18n-language-add-code"
            class="form-control form-control-sm"
            :value="form.code"
            :placeholder="copy.codePlaceholder"
            :disabled="formAction.busy.value"
            maxlength="2"
            aria-describedby="i18n-language-add-code-hint"
            data-id="i18n-language-add-code"
            data-autofocus
            @input="changeCode"
          />
          <div id="i18n-language-add-code-hint" class="form-text">
            {{ copy.codeHint }}
          </div>
        </div>
        <div class="col-12 col-sm-8">
          <label
            class="form-label small fw-semibold"
            for="i18n-language-add-native-name"
          >
            {{ copy.nativeNameLabel }}
          </label>
          <input
            id="i18n-language-add-native-name"
            class="form-control form-control-sm"
            :value="form.nativeName"
            :placeholder="copy.nativeNamePlaceholder"
            :disabled="formAction.busy.value"
            maxlength="64"
            aria-describedby="i18n-language-add-native-name-hint"
            data-id="i18n-language-add-native-name"
            @input="changeNativeName"
          />
          <div id="i18n-language-add-native-name-hint" class="form-text">
            {{ known ? copy.nativeNameKnownHint : copy.nativeNameOwnHint }}
          </div>
        </div>
      </div>
      <div class="form-check mt-2">
        <input
          id="i18n-language-add-rtl"
          class="form-check-input"
          type="checkbox"
          :checked="form.rtl"
          :disabled="formAction.busy.value"
          data-id="i18n-language-add-rtl"
          @change="changeRtl"
        />
        <label class="form-check-label small" for="i18n-language-add-rtl">
          {{ copy.rtlLabel }}
        </label>
      </div>
    </form>
    <div class="mt-3" data-id="i18n-language-add-error">
      <HilosActionError
        :action="formAction"
        :details-title="copy.refusalTitle"
      />
    </div>
    <div
      v-if="complete"
      class="alert small py-2 mb-0 d-flex gap-2"
      :class="known ? 'alert-success' : 'alert-warning'"
      :data-kind="known ? 'known' : 'own'"
      data-id="i18n-language-add-plate"
    >
      <i
        class="bi flex-shrink-0 mt-1"
        :class="known ? 'bi-database-check' : 'bi-question-circle'"
        aria-hidden="true"
      ></i>
      <div>
        <strong>{{ known ? copy.knownLead : copy.ownLead }}</strong>
        {{ known ? copy.knownRest : copy.ownRest }}
      </div>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="formAction.busy.value"
        data-id="i18n-language-add-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-primary"
        type="submit"
        form="i18n-language-add-form"
        :loading="formAction.busy.value"
        :disabled="!submitAvailable"
        data-id="i18n-language-add-submit"
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
    <div data-id="i18n-language-confirm">
      <div class="d-flex align-items-center gap-3 mb-3">
        <span
          class="rounded-3 bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
          data-id="i18n-language-confirm-code"
          >{{ form.code }}</span
        >
        <div>
          <div class="fw-semibold">{{ form.nativeName.trim() }}</div>
          <div class="small text-body-secondary">
            {{ copy.direction(form.rtl) }}
          </div>
        </div>
      </div>
      <p>{{ copy.catalogLine(form.code, catalog?.length ?? 0) }}</p>
      <ul class="mb-2 ps-3">
        <li v-for="consequence in copy.consequences" :key="consequence">
          {{ consequence }}
        </li>
      </ul>
      <div data-id="i18n-language-confirm-error">
        <HilosActionError
          :action="confirmAction"
          :details-title="copy.refusalTitle"
        />
      </div>
      <div class="alert alert-secondary small py-2 mb-0">
        {{ copy.confirmNote }}
      </div>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="confirmAction.busy.value"
        data-id="i18n-language-confirm-cancel"
        @click="requestClose"
      >
        {{ copy.cancel }}
      </button>
      <LoadingButton
        class="btn-warning"
        :loading="confirmAction.busy.value"
        :disabled="!submitAvailable"
        data-id="i18n-language-confirm-submit"
        @click="confirm"
      >
        {{ copy.confirmSubmit }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
