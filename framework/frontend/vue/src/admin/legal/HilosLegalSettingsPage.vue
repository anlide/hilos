<script setup lang="ts">
import {
  createHilosLegalSettingsTable,
  createHilosLegalSettingsActions,
  createHilosLegalSettingEdit,
  HilosPages,
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
  HILOS_LEGAL_SETTING_PREVIEWS,
  type HilosLegalContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, useId } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import ConflictActions from '../../ConflictActions.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
const props = defineProps<{ context: HilosLegalContext }>()
const table = createHilosLegalSettingsTable(props.context)
const actions = createHilosLegalSettingsActions(props.context)
const editor = createHilosLegalSettingEdit(table.controller)
const row = useSignal(editor.row)
const value = useSignal(editor.value)
const state = useSignal(editor.state)
const noticeText = useSignal(editor.noticeText)
const action = useTrackedAction({ toast: false })
const { busy, loading, clearError, run } = action
const inputId = useId()
const opened = computed({
  get: () => row.value !== null,
  set: (next) => {
    if (!next) editor.close()
  },
})
const draft = computed({
  get: () => value.value,
  set: (next: string) => editor.setValue(next),
})
onMounted(() => {
  table.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  table.dispose()
})
function open(key: string): void {
  if (busy.value) return
  clearError()
  editor.open(key)
}
async function save(): Promise<void> {
  if (
    row.value === null ||
    busy.value ||
    state.value.gone ||
    state.value.conflict
  )
    return
  if (!state.value.dirty) {
    editor.close()
    return
  }
  if (await run(actions.sendSettingSet(row.value.rowKey, value.value)))
    editor.close()
}
</script>
<template>
  <HilosAdminPage :page="HilosPages.LEGAL_SETTINGS">
    <p class="small text-body-secondary">
      Two settings control how consent is given and what happens after a
      deadline. Document texts and deadlines stay in code.
    </p>
    <HilosViewportTable :controller="table.controller">
      <template #cell-rowKey="{ row: setting }"
        ><strong>{{
          HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ?? setting.rowKey
        }}</strong>
        <div class="small text-body-secondary">
          {{ HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.hint }}
        </div></template
      >
      <template #cell-value="{ row: setting }"
        ><span :data-id="`legal-setting-value-${setting.rowKey}`">{{
          HILOS_LEGAL_VALUE_COPY[setting.value] ?? setting.value
        }}</span>
        <div class="small text-body-secondary">
          Default:
          {{
            HILOS_LEGAL_VALUE_COPY[setting.defaultValue] ?? setting.defaultValue
          }}
        </div></template
      >
      <template #cell-actions="{ row: setting }"
        ><button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :data-id="`legal-setting-edit-${setting.rowKey}`"
          @click="open(setting.rowKey)"
        >
          Edit
        </button></template
      >
    </HilosViewportTable>
    <section class="mt-4">
      <h2 class="h5">Previews</h2>
      <div class="row g-3">
        <div
          v-for="preview in HILOS_LEGAL_SETTING_PREVIEWS"
          :key="preview.key"
          class="col-md-6"
        >
          <div
            class="border rounded p-3 h-100"
            :data-id="`legal-setting-preview-${preview.key}`"
          >
            <h3 class="h6">{{ preview.title }}</h3>
            <label
              v-if="preview.key === 'checkbox'"
              class="small d-flex align-items-start gap-2 mb-2"
              ><input
                type="checkbox"
                class="form-check-input flex-shrink-0"
                disabled
              />{{ preview.text }}</label
            >
            <button
              v-if="preview.key === 'checkbox' || preview.key === 'line'"
              type="button"
              class="btn btn-sm btn-primary w-100 mb-2"
              disabled
            >
              Create account
            </button>
            <blockquote
              v-if="preview.key !== 'checkbox'"
              class="small"
              :class="
                preview.key === 'freeze'
                  ? 'alert alert-info'
                  : preview.key === 'remind'
                    ? 'alert alert-warning'
                    : ''
              "
            >
              {{ preview.text }}
            </blockquote>
            <p class="small text-body-secondary mb-0">{{ preview.hint }}</p>
          </div>
        </div>
      </div>
    </section>
    <section class="mt-4 small text-body-secondary">
      <h2 class="h6">What is not a setting</h2>
      <p class="mb-0">
        The acceptance window ends on the revision's effective date. There is no
        global window length, switch to bypass consent, or editor for document
        text.
      </p>
    </section>
    <HilosModal
      v-model="opened"
      :title="
        row
          ? (HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey)
          : 'Edit setting'
      "
      @cancel="editor.close"
    >
      <HilosActionError
        :action="action"
        details-title="Couldn't save the legal setting"
      />
      <form v-if="row" @submit.prevent="save">
        <label class="form-label" :for="inputId">{{
          HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
        }}</label>
        <select
          :id="inputId"
          v-model="draft"
          class="form-select"
          data-id="legal-setting-input"
          data-autofocus
          :disabled="busy"
        >
          <option
            v-for="option in HILOS_LEGAL_SETTING_COPY[row.rowKey]?.values ?? []"
            :key="option"
            :value="option"
          >
            {{ HILOS_LEGAL_VALUE_COPY[option] ?? option }}
          </option>
        </select>
        <HilosEditNotice
          :kind="state.notice?.kind ?? null"
          :text="noticeText"
          data-id="legal-setting-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="busy"
          data-id="legal-setting-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <ConflictActions
          :conflict="state.conflict"
          :disable-save="!state.dirty || busy || state.gone"
          :mergeable="false"
          :save-label="state.gone ? 'Deleted' : 'Save'"
          @save="save"
          @accept-mine="editor.keepMine"
          @accept-theirs="editor.takeTheirs"
        >
          <template #save-button="{ disabled, onSave }"
            ><LoadingButton
              class="btn-primary"
              :loading="loading"
              :disabled="disabled"
              data-id="legal-setting-save"
              @click="onSave"
              >{{ state.gone ? 'Deleted' : 'Save' }}</LoadingButton
            ></template
          >
        </ConflictActions>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
