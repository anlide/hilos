<!-- Deliberately broken sample: the British forms stand in the template — in its
     text, in an attribute value and in a bound expression — and in the script
     block, so SPELLING must report each one whether or not a parser would have
     reached it. -->
<template>
  <div class="card">
    <span :title="colourLabel">The colour of the badge</span>
    <button aria-label="Cancel the licence check">Cancelled</button>
  </div>
</template>

<script setup lang="ts">
/** The label the template binds — the script-block hit. */
const colourLabel = 'behaviour'
</script>
