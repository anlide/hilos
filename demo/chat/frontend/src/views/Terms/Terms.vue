<!-- The public Terms page (HilosPages.TERMS). A framework-declared static page;
this project supplies the content. See views/About/About.vue. -->
<script setup lang="ts">
import { HilosStaticPage } from '@hilos/vue'

defineOptions({ name: 'TermsPage' })
</script>

<template>
  <HilosStaticPage title="Terms of Service">
    <p>
      This is a demonstration application provided for evaluation purposes only,
      without warranty of any kind.
    </p>
    <p>
      Messages and files you submit are processed to show the framework's
      real-time features and may be visible to other participants. Do not submit
      confidential or personal information.
    </p>
    <p class="mb-0">Using this demo implies acceptance of these terms.</p>
  </HilosStaticPage>
</template>
