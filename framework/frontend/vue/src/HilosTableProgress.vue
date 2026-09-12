<!-- HilosTableProgress — the track a running job is drawn as, the one piece of
markup shared by the bar above a table and the bar under a row. It computes
NOTHING about the work: the core hands over the fraction (tableProgress.ts) for
the reason it sums the announced rows — three views dividing the same two numbers
each in its own way are three different bars on one product. Work that named no
total gets a striped track running end to end and no number at all, exactly as
the backup screen already shows a run without an estimate. Internal to the Vue
view layer on purpose: it is not exported from index.ts, for the reason the bar
and the footer are not — outside a table it means nothing. -->
<script setup lang="ts">
import { computed } from 'vue'
import type { HilosTableProgress } from '@hilos/core'

const props = defineProps<{
  /** The bar to draw, with the fraction already worked out by the core. */
  progress: HilosTableProgress
  /** Accessible name of the track; the wording belongs to the place that draws it. */
  label: string
}>()

// The percentage the track is filled to, or null when the work named no total —
// which is the difference between a bar and a striped track, and the difference
// between reporting a number to assistive tech and reporting none.
const percent = computed(() =>
  props.progress.fraction === null
    ? null
    : Math.round(props.progress.fraction * 100),
)
</script>

<template>
  <div
    class="progress hilos-progress-track"
    role="progressbar"
    :aria-label="label"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-valuenow="percent ?? undefined"
  >
    <div
      :class="
        percent === null
          ? 'progress-bar progress-bar-striped progress-bar-animated hilos-progress'
          : 'progress-bar hilos-progress'
      "
      :style="{ '--hilos-progress': percent ?? 100 }"
    ></div>
  </div>
</template>
