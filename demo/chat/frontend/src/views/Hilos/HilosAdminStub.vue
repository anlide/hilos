<!-- HilosAdminStub — the one component every stubbed Hilos admin page renders
through. Every stub key in adminMap maps to this component in App.vue, so it
reads the current route from the core navigator, looks its page up in the admin
map, and renders the breadcrumb, heading, any route params, and either a grid of
sub-navigation cards (a section) or a stub empty-state (a leaf). Navigating
between two stub pages keeps this component mounted and just re-resolves the
reactive route. Styling is Bootstrap classes only, no CSS of its own
(styling-rules.md); a real section replaces the mapping with its own page. -->
<script setup lang="ts">
import { HilosLink, hilosRouterKey, useSignal } from '@hilos/vue'
import { computed, inject } from 'vue'

import {
  ADMIN_PAGES,
  adminBreadcrumb,
  adminChildLinks,
} from './adminMap'

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAdminStub requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const route = useSignal(router.currentRoute)
const meta = computed(
  () =>
    ADMIN_PAGES[route.value.page] ?? {
      title: route.value.page,
      lead: 'Stub page.',
    },
)
const breadcrumb = computed(() =>
  adminBreadcrumb(route.value.page, route.value.params),
)
const childLinks = computed(() =>
  adminChildLinks(route.value.page, route.value.params),
)
const paramEntries = computed(() => Object.entries(route.value.params))
</script>

<template>
  <section data-id="admin-stub" :data-admin-page="route.page">
    <nav aria-label="breadcrumb" data-id="admin-breadcrumb">
      <ol class="breadcrumb small mb-2">
        <template v-for="(crumb, index) in breadcrumb" :key="crumb.to">
          <li
            v-if="index < breadcrumb.length - 1"
            class="breadcrumb-item"
          >
            <HilosLink :to="crumb.to">{{ crumb.label }}</HilosLink>
          </li>
          <li v-else class="breadcrumb-item active" aria-current="page">
            {{ crumb.label }}
          </li>
        </template>
      </ol>
    </nav>

    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
      <h1 class="h4 mb-0" data-id="admin-stub-title">{{ meta.title }}</h1>
      <span class="badge text-bg-secondary">Stub</span>
    </div>
    <p class="text-body-secondary">{{ meta.lead }}</p>

    <div v-if="paramEntries.length" class="d-flex flex-wrap gap-2 mb-3">
      <span
        v-for="[name, value] in paramEntries"
        :key="name"
        class="badge text-bg-light border text-body-secondary fw-normal"
      >
        {{ name }}: <code class="ms-1">{{ value }}</code>
      </span>
    </div>

    <div
      v-if="childLinks.length"
      class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3"
      data-id="admin-children"
    >
      <div v-for="child in childLinks" :key="child.page" class="col">
        <HilosLink
          :to="child.to"
          class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
          :data-id="`admin-child-${child.page}`"
        >
          <div class="card-body d-flex flex-column gap-1">
            <span
              class="h6 mb-0 d-flex align-items-center justify-content-between gap-2"
            >
              <span>{{ child.title }}</span>
              <i
                class="bi bi-chevron-right text-body-secondary"
                aria-hidden="true"
              ></i>
            </span>
            <span class="small text-body-secondary">{{ child.lead }}</span>
          </div>
        </HilosLink>
      </div>
    </div>
    <div
      v-else
      class="border rounded p-4 text-center text-body-secondary"
      data-id="admin-stub-empty"
    >
      <i class="bi bi-cone-striped fs-2 d-block mb-2" aria-hidden="true"></i>
      <p class="mb-0">
        Stub page — real content arrives with this section's implementation.
      </p>
    </div>
  </section>
</template>
