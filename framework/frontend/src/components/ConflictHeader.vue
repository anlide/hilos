<template>
  <div class="d-flex align-items-center gap-2">
    <h5 class="modal-title mb-0">{{ title }}</h5>
    <span v-if="conflict" class="text-danger fw-semibold">conflict</span>
  </div>
</template>

<script setup lang="ts">
interface Props {
  title: string
  conflict: boolean
}

defineProps<Props>()
</script>
