<!-- The public Privacy page (HilosPages.PRIVACY). A framework-declared static
page; this project supplies the content. See views/About/About.vue. -->
<script setup lang="ts">
import { HilosStaticPage } from '@hilos/vue'
</script>

<template>
  <HilosStaticPage title="Privacy">
    <p>
      This demo stores only the data needed to show its real-time features: your
      chosen display name, the messages you send, and any files you attach.
    </p>
    <p class="mb-0">
      No analytics or third-party trackers are used, and demo data may be reset
      at any time.
    </p>
  </HilosStaticPage>
</template>
