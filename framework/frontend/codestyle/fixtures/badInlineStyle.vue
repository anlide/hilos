<!-- Deliberately broken sample: every Vue spelling of an inline style, each one
     setting a property that is not a custom property, plus the binding whose
     names cannot be read where they stand. STYLE-INLINE must report one line per
     element, not one per declaration inside it. -->
<template>
  <div class="card" style="max-width: 18rem; overflow-y: auto">
    <span :style="{ width: '10px', height: '10px' }" />
    <span v-bind:style="{ color: accent }" />
    <span :style="cardStyle" />
    <i class="bi bi-x" :style="[{ margin: 0 }, spacing]" />
  </div>
</template>

<script setup lang="ts">
/** The value the unreadable binding above hands over. */
const cardStyle = { top: 0 }

/** The accent the readable binding above hands over. */
const accent = 'red'

/** The array element the last binding above cannot read. */
const spacing = { padding: 0 }

/** The imperative write, which lands in the same place through another door. */
export function paint(element: HTMLElement): void {
  element.style.color = accent
}
</script>
