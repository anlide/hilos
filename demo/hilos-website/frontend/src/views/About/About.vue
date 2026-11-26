<!-- The public About page (HilosPages.ABOUT). A framework-declared static page:
the framework owns the route, the frame, the heading and the support block at the
end of the text (HilosAboutPage), and this project owns the content. The page
subscribes like any other but the framework page sends no payload, so nothing
here depends on the socket. -->
<script setup lang="ts">
import { HilosAboutPage } from '@hilos/vue/base'

import AuthorContacts from '../../contacts/AuthorContacts.vue'

defineOptions({ name: 'AboutPage' })
</script>

<template>
  <HilosAboutPage>
    <p class="lead">
      This is the official website of Hilos — a PHP framework for real-time,
      no-refresh web applications, where a daemon, its workers and agents hold
      the live state and the browser follows it over a WebSocket.
    </p>
    <p>
      The site is itself an application on Hilos, built from the same SDK as the
      framework's demos.
    </p>
    <p>
      Today it is the frame of that site: the home page carries the name, and
      the footer carries these pages. What Hilos is, the video about it and the
      catalog of the demos arrive one by one.
    </p>
    <AuthorContacts />
  </HilosAboutPage>
</template>
