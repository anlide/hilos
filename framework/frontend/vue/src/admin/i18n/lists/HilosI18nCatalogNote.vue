<!-- HilosI18nCatalogNote — the built-in catalog callout of the i18n section (HIL-112, HIL-1481).
Drawn on list pages (languages, countries) when builtInCatalog data is available.
The note heading tells the tallies known to the framework; the page body supplies
the explanatory text via the default slot. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { type HilosI18nBuiltInCatalog } from '@hilos/core'

defineProps<{
  /** The built-in catalog tally with language and country counts. */
  catalog: HilosI18nBuiltInCatalog
  /** Test hook identifying the catalog callout. */
  dataId: string
}>()
</script>

<template>
  <section
    class="alert alert-info small d-flex gap-2 align-items-start"
    :data-id="dataId"
  >
    <i class="bi bi-database-check flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div>
      <h2 class="h6 mb-1">
        The system knows {{ catalog.languageCount }} languages and
        {{ catalog.countryCount }} countries on its own
      </h2>
      <p class="mb-0">
        <slot />
      </p>
    </div>
  </section>
</template>
