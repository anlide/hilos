<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_MAX,
  type HilosI18nCountryNameWindow,
} from '@hilos/core/admin/i18n'
import { computed, onMounted, onUnmounted, shallowRef, watch } from 'vue'

import ConflictActions from '../../../ConflictActions.vue'
import ConflictHeader from '../../../ConflictHeader.vue'
import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useLookOnly } from '../../../hilosAdminViewMode.js'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{ window: HilosI18nCountryNameWindow }>()
const copy = HILOS_I18N_COUNTRY_NAME_COPY
const opened = useSignal(props.window.opened)
const target = useSignal(props.window.target)
const form = useSignal(props.window.form)
const state = useSignal(props.window.state)
const dirty = useSignal(props.window.dirty)
const noticeKind = useSignal(props.window.noticeKind)
const noticeText = useSignal(props.window.noticeText)
const saving = useSignal(props.window.saving)
const canSave = useSignal(props.window.canSave)
const saveAvailable = useSignal(props.window.saveAvailable)
const saveLabel = useSignal(props.window.saveLabel)
const { locked } = useLookOnly()
// Each opening has its own runner: a previous address's late failure cannot
// paint an error or unlock the action of the next opening.
const action = shallowRef(useTrackedAction())
watch(
  target,
  () => {
    action.value = useTrackedAction()
  },
  { flush: 'sync' },
)
const title = computed(() => {
  const held = target.value
  if (!held) return ''
  const key =
    held.localeCode === null
      ? held.mode
      : held.mode === 'add'
        ? 'addCorrection'
        : 'editCorrection'
  return `${copy.title[key]} · ${held.countryName}`
})
const hint = computed(() =>
  target.value?.localeCode === null ? copy.hints.base : copy.hints.correction,
)
const modalOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) props.window.close()
  },
})
onMounted(() => props.window.start())
onUnmounted(() => props.window.dispose())

function changeName(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    props.window.patchForm({ name: event.target.value })
}
async function submit(): Promise<void> {
  if (locked.value || !canSave.value) return
  await props.window.save(action.value.run)
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="title"
    :confirm-on-close="dirty && !saving"
    :busy="saving"
  >
    <template #header>
      <div data-id="i18n-country-name-title">
        <ConflictHeader :title="title" :conflict="state.conflict" />
      </div>
    </template>
    <div data-id="i18n-country-name-window">
      <dl class="row small mb-3">
        <dt class="col-sm-4">{{ copy.labels.subject }}</dt>
        <dd class="col-sm-8">
          {{ target?.countryName }} ({{ target?.countryCode.toUpperCase() }})
        </dd>
        <dt class="col-sm-4">{{ copy.labels.inLanguage }}</dt>
        <dd class="col-sm-8">
          {{ target?.inLanguageNativeName }} ({{ target?.inLanguageCode }})
        </dd>
        <template v-if="target?.localeCode !== null">
          <dt class="col-sm-4">{{ copy.labels.locale }}</dt>
          <dd class="col-sm-8">{{ target?.localeCode }}</dd>
        </template>
      </dl>
      <form @submit.prevent="submit">
        <label class="form-label" for="i18n-country-name-input">{{
          copy.labels.name
        }}</label>
        <input
          id="i18n-country-name-input"
          class="form-control"
          :value="form.name"
          :maxlength="HILOS_I18N_COUNTRY_NAME_MAX"
          :disabled="saving"
          aria-describedby="i18n-country-name-hint"
          data-id="i18n-country-name-input"
          data-autofocus
          @input="changeName"
        />
        <div id="i18n-country-name-hint" class="form-text">{{ hint }}</div>
      </form>
      <p v-if="target?.localeCode === null" class="small mt-3 mb-0">
        {{ copy.hints.catalog }}
      </p>
      <div data-id="i18n-country-name-error">
        <HilosActionError :action="action" :details-title="copy.refusalTitle" />
      </div>
      <HilosEditNotice
        :kind="noticeKind"
        :text="noticeText"
        data-id="i18n-country-name-notice"
      />
    </div>
    <template #actions="{ requestClose }">
      <ConflictActions
        :conflict="state.conflict"
        :disable-save="!saveAvailable"
        :save-label="saveLabel"
        @save="submit"
        @accept-mine="window.keepMine"
        @accept-theirs="window.takeTheirs"
      >
        <template #cancel-button>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="saving"
            data-id="i18n-country-name-cancel"
            @click="requestClose"
          >
            {{ copy.cancel }}
          </button>
        </template>
        <template #save-button="{ disabled, onSave }">
          <LoadingButton
            class="btn-primary"
            :loading="action.busy.value"
            :disabled="disabled"
            data-id="i18n-country-name-submit"
            @click="onSave"
            >{{ saveLabel }}</LoadingButton
          >
        </template>
      </ConflictActions>
    </template>
  </HilosModal>
</template>
