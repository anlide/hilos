<script setup lang="ts">
import {
  createHilosAppearanceActions,
  createHilosAppearanceSwitchingEdit,
  createHilosAppearanceTable,
  HilosAppearanceSettingKey,
  HilosPages,
  type HilosAppearanceContext,
  type HilosAppearanceSettingRow,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'
import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{ context: HilosAppearanceContext }>()
const table = createHilosAppearanceTable(props.context)
const actions = createHilosAppearanceActions(props.context)
const editor = createHilosAppearanceSwitchingEdit(table.controller, actions)
const opened = useSignal(editor.opened)
const editOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) editor.close()
  },
})
const editRow = useSignal(editor.row)
const editForm = useSignal(editor.form)
const state = useSignal(editor.state)
const noticeText = useSignal(editor.noticeText)
const saveLabel = useSignal(editor.saveLabel)
const canSave = useSignal(editor.canSave)
const editHidden = computed(() => editForm.value.hidden)
const enabled = computed({
  get: () => editForm.value.enabled,
  set: (value: boolean) => editor.patchForm({ enabled: value }),
})
const noticeKind = computed(() => state.value.notice?.kind ?? null)
const editAction = useTrackedAction()
const { loading, busy, run, clearError } = editAction

onMounted(() => {
  table.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  table.dispose()
})

function openEdit(row: HilosAppearanceSettingRow): void {
  clearError()
  editor.open(row.rowKey)
}

function submitEdit(): void {
  void editor.save(run)
}

function settingLabel(key: string): string {
  return key === HilosAppearanceSettingKey.switchingEnabled
    ? 'Theme switching'
    : key === HilosAppearanceSettingKey.defaultTheme
      ? 'Default theme'
      : key
}

function valueLabel(value: boolean | string): string {
  if (typeof value === 'boolean') return value ? 'On' : 'Off'
  switch (value) {
    case 'light':
      return 'Light'
    case 'dark':
      return 'Dark'
    case 'system':
      return 'As the system'
    default:
      return value
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.APPEARANCE">
    <p class="small text-body-secondary">
      Theme switching and the default theme for this installation.
    </p>
    <HilosViewportTable :controller="table.controller">
      <template #cell-rowKey="{ row: setting }">
        <strong>{{ settingLabel(setting.rowKey) }}</strong>
      </template>
      <template #cell-value="{ row: setting }">
        <span :data-id="`appearance-setting-value-${setting.rowKey}`">
          <HilosHideable v-slot="{ value: shown }" :value="setting.value">
            {{ valueLabel(shown) }}
          </HilosHideable>
        </span>
      </template>
      <template #cell-defaultValue="{ row: setting }">
        <span :data-id="`appearance-setting-default-${setting.rowKey}`">
          <HilosHideable
            v-slot="{ value: shown }"
            :value="setting.defaultValue"
          >
            {{ valueLabel(shown) }}
          </HilosHideable>
        </span>
      </template>
      <template #cell-actions="{ row: setting }">
        <button
          v-if="setting.rowKey === HilosAppearanceSettingKey.switchingEnabled"
          type="button"
          class="btn btn-sm btn-outline-primary"
          title="Edit Theme switching"
          aria-label="Edit Theme switching"
          data-id="appearance-setting-edit-theme.switching_enabled"
          @click="openEdit(setting)"
        >
          <i class="bi bi-pencil" aria-hidden="true"></i>
          <span class="d-md-none ms-1">Edit</span>
        </button>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="state.dirty"
      aria-label="Edit · Theme switching"
      @cancel="editor.close()"
    >
      <template #header>
        <ConflictHeader
          title="Edit · Theme switching"
          :conflict="state.conflict"
        />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <HilosHiddenMark v-if="editHidden" />
        <template v-else>
          <div class="form-check form-switch mb-2">
            <input
              id="appearance-switching-field"
              v-model="enabled"
              class="form-check-input"
              type="checkbox"
              role="switch"
              aria-describedby="appearance-switching-hint"
              data-id="appearance-switching-input"
              data-autofocus
            />
            <label class="form-check-label" for="appearance-switching-field">
              Let people choose a theme
            </label>
            <span
              class="badge rounded-pill bg-body-tertiary text-body-emphasis border ms-1"
            >
              <HilosHideable
                v-slot="{ value: shown }"
                :value="editRow.defaultValue"
              >
                default: {{ valueLabel(shown) }}
              </HilosHideable>
            </span>
          </div>
          <p
            id="appearance-switching-hint"
            class="small text-body-secondary mb-0"
          >
            When off, the theme icon in the header and the Theme row in the
            profile disappear for everyone, and everyone sees the default theme.
            People's choices are kept and come back when it is on again.
          </p>
        </template>
        <HilosEditNotice
          :kind="noticeKind"
          :text="noticeText"
          data-id="appearance-switching-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="state.conflict"
          :disable-save="!canSave"
          :save-label="saveLabel"
          @save="submitEdit"
          @accept-mine="editor.keepMine()"
          @accept-theirs="editor.takeTheirs()"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="busy"
              data-id="appearance-switching-cancel"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="loading"
              :disabled="disabled"
              data-id="appearance-switching-save"
              @click="onSave"
            >
              {{ saveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
