<!-- HilosI18nLanguagesPage — the Languages admin page (HilosPages.I18N_LANGUAGES):
every language of the system in one table. The code links to the language
card. Default and custom are icon marks; rtl and enabled are read-only ticks.
A switched-off language is drawn in the secondary text color. The table, the
row view-model and the frame are the core headless's
(createHilosI18nLanguagesTable); this view owns only the markup, so a project
mounts it by passing its HilosI18nLanguageContext. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInLanguages,
  createHilosI18nLanguageAdd,
  createHilosI18nLanguagesTable,
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGES_LEGEND,
  HilosPages,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosLink from '../../../HilosLink.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCatalogNote from './HilosI18nCatalogNote.vue'
import HilosI18nLanguageAdd from './HilosI18nLanguageAdd.vue'
import HilosI18nMarksLegend from './HilosI18nMarksLegend.vue'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosI18nLanguageContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguagesPage requires a provided Hilos router.')
}

const builtInCatalog = useSignal(createHilosI18nBuiltInCatalog(props.context))
const catalog = createHilosI18nBuiltInLanguages(props.context)
const builtInLanguages = useSignal(catalog)
const add = createHilosI18nLanguageAdd(props.context, catalog)
const languages = createHilosI18nLanguagesTable(props.context, {
  openAdd: add.open,
})

onMounted(() => languages.start())
onUnmounted(() => {
  languages.dispose()
  add.dispose()
})

/** The language card path, or nothing when that page has no address here. */
const languagePath = (row: HilosI18nLanguageRow): string | undefined =>
  router.resolvePath(HilosPages.I18N_LANGUAGE, { languageCode: row.code })
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGES">
    <HilosI18nCatalogNote
      v-if="builtInCatalog"
      :catalog="builtInCatalog"
      data-id="i18n-languages-catalog"
    >
      The framework's built-in catalog holds
      {{ builtInLanguages?.length ?? builtInCatalog.languageCount }} ISO 639-1
      codes with their own names and writing direction. A language from it is
      added by its code alone — the rest is filled in — and is refreshed
      whenever a framework update changes the catalog, but only while that
      language is switched off. A code that is not in the catalog can still be
      added, but keeping that language up is on you.
    </HilosI18nCatalogNote>
    <HilosViewportTable :controller="languages.controller">
      <template #cell-code="{ row }">
        <template v-for="path in [languagePath(row)]" :key="path ?? 'none'">
          <HilosLink
            v-if="path"
            :to="path"
            class="text-uppercase"
            :class="{ 'link-secondary': !row.enabled }"
            :data-id="`i18n-languages-link-${row.code}`"
          >
            {{ row.code }}
          </HilosLink>
          <span
            v-else
            class="text-uppercase"
            :class="{ 'text-body-secondary': !row.enabled }"
            :data-id="`i18n-languages-link-${row.code}`"
          >
            {{ row.code }}
          </span>
        </template>
      </template>
      <template #cell-nativeName="{ row }">
        <span :class="{ 'text-body-secondary': !row.enabled }">{{
          row.nativeName
        }}</span>
        <span
          v-if="row.isDefault"
          class="badge ms-1"
          :class="HILOS_I18N_LANGUAGE_MARKS.default.tone"
          :title="HILOS_I18N_LANGUAGE_MARKS.default.label"
          :data-id="`i18n-languages-default-${row.code}`"
        >
          <i
            class="bi"
            :class="HILOS_I18N_LANGUAGE_MARKS.default.icon"
            aria-hidden="true"
          ></i>
          <span class="visually-hidden">{{
            HILOS_I18N_LANGUAGE_MARKS.default.label
          }}</span>
        </span>
        <span
          v-if="row.isOwn"
          class="badge ms-1"
          :class="HILOS_I18N_LANGUAGE_MARKS.own.tone"
          :title="HILOS_I18N_LANGUAGE_MARKS.own.label"
          :data-id="`i18n-languages-own-${row.code}`"
        >
          <i
            class="bi"
            :class="HILOS_I18N_LANGUAGE_MARKS.own.icon"
            aria-hidden="true"
          ></i>
          <span class="visually-hidden">{{
            HILOS_I18N_LANGUAGE_MARKS.own.label
          }}</span>
        </span>
      </template>
      <template #cell-rtl="{ row }">
        <input
          class="form-check-input"
          type="checkbox"
          disabled
          :checked="row.rtl"
          aria-label="Right to left"
          :data-id="`i18n-languages-rtl-${row.code}`"
        />
      </template>
      <template #cell-enabled="{ row }">
        <input
          class="form-check-input"
          type="checkbox"
          disabled
          :checked="row.enabled"
          aria-label="Enabled"
          :data-id="`i18n-languages-enabled-${row.code}`"
        />
      </template>
    </HilosViewportTable>
    <HilosI18nMarksLegend
      :rows="HILOS_I18N_LANGUAGES_LEGEND"
      data-id="i18n-languages-legend"
      class="mt-4"
    />
    <HilosI18nLanguageAdd :add="add" :context="context" />
  </HilosAdminPage>
</template>
