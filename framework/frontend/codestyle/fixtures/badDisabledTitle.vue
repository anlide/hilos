<!-- Deliberately broken sample: the four ways a title on a control that can be
     disabled stops being its name — a reason shown at the disabled state, a title
     with no name beside it, a title beside loading alone, and a ternary whose title
     differs from its name in one branch. DISABLED-TITLE must report each element
     once, on the line of its title. -->
<template>
  <div class="d-flex gap-2">
    <button
      type="button"
      :disabled="reason !== null"
      :title="reason ?? 'Restore this backup'"
      aria-label="Restore this backup"
    ></button>
    <button type="button" disabled title="Delete backup"></button>
    <LoadingButton
      v-bind:loading="saving"
      title="Saving the draft"
      aria-label="Save"
    />
    <input
      type="checkbox"
      v-bind:disabled="busy"
      :aria-label="pinned ? 'Unpin from rotation' : 'Pin out of rotation'"
      v-bind:title="pinned ? 'Pinned out of rotation' : 'Pin out of rotation'"
    />
  </div>
</template>

<script setup lang="ts">
/** Why the first button is dark, or null while it is live. */
const reason: string | null = 'This archive does not match its checksum'

/** Whether the draft is being saved. */
const saving = false

/** Whether the switch waits on its row. */
const busy = false

/** Whether the row is pinned. */
const pinned = true
</script>
