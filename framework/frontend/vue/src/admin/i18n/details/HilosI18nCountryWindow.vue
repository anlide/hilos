<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  type HilosI18nCountryWindow,
} from '@hilos/core/admin/i18n'
import { computed, watch } from 'vue'
import ConflictActions from '../../../ConflictActions.vue'
import ConflictHeader from '../../../ConflictHeader.vue'
import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'
import HilosI18nCountryFields from './HilosI18nCountryFields.vue'

const props = defineProps<{ window: HilosI18nCountryWindow }>()
const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
const mode = useSignal(props.window.mode)
const title = useSignal(props.window.title)
const opened = useSignal(props.window.opened)
const row = useSignal(props.window.row)
const form = useSignal(props.window.form)
const state = useSignal(props.window.state)
const noticeKind = useSignal(props.window.noticeKind)
const noticeText = useSignal(props.window.noticeText)
const plate = useSignal(props.window.plate)
const options = useSignal(props.window.options)
const localeHint = useSignal(props.window.localeHint)
const touched = useSignal(props.window.touched)
const canSave = useSignal(props.window.canSave)
const saveLabel = useSignal(props.window.saveLabel)
const saving = useSignal(props.window.saving)
const action = useTrackedAction()
const { locked } = useLookOnly()
const busy = computed(() => saving.value || action.busy.value)
const modalOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) props.window.close()
  },
})
watch(opened, (next) => {
  if (next) action.clearError()
})

async function submit(): Promise<void> {
  if (locked.value || busy.value) return
  await props.window.save(action.run)
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="title"
    :confirm-on-close="mode === 'edit' && touched"
    :close-on-backdrop="!busy"
    :close-on-esc="!busy"
    initial-focus="inner"
    @cancel="window.close"
  >
    <template #header>
      <div data-id="i18n-country-title">
        <ConflictHeader
          v-if="mode === 'edit'"
          :title="title"
          :conflict="state.conflict"
        />
        <h5 v-else class="modal-title mb-0">{{ title }}</h5>
      </div>
    </template>
    <div data-id="i18n-country-window">
      <form id="i18n-country-edit-form" @submit.prevent="submit">
        <HilosI18nCountryFields
          id-prefix="i18n-country-edit"
          :mode="mode"
          :code="row?.code ?? ''"
          :currency-symbol="form.currencySymbol"
          :currency-code="form.currencyCode"
          :default-locale-id="form.defaultLocaleId"
          :locale-options="options"
          :locale-hint="localeHint"
          described-by="i18n-country-plate"
          @update:currency-symbol="window.setSymbol"
          @update:currency-code="window.setCurrencyCode"
          @update:default-locale-id="window.setDefaultLocale"
        />
      </form>
      <div class="mt-3">
        <HilosEditNotice
          :kind="noticeKind"
          :text="noticeText"
          data-id="i18n-country-notice"
        />
      </div>
      <div v-if="mode === 'edit'" data-id="i18n-country-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <div class="hilos-stack">
        <!-- The switch can change while editing; its longer explanation
        holds its room before it arrives, in either theme and at any width. -->
        <div
          v-if="mode === 'edit'"
          class="hilos-stack invisible"
          aria-hidden="true"
          inert
        >
          <div
            v-for="variant in copy.plates"
            :key="variant.rest"
            class="alert small py-2 mb-0 d-flex gap-2 alert-info"
          >
            <i class="bi bi-pencil flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
              <strong>{{ variant.lead }}</strong> {{ variant.rest }}
            </div>
          </div>
        </div>
        <div
          id="i18n-country-plate"
          class="alert small py-2 mb-0 d-flex gap-2"
          :class="plate.tone"
          data-id="i18n-country-plate"
        >
          <i
            class="bi flex-shrink-0 mt-1"
            :class="plate.icon"
            aria-hidden="true"
          ></i>
          <div>
            <strong>{{ plate.lead }}</strong> {{ plate.rest }}
          </div>
        </div>
      </div>
    </div>
    <template #actions="{ requestClose }">
      <ConflictActions
        v-if="mode === 'edit'"
        :conflict="state.conflict"
        :disable-save="!canSave || busy"
        :save-label="saveLabel"
        @save="submit"
        @accept-mine="window.keepMine"
        @accept-theirs="window.takeTheirs"
      >
        <template #cancel-button>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="busy"
            data-id="i18n-country-cancel"
            @click="requestClose"
          >
            {{ copy.cancel }}
          </button>
        </template>
        <template #save-button="{ disabled, onSave }">
          <LoadingButton
            class="btn-primary"
            :loading="action.loading.value"
            :disabled="disabled"
            data-id="i18n-country-save"
            @click="onSave"
            >{{ saveLabel }}</LoadingButton
          >
        </template>
      </ConflictActions>
      <button
        v-else
        type="button"
        class="btn btn-secondary"
        data-id="i18n-country-close"
        @click="requestClose"
      >
        {{ copy.close }}
      </button>
    </template>
  </HilosModal>
</template>
