<!-- HilosI18nLanguageNamesPage — the names of one language
(HilosPages.I18N_LANGUAGE_NAMES): how the language the address names is called in
every other language of the system (HIL-1477). The table is opened on that
language — its code travels as the table's filter, and moving to another language
on the same page asks for that language's window. Only the table stands here: the
card's header and tabs above it arrive with HIL-1479. -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nNamesTable,
  HILOS_I18N_LANGUAGE_NAMES,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageNamesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
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
    <HilosI18nNamesTable :controller="names.controller" />
  </HilosAdminPage>
</template>
