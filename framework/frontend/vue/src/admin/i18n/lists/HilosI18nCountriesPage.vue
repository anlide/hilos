<!-- HilosI18nCountriesPage — the Countries admin page (HilosPages.I18N_COUNTRIES):
every country of the system in one table. The code links to the country card
when that page is built. A missing name shows the code, a missing default
locale shows "Not chosen", and a custom country carries an icon mark. A
switched-off country is drawn in the secondary text color. The table, the row
view-model and the frame are the core headless's
(createHilosI18nCountriesTable); this view owns only the markup, so a project
mounts it by passing its HilosI18nCountryContext. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosI18nCountriesTable,
  HilosPages,
  type HilosI18nCountryContext,
  type HilosI18nCountryRow,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosLink from '../../../HilosLink.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosI18nCountryContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountriesPage requires a provided Hilos router.')
}

const countries = createHilosI18nCountriesTable(props.context)

onMounted(() => countries.start())
onUnmounted(() => countries.dispose())

/** The country card path, or nothing when that page has no address here. */
const countryPath = (row: HilosI18nCountryRow): string | undefined =>
  router.resolvePath(HilosPages.I18N_COUNTRY, { countryCode: row.code })
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_COUNTRIES">
    <HilosViewportTable :controller="countries.controller">
      <template #cell-code="{ row }">
        <template v-for="path in [countryPath(row)]" :key="path ?? 'none'">
          <HilosLink
            v-if="path"
            :to="path"
            class="text-uppercase"
            :class="{ 'link-secondary': !row.enabled }"
            :data-id="`i18n-countries-link-${row.code}`"
          >
            {{ row.code }}
          </HilosLink>
          <span
            v-else
            class="text-uppercase"
            :class="{ 'text-body-secondary': !row.enabled }"
            :data-id="`i18n-countries-link-${row.code}`"
          >
            {{ row.code }}
          </span>
        </template>
      </template>
      <template #cell-name="{ row }">
        <span :class="{ 'text-body-secondary': !row.enabled }">{{
          row.name ?? row.code.toUpperCase()
        }}</span>
        <span
          v-if="row.isOwn"
          class="badge text-bg-warning-subtle text-warning-emphasis ms-1"
          title="Custom country"
          :data-id="`i18n-countries-own-${row.code}`"
        >
          <i class="bi bi-question-circle" aria-hidden="true"></i>
          <span class="visually-hidden">Custom country</span>
        </span>
      </template>
      <template #cell-currencyCode="{ row }">
        <span
          class="fw-semibold"
          :class="{ 'text-body-secondary': !row.enabled }"
          >{{ row.currencySymbol }}</span
        >
        <span class="font-monospace text-body-secondary ms-1">{{
          row.currencyCode
        }}</span>
      </template>
      <template #cell-defaultLocaleCode="{ row }">
        <span
          v-if="row.defaultLocaleCode"
          class="font-monospace"
          :class="{ 'text-body-secondary': !row.enabled }"
          >{{ row.defaultLocaleCode }}</span
        >
        <span
          v-else
          class="badge text-bg-light border text-body-secondary fw-normal"
          :data-id="`i18n-countries-locale-none-${row.code}`"
        >
          <i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Not chosen
        </span>
      </template>
      <template #cell-enabled="{ row }">
        <input
          class="form-check-input"
          type="checkbox"
          disabled
          :checked="row.enabled"
          aria-label="Enabled"
          :data-id="`i18n-countries-enabled-${row.code}`"
        />
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
