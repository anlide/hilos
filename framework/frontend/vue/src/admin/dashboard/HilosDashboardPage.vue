<!-- HilosDashboardPage — the framework Hilos admin dashboard (HilosPages.DASHBOARD):
the entry to the admin section reached by the shell's gear over the live socket. It
renders the admin sections the dashboard's own subscription answered with, framework
groups first and a project's own after them, as no-refresh HilosLink cards. It is
self-contained (no project context), so it is the framework default for the dashboard
key; a project declares its cards in its page catalog on the backend rather than
wrapping this page, and the `#top` slot is left for content that is not a card at
all (the React and Angular ports offer the same seam). The sections are a computed, not a module constant: nothing is
known about them at module load, they arrive with the page. Until they do, the cards
are placeholders — an empty grid would jump the layout on every visit.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, inject } from 'vue'

import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosDashboardPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const answered = useSignal(router.dashboardSections)
const sections = computed(() =>
  (answered.value ?? []).map((section) => ({
    title: section.title,
    description: section.description,
    // A card with no address is left out: a card IS its target, and the shell
    // must not offer one that goes nowhere.
    items: section.items.flatMap((item) => {
      const to = router.resolvePath(item.page)

      return to === undefined ? [] : [{ ...item, to }]
    }),
  })),
)
</script>

<template>
  <section data-id="dashboard-view">
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Hilos</h1>
      <p class="mb-0 text-body-secondary">
        Administrative sections with quick access to key project areas.
      </p>
    </div>

    <!-- Project-supplied admin areas above the framework sections; empty by
    default. A project's own cards ride its page catalog on the backend, so this
    seam is for content that is not a card at all. -->
    <slot name="top" />

    <div
      v-if="answered === undefined"
      class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 placeholder-glow"
      data-id="dashboard-skeleton"
    >
      <div v-for="slot in 6" :key="slot" class="col">
        <span class="placeholder col-12 rounded py-5 d-block"></span>
      </div>
    </div>

    <div v-for="section in sections" :key="section.title" class="mb-4">
      <div class="mb-3">
        <h2 class="h6 text-uppercase text-body-secondary mb-1">
          {{ section.title }}
        </h2>
        <p class="mb-0 text-body-secondary">{{ section.description }}</p>
      </div>

      <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
        <div v-for="item in section.items" :key="item.page" class="col">
          <HilosLink
            :to="item.to"
            class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
            :data-id="`dashboard-card-${item.page}`"
          >
            <div class="card-body">
              <div class="d-flex align-items-start gap-3">
                <span
                  class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
                >
                  <i
                    :class="['bi', item.icon ?? 'bi-square']"
                    aria-hidden="true"
                  ></i>
                </span>
                <span class="d-flex flex-column gap-1">
                  <span class="h6 mb-0">{{ item.label }}</span>
                  <span class="small text-body-secondary">{{ item.lead }}</span>
                </span>
              </div>
            </div>
          </HilosLink>
        </div>
      </div>
    </div>
  </section>
</template>
