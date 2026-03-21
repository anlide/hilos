<template>
  <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-sm-end gap-2">
    <span
      class="badge rounded-pill d-inline-flex align-items-center"
      :class="running ? 'text-bg-success' : 'bg-warning text-dark'"
    >
      <i
        class="bi me-1"
        :class="running ? 'bi-play-circle-fill' : 'bi-stop-circle-fill'"
        aria-hidden="true"
      ></i>
      {{ running ? 'Running' : 'Stopped' }}
    </span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Guardian agent controls">
      <span
        class="btn btn-outline-success disabled"
        aria-disabled="true"
        title="Start"
        aria-label="Start"
      >
        <i class="bi bi-play-fill" aria-hidden="true"></i>
      </span>
      <span
        class="btn btn-outline-warning disabled"
        aria-disabled="true"
        title="Stop"
        aria-label="Stop"
      >
        <i class="bi bi-stop-fill" aria-hidden="true"></i>
      </span>
      <span
        class="btn btn-outline-danger disabled"
        aria-disabled="true"
        title="Restart"
        aria-label="Restart"
      >
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
      </span>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  running: boolean
}>()
</script>
