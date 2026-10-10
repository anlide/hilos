<!-- HilosI18nCountryNamesPage — the names of one country
(HilosPages.I18N_COUNTRY_NAMES): how the country the address names is called in
every language of the system (HIL-1483). The card's header and tabs (shared
with the main country page) stand above the names table, with three states:
loading skeleton, card with table, and unavailable alert when deleted.
Bootstrap classes only, painted with classes that follow the theme
(styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nCountryCard,
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HilosPages,
  type HilosI18nCountryContext,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCountryHeader from './HilosI18nCountryHeader.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const card = useSignal(createHilosI18nCountryCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountryNamesPage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)
const country = computedSignal(
  () =>
    (router.currentRoute.get().params.countryCode as string | undefined) ?? '',
)
const names = createHilosI18nNamesTable(
  props.context as unknown as HilosI18nLanguageContext,
  HILOS_I18N_COUNTRY_NAMES,
  country,
)
onMounted(() => names.start())
onUnmounted(() => names.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_COUNTRY_NAMES">
    <template v-if="card">
      <HilosI18nCountryHeader
        :card="card"
        :active-page="HilosPages.I18N_COUNTRY_NAMES"
      />
      <section class="mb-4" data-id="country-names">
        <h2 class="h5 d-flex flex-wrap align-items-center gap-2">
          Names in different languages
          <span
            class="badge bg-body-tertiary text-body-secondary border fw-normal"
            >A row for every language</span
          >
        </h2>
        <HilosI18nNamesTable :controller="names.controller" />
      </section>
    </template>
    <div
      v-else-if="loading"
      class="placeholder-glow"
      role="status"
      aria-label="Loading country"
    >
      <span class="placeholder col-6 d-block mb-2 rounded"></span>
      <span class="placeholder col-4 d-block rounded"></span>
    </div>
    <p
      v-else
      class="alert alert-secondary"
      role="status"
      data-id="country-names-unavailable"
    >
      Country details are no longer available.
    </p>
  </HilosAdminPage>
</template>
