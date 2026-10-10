<!-- HilosI18nMarksLegend — the marks legend of the i18n section (HIL-112, HIL-1481).
Explains visual badges used across tables of the i18n section.
Bootstrap classes only, following WCAG 2.1 AA (accessibility.md, styling-rules.md). -->
<script setup lang="ts">
import { type HilosI18nLegendRow } from '@hilos/core'

defineProps<{
  /** Rows explaining the marks used in the table. */
  rows: readonly HilosI18nLegendRow[]
  /** Base test hook identifier for the legend section. */
  dataId: string
}>()
</script>

<template>
  <section :data-id="dataId">
    <h2 class="small text-body-secondary mb-2">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>What the marks
      mean
    </h2>
    <dl class="row row-cols-1 row-cols-md-2 g-1 mb-0 small">
      <div
        v-for="row in rows"
        :key="row.key"
        class="col d-flex gap-2 align-items-baseline"
        :data-id="`${dataId}-${row.key}`"
      >
        <dt class="fw-normal">
          <span
            class="badge me-1"
            :class="row.mark.tone"
            :title="row.mark.label"
            aria-hidden="true"
          >
            <i class="bi" :class="row.mark.icon"></i>
          </span>
          {{ row.mark.label }}
        </dt>
        <dd class="mb-0 text-body-secondary">{{ row.meaning }}</dd>
      </div>
    </dl>
  </section>
</template>
