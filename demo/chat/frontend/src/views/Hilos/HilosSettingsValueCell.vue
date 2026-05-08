<template>
  <template v-if="type === 'boolean'">
    <div class="form-check d-inline-flex align-items-center mb-0 user-select-none">
      <input
        type="checkbox"
        class="form-check-input mt-0"
        :checked="value === '1'"
        disabled
        tabindex="-1"
        :aria-label="value === '1' ? 'Enabled' : 'Disabled'"
      />
    </div>
  </template>
  <template v-else-if="type === 'integer' || type === 'float'">
    <span
      class="fst-italic text-truncate d-inline-block w-100"
      :title="formattedValue"
    >
      {{ formattedValue }}
    </span>
  </template>
  <template v-else>
    <span
      v-if="isEmptyStringSetting"
      class="badge rounded-pill d-inline-flex align-items-center justify-content-center px-2 py-1 bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
      role="img"
      aria-label="Empty string"
      title="Empty string"
    >
      <i class="bi bi-file-earmark" aria-hidden="true"></i>
    </span>
    <span
      v-else
      class="text-truncate d-inline-block w-100"
      :title="rawTitle"
    >
      {{ formattedValue }}
    </span>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  value: string | null | undefined
  type: string
}>()

const formattedValue = computed(() => {
  if (props.value === null || props.value === undefined) return '—'
  if (props.type === 'boolean') return props.value === '1' ? 'true' : 'false'
  return String(props.value)
})

const rawTitle = computed(() => String(props.value ?? ''))

const isEmptyStringSetting = computed(() => {
  if (props.type !== 'string') return false
  return props.value === '' || props.value === null || props.value === undefined
})
</script>
