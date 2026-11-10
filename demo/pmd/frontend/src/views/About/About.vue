<!-- Public project introduction inside the framework About page. -->
<script setup lang="ts">
import { HilosAboutPage } from '@hilos/vue/base'

defineOptions({ name: 'AboutPage' })
</script>

<template>
  <HilosAboutPage>
    <p class="lead">PMD is a demonstration application built with Hilos.</p>
    <p class="mb-0">
      It provides the framework's account, notification, and administration
      features in a real-time WebSocket application.
    </p>
  </HilosAboutPage>
</template>
