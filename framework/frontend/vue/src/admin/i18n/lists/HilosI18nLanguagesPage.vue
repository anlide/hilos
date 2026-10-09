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
  createHilosI18nLanguagesTable,
  HilosPages,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageRow,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosLink from '../../../HilosLink.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosI18nLanguageContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguagesPage requires a provided Hilos router.')
}

const languages = createHilosI18nLanguagesTable(props.context)

onMounted(() => languages.start())
onUnmounted(() => languages.dispose())

/** The language card path, or nothing when that page has no address here. */
const languagePath = (row: HilosI18nLanguageRow): string | undefined =>
  router.resolvePath(HilosPages.I18N_LANGUAGE, { languageCode: row.code })
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGES">
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
          class="badge text-bg-primary-subtle text-primary-emphasis ms-1"
          title="Default language"
          :data-id="`i18n-languages-default-${row.code}`"
        >
          <i class="bi bi-star-fill" aria-hidden="true"></i>
          <span class="visually-hidden">Default language</span>
        </span>
        <span
          v-if="row.isOwn"
          class="badge text-bg-warning-subtle text-warning-emphasis ms-1"
          title="Custom language"
          :data-id="`i18n-languages-own-${row.code}`"
        >
          <i class="bi bi-question-circle" aria-hidden="true"></i>
          <span class="visually-hidden">Custom language</span>
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
  </HilosAdminPage>
</template>
