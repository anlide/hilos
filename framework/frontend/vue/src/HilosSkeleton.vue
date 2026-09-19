<!-- HilosSkeleton — the frame a data block draws while its data has not arrived:
grey bars at the size and place of the content to come, not a blank and not a
spinner over everything (the mockup's «The connection went away» screen). Stock
Bootstrap placeholders, no CSS of its own (styling-rules.md).

The bars are decorative and hidden from a screen reader. The block is silent by
default, because it usually stands inside a surface that already says it is
loading — the routed outlet around a page skeleton says it once for the whole
page. A block that loads on its own on a live page passes `label`, and gets
exactly one announcement for the whole frame, never one per bar
(accessibility.md). -->
<script setup lang="ts">
import { computed } from 'vue'
import { HILOS_SKELETON_LINES } from '@hilos/core'

const props = defineProps<{
  /** Bar widths in Bootstrap columns (1..12); the core default when omitted. */
  lines?: number[]
  /** What a screen reader announces for the block; silent when omitted. */
  label?: string
}>()

const bars = computed(() => props.lines ?? HILOS_SKELETON_LINES)
</script>

<template>
  <div class="placeholder-glow" data-id="hilos-skeleton">
    <span v-if="props.label" class="visually-hidden" role="status">{{
      props.label
    }}</span>
    <span
      v-for="(width, index) in bars"
      :key="index"
      class="placeholder d-block rounded"
      :class="[`col-${width}`, { 'mb-2': index < bars.length - 1 }]"
      aria-hidden="true"
    ></span>
  </div>
</template>
