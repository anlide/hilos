<template>
  <nav class="w-100" aria-label="Breadcrumb">
    <ol class="d-flex flex-nowrap align-items-center overflow-auto text-nowrap list-unstyled mb-0 small">
      <li
        v-for="(item, index) in items"
        :key="`${index}-${item.label}`"
        class="d-inline-flex align-items-center flex-shrink-0"
        :aria-current="item.isActive ? 'page' : undefined"
      >
        <span
          v-if="index > 0"
          class="d-inline-flex align-items-center px-2 text-body-secondary user-select-none"
          aria-hidden="true"
        >
          <i class="bi bi-chevron-double-right"></i>
        </span>
        <component
          v-if="item.to && !item.isActive"
          :is="'router-link'"
          :to="item.to"
          class="link-body-emphasis text-decoration-none"
        >
          {{ item.label }}
        </component>
        <span v-else-if="item.isActive" class="fw-semibold text-body">
          {{ item.label }}
        </span>
        <span v-else class="text-body-secondary">
          {{ item.label }}
        </span>
      </li>
    </ol>
  </nav>
</template>

<script setup lang="ts">
export type BreadcrumbItem = {
  label: string
  to?: unknown
  isActive?: boolean
}

defineProps<{
  items: BreadcrumbItem[]
}>()
</script>
