<!-- HilosI18nLanguageLocalesPage — the locales of one language
(HilosPages.I18N_LANGUAGE_LOCALES): a row for the language alone and one for every
country of the system, whether the pair has a locale or not (HIL-1476). The table
is opened on the language the address names — its code travels as the table's
filter, and moving to another language on the same page asks for that language's
window. A row shows the country by its code in capitals and its base name in the
card's language, "— no country" on the language's own row; the locale's code or
the "No locale" plate — a plate, not a dash, because a dash reads like a value; and
the locale's switch as a disabled tick. A switched-off locale's text is muted with
the secondary text color, which keeps its contrast, never with opacity. HIL-1491
adds the row's add/edit/view button and one locale window; the card's header and tabs (shared
with the main language page and language names page) stand above the locales table,
with three states matching the main page. Bootstrap classes only, painted with classes
that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageLocalesTable,
  createHilosI18nLocaleTemplates,
  HILOS_I18N_LOCALE_COPY,
  HilosPages,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleWindowMode,
} from '@hilos/core'
import { inject, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
import HilosI18nLocaleWindow from './HilosI18nLocaleWindow.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const card = useSignal(createHilosI18nLanguageCard(props.context))
const templates = useSignal(createHilosI18nLocaleTemplates(props.context))
const opened = ref<{ rowKey: string; mode: HilosI18nLocaleWindowMode } | null>(
  null,
)
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageLocalesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const loading = useSignal(router.pageLoading)
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

function openWindow(
  row: HilosI18nLocaleRow,
  mode: HilosI18nLocaleWindowMode,
): void {
  opened.value = { rowKey: row.rowKey, mode }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE_LOCALES">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE_LOCALES"
      />
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
        <template #cell-actions="{ row }">
          <button
            v-if="row.localeCode === null"
            type="button"
            class="btn btn-sm btn-primary"
            :title="HILOS_I18N_LOCALE_COPY.open.add"
            :aria-label="HILOS_I18N_LOCALE_COPY.open.add"
            :data-id="`i18n-locales-add-${row.rowKey}`"
            @click="openWindow(row, 'add')"
          >
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
          </button>
          <button
            v-else-if="row.enabled === false"
            type="button"
            class="btn btn-sm btn-outline-primary"
            :title="HILOS_I18N_LOCALE_COPY.open.edit"
            :aria-label="HILOS_I18N_LOCALE_COPY.open.edit"
            :data-id="`i18n-locales-edit-${row.rowKey}`"
            @click="openWindow(row, 'edit')"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
          <button
            v-else
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :title="HILOS_I18N_LOCALE_COPY.open.view"
            :aria-label="HILOS_I18N_LOCALE_COPY.open.view"
            :data-id="`i18n-locales-view-${row.rowKey}`"
            @click="openWindow(row, 'view')"
          >
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </template>
      </HilosViewportTable>
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
    <HilosI18nLocaleWindow
      :context="context"
      :controller="locales.controller"
      :card="card"
      :templates="templates"
      :opened="opened"
      @close="opened = null"
    />
  </HilosAdminPage>
</template>
