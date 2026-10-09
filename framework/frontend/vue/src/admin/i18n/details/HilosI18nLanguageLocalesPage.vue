<!-- HilosI18nLanguageLocalesPage — the locales of one language
(HilosPages.I18N_LANGUAGE_LOCALES): a row for the language alone and one for every
country of the system, whether the pair has a locale or not (HIL-1476). The table
is opened on the language the address names — its code travels as the table's
filter, and moving to another language on the same page asks for that language's
window. A row shows the country by its code in capitals and its base name in the
card's language, "— no country" on the language's own row; the locale's code or
the "No locale" plate — a plate, not a dash, because a dash reads like a value; and
the locale's switch as a disabled tick. A switched-off locale's text is muted with
the secondary text color, which keeps its contrast, never with opacity. There are
no buttons here — the action leaves put them in — and only the table stands here:
the card's header and tabs above it arrive with HIL-1480. Bootstrap classes only,
painted with classes that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nLanguageLocalesTable,
  HilosPages,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleRow,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageLocalesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const language = computedSignal(
  () =>
    (router.currentRoute.get().params.languageCode as string | undefined) ?? '',
)
const locales = createHilosI18nLanguageLocalesTable(props.context, language)
onMounted(() => locales.start())
onUnmounted(() => locales.dispose())

/** A plate standing where there is no value of its own, in both themes. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** Whether the row's locale exists and is switched off, which mutes its text. */
function switchedOff(row: HilosI18nLocaleRow): boolean {
  return row.enabled === false
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE_LOCALES">
    <HilosViewportTable :controller="locales.controller">
      <template #cell-countryCode="{ row }">
        <span
          v-if="row.countryCode === null"
          class="fst-italic text-body-secondary"
          :data-id="`i18n-locales-country-${row.rowKey}`"
          >— no country</span
        >
        <span
          v-else
          :class="{ 'text-body-secondary': switchedOff(row) }"
          :data-id="`i18n-locales-country-${row.rowKey}`"
          ><span class="text-uppercase text-body-secondary me-1">{{
            row.countryCode
          }}</span
          >{{ row.countryName }}</span
        >
      </template>
      <template #cell-localeCode="{ row }">
        <span
          v-if="row.localeCode === null"
          :class="PLATE"
          :data-id="`i18n-locales-none-${row.rowKey}`"
          ><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No
          locale</span
        >
        <span
          v-else
          :class="{ 'text-body-secondary': switchedOff(row) }"
          :data-id="`i18n-locales-code-${row.rowKey}`"
          >{{ row.localeCode }}</span
        >
      </template>
      <template #cell-enabled="{ row }">
        <input
          v-if="row.localeCode !== null"
          class="form-check-input"
          type="checkbox"
          disabled
          :checked="row.enabled === true"
          aria-label="Enabled"
          :data-id="`i18n-locales-enabled-${row.rowKey}`"
        />
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
