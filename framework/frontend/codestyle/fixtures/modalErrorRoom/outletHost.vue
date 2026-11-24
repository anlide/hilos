<script setup lang="ts">
import { HilosModal } from '@hilos/vue/base'
import Panel from './outletPanel.vue'
</script>
<template>
  <HilosModal :model-value="true">
    <Panel
      ><template #body><!-- body --></template></Panel
    >
  </HilosModal>
</template>
