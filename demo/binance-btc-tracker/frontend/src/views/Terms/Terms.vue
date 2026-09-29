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
      Nothing it shows is financial advice: the trades it will show are paper
      trades of a robot, made to show the framework's real-time features, and
      no money is involved.
    </p>
    <p class="mb-0">Using this demo implies acceptance of these terms.</p>
  </HilosStaticPage>
</template>
