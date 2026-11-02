<!-- A node's reported name beside its stable id, or just the unknown node's id. -->
<script setup lang="ts">
defineProps<{ nodeId: string; nodeName: string | null }>()
</script>

<template>
  <span class="d-inline-flex flex-wrap gap-1 text-break">
    <template v-if="nodeName !== null">
      <span>{{ nodeName }}</span>
      <span class="font-monospace text-body-secondary small">{{ nodeId }}</span>
    </template>
    <span v-else class="font-monospace">{{ nodeId }}</span>
  </span>
</template>
