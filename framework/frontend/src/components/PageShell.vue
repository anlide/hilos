<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div :class="['col-12', colWidth, 'mx-auto d-flex flex-column h-100 min-h-0']">
      <div
        class="card flex-grow-1"
        :class="scrollBody ? 'd-flex flex-column min-h-0 overflow-hidden' : 'overflow-auto'"
      >
        <div
          class="card-header"
          :class="$slots['header-actions'] ? 'd-flex justify-content-between align-items-center' : ''"
        >
          <slot name="header">
            <h5 class="mb-0">{{ title }}</h5>
          </slot>
          <slot name="header-actions" />
        </div>
        <div class="card-body" :class="scrollBody ? 'overflow-auto' : ''">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  colWidth?: string
  scrollBody?: boolean
}>(), {
  title: '',
  colWidth: 'col-lg-10',
  scrollBody: false,
})
</script>
