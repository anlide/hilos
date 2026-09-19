<!-- The main page's skeleton (HIL-983): what the routed outlet draws while the
page waits for its first answer. It lays out on the grid Main.vue lays out on —
the "who is here" line, the event stream in the wide column, participants and
bots in the narrow one, the composer below — so nothing moves when the page
lands. Decorative from end to end: the outlet announces the wait once. -->
<script setup lang="ts">
import { HilosSkeleton } from '@hilos/vue'
</script>

<template>
  <div class="d-flex flex-column h-100" aria-hidden="true">
    <p class="flex-shrink-0 placeholder-glow">
      <span class="placeholder col-3 rounded"></span>
    </p>

    <div class="row g-3 flex-grow-1 min-h-0">
      <div class="col-lg-8 d-flex flex-column min-h-0">
        <div class="card flex-grow-1 min-h-0 d-flex flex-column">
          <div class="card-header flex-shrink-0 placeholder-glow">
            <span class="placeholder col-3 rounded"></span>
          </div>
          <div class="card-body">
            <HilosSkeleton :lines="[5, 10, 7, 9, 4, 8]" />
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-3 min-h-0">
        <div v-for="card in 2" :key="card" class="card">
          <div class="card-header placeholder-glow">
            <span class="placeholder col-4 rounded"></span>
          </div>
          <div class="card-body">
            <HilosSkeleton :lines="[8, 6]" />
          </div>
        </div>
      </div>
    </div>

    <div class="mt-3 flex-shrink-0 d-flex gap-2 placeholder-glow">
      <span class="btn btn-outline-secondary disabled placeholder col-1"></span>
      <span class="placeholder flex-grow-1 rounded"></span>
      <span class="btn btn-primary disabled placeholder col-1"></span>
    </div>
  </div>
</template>
