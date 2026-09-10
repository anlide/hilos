<!-- The public Privacy page (HilosPages.PRIVACY). A framework-declared static
page; this project supplies the content and nothing else. The frame, the heading
and the erase block under the prose are the framework's (HilosPrivacyPage), and
this demo declares no browser value of its own, so it hands in no `values`.
See views/About/About.vue. -->
<script setup lang="ts">
import { HilosPrivacyPage } from '@hilos/vue'

import { actions, connection } from '../../bootstrap/connection'

defineOptions({ name: 'PrivacyPage' })
</script>

<template>
  <HilosPrivacyPage :connection="connection" :actions="actions">
    <p>
      This demo stores only the data needed to show its real-time features: your
      chosen display name, the messages you send, and any files you attach.
    </p>
    <p class="mb-0">
      No analytics or third-party trackers are used, and demo data may be reset
      at any time.
    </p>
  </HilosPrivacyPage>
</template>
