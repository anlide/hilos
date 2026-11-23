<script setup lang="ts">
const example = '<div data-id="hilos-table-live-slot"></div>'
</script>

<template>
  <!-- <div data-id="hilos-table-live-idle"></div> -->
  <section>
    <div data-id="hilos-table-live-slot"></div>
    <HilosModal>
      <HilosViewportTable />
      <div :data-id="'hilos-table-live-idle'"></div>
    </HilosModal>
    <p>{{ example }}</p>
  </section>
</template>
