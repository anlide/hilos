<template>
  <div class="settings-value-cell d-flex align-items-center gap-2 min-w-0">
    <template v-if="type === 'boolean'">
      <div class="form-check d-inline-flex align-items-center mb-0 user-select-none flex-shrink-0">
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
        class="fst-italic text-truncate d-inline-block min-w-0"
        :title="formattedValue"
      >
        {{ formattedValue }}
      </span>
    </template>
    <template v-else>
      <span
        v-if="isEmptyStringSetting"
        class="badge rounded-pill d-inline-flex align-items-center justify-content-center px-2 py-1 bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
        role="img"
        aria-label="Empty string"
        title="Empty string"
      >
        <i class="bi bi-file-earmark" aria-hidden="true"></i>
      </span>
      <span
        v-else
        class="text-truncate d-inline-block min-w-0"
        :title="rawTitle"
      >
        {{ formattedValue }}
      </span>
    </template>
    <span
      v-if="isReferenceValue"
      class="badge rounded-pill d-inline-flex align-items-center gap-1 px-2 py-1 bg-info-subtle text-info-emphasis border border-info-subtle settings-source-badge"
      :title="referenceTitle"
    >
      <i class="bi bi-arrow-down-right" aria-hidden="true"></i>
      <code class="settings-source-key text-truncate">{{ defaultReferenceKey }}</code>
    </span>
    <span
      v-else-if="isCatalogDefault"
      class="badge rounded-pill px-2 py-1 bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
      title="Catalog default"
    >
      default
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  value: string | null | undefined
  type: string
  valueSource?: string | null
  defaultReferenceKey?: string | null
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

const isReferenceValue = computed(() => props.valueSource === 'reference' && !!props.defaultReferenceKey)

const isCatalogDefault = computed(() => props.valueSource === 'default')

const referenceTitle = computed(() => props.defaultReferenceKey ? `Default from ${props.defaultReferenceKey}` : '')
</script>

<style scoped>
.settings-value-cell {
  max-width: 100%;
}

.settings-source-badge {
  min-width: 0;
  max-width: 12rem;
}

.settings-source-key {
  color: inherit;
  font-size: 0.75rem;
  max-width: 9rem;
}
</style>
