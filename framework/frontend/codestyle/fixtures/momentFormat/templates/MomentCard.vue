<!-- A single-file component judged whole: two script blocks around the
     template, a moment type imported from a neighbor, and a comment that
     mentions {{ created.toLocaleString() }} and prints nothing. -->
<script lang="ts">
import type { Stamp } from './moments.js'

/**
 * @param at A moment typed through the import
 * @returns It printed past the formatter, proven a Date once the import resolves
 */
export function caption(at: Stamp): string {
  return at.toString()
}
</script>

<template>
  <section :title="created.toLocaleDateString()" class="card">
    <!-- {{ created.toLocaleTimeString() }} is commented out -->
    <p>Static toLocaleString() and getHours() print nothing.</p>
    <p>{{ count.toLocaleString() }} and {{ formatCount(count) }}</p>
    <p>{{ (5).toLocaleString() }} {{ Number(count).toLocaleString() }}</p>
    <p>{{ BigInt(total).toLocaleString() }} {{ formatMoment(created) }}</p>
    <p>{{ created.toDateString() }} {{ created.toTimeString() }}</p>
    <time :aria-label="created.toUTCString()">
      {{ created.getHours() }}:{{ created.getMinutes() }}
    </time>
    <b :title="created.getHours()">{{ created.getDate() }}</b>
    <button type="button" @click="open(created.toLocaleString())">Open</button>
  </section>
</template>

<script setup lang="ts">
import { formatCount } from './moments.js'

/** When the card was made. */
const created = new Date()

/** How many cards there are. */
const count = 3

/** How many there ever were. */
const total = 3n

/** @returns The moment printed in TypeScript, below the template */
function label(): string {
  return created.toLocaleTimeString()
}

/**
 * @param moment What the button opens
 */
function open(moment: string): void {
  console.info(moment, label())
}
</script>
