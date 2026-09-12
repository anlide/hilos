<!-- The look-alikes: a title that repeats the name as written, the same name as a
     static value on one side and a quoted binding on the other, a title on an
     element that cannot be disabled, and a disabled control with a name and no
     title. DISABLED-TITLE must stay silent on every one of them. -->
<template>
  <div class="d-flex gap-2">
    <button
      type="button"
      :disabled="busy"
      title="Delete backup"
      aria-label="Delete backup"
    ></button>
    <button
      type="button"
      :disabled="busy"
      :title="`Send the code via ${label}`"
      :aria-label="`Send the code via ${label}`"
    ></button>
    <button type="button" disabled title="Close" :aria-label="'Close'"></button>
    <input
      type="checkbox"
      :disabled="busy"
      :aria-label="pinned ? 'Unpin from rotation' : 'Pin out of rotation'"
      :title="pinned ? 'Unpin from rotation' : 'Pin out of rotation'"
    />
    <span :title="shipError">failed</span>
    <button
      type="button"
      :disabled="busy"
      aria-label="Restore this backup"
    ></button>
  </div>
</template>

<script setup lang="ts">
/** Whether the row waits on an action. */
const busy = false

/** The channel the code goes through. */
const label = 'SMS'

/** Whether the row is pinned. */
const pinned = true

/** Why the copy failed. */
const shipError = 'ssh: connect timed out'
</script>
