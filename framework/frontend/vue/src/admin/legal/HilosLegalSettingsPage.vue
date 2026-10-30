<script setup lang="ts">
import {
  createHilosLegalSettingsTable,
  createHilosLegalSettingsActions,
  createHilosLegalSettingEdit,
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
} from '@hilos/core/admin/legal'
import {
  HilosPages,
  isHiddenValue,
  type HilosLegalContext,
} from '@hilos/core/base'
import { computed, onMounted, onUnmounted, useId } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosTableEmptyState from '../../HilosTableEmptyState.vue'
import ConflictActions from '../../ConflictActions.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
const props = defineProps<{ context: HilosLegalContext }>()
const table = createHilosLegalSettingsTable(props.context)
const settingsRows = useSignal(table.controller.rows)
const settingsBody = useSignal(table.controller.frame.body)
const settings = computed(() =>
  settingsRows.value.flatMap(({ row }) => (row ? [row] : [])),
)
const actions = createHilosLegalSettingsActions(props.context)
const editor = createHilosLegalSettingEdit(table.controller, actions)
const opened = useSignal(editor.opened)
const row = useSignal(editor.row)
const form = useSignal(editor.form)
const state = useSignal(editor.state)
const noticeText = useSignal(editor.noticeText)
const saveAvailable = useSignal(editor.saveAvailable)
const saveLabel = useSignal(editor.saveLabel)
// The session's save in flight: nothing closes the modal and its body stays
// locked until the reply, and Cancel waits with it.
const saving = useSignal(editor.saving)
const action = useTrackedAction({ toast: false })
const { busy, clearError, run } = action
const inputId = useId()
const modalOpen = computed({
  get: () => opened.value,
  set: (next) => {
    if (!next) editor.close()
  },
})
// The list edits the draft only while it is a value: a value hidden from a viewer
// of the admin view mode has no list, and the modal shows the mark instead.
const draft = computed({
  get: () => (isHiddenValue(form.value.value) ? '' : form.value.value),
  set: (next: string) => editor.setForm({ value: next }),
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
// Save and Enter go through the session's one door: it refuses, closes an
// unchanged choice, or sends through the tracked action and closes on success.
function save(): void {
  void editor.save(run)
}
</script>
<template>
  <HilosAdminPage :page="HilosPages.LEGAL_SETTINGS">
    <p class="small text-body-secondary">
      Two settings control how consent is given and what happens after a
      deadline. Document texts and deadlines stay in code.
    </p>
    <HilosTableEmptyState
      v-if="settingsBody === 'unavailable'"
      :controller="table.controller"
      kind="unavailable"
    />
    <section
      v-for="setting in settings"
      :key="setting.rowKey"
      class="mt-4"
      :aria-labelledby="`${inputId}-${setting.rowKey}`"
    >
      <h2
        :id="`${inputId}-${setting.rowKey}`"
        class="h6 text-uppercase text-body-secondary mb-2"
      >
        {{ HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ?? setting.rowKey }}
      </h2>
      <div class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom">
        <div class="flex-grow-1">
          <div class="fw-semibold small">
            {{
              HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ?? setting.rowKey
            }}
          </div>
          <div class="small text-body-secondary">
            {{ HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.hint }}
          </div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <div class="text-end small">
            <div
              class="fw-semibold"
              :data-id="`legal-setting-value-${setting.rowKey}`"
            >
              <HilosHideable v-slot="{ value: shown }" :value="setting.value">{{
                HILOS_LEGAL_VALUE_COPY[shown] ?? shown
              }}</HilosHideable>
            </div>
            <dl
              class="d-flex flex-wrap justify-content-end gap-1 text-body-secondary mb-0"
            >
              <dt class="fw-normal">Default:</dt>
              <dd class="mb-0">
                <HilosHideable
                  v-slot="{ value: shown }"
                  :value="setting.defaultValue"
                  >{{ HILOS_LEGAL_VALUE_COPY[shown] ?? shown }}</HilosHideable
                >
              </dd>
            </dl>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :data-id="`legal-setting-edit-${setting.rowKey}`"
            :aria-label="`Edit ${HILOS_LEGAL_SETTING_COPY[setting.rowKey]?.label ?? setting.rowKey}`"
            @click="open(setting.rowKey)"
          >
            Edit
          </button>
        </div>
      </div>
    </section>
    <HilosModal
      v-model="modalOpen"
      :title="
        row
          ? (HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey)
          : 'Edit setting'
      "
      :busy="saving"
      @cancel="editor.close"
    >
      <HilosActionError
        :action="action"
        details-title="Couldn't save the legal setting"
      />
      <form v-if="row" @submit.prevent="save">
        <template v-if="isHiddenValue(form.value)">
          <div class="form-label">
            {{ HILOS_LEGAL_SETTING_COPY[row.rowKey]?.label ?? row.rowKey }}
          </div>
          <HilosHiddenMark />
        </template>
        <template v-else>
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
              v-for="option in HILOS_LEGAL_SETTING_COPY[row.rowKey]?.values ??
              []"
              :key="option"
              :value="option"
            >
              {{ HILOS_LEGAL_VALUE_COPY[option] ?? option }}
            </option>
          </select>
        </template>
        <HilosEditNotice
          :kind="state.notice?.kind ?? null"
          :text="noticeText"
          data-id="legal-setting-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="state.conflict"
          :disable-save="!saveAvailable"
          :save-label="saveLabel"
          @save="save"
          @accept-mine="editor.keepMine"
          @accept-theirs="editor.takeTheirs"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="saving"
              data-id="legal-setting-cancel"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }"
            ><LoadingButton
              class="btn-primary"
              :loading="busy"
              :disabled="disabled"
              data-id="legal-setting-save"
              @click="onSave"
              >{{ saveLabel }}</LoadingButton
            ></template
          >
        </ConflictActions>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
