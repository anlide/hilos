<!-- The public About page (HilosPages.ABOUT). A framework-declared static page:
the framework owns the route and the HilosStaticPage frame, this project owns the
content. The page subscribes like any other but the framework page sends no
payload, so nothing here depends on the socket. -->
<script setup lang="ts">
import { HilosStaticPage } from '@hilos/vue'
</script>

<template>
  <HilosStaticPage title="About">
    <p class="lead">
      Hilos Chat is a demonstration of the Hilos framework — a real-time,
      no-refresh WebSocket application built on a framework-agnostic core.
    </p>
    <p>
      It shows a live event stream, presence, bots, and file attachments wired
      through the Hilos signal protocol, driven by the same SDK that powers the
      React and Angular demos.
    </p>
    <p class="mb-0">
      The page you are reading is a framework-declared static page: the
      framework owns its route and layout, while this project supplies the text.
    </p>
  </HilosStaticPage>
</template>
