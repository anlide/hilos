<!-- HilosI18nNamesTable — the one names table of the i18n section (HIL-1477),
drawn on the names page of a language and, later, of a country. A row is a
language: its code in capitals with the native name beside it, and the base name
written in it or the "No name" plate — a plate, not a dash, because a dash can be
typed into a name and would then read the same as no name at all. An empty base is
a stored value and shows empty. Under the framework's chevron the corrections of
that language's locales of a country: the locale, the country's label, and the
correction or the "as in" plate naming the base it inherits. The frame, the
chevron and which rows get one are the framework's (createHilosI18nNamesTable);
there are no buttons here — the action leaves put them in. Bootstrap classes only,
painted with classes that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import {
  type HilosI18nNameRow,
  type TableViewportController,
} from '@hilos/core'

import HilosViewportTable from '../../../HilosViewportTable.vue'

defineProps<{
  /** The names table's controller, built by createHilosI18nNamesTable. */
  controller: TableViewportController<HilosI18nNameRow>
}>()

/** A plate standing where there is no value of its own, in both themes. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'
</script>

<template>
  <HilosViewportTable :controller="controller">
    <template #cell-code="{ row }">
      <span class="text-uppercase">{{ row.code }}</span>
      <span class="text-body-secondary ms-1">{{ row.nativeName }}</span>
    </template>
    <template #cell-name="{ row }">
      <span
        v-if="row.name === null"
        :class="PLATE"
        :data-id="`i18n-names-none-${row.code}`"
        ><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No name</span
      >
      <span v-else class="fw-medium">{{ row.name }}</span>
    </template>
    <template #detail-corrections="{ row }">
      <ul class="list-unstyled mb-0">
        <li
          v-for="correction in row.corrections"
          :key="correction.localeCode"
          class="d-flex flex-wrap align-items-center gap-2 py-1"
          :data-id="`i18n-names-correction-${correction.localeCode}`"
        >
          <i
            class="bi bi-arrow-return-right text-body-secondary"
            aria-hidden="true"
          ></i>
          <span>{{ correction.localeCode }}</span>
          <span class="text-body-secondary">{{
            correction.countryName ?? correction.countryCode.toUpperCase()
          }}</span>
          <span
            v-if="correction.name === null"
            :class="PLATE"
            :data-id="`i18n-names-inherit-${correction.localeCode}`"
            ><i class="bi bi-arrow-return-right me-1" aria-hidden="true"></i>as
            in “{{ row.nativeName }}”</span
          >
          <span v-else class="fw-medium">{{ correction.name }}</span>
        </li>
      </ul>
    </template>
  </HilosViewportTable>
</template>
