<!-- The look-alikes: the one legal channel in both its static and its bound
     spelling, an attribute that only reads like a style, and a read of an
     element's style, which is not a write. STYLE-INLINE must stay silent. -->
<template>
  <div class="card mw-100" style="--hilos-gap: 0.25rem">
    <span :style="{ '--hilos-progress': percent }" />
    <span v-bind:style="{ '--hilos-progress': percent, '--hilos-gap': gap }" />
    <span data-style="compact" :styles="ignored" />
  </div>
</template>

<script setup lang="ts">
/** The number the legal channel hands over. */
const percent = 50

/** The second number it hands over. */
const gap = 4

/** A prop whose name only looks like the attribute the rule judges. */
const ignored = 'nothing'

/** Reading a declaration is not writing one, and is not judged. */
export function widthOf(element: HTMLElement): string {
  return element.style.width
}
</script>
