<!-- HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
as in-place HilosLink navigation, the last crumb shown as the active page. It
knows nothing about any specific page: the caller supplies the resolved trail
(for the Hilos admin tree, hilosCrumbLinks in @hilos/core builds it from the chain
the page answered with), so the same component serves any page that has a
breadcrumb. A crumb the route map has no address for is drawn as plain text: the
chain has to stay whole, and a link to nowhere is worse than no link. The
navigation's accessible name is the caller's translatable label, 'breadcrumb'
when it gives none. The items carry no breadcrumb-item class, avoiding a
generated CSS slash that a screen reader may speak aloud: utilities draw the
same look, and each non-first crumb begins with a decorative aria-hidden slash
separator. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import type { HilosCrumb } from '@hilos/core/base'

import HilosLink from './HilosLink.vue'

withDefaults(
  defineProps<{
    /** The resolved trail, root first, the current page last. */
    crumbs: HilosCrumb[]
    /** The navigation's accessible name. */
    label?: string
  }>(),
  { label: 'breadcrumb' },
)
</script>

<template>
  <nav v-if="crumbs.length" :aria-label="label" data-id="hilos-breadcrumb">
    <ol class="breadcrumb small mb-2">
      <li
        v-for="(crumb, index) in crumbs"
        :key="crumb.page"
        :class="{
          'ps-2': index > 0,
          'text-body-secondary': index === crumbs.length - 1,
        }"
        :aria-current="index === crumbs.length - 1 ? 'page' : undefined"
      >
        <span
          v-if="index > 0"
          class="float-start pe-2 text-body-secondary"
          aria-hidden="true"
          >/</span
        >
        <template v-if="index === crumbs.length - 1">
          {{ crumb.label }}
        </template>
        <HilosLink
          v-else-if="crumb.to"
          :to="crumb.to"
          :aria-current="null"
          :data-id="`hilos-breadcrumb-${crumb.page}`"
          >{{ crumb.label }}</HilosLink
        >
        <template v-else>{{ crumb.label }}</template>
      </li>
    </ol>
  </nav>
</template>
