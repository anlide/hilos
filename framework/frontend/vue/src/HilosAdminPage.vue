<!-- HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
lead common to every Hilos admin page, plus a default body. A page passes only
its key (props.page); the shell reads the live route params from the navigator
to keep the breadcrumb and child links in context, and resolves label / lead /
parent from the framework admin tree (HILOS_ADMIN_PAGES). It is page-agnostic —
it renders whichever key it is given, never choosing the page itself (that is the
app shell's page→view map). The default body is the section's sub-navigation
cards, or a stub empty-state for a leaf; a real page overrides the default slot
with its own content while keeping the shell.

A section ROOT that has content of its own puts it in the named `body` slot
instead, which is drawn after the default one: it needs both, the cards to its
children and its own figures beneath them, and overriding the default slot would
cost it the cards. A leaf page goes on overriding the default slot as before.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  HILOS_ADMIN_PAGES,
  hilosAdminBreadcrumb,
  hilosAdminChildren,
} from '@hilos/core'
import { computed, inject } from 'vue'

import HilosBreadcrumb from './HilosBreadcrumb.vue'
import HilosLink from './HilosLink.vue'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{ page: string }>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAdminPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const route = useSignal(router.currentRoute)
const params = computed(() => route.value.params)
const meta = computed(() => HILOS_ADMIN_PAGES[props.page])
const crumbs = computed(() => hilosAdminBreadcrumb(props.page, params.value))
const children = computed(() => hilosAdminChildren(props.page, params.value))
</script>

<template>
  <section data-id="hilos-admin-page" :data-page="page">
    <HilosBreadcrumb :crumbs="crumbs" />
    <h1 class="h4 mb-1" data-id="hilos-admin-title">
      {{ meta?.label ?? page }}
    </h1>
    <p v-if="meta?.lead" class="text-body-secondary">{{ meta.lead }}</p>

    <slot :children="children">
      <div
        v-if="children.length"
        class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3"
        data-id="hilos-admin-children"
      >
        <div v-for="child in children" :key="child.page" class="col">
          <HilosLink
            :to="child.to"
            class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
            :data-id="`hilos-admin-child-${child.page}`"
          >
            <div class="card-body d-flex flex-column gap-1">
              <span
                class="h6 mb-0 d-flex align-items-center justify-content-between gap-2"
              >
                <span>{{ child.label }}</span>
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
        data-id="hilos-admin-empty"
      >
        <i class="bi bi-cone-striped fs-2 d-block mb-2" aria-hidden="true"></i>
        <p class="mb-0">
          Stub page — real content arrives with this section's implementation.
        </p>
      </div>
    </slot>
    <slot name="body" />
  </section>
</template>
