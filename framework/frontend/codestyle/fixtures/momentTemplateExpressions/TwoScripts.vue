<!-- Two top-level script blocks around the template. The virtual script keeps
     both where the file has them, imports included, and the template's
     expressions come out at the lines of the file, not of the block. Static
     text, a comment and the directives that print nothing give no expression,
     whatever they mention: {{ created.toLocaleString() }}. -->
<script lang="ts">
import type { Labelled } from './labelled'

/** Options a setup block cannot declare. */
export default {
  inheritAttrs: false,
}

/** Stands in for a helper the template calls. */
export function caption(moment: Labelled): string {
  return moment.label
}
</script>

<template>
  <section :title="caption(card)" class="card">
    <!-- {{ created.toLocaleString() }} is commented out -->
    <h2 v-if="created" v-text="heading" />
    <p>
      Created {{ created?.toLocaleString() }} and seen
      {{ seen['toLocaleDateString']() }}
    </p>
    <button
      type="button"
      :aria-label="`Open ${heading}`"
      @click="open(created.toLocaleString())"
    >
      Open toLocaleString() here
    </button>
    <time
      v-for="moment in moments"
      :key="moment.label"
      v-bind:datetime="moment.label"
      >{{ moment.label }}</time
    >
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'

/** The card the section titles. */
const card: Labelled = { label: 'card' }

/** When the card was made. */
const created = ref(new Date())

/** When it was last seen. */
const seen = new Date()

/** What the heading says. */
const heading = 'Card'

/** Moments the card lists. */
const moments: Labelled[] = []

/**
 * @param moment What the button opens
 */
function open(moment: string): void {
  console.info(moment)
}
</script>
