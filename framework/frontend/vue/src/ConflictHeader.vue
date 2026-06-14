<!-- ConflictHeader — a HilosModal #header that flags an unresolved 3-way-merge
conflict. Drop it in a HilosModal's #header slot; it shows the title and, once a
field has diverged on both the user's and the server's side (the core
threeWayMerge `conflict` result), a badge so the user knows a resolution is
pending before Save re-enables. Bootstrap classes only. -->
<script setup lang="ts">
defineProps<{
  /** The dialog title. */
  title: string
  /** Whether an unresolved conflict is present. */
  conflict?: boolean
}>()
</script>

<template>
  <h5 class="modal-title d-inline-flex align-items-center gap-2 mb-0">
    <span>{{ title }}</span>
    <span v-if="conflict" class="badge text-bg-danger" data-id="conflict-badge"
      >conflict</span
    >
  </h5>
</template>
