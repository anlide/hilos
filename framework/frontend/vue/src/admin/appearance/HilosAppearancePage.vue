<script setup lang="ts">
import {
  createHilosAppearanceActions,
  createHilosAppearanceDefaultThemeEdit,
  createHilosAppearanceSwitchingEdit,
  createHilosAppearanceTable,
  HilosAppearanceSettingKey,
  type HilosAppearanceContext,
  type HilosAppearanceSettingRow,
} from '@hilos/core/admin/appearance'
import {
  HilosPages,
  isHiddenValue,
  THEME_POSITIONS,
  type ThemePosition,
} from '@hilos/core/base'
import { computed, defineComponent, h, onMounted, onUnmounted } from 'vue'
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
import { useLookOnly } from '../../hilosAdminViewMode.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{ context: HilosAppearanceContext }>()

/**
 * The form of one window: Enter saves the way the Save button does, and stays
 * still wherever that button is locked (useLookOnly). It renders inside the
 * admin shell, which provides the lock; this page's own script stands outside
 * the shell and cannot see it.
 */
const AppearanceForm = defineComponent({
  emits: ['save'],
  setup(_props, { emit, slots }) {
    const { locked } = useLookOnly()

    return () =>
      h(
        'form',
        {
          onSubmit: (event: Event) => {
            event.preventDefault()
            if (!locked.value) emit('save')
          },
        },
        slots.default?.(),
      )
  },
})
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
const saveAvailable = useSignal(editor.saveAvailable)
const editHidden = computed(() => editForm.value.hidden)
const enabled = computed({
  get: () => editForm.value.enabled,
  set: (value: boolean) => editor.patchForm({ enabled: value }),
})
const noticeKind = computed(() => state.value.notice?.kind ?? null)
// Each session's save in flight: nothing closes its window and the window's
// body stays locked until the reply, and Cancel waits with it.
const saving = useSignal(editor.saving)
const editAction = useTrackedAction()
const { busy, run, clearError } = editAction

const defaultThemeEditor = createHilosAppearanceDefaultThemeEdit(
  table.controller,
  actions,
)
const defaultThemeOpened = useSignal(defaultThemeEditor.opened)
const defaultThemeOpen = computed({
  get: () => defaultThemeOpened.value,
  set: (next: boolean) => {
    if (!next) defaultThemeEditor.close()
  },
})
const defaultThemeRow = useSignal(defaultThemeEditor.row)
const defaultThemeForm = useSignal(defaultThemeEditor.form)
const defaultThemeState = useSignal(defaultThemeEditor.state)
const defaultThemeNoticeText = useSignal(defaultThemeEditor.noticeText)
const defaultThemeSaveLabel = useSignal(defaultThemeEditor.saveLabel)
const defaultThemeSaveAvailable = useSignal(defaultThemeEditor.saveAvailable)
const defaultThemeHidden = computed(() =>
  isHiddenValue(defaultThemeForm.value.defaultTheme),
)
const defaultTheme = computed({
  get: () => defaultThemeForm.value.defaultTheme,
  set: (value: ThemePosition) =>
    defaultThemeEditor.patchForm({ defaultTheme: value }),
})
const defaultThemeNoticeKind = computed(
  () => defaultThemeState.value.notice?.kind ?? null,
)
const defaultThemeSaving = useSignal(defaultThemeEditor.saving)
const defaultThemeAction = useTrackedAction()
const {
  busy: defaultThemeBusy,
  run: runDefaultTheme,
  clearError: clearDefaultThemeError,
} = defaultThemeAction

onMounted(() => {
  table.start()
  editor.start()
  defaultThemeEditor.start()
})
onUnmounted(() => {
  defaultThemeEditor.dispose()
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

function openDefaultThemeEdit(row: HilosAppearanceSettingRow): void {
  clearDefaultThemeError()
  defaultThemeEditor.open(row.rowKey)
}

function submitDefaultThemeEdit(): void {
  void defaultThemeEditor.save(runDefaultTheme)
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
      return 'System'
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
        <button
          v-if="setting.rowKey === HilosAppearanceSettingKey.defaultTheme"
          type="button"
          class="btn btn-sm btn-outline-primary"
          title="Edit Default theme"
          aria-label="Edit Default theme"
          data-id="appearance-setting-edit-theme.default"
          @click="openDefaultThemeEdit(setting)"
        >
          <i class="bi bi-pencil" aria-hidden="true"></i>
          <span class="d-md-none ms-1">Edit</span>
        </button>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="state.dirty"
      :busy="saving"
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
      <AppearanceForm v-if="editRow" @save="submitEdit">
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
      </AppearanceForm>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="state.conflict"
          :disable-save="!saveAvailable"
          :save-label="saveLabel"
          @save="submitEdit"
          @accept-mine="editor.keepMine()"
          @accept-theirs="editor.takeTheirs()"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="saving"
              data-id="appearance-switching-cancel"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="busy"
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

    <HilosModal
      v-model="defaultThemeOpen"
      :confirm-on-close="defaultThemeState.dirty"
      :busy="defaultThemeSaving"
      aria-label="Edit · Default theme"
      @cancel="defaultThemeEditor.close()"
    >
      <template #header>
        <ConflictHeader
          title="Edit · Default theme"
          :conflict="defaultThemeState.conflict"
        />
      </template>
      <HilosActionError
        :action="defaultThemeAction"
        details-title="Couldn't save"
      />
      <AppearanceForm v-if="defaultThemeRow" @save="submitDefaultThemeEdit">
        <HilosHiddenMark v-if="defaultThemeHidden" />
        <template v-else>
          <fieldset
            class="mb-2"
            aria-describedby="appearance-default-theme-hint"
          >
            <legend class="form-label fs-6 mb-2">Default theme</legend>
            <div
              v-for="position in THEME_POSITIONS"
              :key="position.value"
              class="form-check mb-1"
            >
              <input
                :id="`appearance-default-theme-${position.value}-field`"
                v-model="defaultTheme"
                class="form-check-input"
                type="radio"
                name="appearance-default-theme"
                :value="position.value"
                :data-id="`appearance-default-theme-${position.value}`"
                data-autofocus
              />
              <label
                class="form-check-label d-inline-flex align-items-center gap-2"
                :for="`appearance-default-theme-${position.value}-field`"
              >
                <i :class="['bi', position.icon]" aria-hidden="true"></i>
                {{ position.label }}
                <span
                  v-if="position.value === defaultThemeRow.defaultValue"
                  class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
                  >default</span
                >
              </label>
            </div>
          </fieldset>
          <p
            id="appearance-default-theme-hint"
            class="small text-body-secondary mb-0"
          >
            Everyone who has not chosen a theme switches at once, in every open
            tab. People who chose one keep it.
          </p>
        </template>
        <HilosEditNotice
          :kind="defaultThemeNoticeKind"
          :text="defaultThemeNoticeText"
          data-id="appearance-default-theme-notice"
        />
      </AppearanceForm>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="defaultThemeState.conflict"
          :disable-save="!defaultThemeSaveAvailable"
          :save-label="defaultThemeSaveLabel"
          @save="submitDefaultThemeEdit"
          @accept-mine="defaultThemeEditor.keepMine()"
          @accept-theirs="defaultThemeEditor.takeTheirs()"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="defaultThemeSaving"
              data-id="appearance-default-theme-cancel"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="defaultThemeBusy"
              :disabled="disabled"
              data-id="appearance-default-theme-save"
              @click="onSave"
            >
              {{ defaultThemeSaveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
