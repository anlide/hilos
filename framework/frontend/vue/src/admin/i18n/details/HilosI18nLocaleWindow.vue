<script setup lang="ts">
import {
  HILOS_I18N_LOCALE_COPY,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleSwitchOn,
  hilosI18nLocaleCountryLabel,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleFormats,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleTemplates,
  type HilosI18nLocaleWindowMode,
  type TableViewportController,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import ConflictActions from '../../../ConflictActions.vue'
import ConflictHeader from '../../../ConflictHeader.vue'
import HilosActionError from '../../../HilosActionError.vue'
import HilosEditNotice from '../../../HilosEditNotice.vue'
import HilosModal from '../../../HilosModal.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { useSignal } from '../../../useSignal.js'
import { useTrackedAction } from '../../../useTrackedAction.js'

const props = defineProps<{
  context: HilosI18nLanguageContext
  controller: TableViewportController<HilosI18nLocaleRow>
  card: HilosI18nLanguageCard | null
  templates: HilosI18nLocaleTemplates | null
  opened: { rowKey: string; mode: HilosI18nLocaleWindowMode } | null
}>()
const emit = defineEmits<{ close: [] }>()
const copy = HILOS_I18N_LOCALE_COPY
const fields = [
  'date',
  'time',
  'number',
  'phone',
  'address',
  'measurement',
  'collation',
] as const
const address = { get: () => props.card?.code ?? '' }
const editor = createHilosI18nLocaleEdit(
  props.controller,
  props.context,
  address,
)
const creator = createHilosI18nLocaleAdd(
  props.controller,
  props.context,
  address,
)
const switcher = createHilosI18nLocaleSwitchOn(props.context, address)
const editOpened = useSignal(editor.opened)
const editRow = useSignal(editor.row)
const editForm = useSignal(editor.form)
const editState = useSignal(editor.state)
const editNotice = useSignal(editor.noticeText)
const editCanSave = useSignal(editor.canSave)
const editSaveLabel = useSignal(editor.saveLabel)
const addRow = useSignal(creator.row)
const addForm = useSignal(creator.form)
const addCanSave = useSignal(creator.canAdd)
const addElsewhere = useSignal(creator.elsewhere)
const addGone = useSignal(creator.gone)
const focusedRow = useSignal(props.controller.focusedRow)
const addAction = useTrackedAction()
const editAction = useTrackedAction()
const switchAction = useTrackedAction()
const step = ref<'form' | 'switchOn'>('form')
const unsavedAtStep = ref(false)
const openedName = ref('')
const viewSnapshot = ref<HilosI18nLocaleRow | null>(null)
const mode = computed(() => props.opened?.mode ?? null)
const row = computed(() =>
  mode.value === 'add'
    ? addRow.value
    : mode.value === 'edit'
      ? editRow.value
      : viewSnapshot.value,
)
const liveRow = computed(() =>
  focusedRow.value?.rowKey === row.value?.rowKey ? focusedRow.value : undefined,
)
const shownRow = computed(() =>
  mode.value === 'view' ? (liveRow.value ?? viewSnapshot.value) : row.value,
)
const country = computed(() =>
  row.value ? hilosI18nLocaleCountryLabel(row.value) : null,
)
const formTitle = computed(() =>
  copy.title(mode.value ?? 'view', openedName.value, country.value),
)
const switchOnTitle = computed(() =>
  copy.switchOn.title(openedName.value, country.value),
)
const title = computed(() =>
  step.value === 'switchOn' ? switchOnTitle.value : formTitle.value,
)
const form = computed(() =>
  mode.value === 'add'
    ? addForm.value
    : mode.value === 'edit'
      ? editForm.value
      : (shownRow.value?.formats ??
        viewSnapshot.value?.formats ??
        addForm.value),
)
const busy = computed(
  () =>
    addAction.busy.value || editAction.busy.value || switchAction.busy.value,
)
const lockedOn = computed(
  () => mode.value === 'edit' && !busy.value && liveRow.value?.enabled === true,
)
const switchedOff = computed(
  () =>
    mode.value === 'view' && !busy.value && liveRow.value?.enabled === false,
)
const noticeKind = computed(() => {
  if (mode.value === 'add')
    return addGone.value ? 'deleted' : addElsewhere.value ? 'updated' : null
  if (mode.value === 'edit')
    return editState.value.gone
      ? 'deleted'
      : lockedOn.value
        ? 'updated'
        : (editState.value.notice?.kind ?? null)
  return liveRow.value === undefined
    ? 'deleted'
    : switchedOff.value
      ? 'updated'
      : null
})
const noticeText = computed(() => {
  if (mode.value === 'add')
    return addGone.value
      ? 'Deleted elsewhere — your text stays to copy.'
      : addElsewhere.value
        ? copy.elsewhere.added
        : ''
  if (mode.value === 'edit')
    return editState.value.gone
      ? editNotice.value
      : lockedOn.value
        ? copy.elsewhere.switchedOn
        : editNotice.value
  return liveRow.value === undefined
    ? 'Deleted elsewhere — your text stays to copy.'
    : switchedOff.value
      ? copy.elsewhere.switchedOff
      : ''
})
const plate = computed(() => {
  if (mode.value === 'add')
    return row.value?.catalogFormats ? copy.plate.addKnown : copy.plate.addOwn
  if (mode.value === 'view' || lockedOn.value) return copy.plate.view
  return row.value?.catalogFormats ? copy.plate.editKnown : copy.plate.editOwn
})
const activeAction = computed(() =>
  step.value === 'switchOn'
    ? switchAction
    : mode.value === 'add'
      ? addAction
      : editAction,
)
const modalOpen = computed({
  get: () => props.opened !== null,
  set: (next: boolean) => {
    if (!next) closeWindow()
  },
})

onMounted(() => editor.start())
onUnmounted(() => {
  editor.dispose()
  creator.dispose()
  props.controller.releaseFocus()
})
watch(
  () => props.opened,
  (opening) => {
    step.value = 'form'
    unsavedAtStep.value = false
    if (opening === null) {
      editor.close()
      creator.close()
      props.controller.releaseFocus()
      return
    }
    openedName.value = props.card?.nativeName ?? ''
    addAction.clearError()
    editAction.clearError()
    switchAction.clearError()
    if (opening.mode === 'add' && !creator.open(opening.rowKey)) emit('close')
    if (opening.mode === 'edit' && !editor.open(opening.rowKey)) emit('close')
    if (opening.mode === 'view') {
      viewSnapshot.value = props.controller.focusRow(opening.rowKey)
      if (viewSnapshot.value === null) emit('close')
    }
  },
  { immediate: true },
)

function closeWindow(): void {
  if (busy.value) return
  step.value = 'form'
  unsavedAtStep.value = false
  editor.close()
  creator.close()
  props.controller.releaseFocus()
  emit('close')
}

function openSwitchOn(): void {
  if (busy.value || lockedOn.value || editState.value.gone || !row.value) return
  unsavedAtStep.value = editState.value.dirty || editState.value.conflict
  switchAction.clearError()
  step.value = 'switchOn'
}

async function confirm(): Promise<void> {
  if (
    busy.value ||
    unsavedAtStep.value ||
    lockedOn.value ||
    editState.value.gone ||
    !row.value
  )
    return
  if (await switchAction.run(switcher.switchOn(row.value.countryCode))) {
    closeWindow()
  }
}

function choiceLabel(field: (typeof fields)[number], value: string): string {
  return field === 'measurement' && (value === 'metric' || value === 'imperial')
    ? copy.measurement[value]
    : value
}

function changeField(field: (typeof fields)[number], event: Event): void {
  const target = event.target
  if (!(target instanceof HTMLSelectElement)) return
  if (mode.value === 'add') creator.setField(field, target.value)
  if (mode.value === 'edit')
    editor.patchForm({
      [field]: target.value,
    } as Partial<HilosI18nLocaleFormats>)
}

async function submit(): Promise<void> {
  if (mode.value === 'add') {
    await creator.add(addAction.run)
    if (!creator.opened.get()) emit('close')
  } else if (mode.value === 'edit') {
    await editor.save(editAction.run)
    if (!editOpened.value) emit('close')
  }
}
</script>

<template>
  <HilosModal
    v-model="modalOpen"
    :title="title"
    :confirm-on-close="mode === 'edit' ? editState.dirty : false"
    :close-on-backdrop="!busy"
    :close-on-esc="!busy"
    initial-focus="dialog"
    @cancel="closeWindow"
  >
    <template #header>
      <!-- Each part holds its tallest ordinary step: a body twin alone cannot
      keep a wrapped heading or the footer's conflict row from moving Save. -->
      <div :class="mode === 'edit' ? 'hilos-stack flex-grow-1' : undefined">
        <div
          v-if="mode === 'edit'"
          class="hilos-stack invisible"
          aria-hidden="true"
          inert
        >
          <h5 class="modal-title d-inline-flex align-items-center gap-2 mb-0">
            <span>{{ formTitle }}</span>
            <span class="badge text-bg-danger">conflict</span>
          </h5>
          <h5 class="modal-title mb-0">{{ switchOnTitle }}</h5>
        </div>
        <div data-id="i18n-locale-title">
          <ConflictHeader
            v-if="mode === 'edit' && step === 'form'"
            :title="title"
            :conflict="editState.conflict"
          />
          <h5 v-else class="modal-title mb-0">{{ title }}</h5>
        </div>
      </div>
    </template>
    <div data-id="i18n-locale-window">
      <div class="d-flex flex-wrap gap-3 mb-2 small">
        <div>
          <span class="text-body-secondary">{{ copy.countryLabel }}</span>
          <span class="fw-semibold">{{ country ?? copy.noCountry }}</span>
        </div>
        <div>
          <span class="text-body-secondary">{{ copy.codeLabel }}</span>
          <code data-id="i18n-locale-code">{{ row?.rowKey }}</code>
        </div>
      </div>
      <div :class="mode === 'edit' ? 'hilos-stack' : undefined">
        <div
          v-if="mode === 'edit'"
          class="hilos-stack invisible"
          aria-hidden="true"
          inert
        >
          <p class="small text-body-secondary">{{ copy.lead }}</p>
          <p class="small text-body-secondary">{{ copy.switchOn.body }}</p>
        </div>
        <p
          v-if="step === 'switchOn'"
          class="small text-body-secondary"
          data-id="i18n-locale-switch-on-body"
        >
          {{ copy.switchOn.body }}
        </p>
        <p v-else class="small text-body-secondary">{{ copy.lead }}</p>
      </div>
      <div data-id="i18n-locale-error">
        <HilosActionError
          :action="activeAction"
          :details-title="
            step === 'switchOn'
              ? copy.switchOn.refusalTitle
              : mode === 'add'
                ? copy.refusalTitle.add
                : copy.refusalTitle.edit
          "
        />
      </div>
      <HilosEditNotice
        :kind="noticeKind"
        :text="noticeText"
        data-id="i18n-locale-notice"
      />
      <div :class="mode === 'edit' ? 'hilos-stack' : undefined">
        <div
          v-if="mode === 'edit'"
          class="hilos-stack invisible"
          aria-hidden="true"
          inert
        >
          <div>
            <div class="row g-2">
              <div
                v-for="field in fields"
                :key="field"
                :class="field === 'address' ? 'col-12' : 'col-6'"
              >
                <label class="form-label small fw-semibold">{{
                  copy.labels[field]
                }}</label>
                <div class="form-control form-control-sm">&nbsp;</div>
              </div>
            </div>
            <div
              :class="[
                'alert small py-2 mt-3 mb-0',
                lockedOn ? 'alert-warning' : 'alert-info',
              ]"
            >
              <i
                :class="lockedOn ? 'bi bi-lock me-1' : 'bi bi-pencil me-1'"
                aria-hidden="true"
              ></i>
              {{ plate }}
            </div>
          </div>
          <div>
            <div
              v-if="row?.catalogFormats !== null"
              class="alert alert-secondary small py-2 mb-0"
            >
              {{ copy.switchOn.catalogNote }}
            </div>
            <div class="alert alert-warning small py-2 mb-0">
              {{ copy.switchOn.unsaved }}
            </div>
          </div>
        </div>
        <div v-if="step === 'switchOn'">
          <div
            v-if="row?.catalogFormats !== null"
            class="alert alert-secondary small py-2 mb-0"
            data-id="i18n-locale-switch-on-note"
          >
            {{ copy.switchOn.catalogNote }}
          </div>
          <div
            v-if="unsavedAtStep"
            class="alert alert-warning small py-2 mb-0"
            data-id="i18n-locale-switch-on-unsaved"
          >
            {{ copy.switchOn.unsaved }}
          </div>
        </div>
        <div v-else>
          <form @submit.prevent="submit">
            <div class="row g-2">
              <div
                v-for="field in fields"
                :key="field"
                :class="field === 'address' ? 'col-12' : 'col-6'"
              >
                <label
                  class="form-label small fw-semibold"
                  :for="`i18n-locale-${field}`"
                  >{{ copy.labels[field] }}</label
                >
                <select
                  :id="`i18n-locale-${field}`"
                  class="form-select form-select-sm"
                  :value="form[field]"
                  :disabled="
                    mode === 'view' ||
                    lockedOn ||
                    (editState.gone && mode === 'edit') ||
                    (addGone && mode === 'add') ||
                    busy
                  "
                  :data-id="`i18n-locale-field-${field}`"
                  @change="changeField(field, $event)"
                >
                  <option v-if="form[field] === ''" value="" disabled>
                    {{ copy.choose }}
                  </option>
                  <option
                    v-for="value in templates?.[field] ?? []"
                    :key="value"
                    :value="value"
                  >
                    {{ choiceLabel(field, value) }}
                  </option>
                </select>
              </div>
            </div>
          </form>
          <div
            :class="[
              'alert small py-2 mt-3 mb-0',
              mode === 'view' || lockedOn
                ? 'alert-warning'
                : mode === 'edit'
                  ? 'alert-info'
                  : 'alert-secondary',
            ]"
            data-id="i18n-locale-plate"
          >
            <i
              v-if="mode === 'view' || lockedOn"
              class="bi bi-lock me-1"
              aria-hidden="true"
            ></i>
            <i
              v-else-if="mode === 'edit'"
              class="bi bi-pencil me-1"
              aria-hidden="true"
            ></i>
            {{ plate }}
          </div>
        </div>
      </div>
    </div>
    <template #actions="{ requestClose }">
      <div v-if="mode === 'edit'" class="hilos-stack w-100 m-0">
        <div class="hilos-stack invisible" aria-hidden="true" inert>
          <div
            class="modal-footer hilos-button-row p-0 m-0 border-0 align-self-end"
          >
            <div
              class="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap"
            >
              <div class="hilos-conflict-choices d-flex gap-2">
                <span class="btn btn-outline-secondary">Keep mine</span>
                <span class="btn btn-outline-secondary">Take theirs</span>
              </div>
              <span class="btn btn-secondary">{{ copy.cancel }}</span>
              <span class="btn btn-outline-primary">{{
                copy.switchOn.open
              }}</span>
              <span class="btn btn-primary">{{ editSaveLabel }}</span>
            </div>
          </div>
          <div
            class="modal-footer hilos-button-row p-0 m-0 border-0 align-self-end"
          >
            <span class="btn btn-secondary">{{ copy.switchOn.back }}</span>
            <span class="btn btn-primary">{{
              editState.gone ? 'Deleted' : copy.switchOn.confirm
            }}</span>
          </div>
        </div>
        <div
          class="modal-footer hilos-button-row p-0 m-0 border-0 align-self-end"
        >
          <template v-if="step === 'switchOn'">
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="busy"
              data-id="i18n-locale-switch-on-back"
              @click="step = 'form'"
            >
              {{ copy.switchOn.back }}
            </button>
            <LoadingButton
              class="btn-primary"
              :loading="switchAction.loading.value"
              :disabled="busy || unsavedAtStep || lockedOn || editState.gone"
              data-id="i18n-locale-switch-on-confirm"
              @click="confirm"
              >{{
                editState.gone ? 'Deleted' : copy.switchOn.confirm
              }}</LoadingButton
            >
          </template>
          <ConflictActions
            v-else
            :conflict="editState.conflict"
            :disable-save="!editCanSave"
            :save-label="editSaveLabel"
            @save="submit"
            @accept-mine="editor.keepMine"
            @accept-theirs="editor.takeTheirs"
          >
            <template #cancel-button>
              <button
                type="button"
                class="btn btn-secondary"
                :disabled="busy"
                data-id="i18n-locale-cancel"
                @click="requestClose"
              >
                {{ copy.cancel }}
              </button>
              <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="busy || editState.gone || lockedOn"
                data-id="i18n-locale-switch-on"
                @click="openSwitchOn"
              >
                {{ copy.switchOn.open }}
              </button>
            </template>
            <template #save-button="{ disabled, onSave }">
              <LoadingButton
                class="btn-primary"
                :loading="editAction.loading.value"
                :disabled="disabled"
                data-id="i18n-locale-submit"
                @click="onSave"
                >{{ editSaveLabel }}</LoadingButton
              >
            </template>
          </ConflictActions>
        </div>
      </div>
      <template v-else-if="mode === 'add'">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="busy"
          data-id="i18n-locale-cancel"
          @click="requestClose"
        >
          {{ copy.cancel }}
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="addAction.loading.value"
          :disabled="!addCanSave"
          data-id="i18n-locale-submit"
          @click="submit"
          >{{ addGone ? 'Deleted' : copy.add }}</LoadingButton
        >
      </template>

      <button
        v-else
        type="button"
        class="btn btn-secondary"
        data-id="i18n-locale-close"
        @click="requestClose"
      >
        {{ copy.close }}
      </button>
    </template>
  </HilosModal>
</template>
