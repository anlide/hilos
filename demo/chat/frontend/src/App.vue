<template>
  <div id="app">
    <router-view />
  </div>
</template>

<script setup lang="ts">
// Main app component
</script>

<style>
html,
body,
#app {
  height: 100%;
  margin: 0;
}

/* Chat: Bootstrap lacks font-variant-numeric and min-width (rem) utilities */
.chat-rate-limit-counter {
  min-width: 2.5rem;
  font-variant-numeric: tabular-nums;
}

.min-h-0 {
  min-height: 0;
}
</style>
