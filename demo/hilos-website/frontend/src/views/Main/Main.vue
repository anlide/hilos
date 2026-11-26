<!-- The public introduction and demo catalog, independent of the session. -->
<script setup lang="ts">
import { HilosLink } from '@hilos/vue/base'

import AuthorContacts from '../../contacts/AuthorContacts.vue'
import { PAGE_HISTORY } from '../../pages/keys.js'
import { router } from '../../pages/routes.js'
import DemoCatalog from './DemoCatalog.vue'
import HomeVideo from './HomeVideo.vue'

defineOptions({ name: 'MainPage' })

const historyHref = router.path(PAGE_HISTORY) ?? ''
</script>

<template>
  <div class="d-flex flex-column gap-5">
    <header>
      <h1>Hilos</h1>
      <p class="lead mb-0">
        An AI-first PHP framework for real-time applications.
      </p>
      <p class="mt-2 mb-0">
        Software Factory is the only supported way to build with Hilos. Manual
        development and standalone AI coding workflows are unsupported.
      </p>
      <p class="text-body-secondary mt-2 mb-0">
        Since 2009 — <HilosLink :to="historyHref">read its history</HilosLink>.
      </p>
    </header>

    <section aria-labelledby="home-about-title">
      <h2 id="home-about-title">One change, every open screen</h2>
      <p>
        Hilos keeps a PHP application running as a daemon. Workers handle its
        work, and agents look after the people, data and live pages that make up
        the application.
      </p>
      <p>
        When something changes, the application sends the update to the screens
        that need it. People see fresh data without reloading the page. The
        frontend SDK brings the same connection and live data model to Vue,
        React and Angular.
      </p>
      <p class="mb-0">
        The demos below show that model in use, from a conversation to a shop's
        administration. Open a demo in two windows: a change in one appears in
        the other. Some projects already have their own features; others offer
        the framework's shell while their product is still being built.
      </p>
    </section>

    <HomeVideo />
    <DemoCatalog />

    <section aria-labelledby="home-contact-title">
      <h2 id="home-contact-title">Contact the author</h2>
      <AuthorContacts />
    </section>
  </div>
</template>
