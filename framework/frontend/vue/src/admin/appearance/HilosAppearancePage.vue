<script setup lang="ts">
import {
  createHilosAppearanceTable,
  HilosAppearanceSettingKey,
  HilosPages,
  type HilosAppearanceContext,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import HilosHideable from '../../HilosHideable.vue'

const props = defineProps<{ context: HilosAppearanceContext }>()
const table = createHilosAppearanceTable(props.context)

onMounted(() => table.start())
onUnmounted(() => table.dispose())

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
    </HilosViewportTable>
  </HilosAdminPage>
</template>
