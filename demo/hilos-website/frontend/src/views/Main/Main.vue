<!-- The public introduction and demo catalog, independent of the session. -->
<script setup lang="ts">
import DemoCatalog from './DemoCatalog.vue'
import HomeVideo from './HomeVideo.vue'

defineOptions({ name: 'MainPage' })
</script>

<template>
  <div class="d-flex flex-column gap-5">
    <header>
      <h1>Hilos</h1>
      <p class="lead mb-0">
        A PHP framework for applications that update in real time.
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
  </div>
</template>
