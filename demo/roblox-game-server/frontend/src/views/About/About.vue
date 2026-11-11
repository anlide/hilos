<!-- The public About page (HilosPages.ABOUT). A framework-declared static page:
the framework owns the route, the frame, the heading and the support block at the
end of the text (HilosAboutPage), and this project owns the content. The page
subscribes like any other but the framework page sends no payload, so nothing
here depends on the socket. -->
<script setup lang="ts">
import { HilosAboutPage } from '@hilos/vue/base'

defineOptions({ name: 'AboutPage' })
</script>

<template>
  <HilosAboutPage>
    <p class="lead">
      Roblox Game Server is a demonstration application built with Hilos.
    </p>
    <p class="mb-0">
      Today it is an empty shell: it provides the framework's account,
      notification, moderation and administration features in a real-time
      WebSocket application.
    </p>
  </HilosAboutPage>
</template>
