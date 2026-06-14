<!-- SettingValueCell — renders one setting's effective value for the settings
table and the edit dialog: a disabled checkbox for booleans, an italic figure for
numbers, the text (or an "empty string" chip) otherwise, plus a source badge —
the referenced key, "default", or "custom" — so the catalog origin is visible at
a glance. Presentation only; Bootstrap classes (styling-rules.md). -->
<script setup lang="ts">
import { computed } from 'vue'

import { type SettingValueSource } from '../../../types/tables/HilosSettingRow'

defineOptions({ name: 'SettingValueCell' })

const props = defineProps<{
  value: string | null
  type: string
  valueSource: SettingValueSource
  defaultReferenceKey: string | null
}>()

const isBoolean = computed(() => props.type === 'boolean')
const isNumber = computed(
  () => props.type === 'integer' || props.type === 'float',
)
const isEmptyString = computed(
  () => props.type === 'string' && (props.value === null || props.value === ''),
)
const display = computed(() => {
  if (props.value === null) {
    return '—'
  }
  if (isBoolean.value) {
    return props.value === '1' ? 'true' : 'false'
  }

  return props.value
})
const isReference = computed(
  () => props.valueSource === 'reference' && props.defaultReferenceKey !== null,
)
</script>

<template>
  <span
    class="d-inline-flex align-items-center gap-2 mw-100"
    data-id="setting-value"
  >
    <input
      v-if="isBoolean"
      type="checkbox"
      class="form-check-input mt-0 flex-shrink-0"
      :checked="value === '1'"
      disabled
      tabindex="-1"
      :aria-label="value === '1' ? 'Enabled' : 'Disabled'"
    />
    <span
      v-else-if="isEmptyString"
      class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
      title="Empty string"
    >
      <i class="bi bi-file-earmark" aria-hidden="true"></i> empty
    </span>
    <span
      v-else
      class="text-truncate"
      :class="{ 'fst-italic': isNumber }"
      :title="display"
      >{{ display }}</span
    >

    <span
      v-if="isReference"
      class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle d-inline-flex align-items-center gap-1"
      :title="`Default from ${defaultReferenceKey}`"
    >
      <i class="bi bi-arrow-down-right" aria-hidden="true"></i>
      <code class="text-truncate text-info-emphasis">{{
        defaultReferenceKey
      }}</code>
    </span>
    <span
      v-else-if="valueSource === 'default'"
      class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
      title="Catalog default"
      >default</span
    >
    <span
      v-else-if="valueSource === 'override'"
      class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle flex-shrink-0"
      title="Custom value"
      >custom</span
    >
  </span>
</template>
