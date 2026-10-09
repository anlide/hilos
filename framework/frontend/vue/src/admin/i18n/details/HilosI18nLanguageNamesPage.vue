<!-- HilosI18nLanguageNamesPage — the names of one language
(HilosPages.I18N_LANGUAGE_NAMES): how the language the address names is called in
every other language of the system (HIL-1477). The card's header and tabs (shared
with the main language page and locales page) stand above the names table, with
three states matching the main page: loading skeleton, card with table, and
unavailable alert when deleted (HIL-1479). Bootstrap classes only, painted with
classes that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nLanguageCard,
  createHilosI18nNamesTable,
  HILOS_I18N_LANGUAGE_NAMES,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const card = useSignal(createHilosI18nLanguageCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageNamesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const loading = useSignal(router.pageLoading)
const language = computedSignal(
  () =>
    (router.currentRoute.get().params.languageCode as string | undefined) ?? '',
)
const names = createHilosI18nNamesTable(
  props.context,
  HILOS_I18N_LANGUAGE_NAMES,
  language,
)
onMounted(() => names.start())
onUnmounted(() => names.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE_NAMES">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE_NAMES"
      />
      <HilosI18nNamesTable :controller="names.controller" />
    </template>
    <div
      v-else-if="loading"
      class="placeholder-glow"
      role="status"
      aria-label="Loading language"
    >
      <span class="placeholder col-6 d-block mb-2 rounded"></span>
      <span class="placeholder col-4 d-block rounded"></span>
    </div>
    <p
      v-else
      class="alert alert-secondary"
      role="status"
      data-id="language-card-unavailable"
    >
      Language details are no longer available.
    </p>
  </HilosAdminPage>
</template>
