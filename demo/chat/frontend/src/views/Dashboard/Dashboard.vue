<!-- The framework admin dashboard (HilosPages.DASHBOARD): the entry to the Hilos
admin section reached by the shell's gear over the live socket. It renders the
stubbed admin sections grouped from the admin map (adminMap) as no-refresh
HilosLink cards. The sections that already have real backend implementations
(settings, users, guardian) get their own frontend pages and are intentionally
not listed here. Styling is Bootstrap classes only, no CSS of its own
(styling-rules.md). -->
<script setup lang="ts">
import { HilosLink } from '@hilos/vue'

import {
  ADMIN_DASHBOARD_SECTIONS,
  ADMIN_PAGES,
  resolveAdminPath,
} from '../Hilos/adminMap'

const sections = ADMIN_DASHBOARD_SECTIONS.map((section) => ({
  title: section.title,
  description: section.description,
  items: section.items.map((page) => ({
    page,
    title: ADMIN_PAGES[page]?.title ?? page,
    lead: ADMIN_PAGES[page]?.lead ?? '',
    icon: ADMIN_PAGES[page]?.icon ?? 'bi-square',
    to: resolveAdminPath(page),
  })),
}))
</script>

<template>
  <section data-id="dashboard-view">
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Hilos</h1>
      <p class="mb-0 text-body-secondary">
        Administrative sections (stub) with quick access to key project areas.
      </p>
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
                  <i :class="['bi', item.icon]" aria-hidden="true"></i>
                </span>
                <span class="d-flex flex-column gap-1">
                  <span class="h6 mb-0">{{ item.title }}</span>
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
