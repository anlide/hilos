<!-- HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
lead common to every Hilos admin page, plus the cards to the page's children. A
page passes only its key (props.page); the shell reads the live route params from
the navigator to keep the breadcrumb and child links in context, and takes the
heading, the lead, the chain and the subsection cards from the navigator's
pageIdentity — which is what the page's own subscription answered with, not a
frontend constant. It is page-agnostic — it renders whichever key it is given,
never choosing the page itself (that is the app shell's page→view map).

Under the heading the shell always draws the section's sub-navigation cards — one
for every child the live params can resolve — and a page's own content goes in
the one default slot, drawn beneath them: the way on stands above what the admin
came for, and no page's content can cost its children their cards. A leaf that
passes no content gets a stub empty-state instead.

While the identity is still on the wire the heading is a neutral placeholder and
neither the cards nor the stub is drawn; a page's own content already is, and the
cards rise above it once the identity lands. The raw page key is never printed,
and an empty h1 under the same data-id would make "the name did not arrive" look
exactly like "the name arrived empty".

The heading carries an id the shell provides to what it holds: a table that
declares no title of its own takes its accessible name from this heading, which
already names it.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { hilosChildLinks, hilosCrumbLinks } from '@hilos/core'
import { computed, inject, provide, useId } from 'vue'

import HilosBreadcrumb from './HilosBreadcrumb.vue'
import HilosLink from './HilosLink.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

defineProps<{ page: string }>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAdminPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const headingId = useId()
provide(hilosPageHeadingIdKey, headingId)

const route = useSignal(router.currentRoute)
const identity = useSignal(router.pageIdentity)
const params = computed(() => route.value.params)
const crumbs = computed(() =>
  hilosCrumbLinks(
    identity.value?.breadcrumb ?? [],
    params.value,
    router.resolvePath,
  ),
)
const children = computed(() =>
  hilosChildLinks(
    identity.value?.children ?? [],
    params.value,
    router.resolvePath,
  ),
)
</script>

<template>
  <section data-id="hilos-admin-page" :data-page="page">
    <template v-if="identity">
      <HilosBreadcrumb :crumbs="crumbs" />
      <h1 :id="headingId" class="h4 mb-1" data-id="hilos-admin-title">
        {{ identity.label }}
      </h1>
      <p v-if="identity.lead" class="text-body-secondary">
        {{ identity.lead }}
      </p>
    </template>
    <div
      v-else
      class="placeholder-glow mb-3"
      data-id="hilos-admin-title-skeleton"
    >
      <span class="placeholder col-3 d-block mb-2 rounded"></span>
      <span class="placeholder col-6 d-block rounded"></span>
    </div>

    <div
      v-if="children.length"
      class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4"
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

    <slot>
      <div
        v-if="!children.length && identity"
        class="border rounded p-4 text-center text-body-secondary"
        data-id="hilos-admin-empty"
      >
        <i class="bi bi-cone-striped fs-2 d-block mb-2" aria-hidden="true"></i>
        <p class="mb-0">
          Stub page — real content arrives with this section's implementation.
        </p>
      </div>
    </slot>
  </section>
</template>
