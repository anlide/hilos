<!-- HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
as in-place HilosLink navigation, the last crumb shown as the active page. It
knows nothing about any specific page: the caller supplies the resolved trail
(for the Hilos admin tree, hilosAdminBreadcrumb in @hilos/core builds it), so the
same component serves any page that has a breadcrumb. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import type { HilosCrumb } from '@hilos/core'

import HilosLink from './HilosLink.vue'

defineProps<{ crumbs: HilosCrumb[] }>()
</script>

<template>
  <nav v-if="crumbs.length" aria-label="breadcrumb" data-id="hilos-breadcrumb">
    <ol class="breadcrumb small mb-2">
      <template v-for="(crumb, index) in crumbs" :key="crumb.to">
        <li v-if="index < crumbs.length - 1" class="breadcrumb-item">
          <HilosLink :to="crumb.to">{{ crumb.label }}</HilosLink>
        </li>
        <li v-else class="breadcrumb-item active" aria-current="page">
          {{ crumb.label }}
        </li>
      </template>
    </ol>
  </nav>
</template>
