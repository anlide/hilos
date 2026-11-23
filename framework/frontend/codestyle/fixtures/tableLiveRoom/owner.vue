<script setup lang="ts">
import HilosModal from './HilosModal.vue'
</script>

<template>
  <div
    class="mb-2 position-relative"
    :class="{ 'rounded bg-body-tertiary': !(null !== live.top) }"
    data-id="hilos-table-live-slot"
  >
    <div
      class="alert-secondary invisible"
      aria-hidden="true"
      data-id="hilos-table-live-idle"
    >
      <i class="bi bi-info-circle"></i>
      <span class="btn btn-outline-secondary">&nbsp;</span>
    </div>
    <div v-if="live.top" class="border spinner-border">
      <button>Details</button>
    </div>
    <template v-else><!-- Nothing to announce. --></template>
    <HilosModal>
      <button class="border">Close</button>
    </HilosModal>
    <span class="visually-hidden" role="status">{{ announcement }}</span>
  </div>
</template>
